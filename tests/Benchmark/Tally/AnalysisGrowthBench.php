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
 * Growth series of the analyses, held to the class they grow in: the ReDoS
 * proof of a counted repeat under a plus, the ReDoS reading of loops that
 * once compared each part with every other, and the automaton of a counted
 * repeat.
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
     * A loop holding a lookahead and alternations whose branches start
     * alike: each alternation read the whole loop again for the assertion.
     */
    #[DataProvider('from100')]
    #[Linear('size')]
    public function benchLoopHoldingALookahead(int $size): void
    {
        Workloads::redos('/^(x)(?:'.str_repeat('(?:a|ab)c', $size).'(?=c))+\\1$/', $this->parser);
    }

    /**
     * A run of loops that never meet, "(?:ba)*" after "(?:ba)*": past eight
     * items that may match empty, the scan reads a bounded number of pairs
     * where each loop would be compared with every later one.
     */
    #[DataProvider('from100')]
    #[Linear('size')]
    public function benchRunOfLoopsThatNeverMeet(int $size): void
    {
        Workloads::redos('/^(x)'.str_repeat('(?:ba)*', $size).'\\1$/', $this->parser);
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
