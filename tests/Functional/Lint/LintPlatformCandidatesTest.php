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

namespace PHPRegex\Tests\Functional\Lint;

use PHPRegex\Linter\Parallel\ForkedWorkerPool;
use PHPRegex\Tests\Support\ArgumentCarriers;
use PHPRegex\Tests\Support\EngineProbe;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pattern built of DIRECTORY_SEPARATOR, PHP_EOL or PATH_SEPARATOR is
 * one pattern on each system: the argument holds two, and the issues of
 * the two are compared by identifier. An issue both raise is the issue
 * of the code, at its level. An issue one of them raises alone holds on
 * that system only: it is a warning at most, an error counted as a
 * warning and an info left an info, and its message ends by naming the
 * constant and the value it holds there, so a project that runs on one
 * system is not failed by the other.
 *
 * The engine, PHP 8.4.27 and PCRE2 10.49 (php -r, preg_match() and its
 * warning):
 *
 *   ~/~        matches "/"                ~\~        No ending delimiter '~' found
 *   #^[^/]+#   compiles                   #^[^\]+#   missing terminating ] for character class at offset 6
 *   ~(a/b~     missing closing parenthesis at offset 4, and ~(a\b~ the same
 *   ~a{1}/b~   compiles                   ~a{1}\b~   compiles
 *   ~a/{1}~    matches "a/"               ~a\{1}~    matches "a{1}", not "a/": no quantifier there
 *   /[;-:]/    range out of order in character class at offset 4
 *   /[;-;]/    compiles
 *   ~(a/)+~    matches "a/a/" and keeps "a/", the last capture
 *   ~(a\)+~    missing closing parenthesis at offset 5
 *
 * Each case runs in this process, the files read with the parser when it
 * is installed and the patterns analysed by one worker, and through the
 * binary, the files read with the tokenizer and the two patterns of the
 * argument analysed by two workers.
 */
final class LintPlatformCandidatesTest extends TestCase
{
    use RunsRegexCli;
    use TemporaryProject;

    /**
     * Each row gives the argument, its two patterns with whether the
     * engine compiles each, and the issues of the argument: the level,
     * the identifier, and what the message says the issue holds for, null
     * for an issue of both patterns.
     *
     * @return iterable<string, array{argument: string, candidates: array<string, bool>, issues: list<array{string, string, string|null}>, exitCode: int}>
     */
    public static function provideArguments(): iterable
    {
        yield 'an error of the Windows pattern alone' => [
            'argument' => "'~' . DIRECTORY_SEPARATOR . '~'",
            'candidates' => ['~/~' => true, '~\~' => false],
            'issues' => [['warning', 'regex.delimiter.unclosed', 'DIRECTORY_SEPARATOR is "\"']],
            'exitCode' => 0,
        ];

        yield 'an error of the Windows pattern alone, inside a class' => [
            'argument' => "'#^[^' . DIRECTORY_SEPARATOR . ']+#'",
            'candidates' => ['#^[^/]+#' => true, '#^[^\]+#' => false],
            'issues' => [['warning', 'regex.charclass.unclosed', 'DIRECTORY_SEPARATOR is "\"']],
            'exitCode' => 0,
        ];

        yield 'an error of both patterns' => [
            'argument' => "'~(a' . DIRECTORY_SEPARATOR . 'b~'",
            'candidates' => ['~(a/b~' => false, '~(a\b~' => false],
            'issues' => [['error', 'regex.group.unclosed', null]],
            'exitCode' => 1,
        ];

        yield 'a warning of both patterns' => [
            'argument' => "'~a{1}' . DIRECTORY_SEPARATOR . 'b~'",
            'candidates' => ['~a{1}/b~' => true, '~a{1}\b~' => true],
            'issues' => [['warning', 'regex.lint.quantifier.useless', null]],
            'exitCode' => 0,
        ];

        yield 'a warning of the other pattern alone' => [
            'argument' => "'~a' . DIRECTORY_SEPARATOR . '{1}~'",
            'candidates' => ['~a/{1}~' => true, '~a\{1}~' => true],
            'issues' => [['warning', 'regex.lint.quantifier.useless', 'DIRECTORY_SEPARATOR is "/"']],
            'exitCode' => 0,
        ];

        yield 'an issue of each pattern, another constant' => [
            'argument' => "'/[;-' . PATH_SEPARATOR . ']/'",
            'candidates' => ['/[;-:]/' => false, '/[;-;]/' => true],
            'issues' => [
                ['warning', 'regex.lint.range.useless', 'PATH_SEPARATOR is ";"'],
                ['warning', 'regex.range.reversed', 'PATH_SEPARATOR is ":"'],
            ],
            'exitCode' => 0,
        ];

        // The warning is a ceiling, not a level every such issue is given:
        // the info of the one pattern stays an info, and the error of the
        // other becomes a warning.
        yield 'an info of one pattern and an error of the other' => [
            'argument' => "'~(a' . DIRECTORY_SEPARATOR . ')+~'",
            'candidates' => ['~(a/)+~' => true, '~(a\)+~' => false],
            'issues' => [
                ['warning', 'regex.group.unclosed', 'DIRECTORY_SEPARATOR is "\"'],
                ['info', 'regex.lint.group.quantifiedCapture', 'DIRECTORY_SEPARATOR is "/"'],
            ],
            'exitCode' => 0,
        ];
    }

    /**
     * @param array<string, bool>                      $candidates
     * @param list<array{string, string, string|null}> $issues
     */
    #[Test]
    #[DataProvider('provideArguments')]
    public function test_an_issue_of_one_system_alone_is_a_warning_that_names_it(string $argument, array $candidates, array $issues, int $exitCode): void
    {
        foreach ($candidates as $pattern => $compiles) {
            $this->assertSame($compiles, EngineProbe::compiles($pattern), $pattern);
        }

        $this->enterProject(['src/a.php' => "<?php\n\npreg_match(".$argument.", \$path);\n"]);
        $arguments = ['lint', 'src', '--format=json', '--no-optimize'];

        $runs = [
            'in this process' => $this->runRegex([...$arguments, '--jobs=1']),
            'through the binary' => $this->runRegexProcess([...$arguments, '--jobs='.(ForkedWorkerPool::canFork() ? 2 : 1)]),
        ];

        foreach ($runs as $name => [$code, $stdout, $stderr]) {
            $document = JsonContract::decodeDocument($stdout);
            $reported = self::issuesOf($document);

            $this->assertSame($issues, self::distinct($reported), $name."\n".$stdout);
            $this->assertSame($exitCode, $code, $name."\n".$stdout.$stderr);

            // Each is counted at the level it is reported at.
            $stats = JsonContract::asArray($document['stats'] ?? null);
            $this->assertSame(\count(array_filter($reported, static fn (array $issue): bool => 'error' === $issue[0])), $stats['errors'] ?? null, $name);
            $this->assertSame(\count(array_filter($reported, static fn (array $issue): bool => 'warning' === $issue[0])), $stats['warnings'] ?? null, $name);
            $this->assertSame(\count(array_filter($reported, static fn (array $issue): bool => 'info' === $issue[0])), $stats['infos'] ?? null, $name);

            // One argument, of two patterns.
            $this->assertSame(['constant' => 0, 'finite_set' => 1, 'quoted_shell' => 0, 'raw_shell' => 0, 'opaque' => 0], ArgumentCarriers::countsOf($stats), $name);
        }
    }

    /**
     * What the engine matches with the patterns the rows above compile:
     * the quantifier of "~a/{1}~" is one, and "~a\{1}~" holds none.
     *
     * @return iterable<string, array{pattern: string, subject: string, matches: list<string>|null}>
     */
    public static function provideSubjects(): iterable
    {
        yield 'the separator alone' => ['pattern' => '~/~', 'subject' => '/', 'matches' => ['/']];
        yield 'a quantifier after the separator' => ['pattern' => '~a/{1}~', 'subject' => 'a/', 'matches' => ['a/']];
        yield 'a quantifier after the separator, the separator missing' => ['pattern' => '~a/{1}~', 'subject' => 'a', 'matches' => null];
        yield 'a brace escaped, the braces in the subject' => ['pattern' => '~a\{1}~', 'subject' => 'a{1}', 'matches' => ['a{1}']];
        yield 'a brace escaped, the separator in the subject' => ['pattern' => '~a\{1}~', 'subject' => 'a/', 'matches' => null];
        yield 'a quantifier before the separator' => ['pattern' => '~a{1}/b~', 'subject' => 'a/b', 'matches' => ['a/b']];
        yield 'a quantifier before a word boundary' => ['pattern' => '~a{1}\b~', 'subject' => 'a/b', 'matches' => ['a']];
        yield 'a group repeated keeps its last capture' => ['pattern' => '~(a/)+~', 'subject' => 'a/a/', 'matches' => ['a/a/', 'a/']];
    }

    /**
     * @param list<string>|null $matches what preg_match() writes, null when it finds nothing
     */
    #[Test]
    #[DataProvider('provideSubjects')]
    public function test_the_engine_matches_what_the_rows_say_of_each_pattern(string $pattern, string $subject, ?array $matches): void
    {
        $this->assertSame(null === $matches ? 0 : 1, preg_match($pattern, $subject, $written));
        $this->assertSame($matches ?? [], $written);
    }

    /**
     * The example of a path pattern: '~' . DIRECTORY_SEPARATOR . '~' is
     * "~/~" where the separator is "/", and "~\~", which has no ending
     * delimiter, on Windows. One warning, naming "\", and the run passes.
     */
    #[Test]
    public function test_a_pattern_that_fails_on_windows_alone_is_one_warning_and_the_run_passes(): void
    {
        $this->assertTrue(EngineProbe::compiles('~/~'));
        $this->assertSame("preg_match(): No ending delimiter '~' found", EngineProbe::warning('~\~'));

        $this->enterProject(['src/a.php' => "<?php\n\npreg_match('~' . DIRECTORY_SEPARATOR . '~', \$path);\n"]);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--format=json', '--no-optimize', '--jobs=1']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $document = JsonContract::decodeDocument($stdout);
        $this->assertSame(
            ['errors' => 0, 'warnings' => 1, 'optimizations' => 0, 'redos_errors' => 0, 'infos' => 0, 'lint_errors' => 0, 'parser_fallbacks' => 0, 'not_analyzed' => 0],
            JsonContract::issueCounts($document['stats'] ?? null),
        );

        $found = [];
        foreach (JsonContract::asArray($document['results'] ?? null) as $result) {
            foreach (JsonContract::asArray(JsonContract::asArray($result)['issues'] ?? null) as $issue) {
                $found[] = JsonContract::asArray($issue);
            }
        }
        $this->assertCount(1, $found, $stdout);
        $this->assertSame(['warning', 'regex.delimiter.unclosed', 'src/a.php', 3, 12], [$found[0]['severity'], $found[0]['issue_id'], $found[0]['file'], $found[0]['line'], $found[0]['column']]);
        $this->assertIsString($found[0]['message']);
        $this->assertStringStartsWith('No closing delimiter "~" found.', $found[0]['message']);
        $this->assertStringEndsWith(' Holds when DIRECTORY_SEPARATOR is "\".', $found[0]['message']);

        // The console says the same, and passes.
        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--no-optimize', '--jobs=1']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertStringContainsString('Holds when DIRECTORY_SEPARATOR is "\".', $stdout);
        $this->assertMatchesRegularExpression('/^\s*PASS\s+1 warnings found, 0 optimizations available\.$/m', $stdout);
    }

    /**
     * The other patterns of the run are no part of the comparison: an
     * error of a pattern that holds on every system stays an error, says
     * nothing of a system, and fails the run, beside the warning of the
     * pattern that fails on Windows alone.
     */
    #[Test]
    public function test_an_issue_of_another_pattern_of_the_run_is_left_as_it_is(): void
    {
        $this->assertSame('preg_match(): Compilation failed: missing closing parenthesis at offset 3', EngineProbe::warning('/(a+/'));

        $this->enterProject(['src/a.php' => "<?php\n\npreg_match('~' . DIRECTORY_SEPARATOR . '~', \$path);\npreg_match('/(a+/', \$path);\n"]);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--format=json', '--no-optimize', '--jobs=1']);

        $this->assertSame(1, $exitCode, $stdout.$stderr);
        $found = [];
        foreach (JsonContract::asArray(JsonContract::decodeDocument($stdout)['results'] ?? null) as $result) {
            $result = JsonContract::asArray($result);
            foreach (JsonContract::asArray($result['issues'] ?? null) as $issue) {
                $issue = JsonContract::asArray($issue);
                $this->assertIsString($issue['message'] ?? null);
                $found[] = [$result['line'] ?? null, $result['pattern'] ?? null, $issue['severity'] ?? null, $issue['issue_id'] ?? null, str_contains($issue['message'], 'Holds when')];
            }
        }
        $this->assertSame([
            [3, '~\~', 'warning', 'regex.delimiter.unclosed', true],
            [4, '/(a+/', 'error', 'regex.group.unclosed', false],
        ], $found, $stdout);
    }

    /**
     * The issues of a report: the level, the identifier, and what the end
     * of the message says the issue holds for, null when it says nothing;
     * each on the argument.
     *
     * @param array<mixed> $document
     *
     * @return list<array{string, string, string|null}>
     */
    private static function issuesOf(array $document): array
    {
        $issues = [];
        foreach (JsonContract::asArray($document['results'] ?? null) as $result) {
            $result = JsonContract::asArray($result);
            self::assertSame(['src/a.php', 3, 12], [$result['file'] ?? null, $result['line'] ?? null, $result['column'] ?? null]);
            foreach (JsonContract::asArray($result['issues'] ?? null) as $issue) {
                $issue = JsonContract::asArray($issue);
                self::assertIsString($issue['severity'] ?? null);
                self::assertIsString($issue['issue_id'] ?? null);
                self::assertIsString($issue['message'] ?? null);
                self::assertSame(['src/a.php', 3, 12], [$issue['file'] ?? null, $issue['line'] ?? null, $issue['column'] ?? null]);
                $holds = 1 === preg_match('/ Holds when (.+)\.$/', $issue['message'], $match) ? $match[1] : null;
                self::assertSame(null === $holds ? 0 : 1, substr_count($issue['message'], 'Holds when'), $issue['message']);
                $issues[] = [$issue['severity'], $issue['issue_id'], $holds];
            }
        }

        return $issues;
    }

    /**
     * The issues without their copies, by identifier: an issue of both
     * patterns may be reported for each.
     *
     * @param list<array{string, string, string|null}> $issues
     *
     * @return list<array{string, string, string|null}>
     */
    private static function distinct(array $issues): array
    {
        $distinct = [];
        foreach ($issues as $issue) {
            $distinct[implode("\0", [$issue[1], $issue[0], (string) $issue[2]])] = $issue;
        }
        ksort($distinct);

        return array_values($distinct);
    }
}
