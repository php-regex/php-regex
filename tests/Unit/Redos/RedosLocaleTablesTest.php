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
 * leave unknown what the tables decide: "\w" and the cases. Each pattern
 * captures "x", then two loops that may share the bytes of the subject,
 * then refers back to "x", which the subject does not repeat: where the
 * loops meet, the engine tries each split of the run, and the steps grow
 * with its square. The engine is run under the row's locale on "x" and 8,
 * 16 and 32 times the byte, or the letter under u.
 *
 * Engine: PHP 8.4.28 / PCRE2 10.49 on macOS, "/^(x)[^a]+\xC3+\1$/" with a
 * raw 0xC3 takes 31, 123 and 499 steps under C and under fr_FR.ISO8859-1;
 * "/^(x)\w+\xE9+\1$/" with a raw 0xE9 takes a few steps under C, where
 * "\w" leaves 0xE9 out, and grows the same way under the Latin-1 tables.
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

        $severity = Regex::create(['cache' => null, 'locale' => $locale])->redos($pattern)->severity;
        $this->assertSame($meet ? RedosSeverity::Medium : RedosSeverity::Safe, $severity, \sprintf('%s under %s', addcslashes($pattern, "\200..\377"), $locale));
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
        ];

        foreach ($rows as $name => [$pattern, $unit, $meetUnderC, $meetUnderLatin1]) {
            yield $name.', C' => ['pattern' => $pattern, 'unit' => $unit, 'locale' => CharacterTables::BUILT_IN, 'meet' => $meetUnderC];
            yield $name.', '.self::LATIN1 => ['pattern' => $pattern, 'unit' => $unit, 'locale' => self::LATIN1, 'meet' => $meetUnderLatin1];
        }
    }
}
