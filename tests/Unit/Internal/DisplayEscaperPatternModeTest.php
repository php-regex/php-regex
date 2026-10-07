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

namespace PHPRegex\Tests\Unit\Internal;

use PHPRegex\Parser\Internal\DisplayEscaper;
use PHPRegex\Parser\PcreTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A delimited pattern is shown in its own mode. The characters that move or
 * hide text in a terminal (C1 controls, bidirectional overrides and
 * isolates, the zero-width space) are escaped: as a code point "\x{...}"
 * under u or a leading (*UTF), as one "\xHH" per byte otherwise, where
 * "\x{202E}" is no byte at all. Under x the pattern is shown on one line:
 * the "#" comments are dropped and the white space that separates or
 * matches something keeps its meaning without a line break.
 *
 * Every row reads back: the pattern shown matches what the pattern matched
 * on each subject (PHP 8.4, PCRE2 10.49, JIT off).
 */
final class DisplayEscaperPatternModeTest extends TestCase
{
    /**
     * The code point and the hex spelling of each hidden character.
     */
    private const HIDDEN = [
        'next line (C1)' => "\u{85}",
        'control sequence introducer (C1)' => "\u{9B}",
        'right-to-left override' => "\u{202E}",
        'left-to-right isolate' => "\u{2066}",
        'zero-width space' => "\u{200B}",
    ];

    /**
     * Unicode format characters (general category Cf, as PCRE2 reads
     * \p{Cf}): each moves or hides text when printed raw, and each is
     * hidden as the characters above are.
     */
    private const FORMAT_CHARACTERS = [
        'byte order mark (Cf)' => "\u{FEFF}",
        'zero-width non-joiner (Cf)' => "\u{200C}",
        'zero-width joiner (Cf)' => "\u{200D}",
        'word joiner (Cf)' => "\u{2060}",
        'invisible plus (Cf)' => "\u{2064}",
        'soft hyphen (Cf)' => "\u{AD}",
        'mongolian vowel separator (Cf)' => "\u{180E}",
        'tag latin capital letter A (Cf)' => "\u{E0041}",
        'arabic letter mark (Cf)' => "\u{61C}",
        'arabic number sign (Cf)' => "\u{600}",
    ];

    /**
     * Characters next to the hidden ones that print as themselves: a
     * no-break space (Zs), a per mille sign (Po), a letter (Ll).
     */
    private const PRINTABLE = [
        'no-break space' => "\u{A0}",
        'per mille sign' => "\u{2030}",
        'e with acute' => "\u{E9}",
    ];

    /**
     * The escape map is built once per process; each test builds it again,
     * so what it checks does not depend on a test run before it.
     */
    protected function setUp(): void
    {
        (new \ReflectionProperty(DisplayEscaper::class, 'escapes'))->setValue(null, []);
    }

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideHiddenCharacters')]
    public function test_escape_spells_a_hidden_character_in_the_pattern_mode(string $pattern, string $character, bool $codePoint, array $subjects): void
    {
        $shown = DisplayEscaper::escape($pattern);

        $this->assertStringNotContainsString($character, $shown);
        $this->assertMatchesRegularExpression(self::spelling($character, $codePoint), $shown);
        $this->assertReadsBack($pattern, $shown, $subjects);
    }

    /**
     * @return iterable<string, array{pattern: string, character: string, codePoint: bool, subjects: list<string>}>
     */
    public static function provideHiddenCharacters(): iterable
    {
        foreach ([...self::HIDDEN, ...self::FORMAT_CHARACTERS] as $name => $character) {
            $next = mb_chr(mb_ord($character, 'UTF-8') + 1, 'UTF-8');
            $subjects = ["a{$character}b", 'ab', "a{$next}b", "{$character}b", "a{$character}{$character}b"];

            yield $name.' under u' => ['pattern' => "/a{$character}b/u", 'character' => $character, 'codePoint' => true, 'subjects' => $subjects];
            yield $name.' under a leading (*UTF)' => ['pattern' => "/(*UTF)a{$character}b/", 'character' => $character, 'codePoint' => true, 'subjects' => $subjects];
            yield $name.' in byte mode' => ['pattern' => "/a{$character}b/", 'character' => $character, 'codePoint' => false, 'subjects' => [...$subjects, "a{$character[0]}b", "a{$character[1]}b"]];
            yield $name.' in a class under u' => ['pattern' => "/[{$character}]b/u", 'character' => $character, 'codePoint' => true, 'subjects' => $subjects];
            // Without u the class holds the character's bytes, each on its own.
            yield $name.' in a class, byte mode' => ['pattern' => "/[{$character}]b/", 'character' => $character, 'codePoint' => false, 'subjects' => [...$subjects, "{$character[1]}b", "{$character[0]}b"]];
        }

        $override = "\u{202E}";
        $subjects = ["a{$override}b", 'ab', "a\u{202D}b"];
        yield 'right-to-left override, (*UTF) after another start verb' => ['pattern' => "/(*CR)(*UTF)a{$override}b/", 'character' => $override, 'codePoint' => true, 'subjects' => $subjects];
        yield 'right-to-left override after a backslash under u' => ['pattern' => "/a\\{$override}b/u", 'character' => $override, 'codePoint' => true, 'subjects' => $subjects];
        yield 'right-to-left override inside \Q..\E under u' => ['pattern' => "/\\Qa{$override}b\\E/u", 'character' => $override, 'codePoint' => true, 'subjects' => $subjects];
        yield 'right-to-left override inside \Q..\E, byte mode' => ['pattern' => "/\\Qa{$override}b\\E/", 'character' => $override, 'codePoint' => false, 'subjects' => $subjects];
    }

    /**
     * Where PCRE reads no escape, in a comment "(?#...)", the character is
     * spelled all the same, and the matches do not change.
     */
    #[Test]
    public function test_escape_spells_a_hidden_character_inside_a_comment(): void
    {
        $pattern = "/a(?#\u{202E})b/u";
        $shown = DisplayEscaper::escape($pattern);

        $this->assertStringNotContainsString("\u{202E}", $shown);
        $this->assertReadsBack($pattern, $shown, ['ab', "a\u{202E}b"]);
    }

    /**
     * Plain text has no pattern mode: the character is spelled one way or
     * the other, never written raw.
     */
    #[Test]
    #[DataProvider('provideHiddenText')]
    public function test_escape_text_spells_a_hidden_character(string $character): void
    {
        $shown = DisplayEscaper::escapeText("a{$character}b");

        $this->assertStringNotContainsString($character, $shown);
        $this->assertTrue(
            1 === preg_match(self::spelling($character, true), $shown) || 1 === preg_match(self::spelling($character, false), $shown),
            $shown,
        );
    }

    /**
     * Plain text spells a hidden character by its code point when the text
     * is valid UTF-8, and each byte from 0x80 in hex when it is not; a
     * backslash is doubled either way.
     */
    #[Test]
    public function test_escape_text_spells_by_encoding_and_doubles_a_backslash(): void
    {
        $this->assertSame('\x{202E}\\\\', DisplayEscaper::escapeText("\u{202E}\\"));
        $this->assertSame('\xE2\x80\xAE\xFF\\\\', DisplayEscaper::escapeText("\u{202E}\xFF\\"));
    }

    /**
     * @return iterable<string, array{character: string}>
     */
    public static function provideHiddenText(): iterable
    {
        foreach ([...self::HIDDEN, ...self::FORMAT_CHARACTERS] as $name => $character) {
            yield $name => ['character' => $character];
        }
    }

    /**
     * A character that prints as itself is written raw, in either mode.
     */
    #[Test]
    #[DataProvider('providePrintableCharacters')]
    public function test_escape_keeps_a_printable_character_raw(string $pattern): void
    {
        $this->assertSame($pattern, DisplayEscaper::escape($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePrintableCharacters(): iterable
    {
        foreach (self::PRINTABLE as $name => $character) {
            yield $name.' under u' => ['pattern' => "/a{$character}b/u"];
            yield $name.' in byte mode' => ['pattern' => "/a{$character}b/"];
        }
    }

    /**
     * The one-line view of an x pattern: no line break, the "#" comment
     * gone, and no "\n" escape where a line break only ended a comment or
     * separated two items ("\n" would match a line feed there).
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideExtendedPatterns')]
    public function test_escape_shows_an_x_pattern_on_one_line(string $pattern, string $comment, array $subjects): void
    {
        $shown = DisplayEscaper::escape($pattern);

        $this->assertStringNotContainsString("\n", $shown);
        $this->assertStringNotContainsString("\r", $shown);
        $this->assertStringNotContainsString('\n', $shown);
        if ('' !== $comment) {
            $this->assertStringNotContainsString($comment, $shown);
        }
        $this->assertReadsBack($pattern, $shown, $subjects);
    }

    /**
     * @return iterable<string, array{pattern: string, comment: string, subjects: list<string>}>
     */
    public static function provideExtendedPatterns(): iterable
    {
        // Oracle: "ab" 1; "a#c\nb", "acb", "a\nb" 0.
        yield 'a comment ended by a line break' => ['pattern' => "/a#c\nb/x", 'comment' => '#c', 'subjects' => ['ab', "a#c\nb", 'acb', "a\nb"]];
        // Oracle: "ab" 1; "a b", "a\n b", "a\nb" 0.
        yield 'a line break then a space between two items' => ['pattern' => "/a\n b/x", 'comment' => '', 'subjects' => ['ab', 'a b', "a\n b", "a\nb"]];
        // Oracle: "ab", "ab\n" 1; "a b", "a\n" 0.
        yield 'a space and a trailing line break' => ['pattern' => "/a b\n/x", 'comment' => '', 'subjects' => ['ab', "ab\n", 'a b', "a\n"]];
        // Oracle: "ab" 1; "a b", "foo", the whole source text 0.
        yield 'a comment holding parentheses' => ['pattern' => "/a # match (foo)\n b/x", 'comment' => 'match (foo)', 'subjects' => ['ab', 'a b', 'foo', "a # match (foo)\n b"]];
    }

    /**
     * Under x the white space that matters keeps its meaning on one line: a
     * line break that separates "\1" from "0" (without it, "\10" is the
     * octal escape of a backspace), a "#" that is a literal, white space
     * inside a class or inside \Q..\E, a comment that runs to the end, and
     * a newline convention under which "\n" ends no comment.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideExtendedEdges')]
    public function test_escape_keeps_the_meaning_of_an_x_pattern_on_one_line(string $pattern, array $subjects): void
    {
        $shown = DisplayEscaper::escape($pattern);

        $this->assertStringNotContainsString("\n", $shown);
        $this->assertStringNotContainsString("\r", $shown);
        $this->assertReadsBack($pattern, $shown, $subjects);
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideExtendedEdges(): iterable
    {
        // Oracle: "aa0" 1; "aa", "a0" 0. "/(a)\10/x" gives 0 on "aa0".
        yield 'a line break separating a back reference from a digit' => ['pattern' => "/(a)\\1\n0/x", 'subjects' => ['aa0', 'aa', 'a0', "aa\x08"]];
        // Oracle: "a#cb" 1; "ab", "a#c b" 0.
        yield 'an escaped hash is a literal' => ['pattern' => "/a\\#c\nb/x", 'subjects' => ['a#cb', 'ab', 'a#c b']];
        // Oracle: "#cb" 1; "cb", "#c b" 0.
        yield 'a hash in a class is a literal' => ['pattern' => "/[#]c\nb/x", 'subjects' => ['#cb', 'cb', '#c b']];
        // Oracle: "\nb", "ab" 1; " b" 0.
        yield 'a line break in a class is a member' => ['pattern' => "/[a\n]b/x", 'subjects' => ["\nb", 'ab', ' b']];
        // Oracle: "ac", " c", "bc" 1 (one x keeps the space in a class).
        yield 'a space in a class is a member' => ['pattern' => "/[a b]\nc/x", 'subjects' => ['ac', ' c', 'bc', "a\nc"]];
        // Oracle: "a\nb" 1; "ab" 0.
        yield 'a line break inside \Q..\E is a literal' => ['pattern' => "/\\Qa\nb\\E/x", 'subjects' => ["a\nb", 'ab']];
        // Oracle: "ab" 1; "acdb" 0.
        yield 'a line break inside a parenthesised comment' => ['pattern' => "/a(?#c\nd)b/x", 'subjects' => ['ab', 'acdb']];
        // Oracle: "ab" 1; "a b" 0.
        yield 'a comment running to the end' => ['pattern' => '/ab # c/x', 'subjects' => ['ab', 'a b']];
        // Oracle: under (*CR) the comment "#c\nb" ends at "\r": "ad" 1;
        // "abd", "ab" 0.
        yield 'a comment under (*CR) runs past a line feed' => ['pattern' => "/(*CR)a#c\nb\rd/x", 'subjects' => ['ad', 'abd', 'ab', "a#c\nb\rd"]];
    }

    /**
     * The one-line view of a pattern spells each white space by what it is
     * where it stands: the NEL byte x skips after a "\xC2" read on its own,
     * a line break inside braces, where a space would make a quantifier,
     * white space inside "\p{...}", an escaped line break, a "(?...)" that
     * turns x off or leaves it on, and the line breaks that end a comment
     * under (*ANY).
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideWhiteSpaceInContext')]
    public function test_escape_spells_white_space_by_its_context(string $pattern, string $shown, array $subjects): void
    {
        $this->assertSame($shown, DisplayEscaper::escape($pattern));
        $this->assertReadsBack($pattern, $shown, $subjects);
    }

    /**
     * @return iterable<string, array{pattern: string, shown: string, subjects: list<string>}>
     */
    public static function provideWhiteSpaceInContext(): iterable
    {
        // Byte by byte, U+0085 is "\xC2" then the NEL byte x skips.
        // Oracle: "a\xC2b" 1; "ab", "a\u{85}b", "a\xC2 b", "a\x85b" 0.
        yield 'a next-line control under x, byte mode' => [
            'pattern' => "/a\u{85}b/x",
            'shown' => '/a\xC2 b/x',
            'subjects' => ["a\xC2b", 'ab', "a\u{85}b", "a\xC2 b", "a\x85b", 'a b'],
        ];
        // "{1,\n2}" is a literal under x, where "{1, 2}" is a quantifier.
        // Oracle: "a{1,2}" 1; "a", "aa" 0 ("/^a{1, 2}$/x" gives 1 on both).
        yield 'a line break inside braces under x' => [
            'pattern' => "/^a{1,\n2}\$/x",
            'shown' => '/^a{1,(?#)2}$/x',
            'subjects' => ['a{1,2}', 'a', 'aa', "a{1,\n2}", ''],
        ];
        // Oracle: "A", "É" 1; "a", "é", "1" 0.
        yield 'a line break inside \p{...}' => [
            'pattern' => "/^\\p{L\nu}\$/u",
            'shown' => '/^\p{L u}$/u',
            'subjects' => ['A', 'É', 'a', 'é', '1'],
        ];
        // Oracle: "A", "É", "A\n" 1; "a", "A#c" 0.
        yield 'a comment after \p{...} under x' => [
            'pattern' => "/^\\p{Lu}#c\n\$/xu",
            'shown' => '/^\p{Lu} $/xu',
            'subjects' => ['A', 'É', "A\n", 'a', 'A#c'],
        ];
        // Oracle: "a\nb" 1; "ab", "a b", "a\\\nb" 0.
        yield 'an escaped line break under x' => [
            'pattern' => "/^a\\\nb\$/x",
            'shown' => '/^a\x0Ab$/x',
            'subjects' => ["a\nb", 'ab', 'a b', "a\\\nb"],
        ];
        // After "(?-x)" the space, the "#" and the line break are literals.
        // Oracle: "ab c #d\ne" 1; "a b c #d\ne", "ab c e", "abc #d\ne" 0.
        yield 'x turned off inside the pattern' => [
            'pattern' => "/^(?x)a b(?-x) c #d\ne\$/",
            'shown' => '/^(?x)a b(?-x) c #d\ne$/',
            'subjects' => ["ab c #d\ne", "a b c #d\ne", 'ab c e', "abc #d\ne"],
        ];
        // Oracle: "ab", "aB" 1; "Ab", "a b", "a #c\nB" 0.
        yield 'an option setting that leaves x on' => [
            'pattern' => "/^a #c\n(?i)b\$/x",
            'shown' => '/^a (?i)b$/x',
            'subjects' => ['ab', 'aB', 'Ab', 'a b', "a #c\nB"],
        ];
        // Without (*ANY) the comment would run to the end: "/^a#c\x0Bb$/x"
        // gives 1 on "acb".
        // Oracle: "ab" 1; "acb", "a b", the source text 0.
        yield 'a vertical tab ends a comment under (*ANY)' => [
            'pattern' => "/(*ANY)^a#c\x0Bb\$/x",
            'shown' => '/(*ANY)^a b$/x',
            'subjects' => ['ab', 'acb', 'a b', "a#c\x0Bb"],
        ];
        // Oracle: "ab" 1; "acb", "a b", the source text 0.
        yield 'a line separator ends a comment under (*ANY) and u' => [
            'pattern' => "/(*ANY)^a#c\u{2028}b\$/xu",
            'shown' => '/(*ANY)^a b$/xu',
            'subjects' => ['ab', 'acb', 'a b', "a#c\u{2028}b"],
        ];
        // Oracle: "ab" 1; "acb", "a b", the source text 0.
        yield 'a NEL byte ends a comment under (*ANY), byte mode' => [
            'pattern' => "/(*ANY)^a#c\x85b\$/x",
            'shown' => '/(*ANY)^a b$/x',
            'subjects' => ['ab', 'acb', 'a b', "a#c\x85b"],
        ];
    }

    /**
     * Each context is spelled exactly: the x level an option setting or a
     * group leaves, a comment or a callout that closes nothing, braces and
     * escapes with an operand, and the delimiters PHP reads. Every row that
     * is a valid pattern reads back with the matches of the pattern it was
     * written from; the others are spelled the same way, byte for byte.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideContexts')]
    public function test_escape_spells_each_context_exactly(string $pattern, string $shown, array $subjects): void
    {
        $this->assertSame($shown, DisplayEscaper::escape($pattern));
        $this->assertReadsBack($pattern, $shown, $subjects);
    }

    /**
     * @return iterable<string, array{pattern: string, shown: string, subjects: list<string>}>
     */
    public static function provideContexts(): iterable
    {
        // (?i) leaves x as (?x) set it: a tab in a class is a literal.
        // Oracle: "a", "\t", "B" 1; "x", " " 0.
        yield 'a tab in a class after (?i) under (?x)' => [
            'pattern' => "/(?x)(?i)[a\tb]/",
            'shown' => '/(?x)(?i)[a\tb]/',
            'subjects' => ['a', "\t", 'B', 'x', ' '],
        ];
        // (?xx) skips a tab in a class. Oracle: "a", "b" 1; "\t", " " 0.
        yield 'a tab in a class under (?xx)' => [
            'pattern' => "/(?xx)[a\tb]/",
            'shown' => '/(?xx)[a b]/',
            'subjects' => ['a', 'b', "\t", ' '],
        ];
        // PHP reads the modifiers "xx" as x alone: the tab is a literal.
        // Oracle: "a", "\t" 1; " " 0.
        yield 'a tab in a class under the modifiers xx' => [
            'pattern' => "/[a\tb]/xx",
            'shown' => '/[a\tb]/xx',
            'subjects' => ['a', "\t", ' '],
        ];
        // x is on inside the group only, whatever closes inside it.
        // Oracle: "bd #e\nf" 1; "b d #e\nf", "bd#e\nf", "bd #e f" 0.
        yield 'a comment group inside (?x:...)' => [
            'pattern' => "/(?x:(?#c)b #c\nd) #e\nf/",
            'shown' => '/(?x:(?#c)b d) #e\nf/',
            'subjects' => ["bd #e\nf", "b d #e\nf", "bd#e\nf", 'bd #e f'],
        ];
        // Oracle: "ab #d\ne" 1; "a b #d\ne", "ab#d\ne" 0.
        yield 'a group inside (?x:...)' => [
            'pattern' => "/(?x:(a) #c\nb) #d\ne/",
            'shown' => '/(?x:(a) b) #d\ne/',
            'subjects' => ["ab #d\ne", "a b #d\ne", "ab#d\ne"],
        ];
        // Oracle: "a:bd #e\nf" 1; "a:b d #e\nf", "a:bd#e\nf" 0.
        yield 'a group holding a colon inside (?x:...)' => [
            'pattern' => "/(?x:(a:b) #c\nd) #e\nf/",
            'shown' => '/(?x:(a:b) d) #e\nf/',
            'subjects' => ["a:bd #e\nf", "a:b d #e\nf", "a:bd#e\nf"],
        ];
        // "(ax)" is a group, not an option setting.
        // Oracle: "ax #c\nd" 1; "axd", "ax #c d" 0.
        yield 'a group whose letters read as options' => [
            'pattern' => "/(ax) #c\nd/",
            'shown' => '/(ax) #c\nd/',
            'subjects' => ["ax #c\nd", 'axd', 'ax #c d'],
        ];
        // Oracle: "ab" 1; "a b", "acb" 0.
        yield 'x set together with r' => [
            'pattern' => "/(?xr)a #c\nb/",
            'shown' => '/(?xr)a b/',
            'subjects' => ['ab', 'a b', 'acb'],
        ];
        // Oracle: "ab" 1; "a b", "a\tb" 0.
        yield 'a comment, then a tab, under x' => [
            'pattern' => "/a #\n\tb/x",
            'shown' => '/a b/x',
            'subjects' => ['ab', 'a b', "a\tb"],
        ];
        // Oracle: "a", "a " 1; "" 0.
        yield 'white space ending the pattern under x' => [
            'pattern' => "/a \n/x",
            'shown' => '/a/x',
            'subjects' => ['a', 'a ', ''],
        ];
        // "{1, 2}" is a quantifier under x. Oracle: "a", "aa", "a{1, 2}" 1; "" 0.
        yield 'a space inside braces under x' => [
            'pattern' => '/a{1, 2}/x',
            'shown' => '/a{1, 2}/x',
            'subjects' => ['a', 'aa', 'a{1, 2}', ''],
        ];
        // Oracle: "ab" 1; "acb", "a b" 0.
        yield 'a next-line control ends a comment under (*ANY) and u' => [
            'pattern' => "/(*ANY)^a#c\u{85}b\$/xu",
            'shown' => '/(*ANY)^a b$/xu',
            'subjects' => ['ab', 'acb', 'a b'],
        ];
        // An escaped tab is the escape "\t", the brace after it a quantifier.
        // Oracle: "a\t" 1; "a", "a\\\t" 0.
        yield 'an escaped tab before a quantifier' => [
            'pattern' => "/a\\\t{1}/",
            'shown' => '/a\t{1}/',
            'subjects' => ["a\t", 'a', "a\\\t"],
        ];
        yield 'an option setting cut by a line break' => [
            'pattern' => "/(?x\n)a #c\nb/",
            'shown' => '/(?x\n)a #c\nb/',
            'subjects' => [],
        ];
        yield '\p{ left open, a tab last' => [
            'pattern' => "/\\p{L\t/",
            'shown' => '/\p{L /',
            'subjects' => [],
        ];
        yield '\p{ left open, nothing after it' => [
            'pattern' => '/\p{/',
            'shown' => '/\p{/',
            'subjects' => [],
        ];
        yield '\c before a right-to-left override, byte mode' => [
            'pattern' => "/\\c\u{202E}/",
            'shown' => '/\c\xE2\x80\xAE/',
            'subjects' => [],
        ];
        yield 'a bracket delimiter holding a bracket pair' => [
            'pattern' => "[[a]\u{202E}]",
            'shown' => '[[a]\xE2\x80\xAE]',
            'subjects' => [],
        ];
        yield 'a closing bracket delimiter followed by more text' => [
            'pattern' => "(a))\u{202E}",
            'shown' => '(a))\x{202E}',
            'subjects' => [],
        ];
        yield 'DEL as a delimiter never closed, the C1 controls around a no-break space' => [
            'pattern' => "\x7F\u{80}\u{9F}\u{A0}",
            'shown' => "\\x7F\\x{80}\\x{9F}\u{A0}",
            'subjects' => [],
        ];
        yield 'NUL, which no delimiter can be' => [
            'pattern' => "\0a\u{202E}\0",
            'shown' => '\x00a\x{202E}\x00',
            'subjects' => [],
        ];
        yield 'a backslash before a hidden character, no delimiter' => [
            'pattern' => "\\\u{202E}",
            'shown' => '\x{202E}',
            'subjects' => [],
        ];
    }

    /**
     * The shown form is a pattern PHP reads with the delimiters and the
     * modifiers of the original, and it matches what the original matched,
     * the same text captured: where a "\c" takes a backslash as its
     * operand and x drops what follows it, the closing delimiter must not
     * come right after that backslash; a "(?#)" placed inside braces must
     * not hold a delimiter; a byte-mode backslash before the NEL byte x
     * skips escapes that byte, not the "\xC2" before it; a tab inside
     * braces without x is part of the quantifier or the escape, where the
     * spelling "\t" is not; and the white space PHP skips around the
     * delimiters is not part of the pattern.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideReadBackPatterns')]
    public function test_escape_shown_form_matches_as_the_original(string $pattern, array $subjects): void
    {
        // A tab inside braces pads a count or an escape operand only from
        // PCRE2 10.43, and inside a class "\k{...}" reads as the letter only
        // from 10.45; before that the engine reports the braces as text.
        $verifiedAgainst = match ($pattern) {
            "/a{2,\t3}/",
            "/\\x{\t41}/",
            "/\\x{41\t}/",
            "/\\o{\t101}/",
            "/(a)\\g{\t1}/",
            "/(a)\\g{1\t}/",
            "/(?<n>a)\\k{\tn}/" => '10.43',
            "/[a\\k{\t}]/" => '10.45',
            default => null,
        };
        if (null !== $verifiedAgainst && !PcreTarget::runtime()->pcreAtLeast($verifiedAgainst)) {
            $this->markTestSkipped(sprintf(
                '%s is verified against PCRE2 %s and later; PCRE2 %s reports it differently.',
                $pattern,
                $verifiedAgainst,
                \PCRE_VERSION,
            ));
        }

        $shown = DisplayEscaper::escape($pattern);

        foreach ($subjects as $subject) {
            $original = self::captures($pattern, $subject);
            $this->assertIsArray($original, \sprintf('%s compiles: %s', DisplayEscaper::quote($pattern), \is_string($original) ? $original : ''));
            $this->assertSame(
                $original,
                self::captures($shown, $subject),
                \sprintf('%s shown as %s, on %s', DisplayEscaper::quote($pattern), DisplayEscaper::quote($shown), DisplayEscaper::quote($subject)),
            );
        }
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideReadBackPatterns(): iterable
    {
        // "\c\" is the control character 0x1C; x drops the space after it.
        // Oracle: "\x1C", "\x1C " 1 ("\x1C" matched); " " 0.
        yield '\c\ then a space under x' => ['pattern' => '/\\c\\ /x', 'subjects' => ["\x1C", "\x1C ", ' ', '\\']];
        // Oracle: "\x1C", "\x1C#c" 1 ("\x1C" matched); "#c" 0.
        yield '\c\ then a comment under x' => ['pattern' => '/\\c\\#c/x', 'subjects' => ["\x1C", "\x1C#c", '#c']];
        // Oracle: "\x1C" 1; " " 0.
        yield '\c\ then a space under an inline (?x)' => ['pattern' => '/(?x)\\c\\ /', 'subjects' => ["\x1C", "\x1C ", ' ']];
        // Oracle: "\x1C" 1; " " 0.
        yield '\c\ then a space under x, hash delimiter' => ['pattern' => '#\\c\\ #x', 'subjects' => ["\x1C", "\x1C ", ' ']];

        // "{1,\n2}" is a literal under x, where "{1, 2}" is a quantifier.
        // Oracle: "a{1,2}" 1; "a", "aa" 0.
        yield 'a line break inside braces under x, hash delimiter' => ['pattern' => "#a{1,\n2}#x", 'subjects' => ['a{1,2}', 'a', 'aa']];
        // Oracle: "{\"k\"" 1; "{k" 0.
        yield 'a line break after an opening brace under x, hash delimiter' => ['pattern' => "#{\n\"k\"#x", 'subjects' => ['{"k"', '{k', '{ "k"']];
        // Oracle: "a{1,2}" 1; "a" 0.
        yield 'a line break inside braces under x, question mark delimiter' => ['pattern' => "?a{1,\n2}?x", 'subjects' => ['a{1,2}', 'a', 'aa']];
        // A closing parenthesis opens a pattern PHP closes with the same character.
        // Oracle: "a{1,2}" 1; "a" 0.
        yield 'a line break inside braces under x, closing parenthesis delimiter' => ['pattern' => ")a{1,\n2})x", 'subjects' => ['a{1,2}', 'a', 'aa']];

        // Byte by byte, "\" then U+0085 escapes "\xC2"; x then skips the
        // NEL byte. Oracle: "a\xC2b" 1; "a\xC2\x85b", "ab" 0.
        yield 'a backslash before a next-line control under x, byte mode' => ['pattern' => "/a\\\xC2\x85b/x", 'subjects' => ["a\xC2b", "a\xC2\x85b", 'ab', "a\xC2 b"]];
        // Oracle: "\xC21" 1; "\u{85}1", "1" 0.
        yield 'a backslash before a next-line control under (*UCP), x and i' => ['pattern' => "/(*UCP)\\\u{85}1/xi", 'subjects' => ["\xC21", "\u{85}1", '1']];
        // Oracle: "\u{85}" 1, "\xC2" captured; "\x85" 0.
        yield 'a backslash before a next-line control under x and n' => ['pattern' => "/\\\u{85}/xn", 'subjects' => ["\u{85}", "\xC2", "\x85"]];

        // Without x a tab inside braces is skipped where PCRE2 reads a
        // quantifier or an escape operand. Oracle: "aa" 1, "aa" matched;
        // "a{2,\t3}" 0.
        yield 'a tab inside a quantifier' => ['pattern' => "/a{2,\t3}/", 'subjects' => ['aa', 'aaa', 'a', "a{2,\t3}"]];
        // Oracle: "A" 1; "\t" 0.
        yield 'a tab before the digits of \x{...}' => ['pattern' => "/\\x{\t41}/", 'subjects' => ['A', "\t", 'B']];
        yield 'a tab after the digits of \x{...}' => ['pattern' => "/\\x{41\t}/", 'subjects' => ['A', "\t", 'B']];
        yield 'a tab before the digits of \o{...}' => ['pattern' => "/\\o{\t101}/", 'subjects' => ['A', "\t", 'B']];
        // Oracle: "aa" 1, "a" captured; "a" 0.
        yield 'a tab before the number of \g{...}' => ['pattern' => "/(a)\\g{\t1}/", 'subjects' => ['aa', 'a', "a\t1"]];
        yield 'a tab after the number of \g{...}' => ['pattern' => "/(a)\\g{1\t}/", 'subjects' => ['aa', 'a']];
        yield 'a tab before the name of \k{...}' => ['pattern' => "/(?<n>a)\\k{\tn}/", 'subjects' => ['aa', 'a']];
        // Inside a class "\g" and "\k" are the letters themselves: the
        // braces are members and the tab between them is a literal tab.
        // Oracle: "\t", "g", "1" 1; " " 0; "g\t}" 1, "g" matched.
        yield 'a tab inside braces after \g in a class' => ['pattern' => "/[\\g{\t1}]/", 'subjects' => [' ', "\t", "g\t}", 'g', '1']];
        // Oracle: "\t", "k" 1; " " 0; "g\t}" 1, "\t" matched.
        yield 'a tab inside braces after \k in a class' => ['pattern' => "/[a\\k{\t}]/", 'subjects' => [' ', "\t", "g\t}", 'k']];
        // The class ends at the first "]"; the tab after it is a literal.
        // Oracle: "g\t}" 1, "g\t}" matched; " ", "\t", "g }" 0.
        yield 'a class holding \g{ before a tab' => ['pattern' => "/[\\g{]\t}/", 'subjects' => [' ', "\t", "g\t}", 'g }']];
        // Braces that are no quantifier keep the tab a literal.
        // Oracle: "{\tx}" 1; "{ x}", "{x}" 0.
        yield 'a tab inside braces that are a literal' => ['pattern' => "/{\tx}/", 'subjects' => ["{\tx}", '{ x}', '{x}']];

        // PHP skips the white space before the opening delimiter and reads
        // a line break among the modifiers as nothing.
        // Oracle: "ab" 1; "a b" 0.
        yield 'a line break before the opening delimiter' => ['pattern' => "\n/a b/x", 'subjects' => ['ab', 'a b']];
        // Oracle: "a" 1; "b" 0.
        yield 'a line break after the closing delimiter' => ['pattern' => "/a/\n", 'subjects' => ['a', 'b', "\n"]];
        // Oracle: "ab" 1; "a b" 0.
        yield 'a line break among the modifiers' => ['pattern' => "/a b/\nx", 'subjects' => ['ab', 'a b']];
    }

    /**
     * A backslash that ends the text escapes nothing: it is kept as it is,
     * and nothing is read past the end of the text.
     */
    #[Test]
    public function test_escape_keeps_a_backslash_that_ends_the_text(): void
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $shown = DisplayEscaper::escape('a\\');
            $fragment = DisplayEscaper::escapeFragment('a\\', true);
        } finally {
            restore_error_handler();
        }

        $this->assertSame('a\\', $shown);
        $this->assertSame('a\\', $fragment);
        $this->assertSame([], $warnings);
    }

    /**
     * Without x a line break is a literal: "\n" stays its spelling.
     */
    #[Test]
    public function test_escape_keeps_a_line_break_escape_outside_x(): void
    {
        $pattern = "/a#c\nb/";

        $this->assertSame('/a#c\nb/', DisplayEscaper::escape($pattern));
        // Oracle: "a#c\nb" 1; "ab" 0.
        $this->assertReadsBack($pattern, '/a#c\nb/', ["a#c\nb", 'ab']);
    }

    /**
     * x turned on inside the pattern starts the comments there.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideInlineExtendedPatterns')]
    public function test_escape_shows_an_inline_x_pattern_on_one_line(string $pattern, array $subjects): void
    {
        $shown = DisplayEscaper::escape($pattern);

        $this->assertStringNotContainsString("\n", $shown);
        $this->assertReadsBack($pattern, $shown, $subjects);
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideInlineExtendedPatterns(): iterable
    {
        // Oracle: "ab" 1; "acb", "a#c\nb" 0.
        yield 'x at the start' => ['pattern' => "/(?x)a#c\nb/", 'subjects' => ['ab', 'acb', "a#c\nb"]];
        // Oracle: "ab" 1; "acb" 0.
        yield 'x after the first item' => ['pattern' => "/a(?x)#c\nb/", 'subjects' => ['ab', 'acb', "a#c\nb"]];
        // Oracle: "ac", "bc" 1; " c" 0 (xx drops the space in a class).
        yield 'xx at the start' => ['pattern' => "/(?xx)[a b]\nc/", 'subjects' => ['ac', 'bc', ' c', "a\nc"]];
    }

    /**
     * @param list<string> $subjects
     */
    private function assertReadsBack(string $pattern, string $shown, array $subjects): void
    {
        foreach ($subjects as $subject) {
            $this->assertSame(
                self::oracle($pattern, $subject),
                self::oracle($shown, $subject),
                \sprintf('%s shown as %s, on %s', DisplayEscaper::quote($pattern), DisplayEscaper::quote($shown), DisplayEscaper::quote($subject)),
            );
        }
    }

    /**
     * The spelling of the character: "\x{HEX}" (any case, any leading
     * zeros) for a code point, "\xHH" for each of its bytes otherwise.
     */
    private static function spelling(string $character, bool $codePoint): string
    {
        if ($codePoint) {
            return \sprintf('/\\\\x\{0*%X\}/i', mb_ord($character, 'UTF-8'));
        }

        $bytes = '';
        foreach (str_split($character) as $byte) {
            $bytes .= \sprintf('\\\\x%02X', \ord($byte));
        }

        return '/'.$bytes.'/i';
    }

    /**
     * What preg_match() captures, JIT off, an empty list when it does not
     * match; a compilation failure or an engine error is its message.
     *
     * @return array<array-key, string>|string
     */
    private static function captures(string $pattern, string $subject): array|string
    {
        $jit = (string) \ini_get('pcre.jit');
        \ini_set('pcre.jit', '0');
        $warning = null;
        set_error_handler(static function (int $errno, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $result = preg_match($pattern, $subject, $groups);
        } finally {
            restore_error_handler();
            \ini_set('pcre.jit', $jit);
        }

        return false === $result ? 'error: '.($warning ?? preg_last_error_msg()) : $groups;
    }

    /**
     * What preg_match() answers, JIT off; a compilation failure or an engine
     * error is its message.
     */
    private static function oracle(string $pattern, string $subject): int|string
    {
        $jit = (string) \ini_get('pcre.jit');
        \ini_set('pcre.jit', '0');
        $warning = null;
        set_error_handler(static function (int $errno, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $result = preg_match($pattern, $subject);
        } finally {
            restore_error_handler();
            \ini_set('pcre.jit', $jit);
        }

        return false === $result ? 'error: '.($warning ?? preg_last_error_msg()) : $result;
    }
}
