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

namespace PHPRegex\Tests\Unit\Redos;

use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Redos\RedosConfidence;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Tests\Support\PcreAttemptSteps;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Under another locale's tables than PCRE2's own, the heuristics read the
 * bytes above ASCII a pattern writes and those a negated class takes, and
 * what the tables decide, the bytes of "\d", "\s" and "\w" and the cases,
 * as those tables give them. Each pattern captures "x", then two loops
 * that may share the bytes of the subject, then refers back to "x", which
 * the subject does not repeat: where the loops meet, the engine tries each
 * split of the run, and the steps grow with its square. The engine is run
 * under the row's locale on "x" and 8, 16 and 32 times the byte, or the
 * letter under u.
 *
 * Engine: PHP 8.4.28 / PCRE2 10.49 on macOS, "/^(x)[^a]+\xC3+\1$/" with a
 * raw 0xC3 takes 31, 123 and 499 steps under C and under fr_FR.ISO8859-1;
 * "/^(x)\w+\xE9+\1$/" with a raw 0xE9 takes a few steps under C, where
 * "\w" leaves 0xE9 out, and grows the same way under the Latin-1 tables,
 * where "/^(x)\W+\xE9+\1$/" takes 3 steps whatever the run; the same loops
 * bounded at 999, "/^(x)\w{1,999}\xE9{1,999}\1$/", take 31, 123 and 499
 * steps there too. A POSIX class reads the same tables: under C
 * "/^(x)[[:alpha:]]+\xE9+\1$/" takes a few steps, and grows the same way
 * under the Latin-1 tables, which put 0xE9 in "[:alpha:]".
 */
final class RedosLocaleTablesTest extends TestCase
{
    use InstalledLocales;

    private const LATIN1 = 'fr_FR.ISO8859-1';

    #[Test]
    #[DataProvider('provideLoopsUnderEachTables')]
    public function test_the_loops_meet_as_the_tables_of_the_target_locale_say(string $pattern, string $unit, string $locale, bool $meet): void
    {
        if (CharacterTables::BUILT_IN !== $locale) {
            $this->requiresLocale($locale, $pattern);
        }

        $steps = [];
        CharacterTables::under($locale, static function () use ($pattern, $unit, &$steps): void {
            foreach ([8, 16, 32] as $units) {
                $steps[] = PcreAttemptSteps::steps(PcreAttemptSteps::pinned($pattern), 'x'.str_repeat($unit, $units));
            }
        });
        $this->assertSame($meet, $steps[2] >= 3 * $steps[1] && $steps[1] >= 3 * $steps[0], \sprintf('Oracle: %s under %s takes %s steps.', addcslashes($pattern, "\200..\377"), $locale, implode(', ', $steps)));

        $analysis = Regex::create(['cache' => null, 'locale' => $locale])->redos($pattern);
        $this->assertSame($meet ? RedosSeverity::Medium : RedosSeverity::Safe, $analysis->severity, \sprintf('%s under %s', addcslashes($pattern, "\200..\377"), $locale));
        // The overlap is known: the finding of two loops that meet is not
        // one of low confidence.
        if ($meet) {
            $this->assertSame(RedosConfidence::Medium, $analysis->confidence, \sprintf('%s under %s', addcslashes($pattern, "\200..\377"), $locale));
        }
    }

    /**
     * Two loops bounded below 1,000 that meet are low, and safe where they
     * are known apart: under another locale's tables, whether they meet is
     * read from those tables, the bytes of "\w", "\s" and "\d" included.
     * The engine is run as above, and decides whether the loops meet.
     */
    #[Test]
    #[DataProvider('provideBoundedLoopsUnderEachTables')]
    public function test_bounded_loops_meet_as_the_tables_of_the_target_locale_say(string $pattern, string $unit, string $locale, bool $meet): void
    {
        if (CharacterTables::BUILT_IN !== $locale) {
            $this->requiresLocale($locale, $pattern);
        }

        $steps = [];
        CharacterTables::under($locale, static function () use ($pattern, $unit, &$steps): void {
            foreach ([8, 16, 32] as $units) {
                $steps[] = PcreAttemptSteps::steps(PcreAttemptSteps::pinned($pattern), 'x'.str_repeat($unit, $units));
            }
        });
        $label = \sprintf('%s under %s, where the engine takes %s steps', addcslashes($pattern, "\200..\377"), $locale, implode(', ', $steps));
        $this->assertSame($meet, $steps[2] >= 3 * $steps[1] && $steps[1] >= 3 * $steps[0], 'Oracle: '.$label);

        $analysis = Regex::create(['cache' => null, 'locale' => $locale])->redos($pattern);
        $this->assertSame($meet ? RedosSeverity::Low : RedosSeverity::Safe, $analysis->severity, $label);
    }

    /**
     * @return iterable<string, array{pattern: string, unit: string, locale: string, meet: bool}>
     */
    public static function provideBoundedLoopsUnderEachTables(): iterable
    {
        $rows = [
            'a raw letter after a word character' => ["/^(x)\\w{1,999}\xE9{1,999}\\1$/", "\xE9", false, true],
            'a raw letter after no word character' => ["/^(x)\\W{0,999}\xE9{0,999}\\1$/", "\xE9", true, false],
            'a raw sign after no word character' => ["/^(x)\\W{0,999}\xD7{0,999}\\1$/", "\xD7", true, true],
            'a raw no-break space after no space' => ["/^(x)\\S{0,999}\xA0{0,999}\\1$/", "\xA0", true, true],
            'a raw superscript after a digit' => ["/^(x)\\d{1,999}\xB2{1,999}\\1$/", "\xB2", false, false],
            'a raw letter after a POSIX class of letters' => ["/^(x)[[:alpha:]]{1,999}\xE9{1,999}\\1$/", "\xE9", false, true],
            'a raw letter after a negated POSIX class of letters' => ["/^(x)[[:^alpha:]]{0,999}\xE9{0,999}\\1$/", "\xE9", true, false],
        ];

        foreach ($rows as $name => [$pattern, $unit, $meetUnderC, $meetUnderLatin1]) {
            yield $name.', C' => ['pattern' => $pattern, 'unit' => $unit, 'locale' => CharacterTables::BUILT_IN, 'meet' => $meetUnderC];
            yield $name.', '.self::LATIN1 => ['pattern' => $pattern, 'unit' => $unit, 'locale' => self::LATIN1, 'meet' => $meetUnderLatin1];
        }
    }

    /**
     * @return iterable<string, array{pattern: string, unit: string, locale: string, meet: bool}>
     */
    public static function provideLoopsUnderEachTables(): iterable
    {
        $rows = [
            'a raw byte after a negated class' => ["/^(x)[^a]+\xC3+\\1$/", "\xC3", true, true],
            'an escape after a negated class' => ['/^(x)[^a]+\xC3+\1$/', "\xC3", true, true],
            'a raw byte after a negated raw letter' => ["/^(x)[^\xE9]+\x90+\\1$/", "\x90", true, true],
            'a raw byte after a negated letter of two bytes' => ["/^(x)[^é]+\x90+\\1$/", "\x90", true, true],
            'the byte a negated class leaves out' => ["/^(x)[^\xC3]+\xC3+\\1$/", "\xC3", false, false],
            'a raw letter after a word character' => ["/^(x)\\w+\xE9+\\1$/", "\xE9", false, true],
            'a raw small letter after its capital, caseless' => ["/^(x)\xC9+\xE9+\\1$/i", "\xE9", false, true],
            'a letter after a negated class under u' => ['/^(x)[^a]+é+\1$/u', 'é', true, true],
            'a letter after a space under u' => ['/^(x)\s+é+\1$/u', 'é', false, false],
            'a raw letter after no word character' => ["/^(x)\\W+\xE9+\\1$/", "\xE9", true, false],
            'a raw sign after no word character' => ["/^(x)\\W+\xD7+\\1$/", "\xD7", true, true],
            'a raw letter after a class of word characters' => ["/^(x)[\\w]+\xE9+\\1$/", "\xE9", false, true],
            'a raw letter after a negated class of word characters' => ["/^(x)[^\\w]+\xE9+\\1$/", "\xE9", true, false],
            'a raw no-break space after a space' => ["/^(x)\\s+\xA0+\\1$/", "\xA0", false, false],
            'a raw no-break space after no space' => ["/^(x)\\S+\xA0+\\1$/", "\xA0", true, true],
            'a raw superscript after a digit' => ["/^(x)\\d+\xB2+\\1$/", "\xB2", false, false],
            'a raw superscript after no digit' => ["/^(x)\\D+\xB2+\\1$/", "\xB2", true, true],
            'a raw letter after a POSIX class of letters' => ["/^(x)[[:alpha:]]+\xE9+\\1$/", "\xE9", false, true],
            'an escape after a POSIX class of letters' => ['/^(x)[[:alpha:]]+\xE9+\1$/', "\xE9", false, true],
            'a raw letter after a negated POSIX class of letters' => ["/^(x)[[:^alpha:]]+\xE9+\\1$/", "\xE9", true, false],
            'a raw sign after a negated POSIX class of letters' => ["/^(x)[[:^alpha:]]+\xD7+\\1$/", "\xD7", true, true],
            'a raw letter after a negated class of a POSIX class' => ["/^(x)[^[:alpha:]]+\xE9+\\1$/", "\xE9", true, false],
            'a raw letter after a POSIX class in a larger class' => ["/^(x)[\\d[:alpha:]]+\xE9+\\1$/", "\xE9", false, true],
            'a raw small letter after the POSIX capitals' => ["/^(x)[[:upper:]]+\xE9+\\1$/", "\xE9", false, false],
            'a raw small letter after the POSIX capitals, caseless' => ["/^(x)[[:upper:]]+\xE9+\\1$/i", "\xE9", false, true],
        ];

        foreach ($rows as $name => [$pattern, $unit, $meetUnderC, $meetUnderLatin1]) {
            yield $name.', C' => ['pattern' => $pattern, 'unit' => $unit, 'locale' => CharacterTables::BUILT_IN, 'meet' => $meetUnderC];
            yield $name.', '.self::LATIN1 => ['pattern' => $pattern, 'unit' => $unit, 'locale' => self::LATIN1, 'meet' => $meetUnderLatin1];
        }
    }

    /**
     * Under a locale's tables the machine reads, a caseless item takes the
     * other case those tables give, an ASCII letter's included: two loops
     * meet as the engine runs them under that locale, and the finding of
     * two that meet is no longer one of low confidence, the overlap being
     * known. The engine is run as above, and decides whether the loops
     * meet; the analysis must agree with it.
     */
    #[Test]
    #[DataProvider('provideCaselessLoopsUnderEachTables')]
    public function test_caseless_loops_meet_as_the_cases_of_the_target_locale_say(string $pattern, string $unit, string $locale): void
    {
        if (CharacterTables::BUILT_IN !== $locale) {
            $this->requiresLocale($locale, $pattern);
        }

        $steps = [];
        CharacterTables::under($locale, static function () use ($pattern, $unit, &$steps): void {
            foreach ([8, 16, 32] as $units) {
                $steps[] = PcreAttemptSteps::steps(PcreAttemptSteps::pinned($pattern), 'x'.str_repeat($unit, $units));
            }
        });
        $meet = $steps[2] >= 3 * $steps[1] && $steps[1] >= 3 * $steps[0];
        $this->assertTrue($meet || $steps[0] === $steps[2], \sprintf('Oracle: %s under %s takes %s steps.', addcslashes($pattern, "\200..\377"), $locale, implode(', ', $steps)));

        $analysis = Regex::create(['cache' => null, 'locale' => $locale])->redos($pattern);
        $label = \sprintf('%s under %s, where the engine takes %s steps', addcslashes($pattern, "\200..\377"), $locale, implode(', ', $steps));
        $this->assertSame($meet ? RedosSeverity::Medium : RedosSeverity::Safe, $analysis->severity, $label);
        if ($meet) {
            $this->assertSame(RedosConfidence::Medium, $analysis->confidence, $label);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, unit: string, locale: string}>
     */
    public static function provideCaselessLoopsUnderEachTables(): iterable
    {
        $rows = [
            'a raw small letter after its capital' => ["/^(x)\xC9+\xE9+\\1$/i", "\xE9"],
            'a raw small letter after another capital' => ["/^(x)\xC9+\xEA+\\1$/i", "\xEA"],
            'a capital after its small letter, written as escapes' => ['/^(x)\xE9+\xC9+\1$/i', "\xC9"],
            'an ASCII capital after its small letter' => ['/^(x)a+A+\1$/i', 'A'],
            'an ASCII capital after another small letter' => ['/^(x)a+B+\1$/i', 'B'],
            'an ASCII capital after its small letter under u' => ['/^(x)a+A+\1$/iu', 'A'],
            'an ASCII capital after another small letter under u' => ['/^(x)a+B+\1$/iu', 'B'],
            'an ASCII capital after another small letter under UCP' => ['/(*UCP)^(x)a+B+\1$/i', 'B'],
            'an ASCII capital after the class that leaves its pair out' => ['/^(x)[^a]+A+\1$/i', 'A'],
            'a raw capital after the class that leaves its small letter out' => ["/^(x)[^\xE9]+\xC9+\\1$/i", "\xC9"],
        ];

        foreach ([CharacterTables::BUILT_IN, self::LATIN1, 'el_GR.ISO8859-7', 'en_US.UTF-8'] as $locale) {
            foreach ($rows as $name => [$pattern, $unit]) {
                yield $name.', '.$locale => ['pattern' => $pattern, 'unit' => $unit, 'locale' => $locale];
            }
        }
    }
}
