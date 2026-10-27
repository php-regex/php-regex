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

namespace PHPRegex\Tests\Unit\Hir;

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Parser\Cache\StaticCaches;
use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Hir\ClassSetProvider;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A probe reads which characters a class holds, not where a search finds
 * one. Under fr_FR.ISO8859-1, "\xE9" is a word character, and "(*UTF)" reads
 * "é" as U+00E9: on PHP 8.4.27 / PCRE2 10.49, preg_match('/(*UTF)\w/', 'é')
 * is 0, the start-up optimization leaving out the lead byte "\xC3", while
 * '/(*UTF)^\w$/', '/(*UTF)(*NO_START_OPT)\w/' and '/(*UTF)[\w]/' are 1. Each
 * set holds a character where the class matches it alone, anchored at both
 * ends, under the locale.
 */
final class ClassProbeStartOptimizationTest extends TestCase
{
    use InstalledLocales;

    private const LATIN1 = 'fr_FR.ISO8859-1';

    protected function setUp(): void
    {
        StaticCaches::clear();
        ClassSetProvider::persistIn(null);
    }

    protected function tearDown(): void
    {
        StaticCaches::clear();
    }

    #[Test]
    #[RunInSeparateProcess]
    #[DataProvider('provideAtoms')]
    public function test_a_class_holds_each_character_it_matches_alone(string $atom): void
    {
        $this->requiresLocale(self::LATIN1, $atom);

        $expected = [];
        $previous = (string) setlocale(\LC_CTYPE, '0');

        try {
            $this->assertNotFalse(setlocale(\LC_CTYPE, self::LATIN1));
            for ($codePoint = 0x80; $codePoint <= 0xFF; $codePoint++) {
                $expected[$codePoint] = 1 === preg_match('/(*UTF)\A(?:'.$atom.')\z/', (string) mb_chr($codePoint, 'UTF-8'));
            }
        } finally {
            setlocale(\LC_CTYPE, $previous);
        }

        $this->assertContains(true, $expected, 'The locale makes some of the characters members.');

        $set = ClassSetProvider::query($atom, true, '', '(*UTF)', false, self::LATIN1);
        $this->assertInstanceOf(CharSet::class, $set);
        $actual = [];
        foreach (array_keys($expected) as $codePoint) {
            $actual[$codePoint] = $set->contains($codePoint);
        }

        $this->assertSame($expected, $actual, $atom);
    }

    /**
     * @return iterable<string, array{atom: string}>
     */
    public static function provideAtoms(): iterable
    {
        yield 'a word character' => ['atom' => '\w'];
        yield 'not a word character' => ['atom' => '\W'];
        yield 'a word character in a class' => ['atom' => '[\w]'];
        yield 'a letter' => ['atom' => '[[:alpha:]]'];
        yield 'not a letter' => ['atom' => '[^[:alpha:]]'];
    }

    /**
     * Engine: under fr_FR.ISO8859-1, preg_match('/(*UTF)^\w$/', 'é') is 1
     * and preg_match('/(*UTF)^\W$/', 'é') is 0.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function test_the_automata_read_a_word_character_the_search_would_skip(): void
    {
        $this->requiresLocale(self::LATIN1, 'The word characters of the locale');

        $solver = new LanguageSolver(RegexParser::create(['cache' => null, 'locale' => self::LATIN1]));

        $this->assertFalse($solver->intersection('/(*UTF)^\w$/', '/(*UTF)^\x{e9}$/')->isEmpty);
        $this->assertTrue($solver->intersection('/(*UTF)^\W$/', '/(*UTF)^\x{e9}$/')->isEmpty);
    }
}
