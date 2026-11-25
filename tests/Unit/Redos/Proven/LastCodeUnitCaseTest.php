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

use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\Backtrack\LastCodeUnit;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without u, the other case of a byte past ASCII is the locale's, and under
 * "(*UCP)" Unicode's: PCRE2 then looks for the last code unit it requires in
 * either case. Every row is pcre2test 10.49's "Last code unit" for the
 * pattern, with "locale=" for the locale rows: "\xe9 (caseless)" for
 * /a+\xe9/i under fr_FR.ISO8859-1 and under (*UCP), "\xe9" under C; "\xff"
 * under the Latin-1 locale, which gives it no capital, and "\xff
 * (caseless)" under (*UCP), whose capital U+0178 is past a byte; none for
 * "\xb5" under (*UCP), whose case set holds three characters, nor for "k";
 * "\xdf (caseless)" under (*UCP), with U+1E9E.
 */
final class LastCodeUnitCaseTest extends TestCase
{
    use InstalledLocales;

    #[Test]
    #[DataProvider('provideCaseRows')]
    public function test_the_last_code_unit_is_caseless_as_pcre2_reads_it(string $pattern, string $locale, ?int $expected, ?int $otherCase): void
    {
        if ('C' !== $locale) {
            $this->requiresLocale($locale, $pattern);
        }

        $regex = RegexParser::create(['cache' => null])->parse($pattern);
        $target = new PcreTarget(\PHP_VERSION_ID, \PCRE_VERSION, $locale);

        $this->assertSame(null === $expected ? null : [$expected, $otherCase], LastCodeUnit::required($regex, $target), $pattern.' under '.$locale);
        $this->assertSame(null === $expected ? null : [$expected, null !== $otherCase], LastCodeUnit::read($regex, $target), $pattern.' under '.$locale);
    }

    /**
     * @return iterable<string, array{pattern: string, locale: string, expected: ?int, otherCase: ?int}>
     */
    public static function provideCaseRows(): iterable
    {
        $latin1 = 'fr_FR.ISO8859-1';

        yield 'a byte past ASCII, C' => ['pattern' => '/a+\xe9/i', 'locale' => 'C', 'expected' => 0xE9, 'otherCase' => null];
        yield 'a byte past ASCII, Latin-1' => ['pattern' => '/a+\xe9/i', 'locale' => $latin1, 'expected' => 0xE9, 'otherCase' => 0xC9];
        yield 'a capital past ASCII, Latin-1' => ['pattern' => '/x\xc9/i', 'locale' => $latin1, 'expected' => 0xC9, 'otherCase' => 0xE9];
        yield 'a byte without a capital, Latin-1' => ['pattern' => '/a+\xff/i', 'locale' => $latin1, 'expected' => 0xFF, 'otherCase' => null];
        yield 'a sign, Latin-1' => ['pattern' => '/x\xd7/i', 'locale' => $latin1, 'expected' => 0xD7, 'otherCase' => null];
        yield 'a byte, case-sensitive, Latin-1' => ['pattern' => '/x\xe9/', 'locale' => $latin1, 'expected' => 0xE9, 'otherCase' => null];
        yield 'a class of both cases, Latin-1' => ['pattern' => '/x[\xe9\xc9]/', 'locale' => $latin1, 'expected' => 0xE9, 'otherCase' => 0xC9];
        yield 'a class of both cases, C' => ['pattern' => '/x[\xe9\xc9]/', 'locale' => 'C', 'expected' => null, 'otherCase' => null];
        yield 'a byte past ASCII, UCP' => ['pattern' => '/(*UCP)a+\xe9/i', 'locale' => 'C', 'expected' => 0xE9, 'otherCase' => 0xC9];
        yield 'a class of both cases, UCP' => ['pattern' => '/(*UCP)x[\xe9\xc9]/', 'locale' => 'C', 'expected' => 0xE9, 'otherCase' => 0xC9];
        yield 'a capital past a byte, UCP' => ['pattern' => '/(*UCP)a+\xff/i', 'locale' => 'C', 'expected' => 0xFF, 'otherCase' => 0x178];
        yield 'the sharp s, UCP' => ['pattern' => '/(*UCP)x\xdf/i', 'locale' => 'C', 'expected' => 0xDF, 'otherCase' => 0x1E9E];
        yield 'a sign, UCP' => ['pattern' => '/(*UCP)x\xd7/i', 'locale' => 'C', 'expected' => 0xD7, 'otherCase' => null];
        yield 'an ordinal, UCP' => ['pattern' => '/(*UCP)x\xaa/i', 'locale' => 'C', 'expected' => 0xAA, 'otherCase' => null];
        yield 'an ASCII letter, UCP' => ['pattern' => '/(*UCP)xA/i', 'locale' => 'C', 'expected' => 0x41, 'otherCase' => 0x61];
        yield 'the micro sign, UCP' => ['pattern' => '/(*UCP)a+\xb5/i', 'locale' => 'C', 'expected' => null, 'otherCase' => null];
        yield 'a k, UCP' => ['pattern' => '/(*UCP)xk/i', 'locale' => 'C', 'expected' => null, 'otherCase' => null];
        yield 'an s, UCP' => ['pattern' => '/(*UCP)a+s/i', 'locale' => 'C', 'expected' => null, 'otherCase' => null];
    }

    /**
     * Before PCRE2 10.35, "(*UCP)" without u leaves the case of every byte
     * to the tables, as without it: none past ASCII under C, so the last
     * code unit is case-sensitive there, a class of a byte and its Unicode
     * capital stays a class, and "k", "s" and "\xb5", whose case sets hold
     * three characters from 10.35, are characters of their own with the
     * tables' case (pcre2_compile.c reads a case set under UTF alone
     * there). Engine: on PCRE2 10.34 (php:7.4.10) and 10.49,
     * "/(*NO_JIT)(*UCP)^(?:a|a)+" then the item, run on 30 "a", "!" and a
     * unit, fails at once where the subject lacks the unit it requires, in
     * either case where it looks for either, and gives up where it
     * requires none: 10.34 fails at once without "\xe9" for "\xe9", "\xc9"
     * included, and without "\xb5" for "\xb5", gives up on "[\xe9\xc9]"
     * without either, and fails at once without "k" or "K" for "k".
     */
    #[Test]
    #[DataProvider('provideRowsOfPcre2With1034')]
    public function test_before_pcre2_10_35_ucp_leaves_the_case_of_a_byte_to_the_tables(string $pattern, ?int $expected, ?int $otherCase): void
    {
        $regex = RegexParser::create(['cache' => null])->parse($pattern);
        $target = new PcreTarget(70400, '10.34', 'C');

        $this->assertSame(null === $expected ? null : [$expected, $otherCase], LastCodeUnit::required($regex, $target), $pattern.' on PCRE2 10.34');
        $this->assertSame(null === $expected ? null : [$expected, null !== $otherCase], LastCodeUnit::read($regex, $target), $pattern.' on PCRE2 10.34');
    }

    /**
     * @return iterable<string, array{pattern: string, expected: ?int, otherCase: ?int}>
     */
    public static function provideRowsOfPcre2With1034(): iterable
    {
        yield 'a byte past ASCII' => ['pattern' => '/(*UCP)a+\xe9/i', 'expected' => 0xE9, 'otherCase' => null];
        yield 'a class of both cases' => ['pattern' => '/(*UCP)x[\xe9\xc9]/', 'expected' => null, 'otherCase' => null];
        yield 'a capital past a byte' => ['pattern' => '/(*UCP)a+\xff/i', 'expected' => 0xFF, 'otherCase' => null];
        yield 'the sharp s' => ['pattern' => '/(*UCP)x\xdf/i', 'expected' => 0xDF, 'otherCase' => null];
        yield 'a sign' => ['pattern' => '/(*UCP)x\xd7/i', 'expected' => 0xD7, 'otherCase' => null];
        yield 'an ASCII letter' => ['pattern' => '/(*UCP)xA/i', 'expected' => 0x41, 'otherCase' => 0x61];
        yield 'the micro sign' => ['pattern' => '/(*UCP)a+\xb5/i', 'expected' => 0xB5, 'otherCase' => null];
        yield 'a k' => ['pattern' => '/(*UCP)xk/i', 'expected' => 0x6B, 'otherCase' => 0x4B];
        yield 'an s' => ['pattern' => '/(*UCP)a+s/i', 'expected' => 0x73, 'otherCase' => 0x53];
    }
}
