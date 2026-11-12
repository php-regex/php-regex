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

        rewind($stream);
        $written = (string) stream_get_contents($stream);
        $payload = json_decode(substr($written, (int) strpos($written, "\r\n\r\n") + 4), true);
        $this->assertIsArray($payload);
        $params = $payload['params'] ?? null;
        $this->assertIsArray($params);
        $diagnostics = $params['diagnostics'] ?? null;
        $this->assertIsArray($diagnostics);

        return array_values(array_filter($diagnostics, \is_array(...)));
    }
}
