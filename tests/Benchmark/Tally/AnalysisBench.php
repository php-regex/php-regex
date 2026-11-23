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

namespace PHPRegex\Tests\Benchmark\Tally;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Benchmark\Support\ColdStart;
use PHPRegex\Tests\Benchmark\Support\Workloads;
use PHPRegex\Tests\Benchmark\Tally\Support\Cases;
use Tally\Attribute\Before;
use Tally\Attribute\DataProvider;
use Tally\Attribute\ExpectedFor;

/**
 * The analyses a consumer asks for by name: the ReDoS verdict the linter
 * and the PHPStan rule read, and the automaton the language questions
 * build. The hook runs the work once, which must take the case's expected
 * path, so a change that moves a case onto another path (a proof onto the
 * budget, a construction onto a guard) stops the case instead of counting
 * other work under its name.
 */
final class AnalysisBench
{
    private RegexParser $parser;

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function redosCases(): iterable
    {
        return Cases::outcomes('redos');
    }

    /**
     * subset-blowup-12 builds a DFA of 8,193 states, half a second natively
     * and too long under the simulation: the timed benchmarks keep it.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function automataCases(): iterable
    {
        return Cases::outcomes('automata', 'subset-blowup-12');
    }

    #[Before]
    public function load(string $group, string $pattern, string $expected): void
    {
        Workloads::warmUp($group, $pattern, $expected, ColdStart::regex()->parser());
        $this->parser = ColdStart::regex()->parser();
    }

    #[DataProvider('redosCases')]
    #[ExpectedFor('bounded-alternation-200', instructions: '159.3M', cycles: '214.3M', memory: '749.3KiB')]
    #[ExpectedFor('email-exponential', instructions: '14.08M', cycles: '20.92M', memory: '117.4KiB')]
    #[ExpectedFor('nested-bounded-repeats', instructions: '38.86M', cycles: '50.11M', memory: '528.0KiB')]
    #[ExpectedFor('nested-bounded-repeats-then-loop', instructions: '39.87M', cycles: '52.56M', memory: '532.5KiB')]
    #[ExpectedFor('nested-plus', instructions: '2.050M', cycles: '4.436M', memory: '44.26KiB')]
    #[ExpectedFor('same-alternation-star', instructions: '2.348M', cycles: '4.724M', memory: '46.65KiB')]
    #[ExpectedFor('unicode-scripts', instructions: '723.1M', cycles: '778.6M', memory: '6.422MiB')]
    #[ExpectedFor('every-match-retry', instructions: '2.205M', cycles: '4.621M', memory: '34.15KiB')]
    public function benchRedos(string $group, string $pattern, string $expected): void
    {
        Workloads::redos($pattern, $this->parser);
    }

    #[DataProvider('automataCases')]
    #[ExpectedFor('arabic-block', instructions: '1.024M', cycles: '2.416M', memory: '17.20KiB')]
    #[ExpectedFor('counted-past-budget', instructions: '25.03M', cycles: '31.80M', memory: '2.657MiB')]
    #[ExpectedFor('mixed-blocks', instructions: '1.199M', cycles: '2.662M', memory: '17.86KiB')]
    #[ExpectedFor('nested-same-alternation', instructions: '5.262M', cycles: '7.474M', memory: '312.4KiB')]
    #[ExpectedFor('same-alternation-counted', instructions: '6.286M', cycles: '8.586M', memory: '499.0KiB')]
    public function benchAutomata(string $group, string $pattern, string $expected): void
    {
        try {
            Workloads::automata($pattern, $this->parser);
        } catch (ComplexityException) {
            // The guard is the path this case is expected to take.
        }
    }
}
