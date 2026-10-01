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

use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Limits PCRE2 sets on what it compiles, found in its testinput8, 9, 10 and
 * 15, which the conformance fixture does not vendor:
 *
 * - a (*MARK), (*PRUNE), (*SKIP) or (*THEN) name holds at most 255 code
 *   units (error 176, past the name);
 * - a (*LIMIT_...=n) value is at most 4294967289: PCRE refuses the digit that
 *   would take it further (error 160; PHP on 10.48 reports on that digit,
 *   PHP on 10.42 past it);
 * - parentheses nest at most 250 deep (error 119, past the opener of the
 *   251st), PCRE2's default, which PHP keeps on every release.
 */
final class PcreCompileLimitsTest extends TestCase
{
    /**
     * @param list<int> $offsets
     */
    #[Test]
    #[DataProvider('provideRefusedPatterns')]
    public function test_validate_refuses_what_passes_a_compile_limit(string $pattern, string $code, array $offsets): void
    {
        $this->assertFalse(@preg_match($pattern, ''), \sprintf('%s should not compile.', substr($pattern, 0, 60)));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s does not compile but was reported valid.', substr($pattern, 0, 60)));
        $this->assertSame($code, $result->errorCode?->value);
        $this->assertContains($result->offset, $offsets, \sprintf('%s reported at offset %s, PCRE2 reports %s.', substr($pattern, 0, 60), var_export($result->offset, true), implode(' or ', $offsets)));
    }

    #[Test]
    #[DataProvider('provideAcceptedPatterns')]
    public function test_validate_accepts_what_stays_within_a_compile_limit(string $pattern): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), \sprintf('%s should compile.', substr($pattern, 0, 60)));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s compiles but was reported invalid: %s', substr($pattern, 0, 60), (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string, code: string, offsets: list<int>}>
     */
    public static function provideRefusedPatterns(): iterable
    {
        yield 'short mark name of 256' => ['pattern' => '/(*:'.str_repeat('a', 256).')/', 'code' => 'regex.verb.name_too_long', 'offsets' => [259]];
        yield 'mark name of 256' => ['pattern' => '/(*MARK:'.str_repeat('a', 256).')/', 'code' => 'regex.verb.name_too_long', 'offsets' => [263]];
        yield 'then name of 256' => ['pattern' => '/(*THEN:'.str_repeat('a', 256).')/', 'code' => 'regex.verb.name_too_long', 'offsets' => [263]];
        yield 'match limit past the maximum' => ['pattern' => '/(*LIMIT_MATCH=4294967290)a/', 'code' => 'regex.verb.limit_too_large', 'offsets' => [23, 24]];
        yield 'heap limit of ten digits' => ['pattern' => '/(*LIMIT_HEAP=5000000000)a/', 'code' => 'regex.verb.limit_too_large', 'offsets' => [22, 23]];
        yield 'depth limit past the maximum' => ['pattern' => '/(*LIMIT_DEPTH=4294967295)a/', 'code' => 'regex.verb.limit_too_large', 'offsets' => [23, 24]];
        yield '251 capturing groups' => ['pattern' => '/'.str_repeat('(', 251).'a'.str_repeat(')', 251).'/', 'code' => 'regex.group.nested_too_deep', 'offsets' => [251]];
        yield '251 non-capturing groups' => ['pattern' => '/'.str_repeat('(?:', 251).'a'.str_repeat(')', 251).'/', 'code' => 'regex.group.nested_too_deep', 'offsets' => [753]];
        yield '251 lookaheads' => ['pattern' => '/'.str_repeat('(?=', 251).'a'.str_repeat(')', 251).'/', 'code' => 'regex.group.nested_too_deep', 'offsets' => [753]];
        yield '251 alphabetic lookaheads' => ['pattern' => '/'.str_repeat('(*pla:', 251).'a'.str_repeat(')', 251).'/', 'code' => 'regex.group.nested_too_deep', 'offsets' => [1506]];
        yield '251 conditionals' => ['pattern' => '/(a)'.str_repeat('(?(1)', 251).'a'.str_repeat(')', 251).'/', 'code' => 'regex.group.nested_too_deep', 'offsets' => [1258]];
        // pcre2test 10.48 and PHP: the level is refused once its opener is
        // read, before a space "x" skips; a conditional that an assertion
        // decides, on that assertion.
        yield 'scoped options at level 251' => ['pattern' => '/'.str_repeat('(', 250).'(?i:a)'.str_repeat(')', 250).'/', 'code' => 'regex.group.nested_too_deep', 'offsets' => [254]];
        yield 'space after the opener under x' => ['pattern' => '/'.str_repeat('(', 250).'( a)'.str_repeat(')', 250).'/x', 'code' => 'regex.group.nested_too_deep', 'offsets' => [251]];
        yield 'conditional on an assertion at level 251' => ['pattern' => '/'.str_repeat('(', 250).'(?(?=a)b)'.str_repeat(')', 250).'/', 'code' => 'regex.group.nested_too_deep', 'offsets' => [252]];
        yield 'assertion of a conditional at level 251' => ['pattern' => '/'.str_repeat('(', 249).'(?(?=a)b)'.str_repeat(')', 249).'/', 'code' => 'regex.group.nested_too_deep', 'offsets' => [254]];
        yield 'define at level 251' => ['pattern' => '/'.str_repeat('(', 250).'(?(DEFINE)a)'.str_repeat(')', 250).'/', 'code' => 'regex.group.nested_too_deep', 'offsets' => [260]];
        yield 'script run at level 251' => ['pattern' => '/'.str_repeat('(', 250).'(*sr:a)'.str_repeat(')', 250).'/', 'code' => 'regex.group.nested_too_deep', 'offsets' => [255]];
        yield 'an error inside the level comes after' => ['pattern' => '/'.str_repeat('(', 250).'(a\\y)'.str_repeat(')', 250).'/', 'code' => 'regex.group.nested_too_deep', 'offsets' => [251]];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideAcceptedPatterns(): iterable
    {
        yield 'short mark name of 255' => ['pattern' => '/(*:'.str_repeat('a', 255).')/'];
        yield 'then name of 255' => ['pattern' => '/(*THEN:'.str_repeat('a', 255).')/'];
        yield 'match limit at the maximum' => ['pattern' => '/(*LIMIT_MATCH=4294967289)a/'];
        yield 'match limit with a leading zero' => ['pattern' => '/(*LIMIT_MATCH=04294967289)a/'];
        yield '250 capturing groups' => ['pattern' => '/'.str_repeat('(', 250).'a'.str_repeat(')', 250).'/'];
        yield '250 non-capturing groups' => ['pattern' => '/'.str_repeat('(?:', 250).'a'.str_repeat(')', 250).'/'];
        yield 'script run at level 250' => ['pattern' => '/'.str_repeat('(', 249).'(*sr:a)'.str_repeat(')', 249).'/'];
        yield 'define at level 250' => ['pattern' => '/'.str_repeat('(', 249).'(?(DEFINE)a)'.str_repeat(')', 249).'/'];
        yield 'options without a group at level 251' => ['pattern' => '/'.str_repeat('(', 250).'(?i)a'.str_repeat(')', 250).'/'];
        yield 'comment and verb at level 251' => ['pattern' => '/'.str_repeat('(', 250).'(?#x)(*MARK:m)a'.str_repeat(')', 250).'/'];
    }
}
