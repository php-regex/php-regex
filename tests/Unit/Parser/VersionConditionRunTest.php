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

use PHPRegex\Tests\Support\LinearTimeAssertions;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * In a pattern refused further on, each version condition read before the
 * error is judged as PCRE judges it there: the ")" that ends a condition is
 * not searched for to the end of the pattern again for each condition that
 * no ")" closes.
 */
final class VersionConditionRunTest extends TestCase
{
    use LinearTimeAssertions;

    /**
     * Measured when it was: 500 then 2 000 "(?(VERSION" took 0.09 s then
     * 1.2 s, thirteen times as long for four times the text, and 9 600
     * took 24 s.
     */
    #[Test]
    #[DataProvider('provideRunsOfVersionConditions')]
    public function test_validate_judges_a_run_of_unclosed_version_conditions_in_linear_time(string $unit): void
    {
        $this->assertLinearTime(
            static function (int $size) use ($unit): void {
                Regex::create(['cache' => null])->validate('/'.str_repeat($unit, $size).'/');
            },
            500,
            $unit,
        );
    }

    /**
     * @return iterable<string, array{unit: string}>
     */
    public static function provideRunsOfVersionConditions(): iterable
    {
        yield 'conditions cut short' => ['unit' => '(?(VERSION'];
        yield 'conditions with a version and no ")"' => ['unit' => '(?(VERSION>=10.4'];
    }
}
