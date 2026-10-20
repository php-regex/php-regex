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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\Cache\ArrayCache;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Whether PCRE2 finds a pattern too large under the locale the patterns
 * run under: a class of two bytes that are each other's case compiles to
 * one caseless character from 10.34, a class of every byte to a match of
 * any character from 10.45, and the character tables of the locale say
 * which do. Every row was run through pcre2test 10.32 to 10.49 with the
 * "locale=" modifier but for C, and through PHP 8.4.26 (PCRE2 10.49) under
 * setlocale(), on macOS.
 */
final class PatternTooLargeLocaleTest extends TestCase
{
    use InstalledLocales;

    #[Test]
    #[DataProvider('provideVerdicts')]
    public function test_the_verdict_follows_the_tables_of_the_locale(string $pattern, string $locale, string $release, ?int $offset): void
    {
        if ('C' !== $locale) {
            $this->requiresLocale($locale, $pattern);
        }

        $result = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => $release, 'locale' => $locale])->validate($pattern);
        $label = \sprintf('%s under %s on PCRE2 %s: %s', substr($pattern, 0, 40), $locale, $release, (string) $result->error);

        if (null === $offset) {
            $this->assertTrue($result->isValid, $label);

            return;
        }

        $this->assertSame(ErrorCode::PatternTooLarge, $result->errorCode, $label);
        $this->assertSame($offset, $result->offset, $label);
    }

    /**
     * @return iterable<string, array{pattern: string, locale: string, release: string, offset: int|null}>
     */
    public static function provideVerdicts(): iterable
    {
        // A caseless class of every byte but the capitals, which the small
        // letters bring: 33 units up to 10.44, one from 10.45.
        $everyByte = '/'.str_repeat('[\\x00-\\x40\\x5b-\\xff]', 3000).'/i';
        yield 'caseless classes of every byte under C, 10.44' => ['pattern' => $everyByte, 'locale' => 'C', 'release' => '10.44', 'offset' => 60000];
        yield 'caseless classes of every byte under C, 10.45' => ['pattern' => $everyByte, 'locale' => 'C', 'release' => '10.45', 'offset' => null];
        yield 'caseless classes of every byte under C, 10.49' => ['pattern' => $everyByte, 'locale' => 'C', 'release' => '10.49', 'offset' => null];

        // Š and š are each other's case in Latin-9.
        $latin9Pairs = '/'.str_repeat('[\\xa6\\xa8]', 2500).'/';
        yield 'pairs of Latin-9 letters under C, 10.44' => ['pattern' => $latin9Pairs, 'locale' => 'C', 'release' => '10.44', 'offset' => 25000];
        yield 'pairs of Latin-9 letters under C, 10.49' => ['pattern' => $latin9Pairs, 'locale' => 'C', 'release' => '10.49', 'offset' => 0];
        yield 'pairs of Latin-9 letters under Latin-9, 10.33' => ['pattern' => $latin9Pairs, 'locale' => 'fr_FR.ISO8859-15', 'release' => '10.33', 'offset' => 25000];
        yield 'pairs of Latin-9 letters under Latin-9, 10.34' => ['pattern' => $latin9Pairs, 'locale' => 'fr_FR.ISO8859-15', 'release' => '10.34', 'offset' => null];
        yield 'pairs of Latin-9 letters under Latin-9, 10.49' => ['pattern' => $latin9Pairs, 'locale' => 'fr_FR.ISO8859-15', 'release' => '10.49', 'offset' => null];

        // Ÿ adds ÿ under Latin-9: the class holds every byte.
        $everyByteButOne = '/'.str_repeat('[\\x00-\\xfe]', 2500).'/i';
        yield 'caseless classes of every byte but one under C, 10.49' => ['pattern' => $everyByteButOne, 'locale' => 'C', 'release' => '10.49', 'offset' => 0];
        yield 'caseless classes of every byte but one under Latin-9, 10.44' => ['pattern' => $everyByteButOne, 'locale' => 'fr_FR.ISO8859-15', 'release' => '10.44', 'offset' => 27500];
        yield 'caseless classes of every byte but one under Latin-9, 10.45' => ['pattern' => $everyByteButOne, 'locale' => 'fr_FR.ISO8859-15', 'release' => '10.45', 'offset' => null];
        yield 'caseless classes of every byte but one under Latin-9, 10.49' => ['pattern' => $everyByteButOne, 'locale' => 'fr_FR.ISO8859-15', 'release' => '10.49', 'offset' => null];

        // "\W" leaves out the Latin-1 letters under Latin-1, which the ASCII
        // words do not bring back.
        $words = '/'.str_repeat('[\\Wa-zA-Z0-9_]', 2500).'/';
        yield 'classes of what is no word and the ASCII words under C, 10.49' => ['pattern' => $words, 'locale' => 'C', 'release' => '10.49', 'offset' => null];
        yield 'classes of what is no word and the ASCII words under Latin-1, 10.45' => ['pattern' => $words, 'locale' => 'fr_FR.ISO8859-1', 'release' => '10.45', 'offset' => 35000];
        yield 'classes of what is no word and the ASCII words under Latin-1, 10.49' => ['pattern' => $words, 'locale' => 'fr_FR.ISO8859-1', 'release' => '10.49', 'offset' => 0];

        // 64007 units under Latin-1, where é and É are a pair.
        $repeatedPair = '/(?:[\\xe9\\xc9]){8000}/';
        yield 'repeated pair of Latin-1 letters under C, 10.44' => ['pattern' => $repeatedPair, 'locale' => 'C', 'release' => '10.44', 'offset' => 20];
        yield 'repeated pair of Latin-1 letters under C, 10.49' => ['pattern' => $repeatedPair, 'locale' => 'C', 'release' => '10.49', 'offset' => 0];
        yield 'repeated pair of Latin-1 letters under Latin-1, 10.33' => ['pattern' => $repeatedPair, 'locale' => 'fr_FR.ISO8859-1', 'release' => '10.33', 'offset' => 20];
        yield 'repeated pair of Latin-1 letters under Latin-1, 10.34' => ['pattern' => $repeatedPair, 'locale' => 'fr_FR.ISO8859-1', 'release' => '10.34', 'offset' => null];
        yield 'repeated pair of Latin-1 letters under Latin-1, 10.49' => ['pattern' => $repeatedPair, 'locale' => 'fr_FR.ISO8859-1', 'release' => '10.49', 'offset' => null];
    }

    /**
     * Two locales never share a verdict, nor a tree, through a cache: each
     * judges the pattern with its own tables.
     */
    #[Test]
    public function test_two_locales_sharing_a_cache_keep_their_own_verdicts(): void
    {
        $this->requiresLocale('fr_FR.ISO8859-1', 'The verdicts of two locales through one cache');
        $cache = new ArrayCache();
        $pattern = '/(?:[\\xe9\\xc9]){8000}/';
        $latin1 = RegexParser::create(['cache' => $cache, 'php_version' => '8.4', 'pcre_version' => '10.49', 'locale' => 'fr_FR.ISO8859-1']);
        $c = RegexParser::create(['cache' => $cache, 'php_version' => '8.4', 'pcre_version' => '10.49', 'locale' => 'C']);

        $this->assertTrue($latin1->validate($pattern)->isValid);
        $this->assertSame(ErrorCode::PatternTooLarge, $c->validate($pattern)->errorCode);
        $this->assertTrue($latin1->validate($pattern)->isValid);
        $this->assertNotSame(RegexParser::cacheSeed($pattern, $latin1->target(), RegexParser::DEFAULT_MAX_RECURSION_DEPTH), RegexParser::cacheSeed($pattern, $c->target(), RegexParser::DEFAULT_MAX_RECURSION_DEPTH));
    }
}
