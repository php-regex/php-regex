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

namespace PHPRegex\Tests\Unit\Parser\Analysis;

use PHPRegex\Parser\Analysis\AtomicInertiaAnalyzer;
use PHPRegex\Parser\Analysis\InertCommitments;
use PHPRegex\Parser\Hir\HirTranslator;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which possessive quantifiers, atomic groups and "\R" commit to nothing,
 * by the offset each starts at in the pattern body. Each row's verdict is
 * PCRE's: the inert ones find the matches of their plain spelling (see
 * LanguageSolverSoundnessTest and JavaScriptCommitmentTest), the others a
 * subject where the two part.
 */
final class AtomicInertiaAnalyzerTest extends TestCase
{
    /**
     * @param list<int> $possessives
     * @param list<int> $atomics
     */
    #[Test]
    #[DataProvider('provideSearchVerdicts')]
    public function test_finds_the_commitments_a_search_may_print_plain(string $pattern, array $possessives, array $atomics): void
    {
        $inert = self::inert($pattern, false);

        foreach ($possessives as $offset) {
            $this->assertTrue($inert->isInertPossessive($offset), $pattern.' at '.$offset);
        }

        foreach ($atomics as $offset) {
            $this->assertTrue($inert->isInertAtomic($offset), $pattern.' at '.$offset);
        }

        $this->assertSame([] === $possessives && [] === $atomics, $inert->isEmpty(), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, possessives: list<int>, atomics: list<int>}>
     */
    public static function provideSearchVerdicts(): iterable
    {
        yield 'a possessive run to the end' => ['pattern' => '/^\d++$/', 'possessives' => [1], 'atomics' => []];
        yield 'an atomic run before a dash' => ['pattern' => '/^(?>\d+)-x$/', 'possessives' => [], 'atomics' => [1]];
        yield 'a line break before a letter' => ['pattern' => '/a\Rb/', 'possessives' => [], 'atomics' => [1]];
        yield 'a fixed atomic body' => ['pattern' => '/(?>abc)c/', 'possessives' => [], 'atomics' => [0]];
        yield 'a longer branch first' => ['pattern' => '/(?>ab|a)c/', 'possessives' => [], 'atomics' => [0]];
        yield 'a run at the end of a lookahead' => ['pattern' => '/(?=a++)a/', 'possessives' => [3], 'atomics' => []];
        yield 'a shorter branch first' => ['pattern' => '/(?>a|ab)c/', 'possessives' => [], 'atomics' => []];
        yield 'a run the follower shares' => ['pattern' => '/a*+a/', 'possessives' => [], 'atomics' => []];
        yield 'a newline after a line break' => ['pattern' => "/\\R\n/", 'possessives' => [], 'atomics' => []];
        yield 'a lazy atomic run' => ['pattern' => '/(?>a+?)b/', 'possessives' => [], 'atomics' => []];
        yield 'a lookahead after a run' => ['pattern' => '/a++(?=a)/', 'possessives' => [], 'atomics' => []];
        yield 'a word boundary after a run' => ['pattern' => '/(?>a+)\B/', 'possessives' => [], 'atomics' => []];
        yield 'a line end under m' => ['pattern' => '/\s++$/m', 'possessives' => [], 'atomics' => []];
        yield 'a commitment further along' => ['pattern' => '/a++b\d++c/', 'possessives' => [4], 'atomics' => []];
    }

    /**
     * Each proof reads what follows its own commitment, and the proofs do
     * not compose: a commitment with another one further along its
     * sequence than the part right after it is not proven.
     */
    #[Test]
    public function test_leaves_a_commitment_with_another_further_along(): void
    {
        $inert = self::inert('/a++b\d++c/', false);

        $this->assertFalse($inert->isInertPossessive(0));
        $this->assertTrue($inert->isInertPossessive(4));
    }

    /**
     * Over a whole subject, a zero-width follower refuses a match or lets
     * it be, but the characters given back must still be taken by a
     * follower that starts otherwise: it is skipped.
     */
    #[Test]
    public function test_skips_a_zero_width_follower_over_a_whole_subject(): void
    {
        $this->assertTrue(self::inert('/a++(?=a)/', true)->isInertPossessive(0));
        $this->assertTrue(self::inert('/(?>a+)\B/', true)->isInertAtomic(0));
        $this->assertFalse(self::inert('/a++(?=a)/', false)->isInertPossessive(0));
    }

    private static function inert(string $pattern, bool $wholeSubject): InertCommitments
    {
        $hir = (new HirTranslator())->translate(RegexParser::create()->parse($pattern));

        return (new AtomicInertiaAnalyzer($wholeSubject))->inertCommitments($hir);
    }
}
