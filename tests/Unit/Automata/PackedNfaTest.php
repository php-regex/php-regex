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

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\Model\Nfa;
use PHPRegex\Automata\Model\NfaState;
use PHPRegex\Automata\Model\NfaTransition;
use PHPRegex\Automata\Model\PackedNfa;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Solver\LazyDfa;
use PHPRegex\Automata\Solver\ProductSearch;
use PHPRegex\Automata\Transform\HirToNfaTransformer;
use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Hir\HirTranslator;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An NFA packed over the classes of an alphabet both sides of a product
 * share, its moves in flat arrays, and the DFA built from it one reached
 * state at a time.
 */
final class PackedNfaTest extends TestCase
{
    use PhpUnitForwardCompat;

    #[Test]
    public function test_two_nfas_share_the_classes_of_their_sets(): void
    {
        // [a-c] then d, beside [b-e]: "a", "b-c", "d", "e" and the rest.
        $left = new Nfa(0, [
            0 => new NfaState(0, [new NfaTransition(CharSet::range(97, 99), 1)], [], false),
            1 => new NfaState(1, [new NfaTransition(CharSet::single(100), 2)], [], false),
            2 => new NfaState(2, [], [], true),
        ], 0, 255);
        $right = new Nfa(0, [
            0 => new NfaState(0, [new NfaTransition(CharSet::range(98, 101), 0)], [1], false),
            1 => new NfaState(1, [], [], true),
        ], 0, 255);

        [$packedLeft, $packedRight] = PackedNfa::packPair($left, $right);

        $this->assertSame($packedLeft->alphabet, $packedRight->alphabet);
        $alphabet = $packedLeft->alphabet;
        $this->assertSame([0, 97, 98, 100, 101, 102], $alphabet->rangeStart);
        $this->assertSame(5, $alphabet->classCount);
        $this->assertSame($alphabet->classOf(0), $alphabet->classOf(200));
        $this->assertSame([$alphabet->classOf(97), $alphabet->classOf(98)], $packedLeft->classesFrom(0));
        $this->assertSame([1 => true], $packedLeft->step(0, (int) $alphabet->classOf(99)));
        $this->assertSame([], $packedLeft->step(0, (int) $alphabet->classOf(100)));
        $this->assertSame(3, $packedLeft->moveCount());
    }

    /**
     * A closure keeps the states that move on a character or accept: the
     * epsilon-only ones of the construction lead to them and no further.
     */
    #[Test]
    public function test_a_closure_keeps_the_important_states(): void
    {
        $nfa = new Nfa(0, [
            0 => new NfaState(0, [], [1, 2], false),
            1 => new NfaState(1, [new NfaTransition(CharSet::single(97), 3)], [], false),
            2 => new NfaState(2, [], [3], false),
            3 => new NfaState(3, [], [], true),
        ], 0, 255);
        $packed = PackedNfa::pack($nfa, PackedNfa::alphabetOf([$nfa]));

        $this->assertSame([1, 3], $packed->closure(0));
        $this->assertTrue($packed->isAccepting(3));
        $this->assertFalse($packed->isAccepting(2));
    }

    /**
     * Of a sixteen-letter window, the inclusion of "b+" reaches a handful
     * of subsets, where the full DFA holds 2^16 states.
     */
    #[Test]
    public function test_a_lazy_dfa_builds_only_the_states_reached(): void
    {
        // What the automata read, as PCRE reads it over the whole subject:
        // "b+" and the union take "bbb", the window alone does not.
        $this->assertSame(1, preg_match('/^(?:b+)$/', 'bbb'));
        $this->assertSame(1, preg_match('/^(?:(a|b)*a(a|b){15}|b+)$/', 'bbb'));
        $this->assertSame(0, preg_match('/^(?:(a|b)*a(a|b){15})$/', str_repeat('b', 16)));
        $this->assertSame(1, preg_match('/^(?:(a|b)*a(a|b){15})$/', 'a'.str_repeat('b', 15)));

        $window = self::nfa('/(a|b)*a(a|b){15}/');
        [$left, $right] = PackedNfa::packPair(self::nfa('/b+/'), self::nfa('/(a|b)*a(a|b){15}|b+/'));
        $dfa = new LazyDfa($right, 100);

        $this->assertNull((new ProductSearch(new SolverOptions()))->difference($left, $dfa));
        $this->assertLessThan(5, $dfa->stateCount());

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains('DFA state limit exceeded (100).');
        [$left, $right] = PackedNfa::packPair(self::nfa('/[ab]{16}/'), $window);
        (new ProductSearch(new SolverOptions()))->difference($left, new LazyDfa($right, 100));
    }

    /**
     * A state that moves on many classes finds the moves of each, wherever
     * the class sits among them: "[a-z]" cut into a class per letter by the
     * other side's branches.
     */
    #[Test]
    public function test_a_step_finds_the_moves_of_each_class(): void
    {
        $letters = range('a', 'z');
        [$left] = PackedNfa::packPair(self::nfa('/[a-z]x/'), self::nfa('/'.implode('|', $letters).'/'));
        $alphabet = $left->alphabet;
        $moving = $left->closure($left->start)[0];

        $this->assertCount(26, $left->classesFrom($moving));
        foreach ($letters as $letter) {
            $this->assertSame(1, preg_match('/^(?:[a-z]x)$/', $letter.'x'));
            $class = (int) $alphabet->classOf(\ord($letter));
            $this->assertCount(1, $left->step($moving, $class), $letter);
        }

        $this->assertSame(0, preg_match('/^(?:[a-z]x)$/', 'Ax'));
        $this->assertSame([], $left->step($moving, (int) $alphabet->classOf(\ord('A'))));
        $this->assertSame([], $left->step($moving, (int) $alphabet->classOf(0)));
    }

    private static function nfa(string $pattern): Nfa
    {
        $ast = RegexParser::create()->parse($pattern);

        return (new HirToNfaTransformer($pattern, false))->transform((new HirTranslator())->translate($ast), new SolverOptions());
    }
}
