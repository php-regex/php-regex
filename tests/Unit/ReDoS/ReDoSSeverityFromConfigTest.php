<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\ReDoS;

use PhpRegex\Parser\Exception\InvalidRegexOptionException;
use PhpRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The one reading of a configured ReDoS threshold, shared by every surface
 * that takes one: the four severities a finding can be reported from, in any
 * case; "safe" and "unknown" are verdicts, not thresholds.
 */
final class ReDoSSeverityFromConfigTest extends TestCase
{
    /**
     * @return iterable<string, array{string, RedosSeverity}>
     */
    public static function provideThresholds(): iterable
    {
        yield 'low' => ['low', RedosSeverity::Low];
        yield 'medium' => ['medium', RedosSeverity::Medium];
        yield 'high' => ['high', RedosSeverity::High];
        yield 'critical' => ['critical', RedosSeverity::Critical];
        yield 'upper case' => ['HIGH', RedosSeverity::High];
        yield 'mixed case' => ['Critical', RedosSeverity::Critical];
        yield 'mixed case inside the word' => ['mEdIuM', RedosSeverity::Medium];
    }

    #[Test]
    #[DataProvider('provideThresholds')]
    public function test_from_config_reads_a_threshold_in_any_case(string $value, RedosSeverity $expected): void
    {
        $this->assertSame($expected, RedosSeverity::fromConfig($value));
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

        RedosSeverity::fromConfig($value);
    }
}
