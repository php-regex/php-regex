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

use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Redos\Hotspot;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\PcreAttemptSteps;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A proof that one attempt is linear decides what the result reports: the
 * structural heuristics guess at a risk the proof rules out, so a result
 * proven safe carries none of their findings, hotspots, recommendations,
 * culprit or trigger, in PHP and in JSON alike. Every consumer prints the
 * result as it is, with no filter of its own.
 */
final class RedosProvenSafeResultTest extends TestCase
{
    /**
     * The engine's own count of the steps of one attempt of the pattern the
     * heuristics call critical: twice the subject, twice the steps, on every
     * adversarial shape of its overlapping alternation (PHP 8.4, PCRE2 10.49:
     * 4n + 3 steps on "ab" x n, where "(?:a|a)+c" takes 4,095 on ten "a").
     *
     * The subject ends with "!c": the "c" every match needs is there, so
     * PCRE2 runs the attempt instead of giving up at once.
     */
    #[Test]
    #[DataProvider('provideAdversarialUnits')]
    public function test_engine_fact_the_overlapping_alternation_stays_linear(string $unit): void
    {
        $pattern = PcreAttemptSteps::pinned('/^(?:a|ab)+c/');
        $previous = PcreAttemptSteps::steps($pattern, str_repeat($unit, 250).'!c');

        foreach ([500, 1000, 2000] as $count) {
            $steps = PcreAttemptSteps::steps($pattern, str_repeat($unit, $count).'!c');
            $this->assertLessThanOrEqual(2 * $previous, $steps, \sprintf('%d x "%s": %d steps after %d.', $count, $unit, $steps, $previous));
            $this->assertGreaterThan($previous, $steps, 'The attempt reads the whole run.');
            $previous = $steps;
        }
    }

    /**
     * @return iterable<string, array{unit: string}>
     */
    public static function provideAdversarialUnits(): iterable
    {
        yield 'both alternatives' => ['unit' => 'ab'];
        yield 'the short alternative only' => ['unit' => 'a'];
        yield 'the short one then the long one' => ['unit' => 'aab'];
        yield 'the long one then the short one' => ['unit' => 'aba'];
    }

    #[Test]
    #[DataProvider('provideProvenSafePatterns')]
    public function test_a_proven_safe_result_carries_no_heuristic_finding(string $pattern, RedosMode $mode): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern, RedosSeverity::Safe, $mode);

        $this->assertTrue($analysis->isProvenSafe());
        $this->assertSame('safe (proven)', $analysis->headline());
        $this->assertSame([], $analysis->findings);
        $this->assertSame([], $analysis->hotspots);
        $this->assertNull($analysis->primaryHotspot());
        $this->assertSame([], $analysis->recommendations);
        $this->assertNull($analysis->vulnerablePart);
        $this->assertNull($analysis->trigger);
        $this->assertNull($analysis->falsePositiveRisk);
        $this->assertNull($analysis->suggestedRewrite);
        $this->assertNull($analysis->culpritNode);
    }

    #[Test]
    #[DataProvider('provideProvenSafePatterns')]
    public function test_the_json_of_a_proven_safe_result_carries_no_heuristic_finding(string $pattern, RedosMode $mode): void
    {
        $json = (new RedosAnalyzer())->analyze($pattern, RedosSeverity::Safe, $mode)->jsonSerialize();

        $this->assertSame('safe', $json['severity']);
        $this->assertSame('proven', $json['proof']);
        $this->assertSame('linear', $json['complexity']);
        $this->assertSame([], $json['findings']);
        $this->assertSame([], $json['hotspots']);
        $this->assertSame([], $json['recommendations']);
        $this->assertNull($json['vulnerable_part']);
        $this->assertNull($json['trigger']);
        $this->assertNull($json['false_positive_risk']);
        $this->assertNull($json['suggested_rewrite']);
    }

    /**
     * Each pattern here is one the heuristics flag on their own: a+ as
     * medium, the overlapping alternation as critical.
     *
     * @return iterable<string, array{pattern: string, mode: RedosMode}>
     */
    public static function provideProvenSafePatterns(): iterable
    {
        foreach ([RedosMode::Theoretical, RedosMode::Confirmed] as $mode) {
            yield 'overlapping alternation, '.$mode->value => ['pattern' => '/^(?:a|ab)+c/', 'mode' => $mode];
            yield 'one unbounded loop, '.$mode->value => ['pattern' => '/a+/', 'mode' => $mode];
            yield 'an anchored class loop, '.$mode->value => ['pattern' => '/^[a-z0-9-]+$/', 'mode' => $mode];
            yield 'a lazy dot then a literal, '.$mode->value => ['pattern' => '/<.*?>/', 'mode' => $mode];
        }
    }

    /**
     * A proven vulnerable verdict keeps the heuristics' culprit and hotspot:
     * they point at the loop the witness pumps.
     */
    #[Test]
    public function test_a_proven_vulnerable_result_keeps_its_heuristic_findings(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/(a+)+$/');

        $this->assertSame(RedosProof::Proven, $analysis->proof);
        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity);
        $this->assertNotSame([], $analysis->findings);
        $this->assertInstanceOf(Hotspot::class, $analysis->primaryHotspot());
        $this->assertInstanceOf(NodeInterface::class, $analysis->culpritNode);
        $this->assertSame('a+', $analysis->vulnerablePart);
    }

    /**
     * Without a proof the heuristics decide, and say why.
     */
    #[Test]
    public function test_a_heuristic_result_keeps_its_findings(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/(a+)+\1/');

        $this->assertSame(RedosProof::Heuristic, $analysis->proof);
        $this->assertNotSame([], $analysis->findings);
        $this->assertNotSame([], $analysis->recommendations);
        $this->assertInstanceOf(Hotspot::class, $analysis->primaryHotspot());
    }

    /**
     * The proof, when it says linear, overrides the heuristic's severity
     * too: the result is one verdict, not two.
     */
    #[Test]
    public function test_every_finding_of_a_result_agrees_with_its_verdict(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/^(?:a|ab)+c/');

        $this->assertSame(RedosSeverity::Safe, $analysis->severity);
        $this->assertSame([], [...$analysis->findings, ...$analysis->hotspots]);
        foreach ([...$analysis->findings, ...$analysis->hotspots] as $hint) {
            $this->assertSame(RedosSeverity::Safe, $hint->severity);
        }
    }
}
