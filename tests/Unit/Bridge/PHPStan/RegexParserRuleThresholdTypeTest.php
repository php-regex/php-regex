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

namespace PHPRegex\Tests\Unit\Bridge\PHPStan;

use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\PHPStan\RegexPatternRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A threshold wired by hand as something else than a string is refused with
 * the parameter named, like a word that names no severity.
 */
final class RegexParserRuleThresholdTypeTest extends TestCase
{
    #[Test]
    public function test_rule_refuses_a_threshold_that_is_no_string(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('checks.redos.threshold');

        new RegexPatternRule(['checks' => ['redos' => ['enabled' => true, 'threshold' => 3]]]);
    }

    #[Test]
    public function test_rule_reads_an_unset_threshold_as_critical(): void
    {
        $rule = new RegexPatternRule(['checks' => ['redos' => ['enabled' => true]]]);

        $this->assertSame('critical', (new \ReflectionProperty($rule, 'redosThreshold'))->getValue($rule));
    }
}
