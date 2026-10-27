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
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PatternPrinterTest extends TestCase
{
    use PcreGates;

    #[Test]
    public function test_compile_simple(): void
    {
        $this->assertSame('/foo/', $this->compile('/foo/'));
    }

    #[Test]
    public function test_compile_group_and_alternation(): void
    {
        $this->assertSame('/(foo|bar)?/', $this->compile('/(foo|bar)?/'));
    }

    #[Test]
    public function test_compile_precedence(): void
    {
        $this->assertSame('/ab*c/', $this->compile('/ab*c/'));
    }

    #[Test]
    public function test_compile_escaped(): void
    {
        // The compiler must re-escape special characters
        $this->assertSame('/a\*c/', $this->compile('/a\*c/'));
    }

    #[Test]
    public function test_compile_new_nodes_and_flags(): void
    {
        $regex = '/^.\d\S(foo|bar)+$/imsU';
        $this->assertSame($regex, $this->compile($regex));
    }

    #[Test]
    public function test_compile_quantified_sequence(): void
    {
        // This test ensures a *capturing* group remains a *capturing* group
        $this->assertSame('/(abc)+/', $this->compile('/(abc)+/'));
    }

    #[Test]
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

    #[Test]
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
    #[Test]
    public function test_compile_new_features(): void
    {
        $regex = '#(?<name>foo)+?|(?!=bar)#i';
        $this->assertSame($regex, $this->compile($regex));
    }

    #[Test]
    public function test_compile_assertion(): void
    {
        $regex = '/\Afoo\b/';
        $this->assertSame($regex, $this->compile($regex));
    }

    #[Test]
    public function test_compile_unicode_prop(): void
    {
        $regex = '/\pL\p{^L}\pL/';
        $this->assertSame($regex, $this->compile($regex));
    }

    #[Test]
    public function test_compile_octal(): void
    {
        $regex = '/\o{777}/';
        $this->assertSame($regex, $this->compile($regex));
    }

    #[Test]
    public function test_compile_octal_legacy(): void
    {
        $regex = '/\077/';
        $this->assertSame($regex, $this->compile($regex));
    }

    #[Test]
    public function test_compile_named_backref(): void
    {
        $regex = '/\k<name>/';
        $this->assertSame($regex, $this->compile($regex));
    }

    #[Test]
    public function test_compile_comment(): void
    {
        $regex = '/(?#test)/';
        $this->assertSame($regex, $this->compile($regex));
    }

    #[Test]
    public function test_compile_conditional(): void
    {
        $regex = '/(?(1)a|b)/';
        $this->assertSame($regex, $this->compile($regex));
    }

    #[Test]
    public function test_compile_inline_flags(): void
    {
        $regex = '/(?i:foo)/';
        $this->assertSame($regex, $this->compile($regex));
    }

    #[Test]
    public function test_compile_escapes_paired_delimiter_closing(): void
    {
        $this->assertSame('<\>>', $this->compile('<\>>'));
        $this->assertSame('<a\>b>', $this->compile('<a\>b>'));
    }

    #[Test]
    public function test_compile_extended_comment_allows_closing_paren_in_comment(): void
    {
        $pattern = "~(a# comment )\nb)~x";
        $this->assertSame($pattern, $this->compile($pattern));
    }

    #[Test]
    public function test_compile_quote_mode_preserves_space_in_extended_mode(): void
    {
        $this->assertSame('/\\ /x', $this->compile('/\Q \E/x'));
    }

    #[Test]
    public function test_compile_quote_mode_preserves_hash_in_extended_mode(): void
    {
        $this->assertSame('/\\#/x', $this->compile('/\Q#\E/x'));
    }

    #[Test]
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

    #[Test]
    public function test_compile_subroutines(): void
    {
        $this->assertSame('/(?R)/', $this->compile('/(?R)/'));
        $this->assertSame('/(?1)/', $this->compile('/(?1)/'));
        $this->assertSame('/(?-1)/', $this->compile('/(?-1)/'));
        $this->assertSame('/(?&name)/', $this->compile('/(?&name)/'));
        $this->assertSame('/(?P>name)/', $this->compile('/(?P>name)/'));
    }

    #[Test]
    public function test_compiler_escapes_control_characters(): void
    {
        // Test that control characters are properly escaped in output
        $this->assertSame('/[\t\n\r\x07]/', $this->compile('/[\t\n\r\x07]/'));
    }

    #[Test]
    public function test_optimizer_preserves_escaped_pipe(): void
    {
        // Test that escaped pipe is preserved outside char class
        $this->assertSame('/foo\|bar/', $this->compile('/foo\|bar/'));

        // Test that pipe in char class is handled correctly (not special there)
        $this->assertSame('/[|]/', $this->compile('/[|]/'));
    }

    #[Test]
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

    /**
     * PHP pairs bracket delimiters by nesting, and counts the bracket in a
     * comment: in "{a(?#{)b}c}" the "}" after "b" is a literal that the
     * comment's "{" balances. Escaping it unbalances the body, and
     * "{a(?#{)b\}c}" does not compile. Pretty printing keeps the balance:
     * a literal bracket whose partner it writes stays unescaped.
     *
     * @param array<string, int> $subjects
     */
    #[Test]
    #[DataProvider('providePrettyBracketDelimiterBalances')]
    public function test_pretty_mode_keeps_the_balance_of_a_bracket_delimiter(string $pattern, string $expected, array $subjects): void
    {
        // The engine is the oracle here, and (?[...]) needs one that has it.
        $this->requiresCompile($pattern);

        $pretty = $this->compilePretty($pattern);

        $this->assertNotFalse(@preg_match($pretty, ''), \sprintf('%s does not compile: %s', $pretty, preg_last_error_msg()));
        $this->assertSame($expected, $pretty);
        foreach ($subjects as $subject => $matches) {
            $this->assertSame($matches, preg_match($pattern, (string) $subject), 'The oracle on the input, '.json_encode((string) $subject));
            $this->assertSame($matches, preg_match($pretty, (string) $subject), $pretty.' on '.json_encode((string) $subject));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, expected: string, subjects: array<string, int>}>
     */
    public static function providePrettyBracketDelimiterBalances(): iterable
    {
        yield 'braces' => ['pattern' => '{a(?#{)b}c}', 'expected' => '{a(?#{)b}c}', 'subjects' => ['ab}c' => 1, 'abc' => 0]];
        yield 'square brackets' => ['pattern' => '[a(?#[)b]c]', 'expected' => '[a(?#[)b]c]', 'subjects' => ['ab]c' => 1, 'abc' => 0]];
        // A class nested in "(?[...])" reads a brace as any class does.
        yield 'a brace in a class of an extended class, balanced by a name' => ['pattern' => '{(?[ [{a] ])(*MARK:})}', 'expected' => '{(?[[{a]])(*MARK:})}', 'subjects' => ['{' => 1, 'a' => 1, 'b' => 0]];
        yield 'angle brackets' => ['pattern' => '<a(?#<)b>c>', 'expected' => '<a(?#<)b>c>', 'subjects' => ['ab>c' => 1, 'abc' => 0]];
        yield 'braces under x' => ['pattern' => '{a(?#{)b}c}x', 'expected' => '{a(?#{)b}c}x', 'subjects' => ['ab}c' => 1, 'abc' => 0]];
        // An escaped bracket PHP does not count stays escaped: unescaped, it
        // would be counted, and the body would end before its end.
        yield 'an escaped brace a comment does not balance' => ['pattern' => '{(?#{)\}b(?#})}', 'expected' => '{(?#{)\}b(?#})}', 'subjects' => ['}b' => 1, 'b' => 0]];
        yield 'an escaped angle bracket between two mark names' => ['pattern' => '<(*MARK:<)\<(*MARK:>)>', 'expected' => '<(*MARK:<)\<(*MARK:>)>', 'subjects' => ['<' => 1, 'a' => 0]];
        yield 'an escaped angle bracket in a class after a lookbehind' => ['pattern' => '<(?<!b)[\>](*MARK:>)>', 'expected' => '<(?<!b)[\>](*MARK:>)>', 'subjects' => ['>' => 1, 'b>' => 0]];
        yield 'an escaped brace between two mark names, a quantifier after' => ['pattern' => '{(*MARK:{)\{(a)(*MARK:})\d{1,2}b}', 'expected' => '{(*MARK:{)\{(a)(*MARK:})\d{1,2}b}', 'subjects' => ['{a1b' => 1, '{a12b' => 1, '{a123b' => 0]];
        // A literal bracket that pairs with a mark name's stays counted:
        // unescaped where it means itself, quoted where it would open
        // something else.
        yield 'a brace a mark name balances, before a letter' => ['pattern' => '{\Q{a\E(*MARK:})}', 'expected' => '{{a(*MARK:})}', 'subjects' => ['{a' => 1, 'a' => 0]];
        yield 'a brace a mark name balances, after two letters' => ['pattern' => '{ab\Q{c\E(*MARK:})}', 'expected' => '{ab{c(*MARK:})}', 'subjects' => ['ab{c' => 1, 'abc' => 0]];
        // After an escape letter the brace is quoted, whatever the letter:
        // after "\x", "\o", "\p" or "\N" an unescaped "{" would open the
        // escape's braces, and "/\x{a/" does not compile (PCRE2 10.49).
        yield 'a brace a mark name balances, after an escape' => ['pattern' => '{\d\Q{a\E(*MARK:})}', 'expected' => '{\d\Q{\Ea(*MARK:})}', 'subjects' => ['1{a' => 1, '1a' => 0]];
        // Before "2" and a closing brace of the delimiter, the brace is
        // quoted: both unescaped, "{2}" would read as a repeat.
        yield 'a brace a mark name balances, before a count' => ['pattern' => '{\Q{2\E\}(*MARK:})}', 'expected' => '{\Q{\E2\}(*MARK:})}', 'subjects' => ['{2}' => 1, '2}' => 0]];
        yield 'a square bracket a mark name balances' => ['pattern' => '[\Q[\Ea(*MARK:])]', 'expected' => '[\Q[\Ea(*MARK:])]', 'subjects' => ['[a' => 1, 'a' => 0]];
        yield 'two square brackets two mark names balance' => ['pattern' => '[\Q[[\E(*MARK:])(*MARK:])]', 'expected' => '[\Q[\E\Q[\E(*MARK:])(*MARK:])]', 'subjects' => ['[[' => 1, '[' => 0]];
        // In a class, a "[" before ":", "." or "=" would open a POSIX class
        // or a collating element, which PCRE2 rejects here.
        yield 'a square bracket in a class, before a range from a colon' => ['pattern' => '[[\Q[\E:-;:](?#])]', 'expected' => '[[\Q[\E:-;:](?#])]', 'subjects' => ['[' => 1, ';' => 1, 'a' => 0]];
        yield 'a square bracket in a class, before a range from a dot' => ['pattern' => '[[\Q[\E.-/.](?#])]', 'expected' => '[[\Q[\E.-/.](?#])]', 'subjects' => ['[' => 1, '/' => 1, 'a' => 0]];
        yield 'a square bracket in a class, before a range from an equals sign' => ['pattern' => '[[\Q[\E=->=](?#])]', 'expected' => '[[\Q[\E=->=](?#])]', 'subjects' => ['[' => 1, '>' => 1, 'a' => 0]];
        yield 'a square bracket closing the members of a class' => ['pattern' => '[[a[](?#])]', 'expected' => '[[a[](?#])]', 'subjects' => ['[' => 1, 'a' => 1, 'b' => 0]];
        // Nothing balances these: they stay escaped.
        yield 'an escaped square bracket closing the members of a class' => ['pattern' => '[[a\[]]', 'expected' => '[[a\[]]', 'subjects' => ['[' => 1, 'a' => 1, 'b' => 0]];
        yield 'an escaped square bracket opening a range' => ['pattern' => '[[a\[-c]]', 'expected' => '[[a\[-c]]', 'subjects' => ['[' => 1, 'b' => 1, 'Z' => 0, 'd' => 0]];
        yield 'an escaped square bracket before a colon, then an escaped closing one' => ['pattern' => '[[\[:]\]]', 'expected' => '[[\[:]\]]', 'subjects' => ['[]' => 1, ':]' => 1, ']' => 0]];
        yield 'an escaped brace ending the body' => ['pattern' => '{\}a\{}', 'expected' => '{\}a\{}', 'subjects' => ['}a{' => 1, 'a' => 0]];
        // The comment's brace balances the first closing brace only.
        yield 'two closing braces after a comment brace' => ['pattern' => '{(?#{)}\}}', 'expected' => '{(?#{)}\}}', 'subjects' => ['}}' => 1, '}' => 0]];
        yield 'an escaped star after a comment brace' => ['pattern' => '{(?#{)\*}}', 'expected' => '{(?#{)\*}}', 'subjects' => ['*}' => 1, '}' => 0]];
        // Raw control bytes in a comment are written as they are: the
        // printer's own mark for a bracket is none of them.
        yield 'a control byte in a comment' => ['pattern' => "{a(?#\x03b)}", 'expected' => "{a(?#\x03b)}", 'subjects' => ['a' => 1, 'b' => 0]];
        $bytes = implode('', array_map(\chr(...), [...range(0x03, 0x08), ...range(0x0E, 0x1F)]));
        yield 'a comment holding every control byte but the last' => ['pattern' => '{a(?#{'.substr($bytes, 0, -1).')b}c}', 'expected' => '{a(?#{'.substr($bytes, 0, -1).')b}c}', 'subjects' => ['ab}c' => 1, 'abc' => 0]];
        yield 'a comment holding every control byte' => ['pattern' => '{a(?#{'.$bytes.')b}c}', 'expected' => '{a(?#{'.$bytes.')b}c}', 'subjects' => ['ab}c' => 1, 'abc' => 0]];
        // The mark of a literal bracket is a control byte the source does not
        // hold, or a longer text where it holds them all.
        yield 'a comment holding the first control byte, then a brace a mark name balances' => ['pattern' => "{(?#\x03)\\Q{a\\E(*MARK:})}", 'expected' => "{(?#\x03){a(*MARK:})}", 'subjects' => ['{a' => 1, 'a' => 0]];
        yield 'a comment holding the first two control bytes, then a brace a mark name balances' => ['pattern' => "{(?#\x03\x04)\\Q{a\\E(*MARK:})}", 'expected' => "{(?#\x03\x04){a(*MARK:})}", 'subjects' => ['{a' => 1, 'a' => 0]];
        yield 'a comment holding every control byte, then a brace a mark name balances' => ['pattern' => '{(?#'.$bytes.')\Q{a\E(*MARK:})}', 'expected' => '{(?#'.$bytes.'){a(*MARK:})}', 'subjects' => ['{a' => 1, 'a' => 0]];
    }

    /**
     * Where pretty printing writes a bracket delimiter, the backslash of a
     * literal bracket stands as a mark while lines are measured: a mark of
     * one byte, whatever control bytes the source holds, measures as the
     * backslash does, and the lines break where they would without them.
     */
    #[Test]
    #[DataProvider('provideControlBytesInABracketDelimitedPattern')]
    public function test_pretty_mode_lays_out_a_bracket_delimited_pattern_alike_whatever_control_bytes_it_holds(string $bytes): void
    {
        $pattern = static fn (string $held): string => '[(?:(?:(?:\[:a:\](?#'.$held.')|zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz)\[:a:\])yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy # '.$held."\n\\[:a:\\])]x";
        $printed = static fn (string $held): string => Regex::create()->parse($pattern($held))->accept(new PatternPrinter(true, true));

        $subject = '[:a:][:a:]'.str_repeat('y', 42).'[:a:]';

        $this->assertSame(1, preg_match($pattern($bytes), $subject));
        $this->assertSame($printed(''), str_replace($bytes, '', $printed($bytes)));
        $this->assertSame(1, preg_match($printed($bytes), $subject));
    }

    /**
     * @return iterable<string, array{bytes: string}>
     */
    public static function provideControlBytesInABracketDelimitedPattern(): iterable
    {
        yield 'the first control byte' => ['bytes' => "\x03"];
        yield 'the first two control bytes' => ['bytes' => "\x03\x04"];
    }

    /**
     * Pretty printing writes a "[" in a class unescaped, except before
     * ":", "." or "=", where it would open a POSIX class: "[a\[:]" holds
     * "a", "[" and ":" (PCRE2 10.49).
     *
     * @param array<string, int> $subjects
     */
    #[Test]
    #[DataProvider('providePrettySquareBracketsInAClass')]
    public function test_pretty_mode_escapes_a_square_bracket_in_a_class_only_before_a_posix_opener(string $pattern, string $expected, array $subjects): void
    {
        $pretty = $this->compilePretty($pattern);

        $this->assertSame($expected, $pretty);
        foreach ($subjects as $subject => $matches) {
            $this->assertSame($matches, preg_match($pattern, (string) $subject), 'The oracle on the input, '.json_encode((string) $subject));
            $this->assertSame($matches, preg_match($pretty, (string) $subject), $pretty.' on '.json_encode((string) $subject));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, expected: string, subjects: array<string, int>}>
     */
    public static function providePrettySquareBracketsInAClass(): iterable
    {
        yield 'before a letter' => ['pattern' => '/[a[b]/', 'expected' => '/[a[b]/', 'subjects' => ['[' => 1, 'b' => 1, 'c' => 0]];
        yield 'before a colon' => ['pattern' => '/[a\[:]/', 'expected' => '/[a\[:]/', 'subjects' => ['[' => 1, ':' => 1, 'c' => 0]];
    }

    /**
     * Pretty printing escapes some literals, "{" or "[" among them, which
     * PHP then no longer counts: where a comment holds the partner of such
     * a bracket, the output must still be one pattern PHP reads whole, and
     * match as the input does (PCRE2 10.49: "{a{1(?#})}" matches "a{1").
     *
     * @param array<string, int> $subjects
     */
    #[Test]
    #[DataProvider('providePrettyBracketsACommentBalances')]
    public function test_pretty_mode_writes_a_pattern_that_compiles_where_a_comment_balances_a_bracket(string $pattern, array $subjects): void
    {
        $pretty = $this->compilePretty($pattern);

        $this->assertNotFalse(@preg_match($pretty, ''), \sprintf('%s does not compile: %s', json_encode($pretty), preg_last_error_msg()));
        foreach ($subjects as $subject => $matches) {
            $this->assertSame($matches, preg_match($pattern, (string) $subject), 'The oracle on the input, '.json_encode((string) $subject));
            $this->assertSame($matches, preg_match($pretty, (string) $subject), json_encode($pretty).' on '.json_encode((string) $subject));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: array<string, int>}>
     */
    public static function providePrettyBracketsACommentBalances(): iterable
    {
        yield 'a literal brace before a digit' => ['pattern' => '{a{1(?#})}', 'subjects' => ['a{1' => 1, 'a' => 0]];
        yield 'a class opening on the delimiter, holding it' => ['pattern' => '[[[](?#])]', 'subjects' => ['[' => 1, 'a' => 0]];
        yield 'a class of several members holding the delimiter' => ['pattern' => '[[a[](?#])]', 'subjects' => ['[' => 1, 'a' => 1, 'b' => 0]];
        yield 'a class holding a range from the delimiter' => ['pattern' => '[[a[-c](?#])]', 'subjects' => ['b' => 1, '[' => 1, 'a' => 1, 'Z' => 0, 'd' => 0]];
        yield 'a negated class holding the delimiter' => ['pattern' => '[[^[](?#])]', 'subjects' => ['a' => 1, '[' => 0]];
        yield 'a class holding the delimiter in a lookbehind' => ['pattern' => '[(?<=[[])(?#])a]', 'subjects' => ['[a' => 1, 'a' => 0]];
        // Raw control bytes the printer escapes, 0x1B as "\e" among them.
        $bytes = implode('', array_map(\chr(...), [...range(0x03, 0x08), ...range(0x0E, 0x1F)]));
        yield 'a brace after raw control bytes' => ['pattern' => '{a(?#{)b}c'.$bytes.'}', 'subjects' => ['ab}c'.$bytes => 1, 'abc'.$bytes => 0]];
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

    #[Test]
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
     * Each named constructor gives a printer for one way of writing a
     * pattern back, and prints what the boolean constructor prints for it:
     * as written, normalized, or pretty printed under x.
     *
     * @param array<string, int> $subjects
     */
    #[Test]
    #[DataProvider('provideNamedConstructorOutputs')]
    public function test_named_constructors_print_their_mode(string $pattern, string $asWritten, string $normalized, string $pretty, array $subjects): void
    {
        $tree = Regex::create()->parse($pattern);

        $printed = [
            'as written' => [$asWritten, $tree->accept(PatternPrinter::asWritten()), $tree->accept(new PatternPrinter())],
            'normalized' => [$normalized, $tree->accept(PatternPrinter::normalized()), $tree->accept(new PatternPrinter(false, false, false))],
            'pretty' => [$pretty, $tree->accept(PatternPrinter::pretty()), $tree->accept(new PatternPrinter(true))],
        ];

        foreach ($printed as $mode => [$expected, $named, $constructed]) {
            $this->assertSame($expected, $named, $mode);
            $this->assertSame($constructed, $named, $mode.', as the constructor prints it');
            foreach ($subjects as $subject => $matches) {
                $this->assertSame($matches, preg_match($pattern, (string) $subject), 'The oracle on the input, '.json_encode((string) $subject));
                $this->assertSame($matches, preg_match($named, (string) $subject), $mode.': '.$named.' on '.json_encode((string) $subject));
            }
        }
    }

    /**
     * @return iterable<string, array{pattern: string, asWritten: string, normalized: string, pretty: string, subjects: array<string, int>}>
     */
    public static function provideNamedConstructorOutputs(): iterable
    {
        yield 'an optional escape' => ['pattern' => '/a\-b/', 'asWritten' => '/a\-b/', 'normalized' => '/a-b/', 'pretty' => '/a-b/', 'subjects' => ['a-b' => 1, 'ab' => 0]];
        yield 'a Python-style reference' => ['pattern' => '/(?P<n>a)(?P=n)/', 'asWritten' => '/(?P<n>a)(?P=n)/', 'normalized' => '/(?P<n>a)\k<n>/', 'pretty' => '/(?P<n>a)\k<n>/', 'subjects' => ['aa' => 1, 'a' => 0]];
        yield 'groups nested three deep under x' => ['pattern' => '/(((a)))/x', 'asWritten' => '/(((a)))/x', 'normalized' => '/(((a)))/x', 'pretty' => "/\n    (\n        ((a))\n    )\n/x", 'subjects' => ['a' => 1, 'b' => 0]];
        yield 'a comment under x' => ['pattern' => "/a b # c\n/x", 'asWritten' => "/a b # c\n/x", 'normalized' => "/ab# c\n/x", 'pretty' => "/\n    ab  # c\n/x", 'subjects' => ['ab' => 1, 'a b' => 0]];
    }

    /**
     * Where the formatter would refuse a layout, pretty printing writes the
     * pattern on one line and refuses nothing: after a top-level "(?-x)" a
     * line break would be a literal.
     */
    #[Test]
    public function test_pretty_mode_writes_one_line_where_a_layout_would_be_refused(): void
    {
        $printer = PatternPrinter::pretty();

        $this->assertSame('/(?-x)(((a)))/x', Regex::create()->parse('/(?-x)(((a)))/x')->accept($printer));
        $this->assertNull($printer->refusal());
    }

    #[Test]
    public function test_named_constructors_give_a_new_printer_each_time(): void
    {
        $this->assertNotSame(PatternPrinter::asWritten(), PatternPrinter::asWritten());
        $this->assertNotSame(PatternPrinter::normalized(), PatternPrinter::normalized());
        $this->assertNotSame(PatternPrinter::pretty(), PatternPrinter::pretty());
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
