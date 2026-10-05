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
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Solver\DfaCacheInterface;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A persistent DFA cache outlives the library version that filled it. When
 * the reading of a pattern changes (here: the inline ASCII options, r per
 * scope), a DFA stored by an older version must not answer for the new
 * one: the cache key carries the version of the analysis.
 */
final class DfaCacheKeyVersionTest extends TestCase
{
    #[Test]
    public function test_a_dfa_stored_under_the_key_of_an_older_version_is_not_reused(): void
    {
        $options = new SolverOptions();
        $solver = new LanguageSolver();

        // What an older version stored for "/(?aD)\d/u": the DFA of "\d",
        // the reading before the ASCII options were modelled.
        $stale = $solver->compile('/\d/u', $options);
        $cache = new class implements DfaCacheInterface {
            /**
             * @var array<string, Dfa>
             */
            public array $entries = [];

            /**
             * @var list<string>
             */
            public array $asked = [];

            public function get(string $key): ?Dfa
            {
                $this->asked[] = $key;

                return $this->entries[$key] ?? null;
            }

            public function set(string $key, Dfa $dfa): void
            {
                $this->entries[$key] = $dfa;
            }
        };
        $legacyKey = self::legacyKey('/(?aD)\d/u', $options);
        $cache->entries[$legacyKey] = $stale;

        $compiled = (new LanguageSolver(dfaCache: $cache))->compile('/(?aD)\d/u', $options);

        $this->assertNotSame($stale, $compiled, 'The DFA an older version stored answered for this one.');
        $this->assertNotContains($legacyKey, $cache->asked);
        $this->assertCount(1, $cache->asked);
    }

    #[Test]
    public function test_the_same_version_reuses_its_own_dfa(): void
    {
        $cache = new class implements DfaCacheInterface {
            /**
             * @var array<string, Dfa>
             */
            public array $entries = [];

            public int $writes = 0;

            public function get(string $key): ?Dfa
            {
                return $this->entries[$key] ?? null;
            }

            public function set(string $key, Dfa $dfa): void
            {
                $this->entries[$key] = $dfa;
                $this->writes++;
            }
        };
        $solver = new LanguageSolver(dfaCache: $cache);

        $first = $solver->compile('/(?aD)\d/u');
        $second = (new LanguageSolver(dfaCache: $cache))->compile('/(?aD)\d/u');

        $this->assertSame($first, $second);
        $this->assertSame(1, $cache->writes);
    }

    /**
     * The key as the solver built it before the version entered it: the
     * pattern, the PHP and PCRE2 judged, and the options.
     */
    private static function legacyKey(string $pattern, SolverOptions $options): string
    {
        return hash('sha256', implode('|', [
            $pattern,
            RegexParser::create()->target()->cacheKey(),
            $options->matchMode->value,
            $options->maxNfaStates,
            $options->maxDfaStates,
            $options->minimizeDfa ? '1' : '0',
            $options->minimizationAlgorithm->value,
            $options->determinizationAlgorithm->value,
            $options->maxTransitionsProcessed ?? 'null',
        ]));
    }
}
