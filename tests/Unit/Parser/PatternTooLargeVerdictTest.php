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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Whether PCRE2 finds a pattern too large to compile ("regular expression
 * is too large"), on each release: the first pass of the compiler counts
 * what each item compiles to, and that count, not the smallest the library
 * could be sure of, decides. Every row was run through pcre2test 10.32 to
 * 10.49, with the nesting limit PHP builds PCRE2 with (250).
 */
final class PatternTooLargeVerdictTest extends TestCase
{
    #[Test]
    #[DataProvider('provideTooLargePatterns')]
    public function test_a_pattern_pcre2_finds_too_large_is_refused(string $pattern, string $php, string $release, int $offset): void
    {
        $result = self::validate($pattern, $php, $release);

        $this->assertSame(ErrorCode::PatternTooLarge, $result->errorCode, \sprintf('%s on PHP %s with PCRE2 %s: %s', $pattern, $php, $release, (string) $result->error));
        $this->assertSame($offset, $result->offset, \sprintf('%s on PHP %s with PCRE2 %s', $pattern, $php, $release));
    }

    #[Test]
    #[DataProvider('provideCompilingPatterns')]
    public function test_a_pattern_pcre2_compiles_is_valid(string $pattern, string $php, string $release): void
    {
        $result = self::validate($pattern, $php, $release);

        $this->assertTrue($result->isValid, \sprintf('%s on PHP %s with PCRE2 %s: %s', $pattern, $php, $release, (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string, php: string, release: string, offset: int}>
     */
    public static function provideTooLargePatterns(): iterable
    {
        // "a{2,}" is a letter and a letter repeated, six units, where the
        // smallest count is two.
        yield 'counted letter in a repeated group, 10.32' => ['pattern' => '/(?:a{2,}){7000}/', 'php' => '7.3', 'release' => '10.32', 'offset' => 15];
        yield 'counted letter in a repeated group, 10.44' => ['pattern' => '/(?:a{2,}){7000}/', 'php' => '8.4', 'release' => '10.44', 'offset' => 15];
        yield 'counted letter in a repeated group, 10.47' => ['pattern' => '/(?:a{2,}){7000}/', 'php' => '8.5', 'release' => '10.47', 'offset' => 0];
        yield 'counted letter in a repeated group, 10.49' => ['pattern' => '/(?:a{2,}){7000}/', 'php' => '8.5', 'release' => '10.49', 'offset' => 0];
        yield 'counted type in a repeated group of branches, 10.40' => ['pattern' => '/(?:\\d{2}|\\w){5000}/', 'php' => '8.2', 'release' => '10.40', 'offset' => 18];
        yield 'counted Unicode type in a repeated group of branches, 10.33' => ['pattern' => '/(?:\\d{2}|\\w){5000}/u', 'php' => '7.4', 'release' => '10.33', 'offset' => 18];
        yield 'two-byte letter in a repeated group of branches, 10.42' => ['pattern' => '/(?:é|b){5000}/u', 'php' => '8.3', 'release' => '10.42', 'offset' => 14];
        yield 'two-byte letter in a repeated group of branches, 10.48' => ['pattern' => '/(?:é|b){5000}/u', 'php' => '8.4', 'release' => '10.48', 'offset' => 0];

        // "\p{Any}" is a property of three units on 10.32.
        yield 'repeated property of any character, 10.32' => ['pattern' => '/(?:\\p{Any}){9000}/', 'php' => '7.3', 'release' => '10.32', 'offset' => 17];
        // A class of any character is an extended class of eight units up
        // to 10.44.
        yield 'repeated class of any character, 10.44' => ['pattern' => '/(?:[\\p{Any}]){9000}/', 'php' => '8.4', 'release' => '10.44', 'offset' => 19];
        // Up to 10.44 the Kelvin sign joins the class once for "k", once
        // for "K".
        yield 'repeated caseless class of k and its other case, 10.44' => ['pattern' => '/(?:[kK]){1300}/iu', 'php' => '8.4', 'release' => '10.44', 'offset' => 14];
        yield 'repeated caseless class of k and its other case, 10.47' => ['pattern' => '/(?:[kK]){1400}/iu', 'php' => '8.5', 'release' => '10.47', 'offset' => 0];

        // The first pass counts a repeated DEFINE group once per copy, though
        // the compiled pattern holds it once.
        yield 'repeated definitions, 10.35' => ['pattern' => '/(?(DEFINE)a){8000}/', 'php' => '8.0', 'release' => '10.35', 'offset' => 18];
        yield 'repeated definitions, 10.48' => ['pattern' => '/(?(DEFINE)a){8000}/', 'php' => '8.4', 'release' => '10.48', 'offset' => 0];
        // A group of options holding only a space under "x" is a group.
        yield 'groups of options holding a space, 10.44' => ['pattern' => '/'.str_repeat('(?x: )', 11000).'/', 'php' => '8.4', 'release' => '10.44', 'offset' => 66000];
        yield 'groups of options holding a space, 10.49' => ['pattern' => '/'.str_repeat('(?x: )', 11000).'/', 'php' => '8.5', 'release' => '10.49', 'offset' => 0];
        yield 'script run holding a repeated group, 10.33' => ['pattern' => '/(*sr:(?:aaaa){9000})/', 'php' => '7.4', 'release' => '10.33', 'offset' => 20];
        yield 'repeated group holding a lookbehind, 10.40' => ['pattern' => '/(?<=a)(?:(?<=b)c){5000}/', 'php' => '8.2', 'release' => '10.40', 'offset' => 23];
        yield 'repeated group holding a string callout, 10.46' => ['pattern' => '/(?:(?C"abcdef")a){4000}/', 'php' => '8.4', 'release' => '10.46', 'offset' => 23];
        // "(*ACCEPT)" closes each capture open around it: three units each,
        // in a pattern that holds no count.
        yield 'accepts inside nested captures, 10.32' => ['pattern' => '/'.str_repeat('(', 200).str_repeat('(*ACCEPT)', 120).str_repeat(')', 200).'/', 'php' => '7.3', 'release' => '10.32', 'offset' => 1480];
        yield 'accepts inside nested captures, 10.49' => ['pattern' => '/'.str_repeat('(', 200).str_repeat('(*ACCEPT)', 120).str_repeat(')', 200).'/', 'php' => '8.5', 'release' => '10.49', 'offset' => 0];
        // A call that returns groups lists them, three units each, from
        // 10.47.
        yield 'calls returning two groups, 10.49' => ['pattern' => '/(a)(b)'.str_repeat('(?1(1,2))', 8000).'/', 'php' => '8.5', 'release' => '10.49', 'offset' => 0];
        // A callout before an assertion as the condition is compiled with it.
        yield 'condition of a callout and a long assertion, 10.32' => ['pattern' => '/(?(?C1)(?='.str_repeat('b', 40000).')c|d)/', 'php' => '7.3', 'release' => '10.32', 'offset' => 40015];
        // An empty atomic script run is two groups.
        yield 'empty atomic script runs, 10.33' => ['pattern' => '/'.str_repeat('(*asr:)', 5500).'/', 'php' => '7.4', 'release' => '10.33', 'offset' => 38500];
        // From 10.35 a counted assertion is repeated.
        yield 'counted lookahead in a repeated capture, 10.35' => ['pattern' => '/(c)(x{0}k(?=a){2}|\\d{2}){1889}/u', 'php' => '8.0', 'release' => '10.35', 'offset' => 30];
        // Up to 10.44 a class of six characters past 255 is six items in
        // each copy of the group.
        yield 'repeated class of six characters past 255, 10.44' => ['pattern' => '/(?:[\\x{100}\\x{102}\\x{104}\\x{106}\\x{108}\\x{10a}]){4500}/u', 'php' => '8.4', 'release' => '10.44', 'offset' => 54];
    }

    /**
     * @return iterable<string, array{pattern: string, php: string, release: string}>
     */
    public static function provideCompilingPatterns(): iterable
    {
        yield 'counted letter in a repeated group' => ['pattern' => '/(?:a{2,}){5000}/', 'php' => '8.4', 'release' => '10.44'];
        // "\p{Any}" compiles to one unit from 10.33, "[\p{Any}]" from 10.45.
        yield 'repeated property of any character, 10.33' => ['pattern' => '/(?:\\p{Any}){9000}/', 'php' => '7.4', 'release' => '10.33'];
        yield 'repeated property of any character, 10.44' => ['pattern' => '/(?:\\p{Any}){9000}/', 'php' => '8.4', 'release' => '10.44'];
        yield 'repeated property of any character, 10.49' => ['pattern' => '/(?:\\p{Any}){9000}/', 'php' => '8.5', 'release' => '10.49'];
        yield 'repeated class of any character, 10.45' => ['pattern' => '/(?:[\\p{Any}]){9000}/', 'php' => '8.4', 'release' => '10.45'];
        yield 'repeated class of any character, 10.48' => ['pattern' => '/(?:[\\p{Any}]){9000}/', 'php' => '8.4', 'release' => '10.48'];
        // From 10.45 the Kelvin sign joins the class once.
        yield 'repeated caseless class of k and its other case, 10.45' => ['pattern' => '/(?:[kK]){1300}/iu', 'php' => '8.4', 'release' => '10.45'];
        yield 'repeated definitions' => ['pattern' => '/(?(DEFINE)a){7000}/', 'php' => '8.4', 'release' => '10.44'];
        // Under an inline "a" a type or a POSIX name in a class is in its
        // map, and "\\d" outside one a type of one unit.
        yield 'classes of letters and a type under ASCII options' => ['pattern' => '/(?a)'.str_repeat('[ab\\d]', 1700).'/u', 'php' => '8.4', 'release' => '10.44'];
        yield 'types under ASCII options' => ['pattern' => '/(?a)'.str_repeat('\\d', 22000).'/u', 'php' => '8.3', 'release' => '10.43'];
        // Under "xx" a space in a class is skipped: "[a ]" is the letter.
        yield 'classes holding a space, read under xx, 10.32' => ['pattern' => '/[a ](?xx)'.str_repeat('[a ]', 1990).'/', 'php' => '7.3', 'release' => '10.32'];
        yield 'classes holding a space, read under xx, 10.49' => ['pattern' => '/[a ](?xx)'.str_repeat('[a ]', 1990).'/', 'php' => '8.5', 'release' => '10.49'];
        // Up to 10.34, UCP without UTF leaves "k" a caseless letter.
        yield 'caseless k under UCP without UTF, 10.34' => ['pattern' => '/(*UCP)'.str_repeat('k', 25000).'/i', 'php' => '7.4', 'release' => '10.34'];
        // Without UTF "\\p{Any}" fills the map without making the class need
        // one.
        yield 'classes of a property and any character without UTF, 10.45' => ['pattern' => '/'.str_repeat('[\\pL\\p{Any}]', 1700).'/', 'php' => '8.4', 'release' => '10.45'];
        // Ranges to the last character with a type and its negation are a
        // match of any character from 10.45.
        yield 'repeated class of every character past 255 and a type both ways, 10.45' => ['pattern' => '/(?:[\\x{100}-\\x{10FFFF}\\d\\D]){5000}/u', 'php' => '8.4', 'release' => '10.45'];
        yield 'nested conditionals on a callout and an assertion' => ['pattern' => '/'.str_repeat('(?(?C1)(?=a)', 240).str_repeat('.', 55000).str_repeat('|b)?', 240).'/', 'php' => '8.5', 'release' => '10.49'];
        // Up to 10.34 a counted assertion is compiled once.
        yield 'counted lookahead in a repeated capture, 10.32' => ['pattern' => '/(c)(x{0}k(?=a){2}|\\d{2}){1889}/u', 'php' => '7.3', 'release' => '10.32'];
        yield 'counted lookahead in a repeated capture, 10.34' => ['pattern' => '/(c)(x{0}k(?=a){2}|\\d{2}){1889}/u', 'php' => '7.4', 'release' => '10.34'];
        // From 10.45 the six characters go in a list kept beside the code,
        // which every copy of the group shares.
        yield 'repeated class of six characters past 255, 10.45' => ['pattern' => '/(?:[\\x{100}\\x{102}\\x{104}\\x{106}\\x{108}\\x{10a}]){4500}/u', 'php' => '8.4', 'release' => '10.45'];
        yield 'repeated class of six characters past 255, 10.48' => ['pattern' => '/(?:[\\x{100}\\x{102}\\x{104}\\x{106}\\x{108}\\x{10a}]){4500}/u', 'php' => '8.4', 'release' => '10.48'];
        // A class of every space type and its negation holds every
        // character: from 10.45 a match of any.
        yield 'repeated class of space types and their negations, 10.44' => ['pattern' => '/(?:[\\h\\H\\v\\V]a){200}/u', 'php' => '8.4', 'release' => '10.44'];
        yield 'repeated class of space types and their negations, 10.45' => ['pattern' => '/(?:[\\h\\H\\v\\V]a){7000}/u', 'php' => '8.4', 'release' => '10.45'];
        // PHP builds PCRE2 with a nesting limit of 250.
        yield 'groups nested 250 deep' => ['pattern' => '/'.str_repeat('(', 250).'a'.str_repeat(')', 250).'/', 'php' => '8.4', 'release' => '10.44'];
    }

    private static function validate(string $pattern, string $php, string $release): ValidationResult
    {
        return RegexParser::create(['cache' => null, 'php_version' => $php, 'pcre_version' => $release, 'runtime_pcre_validation' => false])->validate($pattern);
    }
}
