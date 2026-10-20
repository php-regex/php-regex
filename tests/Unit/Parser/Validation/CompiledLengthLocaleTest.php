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

namespace PHPRegex\Tests\Unit\Parser\Validation;

use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Validation\CompiledLength;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The length the first pass of each release counts for a class whose
 * length follows the character tables of the locale: a pair of a byte and
 * its other case, which compiles to one caseless character from 10.34, and
 * a class of every byte, a match of any character from 10.45. Each length
 * is the one pcre2test 10.32 to 10.49, patched to print the length of its
 * first pass, prints with the "memory" modifier and, but for C, the
 * "locale=" one, on macOS.
 */
final class CompiledLengthLocaleTest extends TestCase
{
    use InstalledLocales;

    /**
     * @param array<string, int|null> $lengths by release
     */
    #[Test]
    #[DataProvider('provideLengths')]
    public function test_the_length_is_the_one_each_release_compiles_under_the_locale(string $pattern, string $locale, array $lengths): void
    {
        $tables = 'C' === $locale ? CharacterTables::builtIn() : $this->requiresLocale($locale, $pattern);

        foreach ($lengths as $release => $length) {
            $this->assertSame($length, self::lengthOf($pattern, $release, $tables), \sprintf('%s under %s on PCRE2 %s', $pattern, $locale, $release));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, locale: string, lengths: array<string, int|null>}>
     */
    public static function provideLengths(): iterable
    {
        $pair = ['10.32' => 40, '10.33' => 40, '10.34' => 9, '10.40' => 9, '10.44' => 9, '10.45' => 9, '10.49' => 9];
        $class = ['10.32' => 40, '10.34' => 40, '10.44' => 40, '10.45' => 40, '10.49' => 40];
        $anyFrom1045 = ['10.32' => 40, '10.44' => 40, '10.45' => 8, '10.47' => 8, '10.48' => 8, '10.49' => 8];

        // Š and š in Latin-9, é and É in Latin-1 and Latin-5.
        yield 'pair of Latin-9 letters under Latin-9' => ['pattern' => '/[\\xa6\\xa8]/', 'locale' => 'fr_FR.ISO8859-15', 'lengths' => $pair];
        yield 'pair of Latin-9 letters under C' => ['pattern' => '/[\\xa6\\xa8]/', 'locale' => 'C', 'lengths' => $class];
        yield 'pair of Latin-1 letters under Latin-1' => ['pattern' => '/[\\xe9\\xc9]/', 'locale' => 'fr_FR.ISO8859-1', 'lengths' => $pair];
        yield 'pair of Latin-1 letters under C' => ['pattern' => '/[\\xe9\\xc9]/', 'locale' => 'C', 'lengths' => $class];
        yield 'pair of Latin-1 letters under Latin-5' => ['pattern' => '/[\\xc9\\xe9]/', 'locale' => 'tr_TR.ISO8859-9', 'lengths' => $pair];
        yield 'i and I under C' => ['pattern' => '/[iI]/', 'locale' => 'C', 'lengths' => $pair];
        // macOS gives the Turkish locales the cases of every other one.
        yield 'i and I under Latin-5' => ['pattern' => '/[iI]/', 'locale' => 'tr_TR.ISO8859-9', 'lengths' => $pair];
        yield 'dotted capital I and dotless i under Latin-5' => ['pattern' => '/[\\xdd\\xfd]/', 'locale' => 'tr_TR.ISO8859-9', 'lengths' => $class];
        yield 'Latin-1 letter and an ASCII one under Latin-1' => ['pattern' => '/[\\xe9e]/', 'locale' => 'fr_FR.ISO8859-1', 'lengths' => $class];
        // The C library of macOS uppercases ÿ to U+0178 under UTF-8, which
        // pcre2_maketables() kept as "x" before 10.43.
        yield 'y with a diaeresis and x under UTF-8' => ['pattern' => '/[\\xff\\x78]/', 'locale' => 'fr_FR.UTF-8', 'lengths' => ['10.33' => 40, '10.34' => 9, '10.40' => 9, '10.42' => 9, '10.43' => 40, '10.44' => 40, '10.49' => 40]];
        yield 'sharp s and a grave a under UTF-8' => ['pattern' => '/[\\xdf\\xe0]/', 'locale' => 'fr_FR.UTF-8', 'lengths' => $class];

        // A single-byte locale keeps the case of a byte below 256, before
        // 10.43 as after: ÿ, ß and º are their own case under Latin-1.
        yield 'y with a diaeresis and x under Latin-1' => ['pattern' => '/[\\xff\\x78]/', 'locale' => 'fr_FR.ISO8859-1', 'lengths' => $class];
        yield 'sharp s and a grave a under Latin-1' => ['pattern' => '/[\\xdf\\xe0]/', 'locale' => 'fr_FR.ISO8859-1', 'lengths' => $class];
        yield 'masculine ordinal and Z under Latin-1' => ['pattern' => '/[\\xba\\x5a]/', 'locale' => 'fr_FR.ISO8859-1', 'lengths' => $class];

        // Ÿ adds ÿ under Latin-9: the caseless class holds every byte.
        yield 'caseless class of every byte but the last under Latin-9' => ['pattern' => '/[\\x00-\\xfe]/i', 'locale' => 'fr_FR.ISO8859-15', 'lengths' => $anyFrom1045];
        yield 'caseless class of every byte but the last under C' => ['pattern' => '/[\\x00-\\xfe]/i', 'locale' => 'C', 'lengths' => $class];
        yield 'caseless class of ASCII and é under C' => ['pattern' => '/[\\x00-\\x7f\\xe9]/i', 'locale' => 'C', 'lengths' => $class];
        yield 'caseless class of ASCII and é under Latin-1' => ['pattern' => '/[\\x00-\\x7f\\xe9]/i', 'locale' => 'fr_FR.ISO8859-1', 'lengths' => $class];
        yield 'caseless class of every byte but the capitals under C' => ['pattern' => '/[\\x00-\\x40\\x5b-\\xff]/i', 'locale' => 'C', 'lengths' => $anyFrom1045];
        // Caseless, upper and lower are alpha.
        yield 'caseless class of every byte but the letters, and the capitals' => ['pattern' => '/[\\x00-\\x40\\x5b-\\x60\\x7b-\\xff[:upper:]]/i', 'locale' => 'C', 'lengths' => $anyFrom1045];
        yield 'caseless class of every byte but the letters, and the small ones' => ['pattern' => '/[\\x00-\\x40\\x5b-\\x60\\x7b-\\xff[:lower:]]/i', 'locale' => 'C', 'lengths' => $anyFrom1045];
        yield 'class of every byte but the letters, and the capitals' => ['pattern' => '/[\\x00-\\x40\\x5b-\\x60\\x7b-\\xff[:upper:]]/', 'locale' => 'C', 'lengths' => $class];

        // A type or a POSIX name holds the bytes the locale gives it.
        yield 'class of ASCII in a POSIX name under C' => ['pattern' => '/[[:ascii:]]/', 'locale' => 'C', 'lengths' => $class];
        yield 'class of ASCII in a POSIX name under Latin-1' => ['pattern' => '/[[:ascii:]]/', 'locale' => 'fr_FR.ISO8859-1', 'lengths' => $class];
        yield 'class of what is past ASCII and ASCII under C' => ['pattern' => '/[\\x80-\\xff[:ascii:]]/', 'locale' => 'C', 'lengths' => $anyFrom1045];
        yield 'class of what is no word and the ASCII words under C' => ['pattern' => '/[\\Wa-zA-Z0-9_]/', 'locale' => 'C', 'lengths' => $anyFrom1045];
        yield 'class of what is no word and the ASCII words under Latin-1' => ['pattern' => '/[\\Wa-zA-Z0-9_]/', 'locale' => 'fr_FR.ISO8859-1', 'lengths' => $class];
        yield 'class of what is no word and the ASCII words in UTF mode under C' => ['pattern' => '/(*UTF)[\\Wa-zA-Z0-9_]/', 'locale' => 'C', 'lengths' => $anyFrom1045];
        yield 'class of what is no word and the ASCII words in UTF mode under Latin-1' => ['pattern' => '/(*UTF)[\\Wa-zA-Z0-9_]/', 'locale' => 'fr_FR.ISO8859-1', 'lengths' => $class];
        yield 'class of ASCII and what is no letter under C' => ['pattern' => '/[\\x00-\\x7f[:^alpha:]]/', 'locale' => 'C', 'lengths' => $anyFrom1045];
        yield 'class of ASCII and what is no letter under Latin-1' => ['pattern' => '/[\\x00-\\x7f[:^alpha:]]/', 'locale' => 'fr_FR.ISO8859-1', 'lengths' => $class];
        yield 'negated class of the words and what is past ASCII under C' => ['pattern' => '/[^\\w\\x80-\\xff]/', 'locale' => 'C', 'lengths' => $class];
        yield 'class of a type and its negation under Latin-1' => ['pattern' => '/[\\D\\d]/', 'locale' => 'fr_FR.ISO8859-1', 'lengths' => $anyFrom1045];
        yield 'negated class of a type and its negation under Latin-1' => ['pattern' => '/[^\\D\\d]/', 'locale' => 'fr_FR.ISO8859-1', 'lengths' => $class];
    }

    private static function lengthOf(string $pattern, string $release, CharacterTables $tables): ?int
    {
        $regex = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => $release, 'runtime_pcre_validation' => false, 'locale' => 'C'])->parse($pattern);
        $source = (string) $regex->source;
        $utf = str_contains($regex->flags, 'u') || StartOptions::turnUtfOn($source);
        $ucp = str_contains($regex->flags, 'u') || \in_array('UCP', StartOptions::items($source), true);

        return (new CompiledLength(static fn (): int => 0, $source, $utf, $ucp, $regex->flags, new PcreTarget(80400, $release, 'C'), $tables))->of($regex->pattern);
    }
}
