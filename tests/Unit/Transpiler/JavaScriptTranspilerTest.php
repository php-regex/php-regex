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

use PHPRegex\Toolkit\Regex;
use PHPRegex\Transpiler\TranspileException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JavaScriptTranspilerTest extends TestCase
{
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
        $this->assertContains('Dropped /x comments during transpilation.', $result->notes);
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
        yield 'a bare script' => ['pattern' => '/^\p{Han}$/u', 'literal' => '/^\p{Script_Extensions=Han}$/u', 'subjects' => ['中' => 1, '、' => 1, 'a' => 0]];
        yield 'a script by its Script' => ['pattern' => '/^\p{sc:Han}$/u', 'literal' => '/^\p{Script=Han}$/u', 'subjects' => ['中' => 1, '、' => 0]];
        yield 'a script by its short name' => ['pattern' => '/^\p{Script=Hani}$/u', 'literal' => '/^\p{Script=Han}$/u', 'subjects' => ['中' => 1, '、' => 0]];
        yield 'a script by its Script_Extensions' => ['pattern' => '/^\p{scx:Hira}$/u', 'literal' => '/^\p{Script_Extensions=Hiragana}$/u', 'subjects' => ['ー' => 1, 'あ' => 1, 'ア' => 0]];
        yield 'a script spelled loosely' => ['pattern' => '/^\P{greek}$/u', 'literal' => '/^\P{Script_Extensions=Greek}$/u', 'subjects' => ['α' => 0, 'a' => 1]];
        yield 'a script negated with a caret' => ['pattern' => '/^\p{^Latin}$/u', 'literal' => '/^\P{Script_Extensions=Latin}$/u', 'subjects' => ['a' => 0, 'α' => 1]];
        yield 'the Common script' => ['pattern' => '/^\p{Common}$/u', 'literal' => '/^\p{Script=Common}$/u', 'subjects' => ['、' => 1, '1' => 1, 'a' => 0]];
        yield 'a script in a class' => ['pattern' => '/^[\p{Han}\d]+$/u', 'literal' => '/^[\p{Script_Extensions=Han}\d]+$/u', 'subjects' => ['中1' => 1, 'a' => 0]];
        yield 'general categories' => ['pattern' => '/^\p{L}\p{Lu}\pN$/u', 'literal' => '/^\p{L}\p{Lu}\p{N}$/u', 'subjects' => ['aB1' => 1, 'ab1' => 0]];
        yield 'a binary property' => ['pattern' => '/^\p{Alphabetic}$/u', 'literal' => '/^\p{Alphabetic}$/u', 'subjects' => ['a' => 1, '1' => 0]];
    }

    #[Test]
    public function test_rejects_a_bidi_class(): void
    {
        $this->expectException(TranspileException::class);
        $this->expectExceptionMessage('Bidi_Class properties are not supported in JavaScript.');

        Regex::create(['cache' => null])->transpile('/\p{bc:L}/u', 'javascript');
    }

    #[Test]
    public function test_rejects_a_script_javascript_does_not_name(): void
    {
        $this->expectException(TranspileException::class);
        $this->expectExceptionMessage('The script Nope has no JavaScript name.');

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

    #[Test]
    public function test_rejects_possessive_quantifiers(): void
    {
        $regex = Regex::create();

        $this->expectException(TranspileException::class);
        $regex->transpile('/a++/', 'javascript');
    }
}
