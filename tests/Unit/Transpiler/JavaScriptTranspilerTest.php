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

namespace PHPRegex\Tests\Unit\Transpiler;

use PHPRegex\PHPUnit\RegexAssertions;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Toolkit\Regex;
use PHPRegex\Transpiler\Exception\TranspileException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JavaScriptTranspilerTest extends TestCase
{
    use PcreGates;

    use PhpUnitForwardCompat;
    use RegexAssertions;

    #[Test]
    public function test_transpiles_basic_pattern_to_js_literal(): void
    {
        $regex = Regex::create();
        $result = $regex->transpile('/foo/i', 'javascript');

        $this->assertSame('javascript', $result->target);
        $this->assertSame('foo', $result->pattern);
        $this->assertSame('i', $result->flags);
        $this->assertSame('/foo/i', $result->literal);
    }

    #[Test]
    public function test_transpiles_named_groups_and_backrefs(): void
    {
        $regex = Regex::create();
        $result = $regex->transpile('/(?P<word>\\w+)\\k{word}/', 'javascript');

        $this->assertSame('/(?<word>\\w+)\\k<word>/', $result->literal);
    }

    #[Test]
    public function test_transpiles_a_quoted_named_backreference(): void
    {
        // Oracle: \k'n' is \k<n> in PCRE2 (preg_match on "aa" is 1).
        $this->assertSame(1, preg_match("/(?<n>a)\\k'n'/", 'aa'));

        $result = Regex::create()->transpile("/(?<n>a)\\k'n'/", 'javascript');

        $this->assertSame('/(?<n>a)\\k<n>/', $result->literal);
    }

    #[Test]
    public function test_transpiles_g_numeric_backreference(): void
    {
        $regex = Regex::create();
        $result = $regex->transpile('/(\\d)\\g{1}/', 'javascript');

        $this->assertSame('/(\\d)\\1/', $result->literal);
    }

    /**
     * A backreference JavaScript spells as PCRE does is kept as it is: the
     * same subjects match on both sides (PCRE through preg_match, JavaScript
     * checked with node: "aa" and "abcdefghijj" match, "ab" and
     * "abcdefghija0" do not, "\10" naming the tenth group in both).
     *
     * @param array<string, int> $subjects
     */
    #[Test]
    #[DataProvider('provideBackreferencesSpelledAlike')]
    public function test_transpiles_a_backreference_spelled_alike_unchanged(string $pattern, array $subjects): void
    {
        foreach ($subjects as $subject => $matches) {
            $this->assertSame($matches, preg_match($pattern, (string) $subject), \sprintf('Oracle: %s on %s.', $pattern, $subject));
        }

        $result = Regex::create()->transpile($pattern, 'javascript');

        $this->assertSame($pattern, $result->literal);
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: array<string, int>}>
     */
    public static function provideBackreferencesSpelledAlike(): iterable
    {
        yield 'numbered' => ['pattern' => '/(a)\\1/', 'subjects' => ['aa' => 1, 'ab' => 0]];
        yield 'named in angle brackets' => ['pattern' => '/(?<n>a)\\k<n>/', 'subjects' => ['aa' => 1, 'ab' => 0]];
        yield 'two digits' => ['pattern' => '/(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)\\10/', 'subjects' => ['abcdefghijj' => 1, 'abcdefghija0' => 0]];
    }

    #[Test]
    public function test_keeps_the_unicode_flag_for_codepoint_escapes(): void
    {
        $regex = Regex::create();
        $result = $regex->transpile('/\\x{1F600}/u', 'javascript');

        $this->assertSame('/\\u{1F600}/u', $result->literal);
        $this->assertSame([], $result->warnings);
    }

    #[Test]
    public function test_drops_extended_mode_comments(): void
    {
        $regex = Regex::create();
        $pattern = "/a # comment\nb/x";
        $result = $regex->transpile($pattern, 'javascript');

        $this->assertSame('/ab/', $result->literal);
        $this->assertContains('Applied /x (extended mode): whitespace and comments were removed during compilation.', $result->notes);
        $this->assertContains('Dropped comments during transpilation.', $result->notes);
    }

    /**
     * A comment matches nothing, whatever turned it on: under an inline
     * "(?x)" as under /x, and "(?#...)" anywhere. PCRE2 reads "a(?#c){2}"
     * as "a{2}" (preg_match matches "aa", not "a{2}"), and so does the tree.
     */
    #[Test]
    #[DataProvider('provideComments')]
    public function test_drops_every_comment(string $pattern, string $literal): void
    {
        $result = Regex::create()->transpile($pattern, 'javascript');

        $this->assertSame($literal, $result->literal);
        $this->assertContains('Dropped comments during transpilation.', $result->notes);
    }

    /**
     * @return iterable<string, array{pattern: string, literal: string}>
     */
    public static function provideComments(): iterable
    {
        yield 'under an inline x' => ['pattern' => '/(?x) a # c/', 'literal' => '/a/'];
        yield 'a parenthesized comment' => ['pattern' => '/a(?#c)b/', 'literal' => '/ab/'];
        yield 'before a quantifier' => ['pattern' => '/a(?#c){2}/', 'literal' => '/a{2}/'];
    }

    /**
     * PCRE2 reads a bare script name as its Script_Extensions ("\p{Han}"
     * takes U+3001, whose script is Common), "sc:" as its Script, and a
     * name loosely; JavaScript wants "\p{Script_Extensions=Han}" or
     * "\p{Script=Han}", spelled as Unicode does. Common and Inherited read
     * as their Script either way in PCRE2. A general category or a binary
     * property is kept. Each literal was checked with node on the same
     * subjects.
     *
     * @param array<int|string, int> $subjects
     */
    #[Test]
    #[DataProvider('provideProperties')]
    public function test_transpiles_a_property_to_its_javascript_name(string $pattern, string $literal, array $subjects): void
    {
        // The names as PCRE2 10.40 reads them: loose, and a bare script for
        // its extensions, where 10.39 reads the script itself.
        $this->requiresPcre2('10.40', $pattern);
        foreach ($subjects as $subject => $matches) {
            $this->assertSame($matches, preg_match($pattern, (string) $subject), \sprintf('Oracle: %s on %s.', $pattern, $subject));
        }

        $this->assertSame($literal, Regex::create(['cache' => null])->transpile($pattern, 'javascript')->literal);
    }

    /**
     * @return iterable<string, array{pattern: string, literal: string, subjects: array<int|string, int>}>
     */
    public static function provideProperties(): iterable
    {
        yield 'a bare script' => ['pattern' => '/^\p{Han}$/u', 'literal' => '/^\p{Script_Extensions=Han}(?=\n?$)/u', 'subjects' => ['中' => 1, '、' => 1, 'a' => 0]];
        yield 'a script by its Script' => ['pattern' => '/^\p{sc:Han}$/u', 'literal' => '/^\p{Script=Han}(?=\n?$)/u', 'subjects' => ['中' => 1, '、' => 0]];
        yield 'a script by its short name' => ['pattern' => '/^\p{Script=Hani}$/u', 'literal' => '/^\p{Script=Han}(?=\n?$)/u', 'subjects' => ['中' => 1, '、' => 0]];
        yield 'a script by its Script_Extensions' => ['pattern' => '/^\p{scx:Hira}$/u', 'literal' => '/^\p{Script_Extensions=Hiragana}(?=\n?$)/u', 'subjects' => ['ー' => 1, 'あ' => 1, 'ア' => 0]];
        yield 'a script spelled loosely' => ['pattern' => '/^\P{greek}$/u', 'literal' => '/^\P{Script_Extensions=Greek}(?=\n?$)/u', 'subjects' => ['α' => 0, 'a' => 1]];
        yield 'a script negated with a caret' => ['pattern' => '/^\p{^Latin}$/u', 'literal' => '/^\P{Script_Extensions=Latin}(?=\n?$)/u', 'subjects' => ['a' => 0, 'α' => 1]];
        yield 'the Common script' => ['pattern' => '/^\p{Common}$/u', 'literal' => '/^\p{Script=Common}(?=\n?$)/u', 'subjects' => ['、' => 1, '1' => 1, 'a' => 0]];
        yield 'a script in a class' => ['pattern' => '/^[\p{Han}\d]+$/u', 'literal' => '/^[\p{Script_Extensions=Han}\p{Nd}]+(?=\n?$)/u', 'subjects' => ['中1' => 1, 'a' => 0]];
        yield 'general categories' => ['pattern' => '/^\p{L}\p{Lu}\pN$/u', 'literal' => '/^\p{L}\p{Lu}\p{N}(?=\n?$)/u', 'subjects' => ['aB1' => 1, 'ab1' => 0]];
        yield 'a binary property' => ['pattern' => '/^\p{Alphabetic}$/u', 'literal' => '/^\p{Alphabetic}(?=\n?$)/u', 'subjects' => ['a' => 1, '1' => 0]];
    }

    /**
     * Before PCRE2 10.40 a bare script name is the script alone:
     * preg_match('/^\p{Han}$/u', "\u{3001}") is 0 on PHP 8.1.34 with its
     * bundled PCRE2 10.39, and 1 from 10.40, where the comma's extensions
     * hold Han.
     */
    #[Test]
    public function test_a_bare_script_is_its_script_for_a_target_before_pcre2_10_40(): void
    {
        $before = Regex::create(['cache' => null, 'php_version' => 80100]);
        $after = Regex::create(['cache' => null, 'php_version' => 80200]);

        $this->assertSame('/^\p{Script=Han}(?=\n?$)/u', $before->transpile('/^\p{Han}$/u', 'javascript')->literal);
        $this->assertSame('/^\P{Script=Han}(?=\n?$)/u', $before->transpile('/^\p{^Han}$/u', 'javascript')->literal);
        $this->assertSame('/^\p{Script_Extensions=Han}(?=\n?$)/u', $after->transpile('/^\p{Han}$/u', 'javascript')->literal);
    }

    #[Test]
    public function test_rejects_a_bidi_class(): void
    {
        $this->expectException(TranspileException::class);
        $this->expectExceptionMessageIsOrContains('Bidi_Class properties are not supported in JavaScript.');

        Regex::create(['cache' => null])->transpile('/\p{bc:L}/u', 'javascript');
    }

    #[Test]
    public function test_rejects_a_script_javascript_does_not_name(): void
    {
        $this->expectException(TranspileException::class);
        $this->expectExceptionMessageIsOrContains('The script Nope has no JavaScript name.');

        Regex::create(['cache' => null])->transpile('/\p{sc:Nope}/u', 'javascript');
    }

    #[Test]
    public function test_drops_the_study_flag(): void
    {
        // Oracle: /S changes nothing since PHP 7.3.
        $this->assertSame(1, preg_match('/ab+c/S', 'xabbc'));

        $result = Regex::create(['cache' => null])->transpile('/ab+c/S', 'javascript');

        $this->assertSame('/ab+c/', $result->literal);
        $this->assertContains('Dropped /S: PHP has ignored it since 7.3, under PCRE2.', $result->notes);
    }

    /**
     * /U and "(?U)" swap greedy and lazy: each quantifier in their scope is
     * printed the other way. Each literal was checked with node: exec() on
     * the subject gives the groups preg_match() gives, every group of the
     * pattern listed by number from the whole match.
     *
     * @param list<string> $groups
     */
    #[Test]
    #[DataProvider('provideUngreedyPatterns')]
    public function test_carries_the_ungreedy_flag(string $pattern, string $literal, string $subject, array $groups): void
    {
        self::assertPatternMatches($pattern, $subject, $groups);

        $result = Regex::create(['cache' => null])->transpile($pattern, 'javascript');

        $this->assertSame($literal, $result->literal);
    }

    /**
     * @return iterable<string, array{pattern: string, literal: string, subject: string, groups: list<string>}>
     */
    public static function provideUngreedyPatterns(): iterable
    {
        yield 'a greedy quantifier' => ['pattern' => '/<.+>/U', 'literal' => '/<[^\n]+?>/', 'subject' => '<a><b>', 'groups' => ['<a>']];
        yield 'a lazy quantifier' => ['pattern' => '/(a+?)/U', 'literal' => '/(a+)/', 'subject' => 'aaa', 'groups' => ['aaa', 'aaa']];
        yield 'a counted quantifier' => ['pattern' => '/a{2,}/U', 'literal' => '/a{2,}?/', 'subject' => 'aaaa', 'groups' => ['aa']];
        yield 'an inline switch' => ['pattern' => '/a(?U)b+/', 'literal' => '/ab+?/', 'subject' => 'abbb', 'groups' => ['ab']];
        yield 'a scoped group' => ['pattern' => '/x(?U:a+)/', 'literal' => '/x(?:a+?)/', 'subject' => 'xaaa', 'groups' => ['xa']];
        yield 'an inline switch turning it off' => ['pattern' => '/(?-U)a+/U', 'literal' => '/a+/', 'subject' => 'aaa', 'groups' => ['aaa']];
    }

    #[Test]
    public function test_notes_the_ungreedy_flag(): void
    {
        $result = Regex::create(['cache' => null])->transpile('/a+/U', 'javascript');

        $this->assertContains('Applied /U (ungreedy): greedy and lazy quantifiers were swapped.', $result->notes);
    }

    #[Test]
    public function test_rejects_inline_flags_beyond_ungreedy(): void
    {
        $this->expectException(TranspileException::class);
        $this->expectExceptionMessageIsOrContains('Inline flags groups are not supported in JavaScript.');

        Regex::create(['cache' => null])->transpile('/a(?Ui)b/', 'javascript');
    }

    #[Test]
    public function test_rejects_possessive_quantifiers(): void
    {
        $regex = Regex::create();

        $this->expectException(TranspileException::class);
        $regex->transpile('/a++/', 'javascript');
    }

    #[Test]
    public function test_rejects_unicode_named_character_name_form(): void
    {
        // Oracle: preg_match() refuses \N{LATIN SMALL LETTER A}: "PCRE2
        // does not support \F, \L, \l, \N{name}, \U, or \u".
        try {
            Regex::create(['cache' => null])->transpile('/\N{LATIN SMALL LETTER A}/u', 'javascript');
            $message = '';
        } catch (TranspileException $e) {
            $message = $e->getMessage();
        }

        // the engine read the name where PCRE2 supports it, and the
        // transpiler says what to write instead; where PCRE2 predates
        // named characters, the name never resolves and the transpiler
        // says so in its own words
        $this->assertContains($message, [
            'PCRE2 does not support \N{name}: write the character as \N{U+hhhh}.',
            'Unicode named character escape with unknown name cannot be converted.',
        ]);
    }

    #[Test]
    public function test_rejects_unicode_named_character_unknown_name(): void
    {
        // Oracle: \N{UNKNOWN} is refused by PCRE2 (no such name). The
        // library parser keeps it as codePoint = -1; the printer must not
        // silently emit \xFFFFFFFFFFFFFFFF.
        $this->expectException(TranspileException::class);

        Regex::create(['cache' => null])->transpile('/\N{UNKNOWN NAME}/u', 'javascript');
    }

    /**
     * "\N{U+hhhh}" is the character itself, which each target writes as a
     * code point escape, and says so.
     */
    #[Test]
    #[DataProvider('provideCodePointSpellings')]
    public function test_converts_a_named_code_point_and_warns(string $target, string $literal): void
    {
        // Oracle: under u, \N{U+2022} is the bullet.
        $this->assertSame(1, preg_match('/^\N{U+2022}$/u', "\u{2022}"));

        $result = Regex::create(['cache' => null])->transpile('/\N{U+2022}/u', $target);

        $this->assertSame($literal, $result->literal);
        $this->assertSame(['Converted Unicode named character to code point escape.'], $result->warnings);
    }

    /**
     * @return iterable<string, array{target: string, literal: string}>
     */
    public static function provideCodePointSpellings(): iterable
    {
        yield 'JavaScript' => ['target' => 'javascript', 'literal' => '/\u2022/u'];
        yield 'Python' => ['target' => 'python', 'literal' => "r'\\u2022'"];
    }

    #[Test]
    public function test_adds_the_unicode_flag_a_property_escape_needs_and_warns(): void
    {
        // Oracle: without u, \pL is a letter as well (matched byte by byte).
        $this->assertSame(1, preg_match('/\pL/', 'a'));

        $result = Regex::create(['cache' => null])->transpile('/\pL/', 'javascript');

        $this->assertSame('/\p{L}/u', $result->literal);
        $this->assertSame('u', $result->flags);
        $this->assertSame(['Added /u for Unicode property escapes.'], $result->warnings);
    }
}
