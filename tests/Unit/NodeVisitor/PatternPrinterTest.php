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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PatternPrinterTest extends TestCase
{
    public function test_compile_simple(): void
    {
        $this->assertSame('/foo/', $this->compile('/foo/'));
    }

    public function test_compile_group_and_alternation(): void
    {
        $this->assertSame('/(foo|bar)?/', $this->compile('/(foo|bar)?/'));
    }

    public function test_compile_precedence(): void
    {
        $this->assertSame('/ab*c/', $this->compile('/ab*c/'));
    }

    public function test_compile_escaped(): void
    {
        // The compiler must re-escape special characters
        $this->assertSame('/a\*c/', $this->compile('/a\*c/'));
    }

    public function test_compile_new_nodes_and_flags(): void
    {
        $regex = '/^.\d\S(foo|bar)+$/imsU';
        $this->assertSame($regex, $this->compile($regex));
    }

    public function test_compile_quantified_sequence(): void
    {
        // This test ensures a *capturing* group remains a *capturing* group
        $this->assertSame('/(abc)+/', $this->compile('/(abc)+/'));
    }

    public function test_compile_char_class(): void
    {
        // Handles negation, ranges, char types, and literals (like '-')
        $regex = '/[a-z\d\-]/';
        $this->assertSame($regex, $this->compile($regex));

        $regex = '/[^a-z]/';
        $this->assertSame($regex, $this->compile($regex));

        // "]", "\", "^" and "-" are literals where they stand, and escaping
        // them is optional, so the pattern keeps the spelling it was written
        // with instead of being normalized.
        $regex = '/[]\^-]/';
        $this->assertSame($regex, $this->compile($regex));
    }

    public function test_compile_posix_class_not_double_wrapped(): void
    {
        // The surrounding character class already provides the brackets.
        $this->assertSame('/[[:alpha:]]/', $this->compile('/[[:alpha:]]/'));
        $this->assertSame('/[[:^alpha:]]/', $this->compile('/[[:^alpha:]]/'));
        $this->assertSame('/[a[:digit:]]/', $this->compile('/[a[:digit:]]/'));
        $this->assertSame('/[^[:alpha:]]/', $this->compile('/[^[:alpha:]]/'));
        $this->assertSame('/[[:digit:][:space:]]/', $this->compile('/[[:digit:][:space:]]/'));
    }

    // Add new tests for new features
    public function test_compile_new_features(): void
    {
        $regex = '#(?<name>foo)+?|(?!=bar)#i';
        $this->assertSame($regex, $this->compile($regex));
    }

    public function test_compile_assertion(): void
    {
        $regex = '/\Afoo\b/';
        $this->assertSame($regex, $this->compile($regex));
    }

    public function test_compile_unicode_prop(): void
    {
        $regex = '/\pL\p{^L}\pL/';
        $this->assertSame($regex, $this->compile($regex));
    }

    public function test_compile_octal(): void
    {
        $regex = '/\o{777}/';
        $this->assertSame($regex, $this->compile($regex));
    }

    public function test_compile_octal_legacy(): void
    {
        $regex = '/\077/';
        $this->assertSame($regex, $this->compile($regex));
    }

    public function test_compile_named_backref(): void
    {
        $regex = '/\k<name>/';
        $this->assertSame($regex, $this->compile($regex));
    }

    public function test_compile_comment(): void
    {
        $regex = '/(?#test)/';
        $this->assertSame($regex, $this->compile($regex));
    }

    public function test_compile_conditional(): void
    {
        $regex = '/(?(1)a|b)/';
        $this->assertSame($regex, $this->compile($regex));
    }

    public function test_compile_inline_flags(): void
    {
        $regex = '/(?i:foo)/';
        $this->assertSame($regex, $this->compile($regex));
    }

    public function test_compile_escapes_paired_delimiter_closing(): void
    {
        $this->assertSame('<\>>', $this->compile('<\>>'));
        $this->assertSame('<a\>b>', $this->compile('<a\>b>'));
    }

    public function test_compile_extended_comment_allows_closing_paren_in_comment(): void
    {
        $pattern = "~(a# comment )\nb)~x";
        $this->assertSame($pattern, $this->compile($pattern));
    }

    public function test_compile_quote_mode_preserves_space_in_extended_mode(): void
    {
        $this->assertSame('/\\ /x', $this->compile('/\Q \E/x'));
    }

    public function test_compile_quote_mode_preserves_hash_in_extended_mode(): void
    {
        $this->assertSame('/\\#/x', $this->compile('/\Q#\E/x'));
    }

    public function test_reset_state_clears_compiler_flags(): void
    {
        $visitor = new PatternPrinter();
        $regexNode = new RegexNode(new LiteralNode('a', 0, 1), 'x', '/', 0, 1);

        $regexNode->accept($visitor);

        $literal = new LiteralNode('a b', 0, 3);
        $this->assertSame('a\\ b', $literal->accept($visitor));

        $visitor->resetState();

        $this->assertSame('a b', $literal->accept($visitor));
    }

    public function test_compile_subroutines(): void
    {
        $this->assertSame('/(?R)/', $this->compile('/(?R)/'));
        $this->assertSame('/(?1)/', $this->compile('/(?1)/'));
        $this->assertSame('/(?-1)/', $this->compile('/(?-1)/'));
        $this->assertSame('/(?&name)/', $this->compile('/(?&name)/'));
        $this->assertSame('/(?P>name)/', $this->compile('/(?P>name)/'));
    }

    public function test_compiler_escapes_control_characters(): void
    {
        // Test that control characters are properly escaped in output
        $this->assertSame('/[\t\n\r\x07]/', $this->compile('/[\t\n\r\x07]/'));
    }

    public function test_optimizer_preserves_escaped_pipe(): void
    {
        // Test that escaped pipe is preserved outside char class
        $this->assertSame('/foo\|bar/', $this->compile('/foo\|bar/'));

        // Test that pipe in char class is handled correctly (not special there)
        $this->assertSame('/[|]/', $this->compile('/[|]/'));
    }

    public function test_compile_pretty_mode(): void
    {
        $pattern = "/\n# comment\nfoo|bar/x";
        $compiled = $this->compilePretty($pattern);
        $this->assertStringContainsString("\n", $compiled);
        $this->assertStringContainsString('# comment', $compiled);
    }

    #[Test]
    public function test_compile_keeps_an_empty_scoped_modifier_group_scoped(): void
    {
        // "(?i:)" turns "i" on for nothing, "(?i)" for what follows:
        // preg_match('/(?i:)a/', 'A') === 0, preg_match('/(?i)a/', 'A') === 1.
        $this->assertSame('/(?i:)a/', $this->compile('/(?i:)a/'));
        $this->assertStringContainsString('(?i:', $this->compilePretty('/(?i:)a/'));
    }

    #[Test]
    public function test_compile_pretty_mode_keeps_an_unscoped_modifier_group_unscoped(): void
    {
        // "(?i)" turns "i" on for what follows it; "(?i:)" would turn it on
        // for nothing.
        $compiled = $this->compilePretty('/(?i)abc/x');

        $this->assertStringContainsString('(?i)', $compiled);
        $this->assertStringNotContainsString('(?i:', $compiled);
        // Nested, it keeps the indentation of its level.
        $this->assertSame("/\n    (?:\n        (?i)a\n        (?:b(?:c))\n    )\n/x", $this->compilePretty('/(?:(?i)a(?:b(?:c)))/x'));
    }

    /**
     * Pretty printing lays an x pattern out as the formatter does, over the
     * normalized spelling, at a width of 80; it never adds x, and a pattern
     * without x stays on one line.
     */
    #[Test]
    #[DataProvider('providePrettyLayouts')]
    public function test_pretty_mode_lays_out_an_x_pattern_like_the_formatter_with_normalized_spelling(string $pattern, string $expected): void
    {
        $this->assertSame($expected, $this->compilePretty($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, expected: string}>
     */
    public static function providePrettyLayouts(): iterable
    {
        $url = '#^(?<scheme>https?)://(?:(?<user>[^:@/]+)(?::(?<pass>[^@/]*))?@)?(?<host>\[[0-9a-f:.]+\]|[^:/?\#]+)(?::(?<port>\d+))?(?<path>/[^?\#]*)?(?:\?(?<query>[^\#]*))?(?:\#(?<fragment>.*))?$#';

        yield 'a long x pattern breaks' => ['pattern' => $url.'ix', 'expected' => implode("\n", ['#', '    ^', '    (?<scheme>https?)', '    ://', '    (?:', '        (?<user>[^:@/]+)', '        (?::(?<pass>[^@/]*))?', '        @', '    )?', '    (?<host>\[[0-9a-f:.]+]|[^:/?\#]+)', '    (?::(?<port>\d+))?', '    (?<path>/[^?\#]*)?', '    (?:\?(?<query>[^\#]*))?', '    (?:\#(?<fragment>.*))?', '    $', '#ix'])];
        yield 'the same pattern without x stays on one line' => ['pattern' => $url.'i', 'expected' => '#^(?<scheme>https?)://(?:(?<user>[^:@/]+)(?::(?<pass>[^@/]*))?@)?(?<host>\[[0-9a-f:.]+]|[^:/?\#]+)(?::(?<port>\d+))?(?<path>/[^?\#]*)?(?:\?(?<query>[^\#]*))?(?:\#(?<fragment>.*))?$#i'];
        yield 'a comment breaks a short x pattern' => ['pattern' => "/^(?P<n>a)\\k{n} # the first\n|(?P<m>b)(?P=m)\\x41\\101\$/x", 'expected' => implode("\n", ['/', '    ^(?P<n>a)\k{n}  # the first', '  | (?P<m>b)\k<m>\x41\101$', '/x'])];
        yield 'a short x pattern stays on one line' => ['pattern' => '/^(?:a|b)c$/x', 'expected' => '/^(?:a|b)c$/x'];
        yield 'a laid-out x pattern that fits joins one line' => ['pattern' => '/^ (?: a | b ) c $/x', 'expected' => '/^(?:a|b)c$/x'];
        // Where the formatter would refuse, pretty printing writes one line.
        yield 'a long x pattern turning x off at the top level stays on one line' => ['pattern' => '/(?-x)a(?:b'.str_repeat('b', 70).')/x', 'expected' => '/(?-x)a(?:b'.str_repeat('b', 70).')/x'];
    }

    /**
     * Pretty printing switches x off only where the pattern does: "(?-x)"
     * in a conditional's branch ends with the conditional, and an empty
     * "(?-x:)" switches it off for nothing, so the "\ " after it stays an
     * escape. Each subject's result is the oracle's, PCRE2 10.49.
     *
     * @param array<string, int> $subjects
     */
    #[Test]
    #[DataProvider('providePrettyExtendedModeScopes')]
    public function test_pretty_mode_switches_x_off_only_where_the_pattern_does(string $pattern, array $subjects): void
    {
        $pretty = $this->compilePretty($pattern);

        foreach ($subjects as $subject => $expected) {
            $this->assertSame($expected, preg_match($pattern, (string) $subject), 'The oracle on the input, '.json_encode((string) $subject));
            $this->assertSame($expected, preg_match($pretty, (string) $subject), $pretty.' on '.json_encode((string) $subject));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: array<string, int>}>
     */
    public static function providePrettyExtendedModeScopes(): iterable
    {
        $long = str_repeat('b', 90);

        yield '(?-x) in the first branch of a long conditional' => ['pattern' => "/(a)?(?(1)(?-x)a|{$long} b)c/x", 'subjects' => ['aac' => 1, "{$long} bc" => 1, "{$long}bc" => 0, 'a b' => 0]];
        yield 'an empty (?-x:) before an escaped space, nested' => ['pattern' => '/(?:(?:(?:a(?-x:)\ b)))/x', 'subjects' => ['a b' => 1, 'ab' => 0]];
        yield 'an empty (?-x:) before an escaped space at the end' => ['pattern' => '/a2(?-x:)\ /xu', 'subjects' => ["a2\x20" => 1, 'a2' => 0]];
    }

    #[Test]
    public function test_compile_keeps_the_short_spelling_of_a_mark_name(): void
    {
        // "(*:a#b)" names "a#b": preg_match('/(*:a#b)c/', 'c', $m) gives MARK "a#b".
        $this->assertSame(1, preg_match('/(*:a#b)c/', 'c', $match));
        $this->assertSame('a#b', $match['MARK'] ?? null);

        $this->assertSame('/(*:a#b)c/', $this->compile('/(*:a#b)c/'));
    }

    /**
     * Inside the lint and the optimizer, pretty printing never checks its
     * output: a tree that disagrees with itself (the spelling "\x41" with
     * the code point of B) is printed, not refused.
     */
    #[Test]
    public function test_pretty_mode_never_checks_its_output(): void
    {
        $sequence = new SequenceNode([new CharLiteralNode('\x41', 0x42, CharLiteralType::Unicode, 0, 4), new LiteralNode('b', 4, 5)], 0, 5);

        $this->assertSame('/\x41b/x', (new RegexNode($sequence, 'x', '/', 0, 5))->accept(new PatternPrinter(pretty: true)));
    }

    public function test_compile_escaped_literal_metacharacters(): void
    {
        // Test that escaped metacharacters used as literals are preserved
        $this->assertSame('#^application/(?:\w+\+)+json$#i', $this->compile('#^application/(?:\w+\+)+json$#i'));

        // Edge cases for other metacharacters
        $this->assertSame('/\*/', $this->compile('/\*/'));
        $this->assertSame('/\?/', $this->compile('/\?/'));
        $this->assertSame('/\./', $this->compile('/\./'));
    }

    /**
     * Under x "\c " and "\c#" are the control characters 0x60 and 0x63, in
     * a class or not. The printer writes them back as written; only the
     * formatter, whose lines an editor may strip, respells them.
     */
    #[Test]
    #[DataProvider('provideControlCharactersUnderX')]
    public function test_compile_keeps_a_control_character_written_with_white_space_or_hash_under_x(string $pattern, string $subject): void
    {
        $this->assertSame(1, preg_match($pattern, $subject), 'The oracle on the input.');

        $this->assertSame($pattern, $this->compile($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideControlCharactersUnderX(): iterable
    {
        yield 'control-space' => ['pattern' => '/^\c $/x', 'subject' => "\x60"];
        yield 'control-hash' => ['pattern' => '/^\c#$/x', 'subject' => "\x63"];
        yield 'control-space in a class' => ['pattern' => '/^[\c ]$/x', 'subject' => "\x60"];
    }

    /**
     * "\g'n'" is "\g<n>" written with quotes: the printer writes the angle
     * brackets, where the formatter keeps the quotes.
     */
    #[Test]
    public function test_compile_writes_a_quoted_subroutine_call_with_angle_brackets(): void
    {
        $this->assertSame([1, 1], [preg_match("/^(a)\\g'1'\$/", 'aa'), preg_match('/^(a)\g<1>$/', 'aa')]);

        $this->assertSame('/^(a)\g<1>$/', $this->compile("/^(a)\\g'1'\$/"));
    }

    /**
     * The printer writes a pattern on the lines it was written on: a DEFINE
     * hands no layout on to what follows it, however long.
     */
    #[Test]
    public function test_compile_keeps_what_follows_a_define_on_its_line(): void
    {
        $branch = str_repeat('a', 50);
        $pattern = "/(?(DEFINE)(?<a>x))(?:{$branch}|{$branch})/x";

        $this->assertSame($pattern, $this->compile($pattern));
    }

    /**
     * The optimizer reuses one printer and resets it between nodes: once
     * reset, it prints what a new printer prints, on the lines and in the
     * spelling it was given.
     */
    #[Test]
    #[DataProvider('providePatternsForAResetPrinter')]
    public function test_reset_state_prints_as_a_new_printer_does(string $pattern, bool $preserveSpelling, string $subject): void
    {
        $this->assertSame(1, preg_match($pattern, $subject), 'The oracle on the input.');
        $ast = Regex::create(['cache' => null])->parse($pattern);
        $reset = new PatternPrinter(preserveSpelling: $preserveSpelling);
        $reset->resetState();

        $this->assertSame($pattern, $ast->accept($reset));
        $this->assertSame($ast->accept(new PatternPrinter(preserveSpelling: $preserveSpelling)), $ast->accept($reset));
    }

    /**
     * @return iterable<string, array{pattern: string, preserveSpelling: bool, subject: string}>
     */
    public static function providePatternsForAResetPrinter(): iterable
    {
        yield 'an alternation under x' => ['pattern' => '/(?:ab)(?:cd)|ef/x', 'preserveSpelling' => true, 'subject' => 'abcd'];
        // Under u, x skips no no-break space: written raw, it stays raw.
        yield 'a raw no-break space under x and u, normalized' => ['pattern' => "/a\u{A0}b/xu", 'preserveSpelling' => false, 'subject' => "a\u{A0}b"];
    }

    private function compile(string $pattern): string
    {
        $regex = Regex::create();
        $ast = $regex->parse($pattern);
        $visitor = new PatternPrinter();

        return $ast->accept($visitor);
    }

    private function compilePretty(string $pattern): string
    {
        $regex = Regex::create();
        $ast = $regex->parse($pattern);
        $visitor = new PatternPrinter(true);

        return $ast->accept($visitor);
    }
}
