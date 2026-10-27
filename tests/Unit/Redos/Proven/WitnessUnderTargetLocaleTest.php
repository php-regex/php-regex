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

namespace PHPRegex\Tests\Unit\Redos\Proven;

use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A witness is asked of the engine under the tables of the target's
 * locale, whatever the locale of the process that analyses it. Under
 * fr_FR.ISO8859-1, "\xE9" is a word character, a letter, and the small
 * "\xC9": with the JIT off, 30 "\xE9" and a "!" exhaust the backtrack limit
 * of PHP 8.4.27 / PCRE2 10.49 on each pattern below under that locale, and
 * run at once under C.
 */
final class WitnessUnderTargetLocaleTest extends TestCase
{
    use InstalledLocales;

    private const LATIN1 = 'fr_FR.ISO8859-1';

    #[Test]
    #[RunInSeparateProcess]
    #[DataProvider('provideExponentialUnderTheLocale')]
    public function test_the_rows_hold_on_the_engine(string $pattern): void
    {
        $this->requiresLocale(self::LATIN1, $pattern);

        $previous = (string) setlocale(\LC_CTYPE, '0');
        $jit = (string) \ini_get('pcre.jit');
        ini_set('pcre.jit', '0');

        try {
            // C first: once a locale was named, its tables stay. The engine
            // gives up under the Latin-1 locale only.
            foreach (['C' => [0, \PREG_NO_ERROR], self::LATIN1 => [false, \PREG_BACKTRACK_LIMIT_ERROR]] as $locale => [$result, $error]) {
                $this->assertNotFalse(setlocale(\LC_CTYPE, $locale));
                $this->assertSame($result, @preg_match($pattern, str_repeat("\xE9", 30).'!'), $pattern.' under '.$locale);
                $this->assertSame($error, preg_last_error(), $pattern.' under '.$locale);
            }
        } finally {
            setlocale(\LC_CTYPE, $previous);
            ini_set('pcre.jit', $jit);
        }
    }

    #[Test]
    #[DataProvider('provideExponentialUnderTheLocale')]
    public function test_the_witness_replays_under_the_target_locale(string $pattern): void
    {
        $this->requiresLocale(self::LATIN1, $pattern);

        $analysis = Regex::create(['cache' => null, 'locale' => self::LATIN1])->redos($pattern, RedosSeverity::Low, RedosMode::Confirmed);

        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity, $analysis->headline());
        $this->assertSame(RedosProof::Proven, $analysis->proof, $analysis->headline());
        $this->assertTrue($analysis->replayed, $analysis->headline());
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideExponentialUnderTheLocale(): iterable
    {
        yield 'a word character or "\xE9"' => ['pattern' => '/^(?:\w|\xE9)+$/'];
        yield '"\xE9" or its capital, caseless' => ['pattern' => '/^(?:\xE9|\xC9)+$/i'];
        // The lookahead is asked of the engine before the witness counts.
        yield 'a letter ahead of the loop' => ['pattern' => '/^(?=[[:alpha:]])(?:\w|\xE9)+$/'];
    }
}
