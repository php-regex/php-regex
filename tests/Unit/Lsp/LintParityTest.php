<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPRegex\Tests\Unit\Lsp;

use PHPRegex\LanguageServer\Converter\PatternSpan;
use PHPRegex\LanguageServer\Converter\PositionConverter;
use PHPRegex\LanguageServer\Document\RegexFinder;
use PHPRegex\LanguageServer\Document\RegexOccurrence;
use PHPRegex\LanguageServer\Protocol\Transport;
use PHPRegex\LanguageServer\Server;
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Linter\RedosVerdict;
use PHPRegex\Parser\Validation\ValidationErrorCategory;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSearchCost;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The editor reports what `regex lint` reports: the same source, read by
 * the server in process and by the analysis the lint command runs, gives
 * the same diagnostics — the same codes, the same severities, each placed
 * from the same offset of the same pattern.
 *
 * Messages are read oracle against oracle, the live AnalysisService output
 * the expected side: only the two transforms the editor documents are
 * pinned as text, the "Not analyzed: " prefix of a limit and the hint a
 * parse or validation error carries on the line under it.
 *
 * Where the two cannot agree the divergence is the promise, not a failure:
 * a pattern no single literal holds (a heredoc, a concatenation) and a
 * string whose first byte is no delimiter are reported by the command
 * alone — nothing in the editor can be underlined for them.
 *
 * @phpstan-type ParityRange array{start: array{line: int, character: int}, end: array{line: int, character: int}}
 * @phpstan-type ParityDiagnostic array{code: string, severity: int, range: ParityRange, message: string}
 * @phpstan-type ExpectedDiagnostic array{code: string, severity: int, range: ParityRange, message?: string, messageOrHint?: non-empty-list<string>, placedExactly: bool}
 */
final class LintParityTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../Fixtures/Lint/';

    private const SEVERITIES = ['error' => 1, 'warning' => 2, 'info' => 3];

    /**
     * @var resource
     */
    private $output;

    /**
     * @var resource
     */
    private $input;

    private ?Server $server = null;

    protected function setUp(): void
    {
        $output = fopen('php://memory', 'r+');
        $input = fopen('php://memory', 'r+');
        if (false === $output || false === $input) {
            self::fail('Unable to open an in-memory stream.');
        }

        $this->output = $output;
        $this->input = $input;
    }

    protected function tearDown(): void
    {
        fclose($this->output);
        fclose($this->input);
    }

    /**
     * The sources: the fixture rows that exercise several lint rules, a
     * validation error, an escaped spelling, and the multibyte and escaped
     * patterns the ranges go through.
     *
     * @return iterable<string, array{fixture: string}>
     */
    public static function provideSources(): iterable
    {
        yield 'useless flags' => ['fixture' => 'regex_fixes_multiple_flags.php'];
        yield 'an unescaped delimiter' => ['fixture' => 'regression_filepath.php'];
        yield 'an impossible anchor' => ['fixture' => 'regression_variable.php'];
        yield 'an unclosed character class' => ['fixture' => 'unclosed_character_class.php'];
        yield 'an escape the literal spells' => ['fixture' => 'tokenbased_escaped_content.php'];
        yield 'escaped delimiters' => ['fixture' => 'pen_emoji_escaped_delimiters.php'];
        yield 'multibyte patterns' => ['fixture' => 'simple_lsp_parity_multibyte.php'];
        yield 'escaped spellings' => ['fixture' => 'simple_lsp_parity_escaped.php'];
    }

    #[Test]
    #[DataProvider('provideSources')]
    public function test_the_editor_publishes_what_regex_lint_reports(string $fixture): void
    {
        $this->assertParity($fixture);
    }

    /**
     * The ReDoS verdicts are part of the promise once the configuration
     * turns the analysis on: their own method, their own configuration.
     */
    #[Test]
    public function test_the_editor_publishes_the_redos_verdicts_regex_lint_reports(): void
    {
        $this->assertParity('simple_lsp_parity_redos.php', redos: true);
    }

    /**
     * A limit of the library is no error in the pattern: both report the
     * pattern was not analyzed, in the same words over the same body.
     */
    #[Test]
    public function test_the_not_analyzed_transform_is_the_same_on_both_sides(): void
    {
        $this->assertParity('simple_lsp_parity_limit.php', ['max_recursion_depth' => 3]);
    }

    /**
     * A pattern built at the call from several pieces, or written as a
     * heredoc: the command reports what it read of it, the editor cannot —
     * no single literal holds it for a diagnostic to land in.
     */
    #[Test]
    public function test_a_pattern_no_single_literal_holds_is_reported_by_the_command_only(): void
    {
        $content = (string) file_get_contents(self::FIXTURES.'simple_lsp_parity_divergence_heredoc.php');

        $this->assertNotSame([], $this->linted($content), 'The command was expected to report the heredoc and the concatenation.');
        $this->assertSame([], $this->published($content), 'The editor published diagnostics for a pattern no single literal holds.');
    }

    /**
     * A string whose first byte is no delimiter PHP accepts: read by the
     * command, which validates what it is given, never taken for a pattern
     * by the editor.
     */
    #[Test]
    public function test_a_pattern_whose_first_byte_is_no_delimiter_is_reported_by_the_command_only(): void
    {
        $content = (string) file_get_contents(self::FIXTURES.'simple_lsp_parity_divergence_delimiter.php');

        $this->assertNotSame([], $this->linted($content), 'The command was expected to report the string.');
        $this->assertSame([], $this->published($content), 'The editor published diagnostics for a string that is no pattern.');
    }

    /**
     * @param array{max_recursion_depth?: int} $options both sides judge with them
     */
    private function assertParity(string $fixture, array $options = [], bool $redos = false): void
    {
        $content = (string) file_get_contents(self::FIXTURES.$fixture);
        $published = $this->sorted($this->published($content, $options, $redos));
        $expected = $this->sorted($this->expected($content, $this->linted($content, $options, $redos)));

        $this->assertSame(
            array_map(self::where(...), $expected),
            array_map(self::where(...), $published),
            \sprintf('%s: the codes, severities and starts the editor published differ from the command\'s.', $fixture),
        );

        foreach ($expected as $row) {
            $found = null;
            foreach ($published as $candidate) {
                if ($candidate['code'] === $row['code'] && self::where($candidate) === self::where($row)) {
                    $found = $candidate;
                }
            }
            $this->assertIsArray($found, \sprintf('%s: no diagnostic published at %s.', $fixture, $row['code']));

            // A lint rule covers the node it names: the command's issue
            // says where the node starts, not where it ends.
            if ($row['placedExactly'] ?? false) {
                $this->assertSame($row['range'], $found['range'], \sprintf('%s: the range of %s differs.', $fixture, $row['code']));
            }

            $message = $row['message'] ?? null;
            $hints = $row['messageOrHint'] ?? null;
            $foundMessage = null;
            if (null !== $message || null !== $hints) {
                $foundMessage = $found['message'] ?? null;
                $this->assertIsString($foundMessage, \sprintf('%s: no message published with %s.', $fixture, $row['code']));
            }

            if (null !== $message) {
                $this->assertSame($message, $foundMessage, \sprintf('%s: the message of %s differs.', $fixture, $row['code']));
            } elseif (null !== $hints) {
                $this->assertSame($hints[0], explode("\n", $foundMessage)[0], \sprintf('%s: the first line of %s differs.', $fixture, $row['code']));
                $this->assertContains($foundMessage, $hints, \sprintf('%s: the hint appended to %s differs.', $fixture, $row['code']));
            }
        }

        // Whatever a diagnostic covers, it never runs past the literal its
        // pattern stands in.
        $occurrences = (new RegexFinder())->find($content);
        foreach ($published as $row) {
            foreach ($occurrences as $occurrence) {
                if ($occurrence->start['line'] !== $row['range']['start']['line']) {
                    continue;
                }

                $this->assertTrue(
                    $row['range']['end']['line'] < $occurrence->end['line']
                    || ($row['range']['end']['line'] === $occurrence->end['line'] && $row['range']['end']['character'] <= $occurrence->end['character']),
                    \sprintf('%s: the diagnostic of %s runs past its literal.', $fixture, $row['code']),
                );
            }
        }
    }

    /**
     * The diagnostics the server publishes for the source, the session
     * initialized as an editor without a workspace initializes it.
     *
     * @param array{max_recursion_depth?: int} $options
     *
     * @return list<ParityDiagnostic>
     */
    private function published(string $content, array $options = [], bool $redos = false): array
    {
        $this->server = new Server(Regex::create(['cache' => null] + $options), $this->input, transport: new Transport($this->output));
        $this->send(
            ['id' => 1, 'method' => 'initialize', 'params' => $redos ? ['initializationOptions' => ['redos' => true]] : []],
            ['method' => 'initialized', 'params' => []],
            ['method' => 'textDocument/didOpen', 'params' => ['textDocument' => [
                'uri' => 'file:///parity.php',
                'languageId' => 'php',
                'version' => 1,
                'text' => $content,
            ]]],
        );

        $diagnostics = [];
        foreach ($this->notifications('textDocument/publishDiagnostics') as $params) {
            $list = $params['diagnostics'] ?? null;
            if ('file:///parity.php' !== ($params['uri'] ?? null) || !\is_array($list)) {
                continue;
            }
            foreach ($list as $diagnostic) {
                if (!\is_array($diagnostic)) {
                    continue;
                }

                $code = $diagnostic['code'] ?? null;
                $severity = $diagnostic['severity'] ?? null;
                $message = $diagnostic['message'] ?? null;
                $range = $diagnostic['range'] ?? null;
                $this->assertIsString($code);
                $this->assertIsInt($severity);
                $this->assertIsString($message);
                $this->assertIsArray($range);

                $diagnostics[] = [
                    'code' => $code,
                    'severity' => $severity,
                    'range' => self::range($range),
                    'message' => $message,
                ];
            }
        }

        return $diagnostics;
    }

    /**
     * The issues the lint command's own analysis reports for the same
     * source, read from a file as the command reads it.
     *
     * @param array{max_recursion_depth?: int} $options
     *
     * @return array<LintIssue>
     */
    private function linted(string $content, array $options = [], bool $redos = false): array
    {
        $base = tempnam(sys_get_temp_dir(), 'regex-parser-parity-');
        $this->assertIsString($base);
        $path = $base.'.php';
        unlink($base);
        file_put_contents($path, $content);

        try {
            $regex = Regex::create(['cache' => null] + $options);
            $patterns = (new PatternExtractor(new TokenBasedExtractionStrategy()))->extract([$path]);

            return (new AnalysisService(
                $regex->parser(),
                redosEnabled: $redos,
                redosMode: RedosMode::Theoretical,
                redosThreshold: 'high',
            ))->lint($patterns);
        } finally {
            unlink($path);
        }
    }

    /**
     * What the editor should publish for the command's issues: each placed
     * from the offset the command reports, through the span of the literal
     * the editor underlines.
     *
     * The message of a lint issue or of a limit is the command's own; the
     * message of an error the validation reported is the command's, with
     * the hint it carries appended on the line under it, as the editor
     * documents it; a ReDoS verdict carries its evidence under it.
     *
     * @param array<LintIssue> $issues
     *
     * @return list<ExpectedDiagnostic>
     */
    private function expected(string $content, array $issues): array
    {
        $positions = new PositionConverter($content);
        $byLine = [];
        foreach ((new RegexFinder())->find($content) as $occurrence) {
            $byLine[$occurrence->start['line']] = $occurrence;
        }

        $rows = [];
        foreach ($issues as $issue) {
            $issueId = $issue->issueId;
            $type = $issue->severity->level();
            $line = $issue->line;
            $message = $issue->message;
            $position = $issue->position;
            $validation = $issue->validation;

            $limit = $validation instanceof ValidationResult && ValidationErrorCategory::Limit === $validation->category;
            $lint = \is_string($issueId);
            $redos = RedosVerdict::ISSUE_ID === $issueId;
            $search = RedosVerdict::SEARCH_ISSUE_ID === $issueId;

            $this->assertIsString($type);
            $this->assertIsInt($line);
            $this->assertIsString($message);
            $this->assertTrue(null === $position || \is_int($position));

            $code = $issueId;
            $hint = null;
            if (!$lint && $validation instanceof ValidationResult) {
                $code = $validation->errorCode?->value;
                $hint = $validation->hint;
            }
            $this->assertIsString($code, 'An issue carries neither an id nor an error code.');

            $occurrence = $byLine[$line - 1] ?? null;
            $this->assertInstanceOf(RegexOccurrence::class, $occurrence, \sprintf('No pattern literal on line %d of the source.', $line));
            $span = PatternSpan::of($occurrence, $positions);

            if ($limit) {
                $range = $span->range(0, $span->bodyLength());
            } elseif ($redos) {
                $range = self::redosRange($issue, $span);
            } elseif ($search) {
                $range = $span->range(0, $span->bodyLength());
            } else {
                $range = $span->range(\is_int($position) ? $position : 0);
            }

            $row = [
                'code' => $code,
                'severity' => self::SEVERITIES[$type] ?? 3,
                'range' => $range,
                'placedExactly' => !$lint || $redos || $search,
            ];
            if (($lint && !$redos && !$search) || $limit) {
                $row['message'] = $message;
            } elseif ($redos) {
                $analysis = $issue->analysis;
                $this->assertInstanceOf(RedosAnalysis::class, $analysis);
                $row['message'] = implode("\n", [$message, ...RedosVerdict::evidence($analysis)]);
            } elseif ($search) {
                $analysis = $issue->analysis;
                $this->assertInstanceOf(RedosAnalysis::class, $analysis);
                $this->assertInstanceOf(RedosSearchCost::class, $analysis->searchCost);
                $row['message'] = implode("\n", [$message, ...RedosVerdict::searchEvidence($analysis->searchCost, $analysis->pcreVersion)]);
            } elseif (\is_string($hint) && '' !== $hint) {
                $row['messageOrHint'] = [$message, $message."\n".$hint];
            } else {
                $row['message'] = $message;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The ReDoS verdict covers the risky part the analysis names, the whole
     * body when it names none.
     *
     * @return ParityRange
     */
    private static function redosRange(LintIssue $issue, PatternSpan $span): array
    {
        $analysis = $issue->analysis;
        self::assertInstanceOf(RedosAnalysis::class, $analysis);

        $hotspot = $analysis->primaryHotspot();

        return null === $hotspot ? $span->range(0, $span->bodyLength()) : $span->range($hotspot->start, $hotspot->end);
    }

    /**
     * @param array<mixed> $range
     *
     * @return ParityRange
     */
    private static function range(array $range): array
    {
        $start = $range['start'] ?? null;
        $end = $range['end'] ?? null;
        self::assertIsArray($start);
        self::assertIsArray($end);
        self::assertIsInt($start['line'] ?? null);
        self::assertIsInt($start['character'] ?? null);
        self::assertIsInt($end['line'] ?? null);
        self::assertIsInt($end['character'] ?? null);

        return [
            'start' => ['line' => $start['line'], 'character' => $start['character']],
            'end' => ['line' => $end['line'], 'character' => $end['character']],
        ];
    }

    /**
     * The rows in the order the protocol meets them: line, column, code.
     *
     * @param list<ParityDiagnostic>|list<ExpectedDiagnostic> $rows
     *
     * @return list<ParityDiagnostic>|list<ExpectedDiagnostic>
     */
    private function sorted(array $rows): array
    {
        $keys = [];
        foreach ($rows as $index => $row) {
            $keys[] = [$row['range']['start']['line'], $row['range']['start']['character'], $row['code'], $row['severity'], $index];
        }
        usort($keys, static fn (array $a, array $b): int => $a <=> $b);

        $sorted = [];
        foreach ($keys as $key) {
            $sorted[] = $rows[$key[4]];
        }

        return $sorted;
    }

    /**
     * @param ParityDiagnostic|ExpectedDiagnostic $row
     *
     * @return array{int, int, string, int}
     */
    private static function where(array $row): array
    {
        return [$row['range']['start']['line'], $row['range']['start']['character'], $row['code'], $row['severity']];
    }

    /**
     * Hands the server the messages and lets it read them, as many times as
     * a test needs: the input stream is written past what it has read.
     *
     * @param array<string, mixed> ...$messages
     */
    private function send(array ...$messages): void
    {
        $this->assertInstanceOf(Server::class, $this->server);

        $start = ftell($this->input);
        foreach ($messages as $message) {
            $json = (string) json_encode(['jsonrpc' => '2.0'] + $message);
            fwrite($this->input, 'Content-Length: '.\strlen($json)."\r\n\r\n".$json);
        }
        fseek($this->input, (int) $start);

        $this->server->run();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function notifications(string $method): array
    {
        rewind($this->output);
        $written = (string) stream_get_contents($this->output);

        $found = [];
        foreach (preg_split('/Content-Length: \d+\r\n\r\n/', $written) ?: [] as $body) {
            $message = json_decode($body, true);
            if (\is_array($message) && $method === ($message['method'] ?? null) && \is_array($message['params'] ?? null)) {
                /** @var array<string, mixed> $params */
                $params = $message['params'];
                $found[] = $params;
            }
        }

        return $found;
    }
}
