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
        // A target that reads the ASCII options of "/(?aD)": PCRE2 takes
        // them from 10.43, whatever engine runs the suite.
        $solver = new LanguageSolver(RegexParser::create(['php_version' => '8.4', 'pcre_version' => '10.44']));

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

        $compiled = (new LanguageSolver(RegexParser::create(['php_version' => '8.4', 'pcre_version' => '10.44']), dfaCache: $cache))->compile('/(?aD)\d/u', $options);

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
        $solver = new LanguageSolver(RegexParser::create(['php_version' => '8.4', 'pcre_version' => '10.44']), dfaCache: $cache);

        $first = $solver->compile('/(?aD)\d/u');
        $second = (new LanguageSolver(RegexParser::create(['php_version' => '8.4', 'pcre_version' => '10.44']), dfaCache: $cache))->compile('/(?aD)\d/u');

        $this->assertSame($first, $second);
        $this->assertSame(1, $cache->writes);
    }

    /**
     * Two engines judging the same target with the same options get two
     * keys; the same engine gets the same key every time.
     */
    #[Test]
    public function test_the_key_changes_with_the_running_engine_only(): void
    {
        $options = new SolverOptions();
        $target = 'php8.4/pcre10.42';

        $older = LanguageSolver::dfaCacheKey('/a+b/', $target, $options, '10.44 2024-06-07');
        $newer = LanguageSolver::dfaCacheKey('/a+b/', $target, $options, '10.49 2026-01-01');

        $this->assertNotSame($older, $newer);
        $this->assertSame($newer, LanguageSolver::dfaCacheKey('/a+b/', $target, $options, '10.49 2026-01-01'));
        // Distro builds share major.minor: the full version string, date
        // included, keeps them apart.
        $this->assertNotSame($newer, LanguageSolver::dfaCacheKey('/a+b/', $target, $options, '10.49 2026-02-02'));
    }

    /**
     * A DFA built under one transition budget is not served for another:
     * the budget decides whether the build stops, so each one, and no
     * budget at all, is a key of its own.
     */
    #[Test]
    public function test_the_key_changes_with_the_transition_budget(): void
    {
        $keys = array_map(
            static fn (?int $budget): string => LanguageSolver::dfaCacheKey('/a+b/', 'php8.4/pcre10.42', new SolverOptions(maxTransitionsProcessed: $budget), '10.49 2026-01-01'),
            [null, 10, 1_000_000],
        );

        $this->assertCount(3, array_unique($keys));
    }

    /**
     * The character sets a DFA is built from come from the running engine,
     * whatever PCRE2 is judged: two runtimes judging the same target with
     * different engines must not share a DFA. A DFA stored under the key
     * that leaves the running engine out is not reused.
     */
    #[Test]
    public function test_a_dfa_stored_under_a_key_without_the_running_engine_is_not_reused(): void
    {
        $options = new SolverOptions();
        $parser = RegexParser::create(['pcre_version' => '10.42']);
        $stale = (new LanguageSolver($parser))->compile('/b/', $options);
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
        $withoutEngine = self::keyWithoutTheRunningEngine('/a/', $parser, $options);
        $cache->entries[$withoutEngine] = $stale;

        $compiled = (new LanguageSolver($parser, $cache))->compile('/a/', $options);

        $this->assertNotSame($stale, $compiled, 'A DFA stored without the running engine in its key answered.');
        $this->assertNotContains($withoutEngine, $cache->asked);
        $this->assertCount(1, $cache->asked);
    }

    /**
     * The key as the solver builds it with the version of the analysis but
     * without the running engine: the pattern, the version, the PHP and
     * PCRE2 judged, and the options.
     */
    private static function keyWithoutTheRunningEngine(string $pattern, RegexParser $parser, SolverOptions $options): string
    {
        return hash('sha256', implode('|', [
            $pattern,
            RegexParser::CACHE_VERSION,
            $parser->target()->cacheKey(),
            $options->matchMode->value,
            $options->maxNfaStates,
            $options->maxDfaStates,
            $options->minimizeDfa ? '1' : '0',
            $options->minimizationAlgorithm->value,
            $options->determinizationAlgorithm->value,
            $options->maxTransitionsProcessed ?? 'null',
        ]));
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
