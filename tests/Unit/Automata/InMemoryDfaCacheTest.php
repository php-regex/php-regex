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

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Model\DfaState;
use PHPRegex\Automata\Solver\InMemoryDfaCache;
use PHPRegex\Parser\Internal\StaticCaches;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The in-memory DFA cache is shared by every analysis of a process: it
 * keeps a bounded number of automata, holding a bounded number of
 * transitions in all, and empties with the library's other caches.
 */
final class InMemoryDfaCacheTest extends TestCase
{
    #[Test]
    public function test_a_full_cache_drops_its_older_half(): void
    {
        $dfa = self::dfaOf(0);
        $cache = new InMemoryDfaCache();

        for ($i = 0; $i < StaticCaches::MAX_ENTRIES; $i++) {
            $cache->set('key'.$i, $dfa);
        }
        $this->assertSame($dfa, $cache->get('key0'), 'A cache at its bound still holds every entry.');

        $cache->set('one more', $dfa);

        $this->assertNull($cache->get('key0'));
        $this->assertNull($cache->get('key'.(int) (StaticCaches::MAX_ENTRIES / 2 - 1)));
        $this->assertSame($dfa, $cache->get('key'.(int) (StaticCaches::MAX_ENTRIES / 2)));
        $this->assertSame($dfa, $cache->get('key'.(StaticCaches::MAX_ENTRIES - 1)));
        $this->assertSame($dfa, $cache->get('one more'));
    }

    /**
     * Many small automata stay, up to the count the other caches keep:
     * those of /a/ hold a few hundred transitions each.
     */
    #[Test]
    public function test_small_automata_are_kept(): void
    {
        $dfa = (new LanguageSolver())->compile('/a/');
        $cache = new InMemoryDfaCache();

        $kept = intdiv(self::bound(), self::transitionsOf($dfa));
        $this->assertGreaterThan(100, $kept, 'A hundred small automata fit.');
        for ($i = 0; $i < $kept; $i++) {
            $cache->set('key'.$i, $dfa);
        }

        for ($i = 0; $i < $kept; $i++) {
            $this->assertSame($dfa, $cache->get('key'.$i));
        }
    }

    #[Test]
    public function test_the_oldest_automata_go_when_the_transitions_pass_the_bound(): void
    {
        $half = intdiv(self::bound(), 2);
        $first = self::dfaOf($half);
        $second = self::dfaOf(self::bound() - $half);
        $third = self::dfaOf(1);
        $cache = new InMemoryDfaCache();

        $cache->set('first', $first);
        $cache->set('second', $second);
        $this->assertSame($first, $cache->get('first'), 'At the bound, every automaton stays.');
        $this->assertSame($second, $cache->get('second'));

        $cache->set('third', $third);

        $this->assertNull($cache->get('first'));
        $this->assertSame($second, $cache->get('second'));
        $this->assertSame($third, $cache->get('third'));
    }

    /**
     * The older half dropped for the count takes its transitions with it: a
     * key of that half set again is the newest, and the next automaton too
     * large to fit pushes out the oldest kept, not that key.
     */
    #[Test]
    public function test_the_older_half_dropped_for_the_count_takes_its_transitions_along(): void
    {
        $each = intdiv(self::bound(), 2 * StaticCaches::MAX_ENTRIES);
        $cache = new InMemoryDfaCache();
        for ($i = 0; $i < StaticCaches::MAX_ENTRIES; $i++) {
            $cache->set('key'.$i, self::dfaOf($each));
        }
        $cache->set('one more', self::dfaOf($each));
        $this->assertNull($cache->get('key0'), 'The older half is dropped.');

        $again = self::dfaOf($each);
        $cache->set('key0', $again);
        $held = (intdiv(StaticCaches::MAX_ENTRIES, 2) + 2) * $each;
        $cache->set('large', self::dfaOf(self::bound() - $held + 1));

        $this->assertSame($again, $cache->get('key0'));
        $this->assertNull($cache->get('key'.intdiv(StaticCaches::MAX_ENTRIES, 2)), 'The oldest kept goes.');
        $this->assertInstanceOf(Dfa::class, $cache->get('key'.(intdiv(StaticCaches::MAX_ENTRIES, 2) + 1)));
    }

    #[Test]
    public function test_an_automaton_over_the_bound_alone_is_not_kept(): void
    {
        $small = self::dfaOf(1);
        $cache = new InMemoryDfaCache();
        $cache->set('small', $small);

        $cache->set('large', self::dfaOf(self::bound() + 1));

        $this->assertNull($cache->get('large'));
        $this->assertSame($small, $cache->get('small'), 'An automaton that is not kept evicts nothing.');
    }

    /**
     * Each state counts its transitions by code point and by range.
     */
    #[Test]
    public function test_the_transitions_of_every_state_are_counted(): void
    {
        $quarter = intdiv(self::bound(), 4) + 1;
        $state = static fn (int $id): DfaState => new DfaState($id, array_fill(0, $quarter, 0), true, array_fill(0, 3 * $quarter, 0));
        $cache = new InMemoryDfaCache();

        $cache->set('wide', new Dfa(0, [$state(0), $state(1)]));

        $this->assertNull($cache->get('wide'));
    }

    /**
     * As many of the oldest go as the new automaton needs room for.
     */
    #[Test]
    public function test_as_many_automata_go_as_the_new_one_needs(): void
    {
        $quarter = intdiv(self::bound(), 4);
        $cache = new InMemoryDfaCache();
        foreach (['a', 'b', 'c', 'd'] as $key) {
            $cache->set($key, self::dfaOf($quarter));
        }

        $cache->set('e', self::dfaOf($quarter + 1));

        $this->assertNull($cache->get('a'));
        $this->assertNull($cache->get('b'));
        $this->assertInstanceOf(Dfa::class, $cache->get('c'));
        $this->assertInstanceOf(Dfa::class, $cache->get('d'));
        $this->assertInstanceOf(Dfa::class, $cache->get('e'));
    }

    /**
     * A key set again counts its transitions once, those of its new
     * automaton.
     */
    #[Test]
    public function test_an_automaton_set_again_replaces_its_transitions(): void
    {
        $half = intdiv(self::bound(), 2);
        $cache = new InMemoryDfaCache();
        $cache->set('first', self::dfaOf($half));
        $cache->set('first', self::dfaOf($half));
        $second = self::dfaOf(self::bound() - $half);

        $cache->set('second', $second);

        $this->assertInstanceOf(Dfa::class, $cache->get('first'));
        $this->assertSame($second, $cache->get('second'));

        $cache->set('second', self::dfaOf(self::bound() + 1));
        $this->assertNull($cache->get('second'), 'The key loses its automaton, and the one over the bound is not kept.');
        $cache->set('third', self::dfaOf(self::bound() - $half));
        $this->assertInstanceOf(Dfa::class, $cache->get('first'), 'The replaced automaton no longer counts.');
    }

    #[Test]
    public function test_clearing_the_caches_empties_every_live_cache(): void
    {
        $dfa = (new LanguageSolver())->compile('/a/');
        $first = new InMemoryDfaCache();
        $second = new InMemoryDfaCache();
        $first->set('a', $dfa);
        $second->set('b', $dfa);

        RegexParser::create()->clearCaches();

        $this->assertNull($first->get('a'));
        $this->assertNull($second->get('b'));

        $first->set('a', $dfa);
        $this->assertSame($dfa, $first->get('a'), 'A cleared cache fills again.');
    }

    #[Test]
    public function test_clearing_the_caches_forgets_the_transitions_held(): void
    {
        $cache = new InMemoryDfaCache();
        $cache->set('a', self::dfaOf(self::bound()));

        RegexParser::create()->clearCaches();
        $full = self::dfaOf(self::bound());
        $cache->set('b', $full);
        $cache->set('c', self::dfaOf(0));

        $this->assertSame($full, $cache->get('b'));
    }

    /**
     * An automaton of one state with this many transitions.
     */
    private static function dfaOf(int $transitions): Dfa
    {
        return new Dfa(0, [new DfaState(0, array_fill(0, $transitions, 0), true)]);
    }

    private static function transitionsOf(Dfa $dfa): int
    {
        $transitions = 0;
        foreach ($dfa->states as $state) {
            $transitions += \count($state->byteTransitions()) + $state->rangeCount();
        }

        return $transitions;
    }

    private static function bound(): int
    {
        $bound = (new \ReflectionClassConstant(InMemoryDfaCache::class, 'MAX_TRANSITIONS'))->getValue();
        \assert(\is_int($bound));

        return $bound;
    }
}
