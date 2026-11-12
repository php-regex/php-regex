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

namespace PHPRegex\Tests\Unit\Lsp\Handler;

use PHPRegex\LanguageServer\Document\DocumentManager;
use PHPRegex\LanguageServer\Document\RegexFinder;
use PHPRegex\LanguageServer\Handler\TextDocumentHandler;
use PHPRegex\LanguageServer\Protocol\Message;
use PHPRegex\LanguageServer\Protocol\Transport;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Tests\Support\PcreRule;
use PHPRegex\Tests\Support\RangeFixtureReadings;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The editor judges a pattern at every version of the project's range, as
 * `regex lint` does, and a guard or a "@regex-target" marker narrows the
 * versions judged the same way.
 */
final class TextDocumentHandlerRangeTest extends TestCase
{
    use PcreRule;
    use RangeFixtureReadings;

    /**
     * Compiles up to PHP 8.4 and is refused from PHP 8.5: `regex lint`
     * reports it under ">=8.2" unless a guard or a marker keeps 8.5 out.
     */
    private const KEEP_IN_LOOKBEHIND = "'/(?<=a\\Kb)c/'";

    /**
     * A group that holds a reference to itself: atomic before PCRE2 10.35
     * (PHP 7.4's 10.33), backtracked into from it (PHP 8.0's 10.35), with
     * the same tree. `regex lint` warns of it under ">=7.4 <8.1".
     */
    private const SELF_REFERENCE = "'/^(a|ab|\\\\1x)+b\$/'";

    private TextDocumentHandler $handler;

    /**
     * @return iterable<string, array{code: string, refused: bool}>
     */
    public static function provideNarrowings(): iterable
    {
        yield 'no guard, no marker' => ['code' => 'preg_match('.self::KEEP_IN_LOOKBEHIND.', $s);', 'refused' => true];
        yield 'a guard that keeps 8.5 out' => ['code' => "if (PHP_VERSION_ID < 80500) {\n    preg_match(".self::KEEP_IN_LOOKBEHIND.", \$s);\n}", 'refused' => false];
        yield 'a guard that keeps 8.5' => ['code' => "if (PHP_VERSION_ID >= 80300) {\n    preg_match(".self::KEEP_IN_LOOKBEHIND.", \$s);\n}", 'refused' => true];
        yield 'a marker that keeps 8.5 out' => ['code' => "// @regex-target-next-line php<8.5\npreg_match(".self::KEEP_IN_LOOKBEHIND.', $s);', 'refused' => false];
    }

    /**
     * @param string $code PHP code after the opening tag
     */
    #[Test]
    #[DataProvider('provideNarrowings')]
    public function test_a_guard_or_a_marker_narrows_the_versions_of_the_range_judged(string $code, bool $refused): void
    {
        self::assertEngineKeepInLookbehind();

        $diagnostics = $this->publish('>=8.2', $code);

        $refusal = 'On PHP 8.5 and later: \\K is not allowed in a lookaround from PHP 8.5,';
        $expected = $refused ? [[1, 'regex.keep.in_lookaround', $refusal]] : [];
        $this->assertSame($expected, array_map(static fn (array $diagnostic): array => [$diagnostic['severity'] ?? null, $diagnostic['code'] ?? null, substr(\is_string($diagnostic['message'] ?? null) ? $diagnostic['message'] : '', 0, \strlen($refusal))], $diagnostics));
    }

    /**
     * A change of meaning that keeps the tree is reported as `regex lint`
     * reports it, over the whole pattern.
     */
    #[Test]
    public function test_a_change_of_meaning_that_keeps_the_tree_is_a_warning(): void
    {
        self::assertEngineBacktracksIntoSelfReference();

        $diagnostics = array_values(array_filter($this->publish('>=7.4 <8.1', 'preg_match('.self::SELF_REFERENCE.', $s);'), static fn (array $diagnostic): bool => 'regex.lint.compat.meaningChanges' === ($diagnostic['code'] ?? null)));

        $this->assertCount(1, $diagnostics);
        $this->assertSame(2, $diagnostics[0]['severity'] ?? null);
        // The body, between the delimiters.
        $this->assertSame('From PHP 8.0 (PCRE2 10.35) a group that holds a reference to itself may be backtracked into; before, PCRE2 made such a group atomic: /^(a|ab|\\1x)+b$/ finds "abb" in "abb" from 10.35 and nothing before.', $diagnostics[0]['message'] ?? null);
        $this->assertSame(['start' => ['line' => 2, 'character' => 13], 'end' => ['line' => 2, 'character' => 28]], $diagnostics[0]['range'] ?? null);
        $this->assertSame(['href' => 'https://php-regex.com/reference/rules/#meaning-that-changes-across-php-versions'], $diagnostics[0]['codeDescription'] ?? null);
    }

    /**
     * The rules map turns the change of meaning off, as it does in `regex
     * lint`; a marker naming it then silences nothing, and is not reported.
     */
    #[Test]
    public function test_the_change_of_meaning_is_not_judged_when_its_rule_is_off(): void
    {
        self::assertEngineBacktracksIntoSelfReference();

        $diagnostics = $this->publish('>=7.4 <8.1', 'preg_match('.self::SELF_REFERENCE.', $s); // @regex-ignore regex.lint.compat.meaningChanges', ['regex.lint.compat.meaningChanges' => false]);

        $this->assertSame([], array_values(array_filter(array_map(static fn (array $diagnostic): mixed => $diagnostic['code'] ?? null, $diagnostics), static fn (mixed $code): bool => 'regex.lint.compat.meaningChanges' === $code || 'regex.ignore.unmatched' === $code)));
    }

    /**
     * Over a range, the change of meaning is a check the editor runs: a
     * marker naming it on a pattern that keeps its meaning silences
     * nothing, and says so, as in `regex lint`.
     */
    #[Test]
    public function test_a_marker_naming_the_change_of_meaning_over_a_range_is_unmatched(): void
    {
        $diagnostics = $this->publish('>=8.2', "preg_match('/x/', \$s); // @regex-ignore regex.lint.compat.meaningChanges");

        $this->assertSame(['regex.ignore.unmatched'], array_map(static fn (array $diagnostic): mixed => $diagnostic['code'] ?? null, $diagnostics));
    }

    /**
     * Opening a document over a range publishes the floor's diagnostics at
     * once, then every version's: the editor shows what the lowest version
     * says without waiting for the later ones. A marker that silences a
     * finding only a later version gives is not reported early as silencing
     * nothing.
     */
    #[Test]
    public function test_opening_a_document_publishes_the_floor_first_then_every_version(): void
    {
        self::assertEngineKeepInLookbehind();

        $stream = $this->open('>=8.2', 'preg_match('.self::KEEP_IN_LOOKBEHIND.", \$s); // @regex-ignore regex.lint.compat.meaningChanges\npreg_match('/(/', \$s);");
        $publications = self::publications($stream);

        $this->assertCount(2, $publications);
        $floor = self::codes($publications[0]);
        $every = self::codes($publications[1]);
        // The floor refuses "/(/" already; only 8.5 refuses the \K.
        $this->assertContains('regex.group.unclosed', array_column($floor, 0));
        $this->assertNotContains('regex.keep.in_lookaround', array_column($floor, 0));
        $this->assertNotContains('regex.ignore.unmatched', array_column($floor, 0));
        $this->assertContains('regex.keep.in_lookaround', array_column($every, 0));
        $this->assertContains('regex.ignore.unmatched', array_column($every, 0));
        $this->assertSame($floor, array_values(array_filter($every, static fn (array $row): bool => !\in_array($row[0], ['regex.keep.in_lookaround', 'regex.ignore.unmatched'], true))));
    }

    /**
     * One version judged: one publication, as before.
     */
    #[Test]
    public function test_a_single_version_publishes_once(): void
    {
        $this->assertCount(1, self::publications($this->open('8.2', "preg_match('/(/', \$s);")));
    }

    /**
     * A keystroke publishes once: the later versions cost little there, and
     * a second push per keystroke would only flicker.
     */
    #[Test]
    public function test_a_change_publishes_once(): void
    {
        $stream = $this->open('>=8.2', "preg_match('/a/', \$s);");
        $before = \count(self::publications($stream));

        $this->handler->didChange(new Message(
            jsonrpc: '2.0',
            method: 'textDocument/didChange',
            id: null,
            params: ['textDocument' => ['uri' => 'file:///range.php', 'version' => 2], 'contentChanges' => [['text' => "<?php\n\npreg_match(".self::KEEP_IN_LOOKBEHIND.", \$s);\n"]]],
        ));

        $publications = self::publications($stream);
        $this->assertCount($before + 1, $publications);
        $this->assertContains('regex.keep.in_lookaround', array_column(self::codes($publications[\count($publications) - 1]), 0));
    }

    /**
     * Opens a document judged for the range a constraint names, and gives
     * the stream the handler writes to.
     *
     * @param string $code PHP code after the opening tag
     *
     * @return resource
     */
    private function open(string $constraint, string $code)
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);

        $target = ProjectTarget::fromSources(['regex.json' => $constraint], [], null, [], ['regex.json' => 'C']);
        $regex = Regex::create(['cache' => null] + $target->regexOptions());
        $this->handler = new TextDocumentHandler(new Transport($stream), new DocumentManager(new RegexFinder()), $regex, [], judged: $target->judgedRange($regex->parser()));
        $this->handler->didOpen(new Message(
            jsonrpc: '2.0',
            method: 'textDocument/didOpen',
            id: null,
            params: ['textDocument' => ['uri' => 'file:///range.php', 'version' => 1, 'text' => "<?php\n\n".$code."\n"]],
        ));

        return $stream;
    }

    /**
     * The diagnostics the handler publishes for a document, judged for the
     * range a constraint names.
     *
     * @param string              $code      PHP code after the opening tag
     * @param array<string, bool> $lintRules
     *
     * @return list<array<array-key, mixed>>
     */
    private function publish(string $constraint, string $code, array $lintRules = []): array
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);

        $target = ProjectTarget::fromSources(['regex.json' => $constraint], [], null, [], ['regex.json' => 'C']);
        $regex = Regex::create(['cache' => null] + $target->regexOptions());
        (new TextDocumentHandler(new Transport($stream), new DocumentManager(new RegexFinder()), $regex, $lintRules, judged: $target->judgedRange($regex->parser())))->didOpen(new Message(
            jsonrpc: '2.0',
            method: 'textDocument/didOpen',
            id: null,
            params: ['textDocument' => ['uri' => 'file:///range.php', 'text' => "<?php\n\n".$code."\n"]],
        ));

        $publications = self::publications($stream);
        $this->assertNotSame([], $publications);

        // The last publication holds every version judged.
        return $publications[\count($publications) - 1];
    }

    /**
     * The diagnostics of each publishDiagnostics notification written, in
     * order.
     *
     * @param resource $stream
     *
     * @return list<list<array<array-key, mixed>>>
     */
    private static function publications($stream): array
    {
        rewind($stream);
        $written = (string) stream_get_contents($stream);
        $publications = [];
        foreach (preg_split('/Content-Length: \d+\r\n\r\n/', $written, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $body) {
            $payload = json_decode($body, true);
            if (!\is_array($payload) || 'textDocument/publishDiagnostics' !== ($payload['method'] ?? null) || !\is_array($payload['params'] ?? null) || !\is_array($payload['params']['diagnostics'] ?? null)) {
                continue;
            }
            $publications[] = array_values(array_filter($payload['params']['diagnostics'], \is_array(...)));
        }

        return $publications;
    }

    /**
     * The codes and messages of a publication, to compare two of them.
     *
     * @param list<array<array-key, mixed>> $diagnostics
     *
     * @return list<array{mixed, mixed}>
     */
    private static function codes(array $diagnostics): array
    {
        return array_map(static fn (array $diagnostic): array => [$diagnostic['code'] ?? null, $diagnostic['message'] ?? null], $diagnostics);
    }
}
