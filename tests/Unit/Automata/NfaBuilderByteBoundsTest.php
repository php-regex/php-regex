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

use PHPRegex\Automata\Builder\DfaBuilder;
use PHPRegex\Automata\Builder\NfaBuilder;
use PHPRegex\Automata\Model\Nfa;
use PHPRegex\Automata\Model\NfaFragment;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Parser\Hir\CharSet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The byte alphabet is the default the model and its builder are born
 * with: a graph built without bounds is a byte graph, and the DFA read
 * from it answers for every byte 0-255 the same way.
 */
final class NfaBuilderByteBoundsTest extends TestCase
{
    #[Test]
    public function test_the_builder_defaults_to_the_byte_alphabet(): void
    {
        $builder = new NfaBuilder(16);
        $start = $builder->createState();
        $end = $builder->createState();
        $builder->addTransition($start, CharSet::single(\ord('a')), $end);

        $nfa = $builder->build(new NfaFragment($start, [$end]));

        $this->assertSame(0, $nfa->minCodePoint);
        $this->assertSame(255, $nfa->maxCodePoint);

        // The bounds decide the alphabet the DFA is read over: every byte
        // is a symbol of it, whatever the pattern itself names.
        $dfa = (new DfaBuilder())->determinize($nfa, new SolverOptions(minimizeDfa: false));
        $state = $dfa->getState($dfa->startState);

        $this->assertSame(\range(0, 255), \array_keys($state->transitions));
    }

    #[Test]
    public function test_the_model_defaults_to_the_byte_alphabet(): void
    {
        $nfa = new Nfa(0, []);

        $this->assertSame(0, $nfa->minCodePoint);
        $this->assertSame(255, $nfa->maxCodePoint);
    }
}
