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

use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Benchmark\Support\ColdStart;
use PHPRegex\Tests\Benchmark\Support\Workloads;
use PHPRegex\Tests\Benchmark\Tally\Support\Ladders;
use Tally\Attribute\Before;
use Tally\Attribute\DataProvider;
use Tally\Attribute\Linear;
use Tally\Attribute\Linearithmic;

/**
 * Two growth series of the timed benchmarks, held to the class they grow
 * in: the ReDoS proof of a counted repeat under a plus, and the automaton
 * of a counted repeat.
 */
final class AnalysisGrowthBench
{
    use Ladders;

    private RegexParser $parser;

    #[Before]
    public function load(): void
    {
        Workloads::redos('/^(?:a{3}b)+$/', ColdStart::regex()->parser());
        Workloads::automata('/a{3}/', ColdStart::regex()->parser());
        $this->parser = ColdStart::regex()->parser();
    }

    /**
     * (?:a{n}b)+ anchored, proven linear to match: the proof once grew
     * about as n² past 80.
     */
    #[DataProvider('from100')]
    #[Linear('size')]
    public function benchProofOfACountedRepeatUnderAPlus(int $size): void
    {
        Workloads::redos('/^(?:a{'.$size.'}b)+$/', $this->parser);
    }

    /**
     * a{n}: one NFA and one DFA state per repetition, yet the build grows
     * a little faster than n.
     */
    #[DataProvider('from100')]
    #[Linearithmic('size')]
    public function benchAutomatonOfACountedRepeat(int $size): void
    {
        Workloads::automata('/a{'.$size.'}/', $this->parser);
    }
}
