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

namespace PHPRegex\Tests\Unit\Toolkit;

use PHPRegex\Parser\Validation\CompatibilityChecker;
use PHPRegex\Parser\Validation\TargetVerdict;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regex::compatibility() is the one-line entry point to the compatibility
 * matrix: the checker's answer, whatever target the instance judges for,
 * and never a throw for the pattern's content.
 */
final class RegexCompatibilityTest extends TestCase
{
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_compatibility_is_the_checker_answer(string $regex): void
    {
        $this->assertEquals((new CompatibilityChecker())->check($regex), Regex::create()->compatibility($regex));
    }

    /**
     * @return iterable<string, array{regex: string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'valid everywhere' => ['regex' => '/abc/'];
        yield 'refused on some targets' => ['regex' => '/(?<=a\Kb)c/'];
        yield 'delimiter error' => ['regex' => 'abc'];
        yield 'unclosed group' => ['regex' => '/(a/'];
    }

    #[Test]
    public function test_compatibility_ignores_the_instance_target(): void
    {
        // An instance judging PHP 8.2 still reports the 8.4 targets where "r" is valid.
        $compatibility = Regex::create(['php_version' => '8.2'])->compatibility('/a/r');

        $valid = array_filter($compatibility->verdicts(), static fn (TargetVerdict $verdict): bool => $verdict->validation->isValid);

        $this->assertNotSame([], $valid);
        $this->assertEquals((new CompatibilityChecker())->check('/a/r'), $compatibility);
    }

    #[Test]
    public function test_compatibility_never_throws_for_a_pattern_no_target_accepts(): void
    {
        $compatibility = Regex::create()->compatibility('/(a/');

        $this->assertFalse($compatibility->isValidEverywhere());
        $this->assertEquals($compatibility->verdicts(), $compatibility->invalidVerdicts());
        $this->assertNotSame([], $compatibility->verdicts());
    }
}
