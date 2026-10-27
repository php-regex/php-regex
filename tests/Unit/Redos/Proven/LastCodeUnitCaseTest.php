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
}
