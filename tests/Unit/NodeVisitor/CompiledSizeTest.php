<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit\NodeVisitor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Regex;

/**
 * PCRE compiles a repeated group as one copy per repetition, and refuses a
 * compiled pattern above 64 KiB (error 120, "regular expression is too
 * large", with the default two-byte links PHP builds with). Each copy costs
 * at least its brackets, so a pattern whose smallest possible size already
 * passes the limit is refused; one that stays under it is left to PCRE.
 *
 * Every boundary below was measured on PHP (PCRE2 10.48): the first count
 * that fails and the one before it. 10.40 to 10.45 report the error at the
 * end of the pattern, 10.48 at offset 0 or at the end.
 */
final class CompiledSizeTest extends TestCase
{
    #[Test]
    #[DataProvider('provideTooLargePatterns')]
    public function test_validate_refuses_a_pattern_too_large_to_compile(string $pattern): void
    {
        $this->assertFalse(self::compiles($pattern), \sprintf('%s should not compile.', $pattern));

        $result = Regex::create(['cache' => null])->validate($pattern);
        $body = substr($pattern, 1, (int) strrpos($pattern, '/') - 1);

        $this->assertFalse($result->isValid, \sprintf('%s is too large for PCRE but was reported valid.', $pattern));
        $this->assertSame('regex.pattern.too_large', $result->errorCode);
        $this->assertContains($result->offset, [0, \strlen($body)]);
    }

    #[Test]
    #[DataProvider('provideLargestPatterns')]
    public function test_validate_accepts_a_pattern_just_under_the_limit(string $pattern): void
    {
        $this->assertTrue(self::compiles($pattern), \sprintf('%s should compile.', $pattern));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s compiles but was reported invalid: %s', $pattern, (string) $result->error));
    }

    #[Test]
    public function test_nested_conditionals_are_measured_in_linear_time(): void
    {
        // Each conditional nests the next in its "no" branch; measuring that
        // branch twice made the time double with every level.
        $pattern = '/(a)'.str_repeat('(?(1)a|', 40).'b'.str_repeat(')', 40).'/';

        $this->assertTrue(Regex::create(['cache' => null])->validate($pattern)->isValid);
    }

    #[Test]
    public function test_other_errors_come_before_the_size(): void
    {
        // PCRE refuses the missing group and the escape before it measures
        // the compiled pattern.
        $this->assertSame('regex.backref.missing_group', Regex::create()->validate('/\\5(?:){20000}/')->errorCode);
        $this->assertSame('regex.escape.unrecognized', Regex::create()->validate('/(?:){20000}\\y/')->errorCode);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideTooLargePatterns(): iterable
    {
        yield 'empty group' => ['pattern' => '/(?:){10922}/'];
        yield 'one-letter group' => ['pattern' => '/(?:a){8192}/'];
        yield 'anchored' => ['pattern' => '/^(?:a){8192}$/'];
        yield 'capturing group' => ['pattern' => '/(a){6553}/'];
        yield 'named group' => ['pattern' => '/(?<n>a){6553}/'];
        yield 'group made non-capturing by n' => ['pattern' => '/(a){8192}/n'];
        yield 'recursion' => ['pattern' => '/(?R){21844}/'];
        yield 'accept, which PCRE wraps in a group to repeat it' => ['pattern' => '/(*ACCEPT){9362}/'];
        yield 'accept with a name' => ['pattern' => '/(*ACCEPT:xyz){5041}/'];
        yield 'lookahead' => ['pattern' => '/(?=a){8192}/'];
        yield 'two branches' => ['pattern' => '/(?:a|b){5041}/'];
        yield 'open maximum' => ['pattern' => '/(?:a){8192,}/'];
        yield 'optional copies' => ['pattern' => '/(?:a){0,4370}/'];
        yield 'mandatory then optional copies' => ['pattern' => '/(?:a){2,4370}/'];
        yield 'lazy count' => ['pattern' => '/(?:a){10000}?/'];
        yield 'scoped modifiers' => ['pattern' => '/(?i:a){8192}/'];
        yield 'nested counts' => ['pattern' => '/((?:a){100}){100}/'];
        yield 'conditional' => ['pattern' => '/(?(1)a|b){5041}(a)/'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideLargestPatterns(): iterable
    {
        yield 'empty group' => ['pattern' => '/(?:){10921}/'];
        yield 'one-letter group' => ['pattern' => '/(?:a){8191}/'];
        yield 'capturing group' => ['pattern' => '/(a){6552}/'];
        yield 'group made non-capturing by n' => ['pattern' => '/(a){8191}/n'];
        yield 'modifiers that scope nothing' => ['pattern' => '/(?i)(?:a){8191}/'];
        yield 'recursion' => ['pattern' => '/(?R){21843}/'];
        yield 'accept' => ['pattern' => '/(*ACCEPT){9361}/'];
        yield 'accept with a name' => ['pattern' => '/(*ACCEPT:xyz){5040}/'];
        yield 'open maximum' => ['pattern' => '/(?:a){8191,}/'];
        yield 'optional copies' => ['pattern' => '/(?:a){0,4369}/'];
        yield 'mandatory then optional copies' => ['pattern' => '/(?:a){2,4369}/'];
        yield 'empty negative lookahead, compiled to a fail' => ['pattern' => '/(?:(?!)){6000}/'];
        yield 'empty negative lookahead, alphabetic' => ['pattern' => '/(?:(*nla:)){6000}/'];
        yield 'lookahead holding a fail' => ['pattern' => '/(?:(?=(?!))){5000}/'];
        yield 'branch that fails' => ['pattern' => '/(?:a|(?!)){5400}/'];
        yield 'octal escape written like a reference' => ['pattern' => '/(?:\\101){7500}/'];
        yield 'two-digit octal after a group' => ['pattern' => '/(a)(?:\\12){7500}/'];
        yield 'x-mode line separator' => ['pattern' => "/(?:a\u{2028}){7000}/xu"];
        yield 'x-mode next-line bytes' => ['pattern' => '/(?:a'.str_repeat("\x85", 30).'){2000}/x'];
        yield 'counted letter' => ['pattern' => '/a{65535}/'];
        yield 'counted character type' => ['pattern' => '/\\d{65535}/'];
    }

    /**
     * Whether PHP compiles the pattern: a match that fails at run time, as
     * an endless recursion does, still compiled.
     */
    private static function compiles(string $pattern): bool
    {
        error_clear_last();
        @preg_match($pattern, '');

        return !str_contains(error_get_last()['message'] ?? '', 'Compilation failed');
    }
}
