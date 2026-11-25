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

namespace PHPRegex\Tests\Unit\Automata;

use PHPRegex\Automata\Transform\LocaleBoundClasses;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Hir\ClassHir;
use PHPRegex\Parser\Hir\LiteralHir;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without "u", the tables of the locale a program sets may give each ASCII
 * letter and each byte past ASCII another case: under a caseless option a
 * class holding some of them, but not all, is one those tables decide, the
 * first and the last of each run included. A literal reads past ASCII from
 * 0x80 to 0xFF, where the tables stop.
 */
final class LocaleBoundClassesTest extends TestCase
{
    /**
     * @param list<array{int, int}> $ranges the characters of the class
     */
    #[Test]
    #[DataProvider('provideCaselessClasses')]
    public function test_under_a_caseless_option_a_class_holding_some_of_the_letters_and_the_bytes_past_ascii_is_bound_to_the_tables(array $ranges, bool $bound): void
    {
        $classes = LocaleBoundClasses::of(RegexParser::create(['cache' => null])->parse('/x/i'), new PcreTarget(\PHP_VERSION_ID, '10.44', CharacterTables::BUILT_IN));

        $this->assertInstanceOf(LocaleBoundClasses::class, $classes);
        $this->assertSame($bound, $classes->binds(new ClassHir(CharSet::fromRanges($ranges))));
    }

    /**
     * @return iterable<string, array{ranges: list<array{int, int}>, bound: bool}>
     */
    public static function provideCaselessClasses(): iterable
    {
        yield 'the first capital' => ['ranges' => [[0x41, 0x41]], 'bound' => true];
        yield 'the last capital' => ['ranges' => [[0x5A, 0x5A]], 'bound' => true];
        yield 'the first small letter' => ['ranges' => [[0x61, 0x61]], 'bound' => true];
        yield 'the last small letter' => ['ranges' => [[0x7A, 0x7A]], 'bound' => true];
        yield 'the first byte past ASCII' => ['ranges' => [[0x80, 0x80]], 'bound' => true];
        yield 'the last byte' => ['ranges' => [[0xFF, 0xFF]], 'bound' => true];
        yield 'the capitals' => ['ranges' => [[0x41, 0x5A]], 'bound' => true];
        yield 'every letter and every byte past ASCII' => ['ranges' => [[0x41, 0x5A], [0x61, 0x7A], [0x80, 0xFF]], 'bound' => false];
        yield 'every byte' => ['ranges' => [[0x00, 0xFF]], 'bound' => false];
        yield 'the characters around the letters' => ['ranges' => [[0x40, 0x40], [0x5B, 0x60], [0x7B, 0x7F]], 'bound' => false];
    }

    /**
     * @param list<int> $codePoints the code points of the literal
     */
    #[Test]
    #[DataProvider('provideLiterals')]
    public function test_a_literal_reads_past_ascii_from_0x80_to_0xff(array $codePoints, bool $pastAscii): void
    {
        $this->assertSame($pastAscii, LocaleBoundClasses::readsPastAscii(new LiteralHir($codePoints)));
    }

    /**
     * @return iterable<string, array{codePoints: list<int>, pastAscii: bool}>
     */
    public static function provideLiterals(): iterable
    {
        yield 'the last ASCII character' => ['codePoints' => [0x7F], 'pastAscii' => false];
        yield 'the first byte past ASCII' => ['codePoints' => [0x80], 'pastAscii' => true];
        yield 'the last byte' => ['codePoints' => [0xFF], 'pastAscii' => true];
        yield 'a code point past the bytes' => ['codePoints' => [0x100], 'pastAscii' => false];
        yield 'a letter, then the last byte' => ['codePoints' => [0x61, 0xFF], 'pastAscii' => true];
    }
}
