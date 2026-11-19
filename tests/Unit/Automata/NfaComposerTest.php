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

use PHPRegex\Automata\Builder\NfaComposer;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Model\Nfa;
use PHPRegex\Automata\Model\PackedNfa;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Solver\LazyDfa;
use PHPRegex\Automata\Solver\ProductSearch;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The union and the separated concatenation of NFAs, read through the
 * strings they accept: never through the text of the patterns.
 */
final class NfaComposerTest extends TestCase
{
    use PhpUnitForwardCompat;

    /**
     * @param list<string> $patterns
     * @param list<string> $accepted
     * @param list<string> $rejected
     */
    #[Test]
    #[DataProvider('provideUnions')]
    public function test_a_union_accepts_what_any_part_accepts(array $patterns, array $accepted, array $rejected): void
    {
        $nfas = array_map(static fn (string $pattern): Nfa => (new LanguageSolver())->nfa($pattern), $patterns);
        $union = (new NfaComposer())->union(...$nfas);

        foreach ($accepted as $subject) {
            $this->assertTrue(self::accepts($union, self::codePoints($subject)), $subject.' is accepted.');
        }

        foreach ($rejected as $subject) {
            $this->assertFalse(self::accepts($union, self::codePoints($subject)), $subject.' is rejected.');
        }
    }

    /**
     * @return iterable<string, array{list<string>, list<string>, list<string>}>
     */
    public static function provideUnions(): iterable
    {
        yield 'one part' => [['/^ab$/'], ['ab'], ['', 'a', 'abb']];
        yield 'disjoint parts' => [['/^a+$/', '/^b+$/'], ['a', 'bbb'], ['', 'ab']];
        yield 'overlapping parts' => [['/^[a-c]$/', '/^[b-d]$/', '/^x*$/'], ['a', 'd', '', 'xx'], ['e', 'ax']];
        yield 'a byte part and a UTF-8 part' => [['/^a$/', '/^é$/u'], ['a', 'é'], ['b']];
    }

    #[Test]
    public function test_a_concatenation_puts_the_separator_between_the_parts(): void
    {
        $solver = new LanguageSolver();
        $joined = (new NfaComposer())->concat($solver->nfa('/^(?:GET|HEAD)$/'), $solver->nfa('/^\/a\/\d+$/'));

        $this->assertTrue(self::accepts($joined, [...self::codePoints('GET'), NfaComposer::SEPARATOR, ...self::codePoints('/a/1')]));
        $this->assertFalse(self::accepts($joined, self::codePoints('GET/a/1')), 'No part runs into the next.');
        $this->assertFalse(self::accepts($joined, [...self::codePoints('GE'), NfaComposer::SEPARATOR, ...self::codePoints('T/a/1')]));
        $this->assertSame(NfaComposer::SEPARATOR, $joined->maxCodePoint);
    }

    #[Test]
    public function test_the_separator_is_no_character_a_pattern_matches(): void
    {
        $solver = new LanguageSolver();
        // A part that takes any character, every code point included,
        // still never takes the separator.
        $joined = (new NfaComposer())->concat($solver->nfa('/^.*$/su'), $solver->nfa('/^.*$/su'));

        $this->assertTrue(self::accepts($joined, [0x10FFFF, NfaComposer::SEPARATOR, 0x10FFFF]));
        $this->assertFalse(self::accepts($joined, [NfaComposer::SEPARATOR, NfaComposer::SEPARATOR]), 'A part never reads the separator.');
    }

    #[Test]
    public function test_a_separator_inside_unicode_is_refused(): void
    {
        $nfa = (new LanguageSolver())->nfa('/^a$/');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('0x10FFFF given');
        (new NfaComposer())->concat($nfa, $nfa, 0x10FFFF);
    }

    #[Test]
    public function test_the_composed_nfas_feed_the_product_search(): void
    {
        $solver = new LanguageSolver();
        $composer = new NfaComposer();
        $left = $composer->concat($solver->nfa('/^GET$/'), $solver->nfa('/^\/a\/[0-9a-z]+$/'));
        $right = $composer->union(
            $composer->concat($solver->nfa('/^GET$/'), $solver->nfa('/^\/a\/\d+$/')),
            $composer->concat($solver->nfa('/^GET$/'), $solver->nfa('/^\/a\/[a-z]+$/')),
        );

        [$l, $r] = PackedNfa::packPair($left, $right);
        $kept = (new ProductSearch(new SolverOptions()))->difference($l, new LazyDfa($r, 1_000));

        $this->assertNotNull($kept);
        $this->assertSame([...self::codePoints('GET'), NfaComposer::SEPARATOR, ...self::codePoints('/a/0a')], array_map(static fn (int $class): int => $l->alphabet->representative[$class], $kept));
    }

    /**
     * @param list<int> $codePoints
     */
    private static function accepts(Nfa $nfa, array $codePoints): bool
    {
        $current = self::closure($nfa, [$nfa->startState]);
        foreach ($codePoints as $codePoint) {
            $next = [];
            foreach ($current as $state) {
                foreach ($nfa->getState($state)->transitions as $transition) {
                    if ($transition->charSet->contains($codePoint)) {
                        $next[] = $transition->target;
                    }
                }
            }

            $current = self::closure($nfa, $next);
        }

        foreach ($current as $state) {
            if ($nfa->getState($state)->isAccepting) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int> $states
     *
     * @return list<int>
     */
    private static function closure(Nfa $nfa, array $states): array
    {
        $seen = [];
        while ([] !== $states) {
            $state = array_pop($states);
            if (isset($seen[$state])) {
                continue;
            }

            $seen[$state] = true;
            foreach ($nfa->getState($state)->epsilonTransitions as $target) {
                $states[] = $target;
            }
        }

        return array_keys($seen);
    }

    /**
     * @return list<int>
     */
    private static function codePoints(string $text): array
    {
        return array_values(array_map(\mb_ord(...), mb_str_split($text, 1, 'UTF-8')));
    }
}
