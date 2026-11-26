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

use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\Backtrack\LastCodeUnit;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosOptions;
use PHPRegex\Redos\RedosSearchCost;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Tests\Support\JitInAFreshProcess;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without u nor "(*UCP)", PCRE2 flags a first or a required code unit
 * caseless below 255 only: it looks for the byte 0xFF in its own case,
 * whatever other case the tables of the locale give it. Under
 * ru_RU.KOI8-R, whose tables pair 0xFF with 0xDF, "/a+\xff/i" finds no
 * match in "a\xdf", where "(*NO_START_OPT)" finds it; 0xFE keeps its other
 * case 0xDE, and 0xDF its other case 0xFF. Under fr_FR.ISO8859-15 0xFF is
 * paired with 0xBE, and looked for alone too. Under "(*UCP)" the other case
 * is Unicode's, whatever the tables; under u the character U+00FF is two
 * code units, the last one required only where it is case-sensitive.
 *
 * Checked on PHP 8.4.28 with PCRE2 10.49, the JIT on and off, under
 * ru_RU.KOI8-R and fr_FR.ISO8859-15; PCRE2 10.34 compares a code unit with
 * 255 alike, "(*UCP)" leaving the case of a byte to the tables there.
 */
final class LastCodeUnitLastByteTest extends TestCase
{
    use InstalledLocales;

    private const KOI8_R = 'ru_RU.KOI8-R';

    private const LATIN9 = 'fr_FR.ISO8859-15';

    /**
     * The cases of each locale, given under a name no machine sets.
     */
    private const GIVEN = [self::KOI8_R => 'xx_XX.LASTBYTEKOI8R', self::LATIN9 => 'xx_XX.LASTBYTELATIN9'];

    /**
     * The engine looks for the code unit the row gives, in either case
     * where the row gives another one: on the subject, which holds that
     * other case or the other case of the tables, the search finds what
     * it finds without its start-up optimizations only where it looks for
     * either. The JIT on, in a process of its own, and off.
     *
     * @param array{int, ?int}|null $expected
     */
    #[Test]
    #[DataProvider('provideLastBytes')]
    public function test_the_last_byte_is_looked_for_as_the_engine_reads_it_under_the_locale(string $locale, string $pattern, string $subject, int $found, int $matched, ?array $expected): void
    {
        $this->requiresLocale($locale, $pattern);

        $read = new \ArrayObject();
        CharacterTables::under($locale, static function () use ($pattern, $subject, $read): void {
            $read[] = preg_match(PcreInterpreter::pattern($pattern), $subject);
            $read[] = preg_match(PcreInterpreter::pattern(self::withoutStartOptimizations($pattern)), $subject);
        });
        $read[] = JitInAFreshProcess::matched($pattern, $subject, 0, $locale);
        $read[] = JitInAFreshProcess::matched(self::withoutStartOptimizations($pattern), $subject, 0, $locale);
        $this->assertSame([$found, $matched, $found, $matched], $read->getArrayCopy(), \sprintf('%s on %s under %s', $pattern, bin2hex($subject), $locale));

        $this->assertRequired($expected, $pattern, $locale);
    }

    /**
     * The same rows on every machine, under the cases of each locale given
     * under a name no machine sets.
     *
     * @param array{int, ?int}|null $expected
     */
    #[Test]
    #[DataProvider('provideLastBytes')]
    public function test_the_last_byte_is_looked_for_as_the_engine_reads_it_under_tables_that_pair_it(string $locale, string $pattern, string $subject, int $found, int $matched, ?array $expected): void
    {
        self::giveTables();

        $this->assertRequired($expected, $pattern, self::GIVEN[$locale]);
    }

    /**
     * @return iterable<string, array{locale: string, pattern: string, subject: string, found: int, matched: int, expected: array{int, ?int}|null}>
     */
    public static function provideLastBytes(): iterable
    {
        $koi8r = self::KOI8_R;

        yield 'the last byte, caseless' => ['locale' => $koi8r, 'pattern' => '/a+\xff/i', 'subject' => "a\xdf", 'found' => 0, 'matched' => 1, 'expected' => [0xFF, null]];
        yield 'the last byte, caseless, Latin-9' => ['locale' => self::LATIN9, 'pattern' => '/a+\xff/i', 'subject' => "a\xbe", 'found' => 0, 'matched' => 1, 'expected' => [0xFF, null]];
        yield 'the byte below, caseless' => ['locale' => $koi8r, 'pattern' => '/a+\xfe/i', 'subject' => "a\xde", 'found' => 1, 'matched' => 1, 'expected' => [0xFE, 0xDE]];
        yield 'the other case of the last byte, caseless' => ['locale' => $koi8r, 'pattern' => '/a+\xdf/i', 'subject' => "a\xff", 'found' => 1, 'matched' => 1, 'expected' => [0xDF, 0xFF]];
        yield 'the byte below, case-sensitive' => ['locale' => $koi8r, 'pattern' => '/a+\xfe/', 'subject' => "a\xde", 'found' => 0, 'matched' => 0, 'expected' => [0xFE, null]];
        // PCRE2 reads a class of a byte and its other case as the byte
        // first written, caseless.
        yield 'a class of the last byte and its other case' => ['locale' => $koi8r, 'pattern' => '/a[\xff\xdf]/', 'subject' => "a\xdf", 'found' => 0, 'matched' => 1, 'expected' => [0xFF, null]];
        yield 'a class of the byte below and its other case' => ['locale' => $koi8r, 'pattern' => '/a[\xfe\xde]/', 'subject' => "a\xde", 'found' => 1, 'matched' => 1, 'expected' => [0xFE, 0xDE]];
        // The first code unit of an alternative, required where the first
        // ones differ.
        yield 'the last byte first in one alternative' => ['locale' => $koi8r, 'pattern' => '/\xff|x\xff/i', 'subject' => "x\xdf", 'found' => 0, 'matched' => 1, 'expected' => [0xFF, null]];
        yield 'the last byte repeated in a group' => ['locale' => $koi8r, 'pattern' => '/x(?:\xff){2}/i', 'subject' => "x\xdf\xdf", 'found' => 0, 'matched' => 1, 'expected' => [0xFF, null]];
        // Under "(*UCP)" 0xFF is paired with U+0178, never with the byte of
        // the tables.
        yield 'the last byte under (*UCP)' => ['locale' => $koi8r, 'pattern' => '/(*UCP)a+\xff/i', 'subject' => "a\xdf", 'found' => 0, 'matched' => 0, 'expected' => [0xFF, 0x178]];
        // Under u, U+00FF is two code units: none is required caseless.
        yield 'U+00FF under u, caseless' => ['locale' => $koi8r, 'pattern' => '/a+\x{ff}/iu', 'subject' => "a\u{178}", 'found' => 1, 'matched' => 1, 'expected' => null];
        yield 'U+00FF under u' => ['locale' => $koi8r, 'pattern' => '/a+\x{ff}/u', 'subject' => "a\u{ff}", 'found' => 1, 'matched' => 1, 'expected' => [0xFF, null]];
    }

    /**
     * PCRE2 10.34 flags a code unit caseless below 255 only, as later
     * releases do, and reads the case of a byte from the tables under
     * "(*UCP)" too (pcre2_compile.c of PHP 7.4.10, "reqcu < 255"): 0xFF
     * is looked for alone there, 0xFE in either case.
     *
     * @param array{int, ?int} $expected
     */
    #[Test]
    #[DataProvider('provideLastBytesOfPcre2With1034')]
    public function test_the_last_byte_is_looked_for_alone_under_ucp_before_pcre2_10_35(string $pattern, array $expected): void
    {
        self::giveTables();
        $regex = RegexParser::create(['cache' => null])->parse($pattern);
        $target = new PcreTarget(70400, '10.34', self::GIVEN[self::KOI8_R]);

        $this->assertSame($expected, LastCodeUnit::required($regex, $target), $pattern.' on PCRE2 10.34');
        $this->assertSame([$expected[0], null !== $expected[1]], LastCodeUnit::read($regex, $target), $pattern.' on PCRE2 10.34');
    }

    /**
     * @return iterable<string, array{pattern: string, expected: array{int, ?int}}>
     */
    public static function provideLastBytesOfPcre2With1034(): iterable
    {
        yield 'the last byte' => ['pattern' => '/(*UCP)a+\xff/i', 'expected' => [0xFF, null]];
        yield 'the byte below' => ['pattern' => '/(*UCP)a+\xfe/i', 'expected' => [0xFE, 0xDE]];
    }

    /**
     * The search-cost witness holds 0xFF itself where the run holds its
     * other case only: without it the engine tries no attempt, and finds
     * no match where "(*NO_START_OPT)" finds one.
     */
    #[Test]
    #[DataProvider('provideSearchesRequiringTheLastByte')]
    public function test_the_search_cost_witness_holds_the_last_byte_itself(string $pattern, string $subject): void
    {
        $this->requiresLocale(self::KOI8_R, $pattern);

        $read = new \ArrayObject();
        CharacterTables::under(self::KOI8_R, static function () use ($pattern, $subject, $read): void {
            $read[] = preg_match(PcreInterpreter::pattern($pattern), $subject);
            $read[] = preg_match(PcreInterpreter::pattern(self::withoutStartOptimizations($pattern)), $subject);
        });
        $read[] = JitInAFreshProcess::matched($pattern, $subject, 0, self::KOI8_R);
        $read[] = JitInAFreshProcess::matched(self::withoutStartOptimizations($pattern), $subject, 0, self::KOI8_R);
        $this->assertSame([0, 1, 0, 1], $read->getArrayCopy(), \sprintf('%s on %s', $pattern, bin2hex($subject)));

        $analysis = Regex::create(['cache' => null, 'locale' => self::KOI8_R])->redos($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low));
        $cost = $analysis->searchCost;

        $this->assertInstanceOf(RedosSearchCost::class, $cost, $analysis->headline());
        $this->assertStringNotContainsString("\xff", $cost->run, $pattern);
        $this->assertStringContainsString("\xff", $cost->breaker, $pattern);
        $this->assertTrue($cost->replayed, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideSearchesRequiringTheLastByte(): iterable
    {
        yield 'the last byte after a repeat of its other case' => ['pattern' => '/\xdf+!\xff/i', 'subject' => "\xdf!\xdf"];
        yield 'a class of the last byte and its other case after one of both' => ['pattern' => '/[\xdf\xff]+![\xff\xdf]/', 'subject' => "\xdf!\xdf"];
    }

    /**
     * @param array{int, ?int}|null $expected
     */
    private function assertRequired(?array $expected, string $pattern, string $locale): void
    {
        $regex = RegexParser::create(['cache' => null])->parse($pattern);
        $target = new PcreTarget(\PHP_VERSION_ID, \PCRE_VERSION, $locale);

        $this->assertSame($expected, LastCodeUnit::required($regex, $target), $pattern.' under '.$locale);
        $this->assertSame(null === $expected ? null : [$expected[0], null !== $expected[1]], LastCodeUnit::read($regex, $target), $pattern.' under '.$locale);
    }

    /**
     * The pattern with "(*NO_START_OPT)" after its opening delimiter.
     */
    private static function withoutStartOptimizations(string $pattern): string
    {
        return $pattern[0].'(*NO_START_OPT)'.substr($pattern, 1);
    }

    /**
     * Gives the cases of ru_RU.KOI8-R, 0xC0 to 0xDF paired with 0xE0 to
     * 0xFF and 0xA3 with 0xB3, and those of fr_FR.ISO8859-15, 0xC0 to 0xDE
     * paired with 0xE0 to 0xFE but for 0xD7, and 0xFF with 0xBE, as macOS
     * builds them, under names no machine sets.
     */
    private static function giveTables(): void
    {
        $koi8r = [0xA3 => 0xB3];
        for ($byte = 0xC0; $byte <= 0xDF; $byte++) {
            $koi8r[$byte] = $byte + 0x20;
        }

        $latin9 = [0xA6 => 0xA8, 0xB4 => 0xB8, 0xBC => 0xBD, 0xBE => 0xFF];
        for ($byte = 0xC0; $byte <= 0xDE; $byte++) {
            if (0xD7 !== $byte) {
                $latin9[$byte] = $byte + 0x20;
            }
        }

        foreach ([self::KOI8_R => $koi8r, self::LATIN9 => $latin9] as $locale => $pairs) {
            $others = [];
            foreach ($pairs as $byte => $other) {
                $others[$byte] = $other;
                $others[$other] = $byte;
            }

            $cases = '';
            for ($byte = 0; $byte < 256; $byte++) {
                $ascii = ($byte >= 0x41 && $byte <= 0x5A) || ($byte >= 0x61 && $byte <= 0x7A) ? $byte ^ 0x20 : $byte;
                $cases .= \chr($others[$byte] ?? $ascii);
            }

            CharacterTables::useTables(self::GIVEN[$locale], $cases);
        }
    }
}
