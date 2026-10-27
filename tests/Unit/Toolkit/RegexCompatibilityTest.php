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
use PHPRegex\Tests\Support\InstalledLocales;
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
    use InstalledLocales;

    /**
     * Each target is judged under the locale of the instance. Oracle: PHP
     * 8.4 (PCRE2 10.49) compiles "(?:[\xe9\xc9]){8000}" under
     * setlocale(LC_CTYPE, "fr_FR.ISO8859-1"), where é and É are each
     * other's case and the class one caseless character, and refuses it
     * under "C", "regular expression is too large at offset 0"; PCRE2 10.33
     * refuses it under both (see PatternTooLargeLocaleTest).
     *
     * @param bool $validFrom1034 whether the targets from PCRE2 10.34 accept the pattern
     */
    #[Test]
    #[DataProvider('provideLocales')]
    public function test_compatibility_judges_every_target_under_the_instance_locale(string $locale, bool $validFrom1034): void
    {
        if ('C' !== $locale) {
            $this->requiresLocale($locale, 'A repeated pair of Latin-1 letters');
        }

        $compatibility = Regex::create(['cache' => null, 'locale' => $locale])->compatibility('/(?:[\xe9\xc9]){8000}/');

        $this->assertNotSame([], $compatibility->verdicts());
        foreach ($compatibility->verdicts() as $verdict) {
            $label = \sprintf('PHP %d, PCRE2 %s: %s', $verdict->target->phpVersionId, $verdict->target->pcreVersion, (string) $verdict->validation->error);

            $this->assertSame($locale, $verdict->target->locale, $label);
            $this->assertSame($validFrom1034 && version_compare($verdict->target->pcreVersion, '10.34', '>='), $verdict->validation->isValid, $label);
        }
    }

    /**
     * @return iterable<string, array{locale: string, validFrom1034: bool}>
     */
    public static function provideLocales(): iterable
    {
        yield 'Latin-1, where the pair is one caseless character' => ['locale' => 'fr_FR.ISO8859-1', 'validFrom1034' => true];
        yield 'C, where it is a class' => ['locale' => 'C', 'validFrom1034' => false];
    }

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
