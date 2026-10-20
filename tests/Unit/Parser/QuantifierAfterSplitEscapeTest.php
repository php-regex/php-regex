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
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Some escapes are read as a character then text: "\10000" is the octal
 * "\100" then "00", and on PHP 7.3 and 7.4 "\g{-1}" with no group before it
 * is "g" then "{-1}". A quantifier after them repeats the last character of
 * the text, and a second quantifier after that one repeats nothing.
 *
 * Offsets are preg_match()'s on PHP 7.3 (PCRE2 10.32), 8.0 (10.35), 8.2
 * (10.40), 8.3 (10.42), 8.4 (10.44) and on PCRE2 10.49, one past the
 * quantifier's start from 10.47.
 */
final class QuantifierAfterSplitEscapeTest extends TestCase
{
    /**
     * @param array<int, int> $offsets the offset for each PHP version
     */
    #[Test]
    #[DataProvider('provideRepeatedQuantifiers')]
    public function test_a_second_quantifier_after_the_text_repeats_nothing(string $pattern, array $offsets): void
    {
        foreach ($offsets as $phpVersion => $offset) {
            $result = Regex::create(['cache' => null, 'php_version' => $phpVersion])->validate($pattern);
            $said = \sprintf('%s on PHP %d: %s', $pattern, $phpVersion, (string) $result->error);

            $this->assertFalse($result->isValid, $said);
            $this->assertSame(ErrorCode::QuantifierNothingToRepeat, $result->errorCode, $said);
            $this->assertSame($offset, $result->offset, $said);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, offsets: array<int, int>}>
     */
    public static function provideRepeatedQuantifiers(): iterable
    {
        $all = static fn (int $offset): array => [70300 => $offset, 80000 => $offset, 80200 => $offset, 80300 => $offset, 80400 => $offset];

        // "quantifier does not follow a repeatable item".
        yield 'two quantifiers after the digits of an octal escape' => ['pattern' => '/\\10000**/', 'offsets' => $all(7)];
        yield 'a count then a quantifier after the digits of an octal escape' => ['pattern' => '/\\10000{2}*/', 'offsets' => $all(9)];
        // PCRE2 10.37 to 10.44 read a number past 214748363 as no reference.
        yield 'two quantifiers after the digits of a number too big' => ['pattern' => '/\\8888888888**/', 'offsets' => [80200 => 12, 80300 => 12, 80400 => 12]];
        // PHP 7.3 reads "\g{-1}" with no group before it as "g" then "{-1}",
        // and "\400" without UTF as "4" then "00".
        yield 'a count then a quantifier after a relative reference read as text' => ['pattern' => '/\\g{-1}{1,3}*/', 'offsets' => [70300 => 11]];
        yield 'two quantifiers after a relative reference read as text' => ['pattern' => '/\\g{-12}*{2}/', 'offsets' => [70300 => 10]];
        yield 'two quantifiers after an octal escape read as text' => ['pattern' => '/\\4000**/', 'offsets' => [70300 => 6]];
        // The quantifier is a syntax error, met before the reference to a
        // missing name, which PCRE2 resolves once the pattern is read.
        yield 'before a reference to a missing name' => ['pattern' => '/\\g{-1}\\k<n>*?\\g{-1}{1,3}*/', 'offsets' => [70300 => 24]];
    }

    /**
     * @param array<int, int> $offsets the offset for each PHP version
     */
    #[Test]
    #[DataProvider('provideRepeatedQuantifiers')]
    public function test_the_running_engine_agrees(string $pattern, array $offsets): void
    {
        $warning = null;
        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });
        $compiles = false !== preg_match($pattern, '');
        restore_error_handler();

        // PHP 7.3 and 7.4 alone read "\g{-1}" or "\400" in parts.
        if ($compiles || !isset($offsets[80400])) {
            $this->assertSame($compiles, Regex::create(['cache' => null])->validate($pattern)->isValid, $pattern);

            return;
        }

        $this->assertIsString($warning);
        $this->assertSame(1, preg_match('/at offset (\d+)/', $warning, $match), $warning);
        $this->assertSame((int) $match[1], Regex::create(['cache' => null])->validate($pattern)->offset, $pattern);
    }

    /**
     * A single quantifier, or one made lazy or possessive, is valid.
     */
    #[Test]
    public function test_one_quantifier_after_the_text_is_valid(): void
    {
        foreach (['/\\10000*/', '/\\10000*+/', '/\\10000+?/', '/\\10000{2}?a/'] as $pattern) {
            $this->assertNotFalse(@preg_match($pattern, ''), $pattern);
            $this->assertTrue(Regex::create(['cache' => null])->validate($pattern)->isValid, $pattern);
        }

        foreach (['/\\g{-1}{1,3}?/', '/\\g{-1}*/'] as $pattern) {
            $this->assertTrue(Regex::create(['cache' => null, 'php_version' => 70300])->validate($pattern)->isValid, $pattern);
        }
    }
}
