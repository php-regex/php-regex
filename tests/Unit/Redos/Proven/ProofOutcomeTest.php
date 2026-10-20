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

namespace PHPRegex\Tests\Unit\Redos\Proven;

use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\Backtrack\BacktrackProver;
use PHPRegex\Redos\Backtrack\ModelLimit;
use PHPRegex\Redos\Backtrack\ProofOutcome;
use PHPRegex\Redos\Backtrack\ProofResult;
use PHPRegex\Redos\Backtrack\SearchCostProver;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosOptions;
use PHPRegex\Redos\RedosSearchCost;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A proof gives back one outcome: the class it proved or the limit it met,
 * with what the model read differently, the ceiling on the steps of one
 * attempt and the pattern's own search, whichever way it ended. Nothing is
 * thrown and nothing is left on the prover to read afterwards.
 */
final class ProofOutcomeTest extends TestCase
{
    #[Test]
    public function test_a_proof_gives_its_class_ceiling_and_search(): void
    {
        $outcome = self::prove('/^a+b$/');

        $this->assertInstanceOf(ProofResult::class, $outcome->result);
        $this->assertNull($outcome->limit);
        $this->assertSame(RedosComplexity::Linear, $outcome->result->complexity);
        $this->assertSame(1, $outcome->stepBound);
        $this->assertNotNull($outcome->search);
        $this->assertSame([], $outcome->abstractions);
    }

    #[Test]
    public function test_a_proof_lists_what_the_model_read_differently(): void
    {
        $outcome = self::prove('/^(?:a{1,2000})+b$/');

        $this->assertInstanceOf(ProofResult::class, $outcome->result);
        $this->assertNotSame([], $outcome->abstractions);
    }

    #[Test]
    public function test_a_pattern_out_of_the_model_gives_its_limit(): void
    {
        $outcome = self::prove('/(a)\1/');

        $this->assertNull($outcome->result);
        $this->assertInstanceOf(ModelLimit::class, $outcome->limit);
        $this->assertFalse($outcome->limit->budgetExceeded);
        $this->assertNull($outcome->stepBound);
        $this->assertNull($outcome->search);
    }

    #[Test]
    public function test_a_proof_over_its_budget_gives_the_limit_and_what_it_read(): void
    {
        $regex = RegexParser::create()->parse('/^(?:a{1,2000}|b)+c(?:d|e)*f$/');
        $outcome = (new BacktrackProver(5_000, 40, 16))->prove($regex);

        $this->assertNull($outcome->result);
        $this->assertInstanceOf(ModelLimit::class, $outcome->limit);
        $this->assertTrue($outcome->limit->budgetExceeded);
        $this->assertNull($outcome->stepBound);
        $this->assertSame(['{1,2000} at offset 4 analysed as {1,}'], $outcome->abstractions, 'The repeats read before the budget ran out.');
    }

    /**
     * A ceiling needs no witness: an ambiguity the model cannot witness
     * still leaves the bound it proved.
     */
    #[Test]
    public function test_an_unwitnessed_ambiguity_keeps_its_ceiling(): void
    {
        $outcome = self::prove("/(.*)'(.*)'(.*)/i");

        $this->assertNull($outcome->result);
        $this->assertInstanceOf(ModelLimit::class, $outcome->limit);
        $this->assertSame(3, $outcome->stepBound);
    }

    /**
     * A prover read twice gives each proof its own outcome: nothing of the
     * first proof leaks into the second.
     */
    #[Test]
    public function test_each_proof_of_a_prover_stands_alone(): void
    {
        $options = new RedosOptions();
        $prover = new BacktrackProver($options->maxStates, $options->maxSteps, $options->boundedRepeatCutoff);
        $first = $prover->prove(RegexParser::create()->parse('/^(?:a{1,2000})+b$/'));
        $second = $prover->prove(RegexParser::create()->parse('/(a)\1/'));

        $this->assertNotSame([], $first->abstractions);
        $this->assertSame([], $second->abstractions);
        $this->assertNotNull($first->search);
        $this->assertNull($second->search);
    }

    #[Test]
    public function test_the_search_cost_reads_the_outcome(): void
    {
        $outcome = self::prove('/\s+$/');

        $this->assertInstanceOf(RedosSearchCost::class, (new SearchCostProver())->find('/\s+$/', RegexParser::create()->parse('/\s+$/'), $outcome, false));
        $this->assertNull((new SearchCostProver())->find('/(a)\1/', RegexParser::create()->parse('/(a)\1/'), self::prove('/(a)\1/'), false));
    }

    private static function prove(string $pattern): ProofOutcome
    {
        $options = new RedosOptions();

        return (new BacktrackProver($options->maxStates, $options->maxSteps, $options->boundedRepeatCutoff))->prove(RegexParser::create()->parse($pattern), $pattern);
    }
}
