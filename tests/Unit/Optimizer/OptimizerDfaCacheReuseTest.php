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

namespace PHPRegex\Tests\Unit\Optimizer;

use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Solver\DfaCacheInterface;
use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The optimizer's automata net compiles a pattern once per instance: the
 * solver it asks is kept, with a DFA cache, so a later run of the same
 * pattern answers from the cache instead of building the automaton again.
 */
final class OptimizerDfaCacheReuseTest extends TestCase
{
    #[Test]
    public function test_the_net_reuses_cached_dfas_between_runs_of_one_optimizer(): void
    {
        $cache = new CountingDfaCache();
        $optimizer = new Optimizer(Regex::create()->parser(), $cache);

        $this->assertSame('/a++b/', $optimizer->optimize('/a+b/', ['possessive' => true])->optimized);
        $this->assertSame(2, $cache->lookups, 'one equivalence check reads two patterns');
        $this->assertSame(0, $cache->hits, 'the first run compiles both sides itself');

        $this->assertSame('/a++b/', $optimizer->optimize('/a+b/', ['possessive' => true])->optimized);
        $this->assertSame(4, $cache->lookups);
        $this->assertSame(2, $cache->hits, 'the second run finds both DFAs the first one built');
    }

    /**
     * A kept cache changes nothing about the gate's contract: a rewrite the
     * solver still refuses — here the possessive atom is multi-character —
     * is kept back on every run, cached or not.
     */
    #[Test]
    public function test_a_refusal_still_keeps_the_rewrite_back_with_the_shared_solver(): void
    {
        $optimizer = new Optimizer(Regex::create()->parser(), new CountingDfaCache());

        $this->assertSame('/(?:ab|a)+c/', $optimizer->optimize('/(?:ab|a)+c/', ['possessive' => true])->optimized);
        $this->assertSame('/(?:ab|a)+c/', $optimizer->optimize('/(?:ab|a)+c/', ['possessive' => true])->optimized);
    }
}

/**
 * Counts what reaches the cache: every lookup, and every lookup an
 * automaton was already stored under.
 */
final class CountingDfaCache implements DfaCacheInterface
{
    public int $lookups = 0;

    public int $hits = 0;

    /**
     * @var array<string, Dfa>
     */
    private array $dfas = [];

    public function get(string $key): ?Dfa
    {
        $this->lookups++;

        $dfa = $this->dfas[$key] ?? null;
        if (null !== $dfa) {
            $this->hits++;
        }

        return $dfa;
    }

    public function set(string $key, Dfa $dfa): void
    {
        $this->dfas[$key] = $dfa;
    }
}
