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

namespace RegexParser\Tests\Unit\ReDoS;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Exception\InvalidRegexOptionException;
use RegexParser\ReDoS\ReDoSSeverity;

/**
 * The one reading of a configured ReDoS threshold, shared by every surface
 * that takes one: the four severities a finding can be reported from, in any
 * case; "safe" and "unknown" are verdicts, not thresholds.
 */
final class ReDoSSeverityFromConfigTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ReDoSSeverity}>
     */
    public static function provideThresholds(): iterable
    {
        yield 'low' => ['low', ReDoSSeverity::LOW];
        yield 'medium' => ['medium', ReDoSSeverity::MEDIUM];
        yield 'high' => ['high', ReDoSSeverity::HIGH];
        yield 'critical' => ['critical', ReDoSSeverity::CRITICAL];
        yield 'upper case' => ['HIGH', ReDoSSeverity::HIGH];
        yield 'mixed case' => ['Critical', ReDoSSeverity::CRITICAL];
        yield 'mixed case inside the word' => ['mEdIuM', ReDoSSeverity::MEDIUM];
    }

    #[Test]
    #[DataProvider('provideThresholds')]
    public function test_from_config_reads_a_threshold_in_any_case(string $value, ReDoSSeverity $expected): void
    {
        $this->assertSame($expected, ReDoSSeverity::fromConfig($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRefusedThresholds(): iterable
    {
        yield 'safe is a verdict' => ['safe'];
        yield 'unknown is a verdict' => ['unknown'];
        yield 'safe in upper case' => ['SAFE'];
        yield 'unknown in mixed case' => ['Unknown'];
        yield 'a word that is no severity' => ['severe'];
        yield 'empty string' => [''];
        yield 'a number' => ['3'];
    }

    #[Test]
    #[DataProvider('provideRefusedThresholds')]
    public function test_from_config_refuses_what_is_no_threshold(string $value): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"'.$value.'"');

        ReDoSSeverity::fromConfig($value);
    }
}
