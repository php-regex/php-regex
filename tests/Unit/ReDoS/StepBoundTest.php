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

namespace PHPRegex\Tests\Unit\ReDoS;

use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\Internal\Backtrack\BacktrackProver;
use PHPRegex\Redos\Internal\Backtrack\Budget;
use PHPRegex\Redos\Internal\Backtrack\ItemAutomaton;
use PHPRegex\Redos\Internal\Backtrack\PnfaBuilder;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An upper bound on the steps of one match attempt, as the degree d of n^d:
 * without a state that has two paths back to itself on one word, an attempt
 * follows at most n^c partial paths, c the most loops a path through the
 * automaton crosses. It holds with or without an attack input, so a verdict
 * left unknown for want of a witness still gets a ceiling.
 */
final class StepBoundTest extends TestCase
{
    #[Test]
    #[DataProvider('provideBounds')]
    public function test_each_attempt_costs_at_most_n_to_the_bound(string $pattern, ?int $bound): void
    {
        $this->assertSame($bound, (new RedosAnalyzer(RegexParser::create()))->analyze($pattern)->upperBoundDegree);
    }

    #[Test]
    #[DataProvider('provideUnwitnessedPatterns')]
    public function test_an_ambiguity_without_witness_still_gets_a_ceiling(string $pattern, int $bound): void
    {
        $analysis = (new RedosAnalyzer(RegexParser::create()))->analyze($pattern);

        $this->assertSame(RedosComplexity::Unknown, $analysis->complexity);
        $this->assertSame($bound, $analysis->upperBoundDegree);
    }

    #[Test]
    #[DataProvider('provideProvenPatterns')]
    public function test_the_ceiling_is_never_below_a_proven_degree(string $pattern): void
    {
        $analysis = (new RedosAnalyzer(RegexParser::create()))->analyze($pattern);

        $this->assertSame(RedosProof::Proven, $analysis->proof);
        if (RedosComplexity::Exponential === $analysis->complexity) {
            $this->assertNull($analysis->upperBoundDegree);

            return;
        }

        $this->assertNotNull($analysis->upperBoundDegree);
        $this->assertGreaterThanOrEqual($analysis->degree ?? 0, $analysis->upperBoundDegree);
    }

    #[Test]
    public function test_a_bound_past_its_budget_is_no_bound(): void
    {
        $regex = RegexParser::create()->parse('/a*b*$/');
        $pnfa = (new PnfaBuilder($regex, new Budget(250_000, 2_000), 16))->build()[0];
        $automaton = new ItemAutomaton($pnfa, new Budget(250_000, 2_000), [ItemAutomaton::CONTEXT_START]);

        // A bound gets a budget of its own: one step is not enough.
        $boundOf = new \ReflectionMethod(BacktrackProver::class, 'boundOf');

        $this->assertNull($boundOf->invoke(new BacktrackProver(2_000, 1, 16), $automaton, 0));
        $this->assertSame(2, $boundOf->invoke(new BacktrackProver(2_000, 250_000, 16), $automaton, 0));
    }

    #[Test]
    public function test_the_ceiling_survives_a_confirmed_analysis(): void
    {
        $analysis = (new RedosAnalyzer(RegexParser::create()))->analyze('/(a+)+$/', RedosSeverity::Low, RedosMode::Confirmed);
        $theoretical = (new RedosAnalyzer(RegexParser::create()))->analyze('/a*a*$/', RedosSeverity::Low, RedosMode::Confirmed);

        $this->assertNull($analysis->upperBoundDegree);
        $this->assertSame(2, $theoretical->upperBoundDegree);
    }

    /**
     * @return iterable<string, array{pattern: string, bound: int|null}>
     */
    public static function provideBounds(): iterable
    {
        yield 'no loop' => ['pattern' => '/^abc$/', 'bound' => 0];
        yield 'one loop' => ['pattern' => '/^a+b$/', 'bound' => 1];
        yield 'three loops in a row' => ['pattern' => '/a*a*a*$/', 'bound' => 3];
        yield 'loops that cannot overlap still count' => ['pattern' => '/^(?:a|b)*c+d*$/', 'bound' => 3];
        yield 'two paths back to a state' => ['pattern' => '/(a+)+$/', 'bound' => null];
        yield 'out of the model' => ['pattern' => '/(a)\1/', 'bound' => null];
    }

    /**
     * @return iterable<string, array{pattern: string, bound: int}>
     */
    public static function provideUnwitnessedPatterns(): iterable
    {
        yield 'three wildcards around quotes' => ['pattern' => "/(.*)'(.*)'(.*)/i", 'bound' => 3];
        yield 'blank lines' => ['pattern' => '/[\r\n]+[\s\t]*[\r\n]+/', 'bound' => 3];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideProvenPatterns(): iterable
    {
        yield 'linear' => ['pattern' => '/^\d{3}-\d{4}$/'];
        yield 'quadratic' => ['pattern' => '/a*a*$/'];
        yield 'cubic' => ['pattern' => '/\s*\s*\s*$/'];
        yield 'nested' => ['pattern' => '/^(\w+\s?)+$/'];
        yield 'alternation in a loop' => ['pattern' => '/^(a|a)*$/'];
        yield 'email-like' => ['pattern' => '/^[\w.+-]+@[\w-]+\.[\w.]+$/'];
    }
}
