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

namespace PHPRegex\Tests\Counted;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Benchmark\Support\ColdStart;
use PHPRegex\Tests\Benchmark\Support\Workloads;
use PHPRegex\Tests\Counted\Support\Cases;
use Tally\Attribute\Before;
use Tally\Attribute\DataProvider;

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
    public function benchRedos(string $group, string $pattern, string $expected): void
    {
        Workloads::redos($pattern, $this->parser);
    }

    #[DataProvider('automataCases')]
    public function benchAutomata(string $group, string $pattern, string $expected): void
    {
        try {
            Workloads::automata($pattern, $this->parser);
        } catch (ComplexityException) {
            // The guard is the path this case is expected to take.
        }
    }
}
