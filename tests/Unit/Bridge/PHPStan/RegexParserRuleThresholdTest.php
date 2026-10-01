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

namespace PhpRegex\Tests\Unit\Bridge\PHPStan;

use PhpRegex\Parser\Exception\InvalidRegexOptionException;
use PhpRegex\PHPStan\RegexPatternRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rule reads "checks.redos.threshold" with the one threshold parser: a
 * value that names no threshold stops PHPStan when the rule is built, as an
 * unreadable version does, instead of silently becoming "critical". The neon
 * schema already refuses such a value; the rule built from an array (as
 * tests and custom wiring do) must not be more lenient.
 */
final class RegexParserRuleThresholdTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRefusedThresholds(): iterable
    {
        yield 'a word that is no severity' => ['severe'];
        yield 'safe is a verdict' => ['safe'];
        yield 'unknown is a verdict' => ['unknown'];
    }

    #[Test]
    #[DataProvider('provideRefusedThresholds')]
    public function test_rule_refuses_a_threshold_that_names_no_severity(string $threshold): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"'.$threshold.'"');

        new RegexPatternRule(['checks' => ['redos' => ['enabled' => true, 'threshold' => $threshold]]]);
    }

    #[Test]
    public function test_rule_refuses_an_unknown_threshold_even_with_redos_off(): void
    {
        // A typo in a switched-off section is still a typo: it would bite
        // the day the section is switched on.
        $this->expectException(InvalidRegexOptionException::class);

        new RegexPatternRule(['checks' => ['redos' => ['enabled' => false, 'threshold' => 'severe']]]);
    }
}
