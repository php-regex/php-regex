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
    public function test_adds_unicode_flag_for_codepoint_escapes(): void
    {
        $regex = Regex::create();
        $result = $regex->transpile('/\\x{1F600}/', 'javascript');

        $this->assertSame('/\\u{1F600}/u', $result->literal);
        $this->assertContains('Added /u for Unicode code point escapes.', $result->warnings);
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

    #[Test]
    public function test_rejects_possessive_quantifiers(): void
    {
        $regex = Regex::create();

        $this->expectException(TranspileException::class);
        $regex->transpile('/a++/', 'javascript');
    }
}
