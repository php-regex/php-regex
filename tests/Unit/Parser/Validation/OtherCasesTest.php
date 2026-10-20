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

use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Validation\OtherCases;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The other case of a character in the Unicode tables each PCRE2 release
 * ships: each row was run through pcre2test 10.32 to 10.49, the character
 * caseless in UTF mode against its other case, which matches from the
 * release whose tables give it that case and not before.
 */
final class OtherCasesTest extends TestCase
{
    #[Test]
    #[DataProvider('provideOtherCases')]
    public function test_each_release_reads_the_other_cases_of_its_unicode_tables(string $release, int $codePoint, int $otherCase): void
    {
        $this->assertSame($otherCase, OtherCases::of(new PcreTarget(80400, $release))->otherCase($codePoint), \sprintf('U+%04X on PCRE2 %s', $codePoint, $release));
    }

    /**
     * @return iterable<string, array{release: string, codePoint: int, otherCase: int}>
     */
    public static function provideOtherCases(): iterable
    {
        yield 'capital A, 10.32' => ['release' => '10.32', 'codePoint' => 0x41, 'otherCase' => 0x61];
        yield 'small e with an acute, 10.49' => ['release' => '10.49', 'codePoint' => 0xE9, 'otherCase' => 0xC9];
        yield 'sharp s, 10.32' => ['release' => '10.32', 'codePoint' => 0xDF, 'otherCase' => 0x1E9E];
        yield 'y with a diaeresis, 10.40' => ['release' => '10.40', 'codePoint' => 0xFF, 'otherCase' => 0x178];
        yield 'small a with a macron, 10.32' => ['release' => '10.32', 'codePoint' => 0x101, 'otherCase' => 0x100];
        // Unicode 12.1, from 10.34.
        yield 'capital glottal a, 10.33' => ['release' => '10.33', 'codePoint' => 0xA7BA, 'otherCase' => 0xA7BA];
        yield 'capital glottal a, 10.34' => ['release' => '10.34', 'codePoint' => 0xA7BA, 'otherCase' => 0xA7BB];
        yield 'small s with a hook, 10.34' => ['release' => '10.34', 'codePoint' => 0x282, 'otherCase' => 0xA7C5];
        // Unicode 13, from 10.35.
        yield 'capital d with a short stroke overlay, 10.34' => ['release' => '10.34', 'codePoint' => 0xA7C7, 'otherCase' => 0xA7C7];
        yield 'capital d with a short stroke overlay, 10.35' => ['release' => '10.35', 'codePoint' => 0xA7C7, 'otherCase' => 0xA7C8];
        // Unicode 14, from 10.39.
        yield 'capital old Polish o, 10.38' => ['release' => '10.38', 'codePoint' => 0xA7C0, 'otherCase' => 0xA7C0];
        yield 'capital old Polish o, 10.39' => ['release' => '10.39', 'codePoint' => 0xA7C0, 'otherCase' => 0xA7C1];
        yield 'Vithkuqi capital a, 10.42' => ['release' => '10.42', 'codePoint' => 0x10570, 'otherCase' => 0x10597];
        // Unicode 16, from 10.45.
        yield 'Cyrillic capital tje, 10.44' => ['release' => '10.44', 'codePoint' => 0x1C89, 'otherCase' => 0x1C89];
        yield 'Cyrillic capital tje, 10.45' => ['release' => '10.45', 'codePoint' => 0x1C89, 'otherCase' => 0x1C8A];
        yield 'small rams horn, 10.47' => ['release' => '10.47', 'codePoint' => 0x264, 'otherCase' => 0xA7CB];
        // Unicode 17, from 10.48.
        yield 'capital pharyngeal voiced fricative, 10.47' => ['release' => '10.47', 'codePoint' => 0xA7CE, 'otherCase' => 0xA7CE];
        yield 'capital pharyngeal voiced fricative, 10.48' => ['release' => '10.48', 'codePoint' => 0xA7CE, 'otherCase' => 0xA7CF];
        yield 'Beria Erfe capital arkab, 10.49' => ['release' => '10.49', 'codePoint' => 0x16EA0, 'otherCase' => 0x16EBB];
        // A release newer than the library knows reads the newest tables,
        // an older one the oldest.
        yield 'capital pharyngeal voiced fricative, 10.50' => ['release' => '10.50', 'codePoint' => 0xA7CE, 'otherCase' => 0xA7CF];
        yield 'capital glottal a, 10.31' => ['release' => '10.31', 'codePoint' => 0xA7BA, 'otherCase' => 0xA7BA];
    }

    #[Test]
    #[DataProvider('provideNextCasedCharacters')]
    public function test_the_next_character_that_has_another_case_is_found(string $release, int $from, ?int $next): void
    {
        $this->assertSame($next, OtherCases::of(new PcreTarget(80400, $release))->nextCased($from));
    }

    /**
     * @return iterable<string, array{release: string, from: int, next: int|null}>
     */
    public static function provideNextCasedCharacters(): iterable
    {
        yield 'a capital after the digits' => ['release' => '10.49', 'from' => 0x30, 'next' => 0x41];
        yield 'a capital itself' => ['release' => '10.49', 'from' => 0x41, 'next' => 0x41];
        yield 'the first character' => ['release' => '10.49', 'from' => 0, 'next' => 0x41];
        yield 'from the capital glottal a, 10.33' => ['release' => '10.33', 'from' => 0xA7BA, 'next' => 0xAB53];
        yield 'from the capital glottal a, 10.34' => ['release' => '10.34', 'from' => 0xA7BA, 'next' => 0xA7BA];
        yield 'none after the last' => ['release' => '10.49', 'from' => 0x1E944, 'next' => null];
    }
}
