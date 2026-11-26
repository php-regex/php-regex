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

use PHPRegex\Automata\Model\Nfa;
use PHPRegex\Automata\Model\NfaState;
use PHPRegex\Automata\Solver\InMemoryDfaCache;
use PHPRegex\Automata\Solver\KeptNfas;
use PHPRegex\Parser\Cache\StaticCaches;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The NFAs the lazy search reads are kept beside the DFA cache a solver was
 * given, for as long as that cache lives: a bounded number of them, holding
 * a bounded number of states in all, emptied with the library's other
 * caches.
 */
final class KeptNfasTest extends TestCase
{
    #[Test]
    public function test_the_nfas_are_kept_beside_their_cache_alone(): void
    {
        $cache = new InMemoryDfaCache();
        $other = new InMemoryDfaCache();
        $nfa = self::nfaOf(1);

        KeptNfas::beside($cache)->set('a', $nfa);

        $this->assertSame(KeptNfas::beside($cache), KeptNfas::beside($cache));
        $this->assertSame($nfa, KeptNfas::beside($cache)->get('a'));
        $this->assertNull(KeptNfas::beside($cache)->get('b'));
        $this->assertNotSame(KeptNfas::beside($cache), KeptNfas::beside($other));
        $this->assertNull(KeptNfas::beside($other)->get('a'));
    }

    #[Test]
    public function test_they_count_the_automata_kept(): void
    {
        // The automata live as long as their cache does.
        $cache = new InMemoryDfaCache();
        $kept = KeptNfas::beside($cache);
        $this->assertCount(0, $kept);

        $kept->set('a', self::nfaOf(1));
        $kept->set('b', self::nfaOf(2));
        $kept->set('a', self::nfaOf(3));
        $this->assertCount(2, $kept, 'A key set again is one automaton.');

        $kept->set('large', self::nfaOf(self::bound() + 1));
        $this->assertCount(2, $kept);

        RegexParser::create()->clearCaches();
        $this->assertCount(0, $kept);
        $this->assertSame($kept, KeptNfas::beside($cache));
    }

    /**
     * A cache nothing holds any longer takes its automata with it.
     */
    #[Test]
    public function test_the_automata_go_with_their_cache(): void
    {
        $cache = new InMemoryDfaCache();
        $kept = \WeakReference::create(KeptNfas::beside($cache));
        KeptNfas::beside($cache)->set('a', self::nfaOf(1));
        $this->assertInstanceOf(KeptNfas::class, $kept->get());

        unset($cache);

        $this->assertNull($kept->get());
    }

    #[Test]
    public function test_full_it_drops_its_older_half(): void
    {
        $nfa = self::nfaOf(1);
        $kept = KeptNfas::beside(new InMemoryDfaCache());

        for ($i = 0; $i < StaticCaches::MAX_ENTRIES; $i++) {
            $kept->set('key'.$i, $nfa);
        }
        $this->assertSame($nfa, $kept->get('key0'), 'At its bound it still holds every entry.');

        $kept->set('one more', $nfa);

        $this->assertNull($kept->get('key0'));
        $this->assertNull($kept->get('key'.(int) (StaticCaches::MAX_ENTRIES / 2 - 1)));
        $this->assertSame($nfa, $kept->get('key'.(int) (StaticCaches::MAX_ENTRIES / 2)));
        $this->assertSame($nfa, $kept->get('key'.(StaticCaches::MAX_ENTRIES - 1)));
        $this->assertSame($nfa, $kept->get('one more'));
    }

    #[Test]
    public function test_the_oldest_go_when_the_states_pass_the_bound(): void
    {
        $half = intdiv(self::bound(), 2);
        $first = self::nfaOf($half);
        $second = self::nfaOf(self::bound() - $half);
        $third = self::nfaOf(1);
        $kept = KeptNfas::beside(new InMemoryDfaCache());

        $kept->set('first', $first);
        $kept->set('second', $second);
        $this->assertSame($first, $kept->get('first'), 'At the bound, every automaton stays.');
        $this->assertSame($second, $kept->get('second'));

        $kept->set('third', $third);

        $this->assertNull($kept->get('first'));
        $this->assertSame($second, $kept->get('second'));
        $this->assertSame($third, $kept->get('third'));
    }

    /**
     * As many of the oldest go as the new automaton needs room for.
     */
    #[Test]
    public function test_as_many_go_as_the_new_one_needs(): void
    {
        $quarter = intdiv(self::bound(), 4);
        $kept = KeptNfas::beside(new InMemoryDfaCache());
        foreach (['a', 'b', 'c', 'd'] as $key) {
            $kept->set($key, self::nfaOf($quarter));
        }

        $kept->set('e', self::nfaOf($quarter + 1));

        $this->assertNull($kept->get('a'));
        $this->assertNull($kept->get('b'));
        $this->assertInstanceOf(Nfa::class, $kept->get('c'));
        $this->assertInstanceOf(Nfa::class, $kept->get('d'));
        $this->assertInstanceOf(Nfa::class, $kept->get('e'));
    }

    #[Test]
    public function test_an_automaton_over_the_bound_alone_is_not_kept(): void
    {
        $small = self::nfaOf(1);
        $kept = KeptNfas::beside(new InMemoryDfaCache());
        $kept->set('small', $small);

        $kept->set('large', self::nfaOf(self::bound() + 1));

        $this->assertNull($kept->get('large'));
        $this->assertSame($small, $kept->get('small'), 'An automaton that is not kept evicts nothing.');

        $whole = self::nfaOf(self::bound());
        $kept->set('whole', $whole);
        $this->assertSame($whole, $kept->get('whole'), 'One of the very bound is kept.');
        $this->assertNull($kept->get('small'));
    }

    /**
     * A key set again counts its states once, those of its new automaton.
     */
    #[Test]
    public function test_an_automaton_set_again_replaces_its_states(): void
    {
        $half = intdiv(self::bound(), 2);
        $kept = KeptNfas::beside(new InMemoryDfaCache());
        $kept->set('first', self::nfaOf($half));
        $kept->set('first', self::nfaOf($half));
        $second = self::nfaOf(self::bound() - $half);

        $kept->set('second', $second);

        $this->assertInstanceOf(Nfa::class, $kept->get('first'));
        $this->assertSame($second, $kept->get('second'));

        $kept->set('second', self::nfaOf(self::bound() + 1));
        $this->assertNull($kept->get('second'), 'The key loses its automaton, and the one over the bound is not kept.');
        $kept->set('third', self::nfaOf(self::bound() - $half));
        $this->assertInstanceOf(Nfa::class, $kept->get('first'), 'The replaced automaton no longer counts.');
    }

    #[Test]
    public function test_clearing_the_caches_empties_them(): void
    {
        $cache = new InMemoryDfaCache();
        $nfa = self::nfaOf(self::bound());
        KeptNfas::beside($cache)->set('a', $nfa);

        RegexParser::create()->clearCaches();

        $this->assertNull(KeptNfas::beside($cache)->get('a'));

        KeptNfas::beside($cache)->set('b', $nfa);
        $this->assertSame($nfa, KeptNfas::beside($cache)->get('b'), 'Emptied, they fill again, the states held forgotten.');

        RegexParser::create()->clearCaches();
        $this->assertNull(KeptNfas::beside($cache)->get('b'), 'And empty again.');
    }

    /**
     * An automaton of this many states.
     */
    private static function nfaOf(int $states): Nfa
    {
        return new Nfa(0, 0 === $states ? [] : array_fill(0, $states, new NfaState(0, [], [], true)));
    }

    private static function bound(): int
    {
        $bound = (new \ReflectionClassConstant(KeptNfas::class, 'MAX_STATES'))->getValue();
        \assert(\is_int($bound));

        return $bound;
    }
}
