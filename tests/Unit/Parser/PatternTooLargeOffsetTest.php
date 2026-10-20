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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where PCRE2 reports a pattern too large to compile ("regular expression is
 * too large"): at the end of the pattern up to 10.46; at offset 0 on 10.47,
 * whichever check finds it. From 10.48 the check made where a group ends, or
 * where the pattern ends, sets no offset again: a group whose branches only
 * pass the limit together, once its last branch is added, or a pattern one
 * unit past it, is reported at the end of the pattern; past the limit inside
 * one branch, at offset 0.
 *
 * Every row was run through pcre2test 10.46 to 10.49.
 */
final class PatternTooLargeOffsetTest extends TestCase
{
    #[Test]
    #[DataProvider('provideTooLargePatterns')]
    public function test_a_pattern_too_large_is_reported_where_pcre2_reports_it(string $pattern, string $php, string $release, int $offset): void
    {
        $result = RegexParser::create(['cache' => null, 'php_version' => $php, 'pcre_version' => $release, 'runtime_pcre_validation' => false])->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s on PHP %s with PCRE2 %s', $pattern, $php, $release));
        $this->assertSame(ErrorCode::PatternTooLarge, $result->errorCode, \sprintf('%s on PHP %s with PCRE2 %s: %s', $pattern, $php, $release, (string) $result->error));
        $this->assertSame($offset, $result->offset, \sprintf('%s on PHP %s with PCRE2 %s', $pattern, $php, $release));
    }

    /**
     * @return iterable<string, array{pattern: string, php: string, release: string, offset: int}>
     */
    public static function provideTooLargePatterns(): iterable
    {
        // Up to 10.46, at the end of the pattern.
        yield 'repeated group, 10.46' => ['pattern' => '/(?:a){65535}/', 'php' => '8.4', 'release' => '10.46', 'offset' => 12];
        yield 'capture one unit past the limit, 10.46' => ['pattern' => '/(a){6553}/', 'php' => '8.4', 'release' => '10.46', 'offset' => 9];
        yield 'repeated group, PHP 8.2' => ['pattern' => '/x(?:a){65535}/', 'php' => '8.2', 'release' => '10.40', 'offset' => 13];

        // On 10.47, at offset 0.
        yield 'repeated group, 10.47' => ['pattern' => '/(?:a){65535}/', 'php' => '8.4', 'release' => '10.47', 'offset' => 0];
        yield 'capture one unit past the limit, 10.47' => ['pattern' => '/(a){6553}/', 'php' => '8.4', 'release' => '10.47', 'offset' => 0];
        yield 'last branch past the limit, 10.47' => ['pattern' => '/a|(?:b){8191}/', 'php' => '8.4', 'release' => '10.47', 'offset' => 0];
        yield 'two branches past the limit, 10.47' => ['pattern' => '/(?:a){4096}|(?:b){4095}/', 'php' => '8.4', 'release' => '10.47', 'offset' => 0];

        // From 10.48, at offset 0 past the limit inside a branch.
        yield 'repeated group, 10.48' => ['pattern' => '/(?:a){65535}/', 'php' => '8.4', 'release' => '10.48', 'offset' => 0];
        yield 'repeated group, 10.49' => ['pattern' => '/(?:a){8192}/', 'php' => '8.5', 'release' => '10.49', 'offset' => 0];
        yield 'first branch past the limit, 10.48' => ['pattern' => '/(?:b){8191}|a/', 'php' => '8.4', 'release' => '10.48', 'offset' => 0];
        yield 'last branch alone past the limit, 10.48' => ['pattern' => '/a|(?:b){8193}/', 'php' => '8.4', 'release' => '10.48', 'offset' => 0];
        yield 'capture two units past the limit, 10.48' => ['pattern' => '/(a){6554}/', 'php' => '8.4', 'release' => '10.48', 'offset' => 0];
        yield 'group branch alone past the limit, 10.48' => ['pattern' => '/(?:x|(?:b){8193})/', 'php' => '8.4', 'release' => '10.48', 'offset' => 0];

        // From 10.48, at the end where a group or the pattern ends.
        yield 'capture one unit past the limit, 10.48' => ['pattern' => '/(a){6553}/', 'php' => '8.4', 'release' => '10.48', 'offset' => 9];
        yield 'named capture one unit past the limit, 10.49' => ['pattern' => '/(?<n>a){6553}/', 'php' => '8.5', 'release' => '10.49', 'offset' => 13];
        yield 'last branch makes it too large, 10.48' => ['pattern' => '/a|(?:b){8191}/', 'php' => '8.4', 'release' => '10.48', 'offset' => 13];
        yield 'two branches past the limit, 10.49' => ['pattern' => '/(?:a){4096}|(?:b){4095}/', 'php' => '8.5', 'release' => '10.49', 'offset' => 23];
        yield 'group whose last branch makes it too large, 10.48' => ['pattern' => '/(?:x|(?:b){8191})/', 'php' => '8.4', 'release' => '10.48', 'offset' => 17];
        yield 'conditional whose second branch makes it too large, 10.48' => ['pattern' => '/(a)(?(1)(?:bc){5000}|(?:de){5000})/', 'php' => '8.4', 'release' => '10.48', 'offset' => 34];
        yield 'assertion conditional whose second branch makes it too large, 10.49' => ['pattern' => '/(?(?=x)(?:bc){5000}|(?:de){5000})/', 'php' => '8.5', 'release' => '10.49', 'offset' => 33];
        yield 'conditional branch alone past the limit, 10.48' => ['pattern' => '/(a)(?(1)a|(?:de){9000})/', 'php' => '8.4', 'release' => '10.48', 'offset' => 0];
        yield 'conditional branches past the limit, 10.47' => ['pattern' => '/(a)(?(1)(?:bc){5000}|(?:de){5000})/', 'php' => '8.4', 'release' => '10.47', 'offset' => 0];

        // A group of branches measured before the limit is passed may be
        // larger than its floor, and too large where it ends: at the end
        // of the pattern, as up to 10.46. "a{2}" compiles to four units.
        yield 'group of branches before the limit, 10.48' => ['pattern' => '/(?:(?:a{2}){3400}|(?:b{2}){3400})(?:cc){1500}/', 'php' => '8.4', 'release' => '10.48', 'offset' => 45];
        yield 'capturing group of branches before the limit, 10.49' => ['pattern' => '/((?:a{2}){3400}|(?:b{2}){3400})x(?:cc){1500}/', 'php' => '8.5', 'release' => '10.49', 'offset' => 44];
    }
}
