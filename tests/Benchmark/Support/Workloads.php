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

namespace PHPRegex\Tests\Benchmark\Support;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Solver\InMemoryDfaCache;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosMode;

/**
 * The work a growth series measures, shared by the benchmarks and the test
 * that keeps every series under the default guards: both must run the very
 * same thing.
 */
final class Workloads
{
    /**
     * The pattern's minimal DFA under the default solver options, through a
     * solver and a DFA cache of its own, so nothing is reused across calls.
     *
     * @throws ComplexityException when the pattern leaves the regular subset or a default limit is reached
     */
    public static function automata(string $pattern, ?RegexParser $parser = null): Dfa
    {
        $solver = new LanguageSolver($parser ?? RegexParser::create(['cache' => null]), new InMemoryDfaCache());

        return $solver->compile($pattern);
    }

    /**
     * Which path the group's work takes on the pattern: for redos, the proof
     * of redos() ("proven", "heuristic", "budget_exceeded" or
     * "not_analyzed"); for automata, "guard" when automata() stops on a
     * limit or on a construct outside the regular subset, "complete" when it
     * builds the DFA. A benchmark compares the same path on both sides only
     * when this value is the same on both.
     *
     * @throws \InvalidArgumentException when the group has no outcome
     */
    public static function outcome(string $group, string $pattern, ?RegexParser $parser = null): string
    {
        switch ($group) {
            case 'redos':
                return self::redos($pattern, $parser)->proof->value;
            case 'automata':
                try {
                    self::automata($pattern, $parser);
                } catch (ComplexityException) {
                    return 'guard';
                }

                return 'complete';
            default:
                throw new \InvalidArgumentException(\sprintf('The group "%s" has no outcome: only redos and automata do.', $group));
        }
    }

    /**
     * The untimed warm-up of a benchmark: the group's work run once, which
     * must take the expected path. A change that moves a case onto another
     * path (a proof onto the budget, a construction onto a guard) breaks the
     * variant on the side that changed instead of timing other work under
     * the same name.
     *
     * @throws \UnexpectedValueException when the outcome is not the expected one
     */
    public static function warmUp(string $group, string $pattern, string $expected, ?RegexParser $parser = null): void
    {
        $outcome = self::outcome($group, $pattern, $parser);
        if ($outcome !== $expected) {
            throw new \UnexpectedValueException(\sprintf('outcome changed: expected %s, got %s', $expected, $outcome));
        }
    }

    /**
     * The theoretical ReDoS analysis under the default options, the path the
     * linter and the PHPStan rule take.
     */
    public static function redos(string $pattern, ?RegexParser $parser = null): RedosAnalysis
    {
        return (new RedosAnalyzer($parser ?? RegexParser::create(['cache' => null])))->analyze($pattern, null, RedosMode::Theoretical);
    }
}
