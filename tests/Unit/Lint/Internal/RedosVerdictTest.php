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

namespace PHPRegex\Tests\Unit\Lint\Internal;

use PHPRegex\Linter\Internal\RedosVerdict;
use PHPRegex\Redos\Confirmation;
use PHPRegex\Redos\ConfirmationSample;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosConfidence;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Redos\RedosWitness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The words of a verdict, read from hand-built analyses: the message each
 * pair of proof and complexity opens on, and the lines that carry the attack.
 */
final class RedosVerdictTest extends TestCase
{
    /**
     * The message opens on the analysis' own headline, whatever the pair of
     * proof and complexity.
     */
    #[Test]
    #[DataProvider('provideHeadlines')]
    public function test_message_opens_on_the_headline(RedosAnalysis $analysis, string $headline): void
    {
        $this->assertStringStartsWith($headline.'. ', RedosVerdict::message($analysis));
    }

    /**
     * @return iterable<string, array{analysis: RedosAnalysis, headline: string}>
     */
    public static function provideHeadlines(): iterable
    {
        yield 'proven exponential' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Critical, 10, complexity: RedosComplexity::Exponential, proof: RedosProof::Proven),
            'headline' => 'Exponential backtracking (proven)',
        ];
        yield 'proven polynomial with its degree' => [
            'analysis' => new RedosAnalysis(RedosSeverity::High, 8, complexity: RedosComplexity::Polynomial, degree: 3, proof: RedosProof::Proven),
            'headline' => 'Polynomial backtracking, degree 3 (proven)',
        ];
        yield 'proven polynomial without a degree' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Medium, 5, complexity: RedosComplexity::Polynomial, proof: RedosProof::Proven),
            'headline' => 'Polynomial backtracking (proven)',
        ];
        yield 'proven linear' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Safe, 0, complexity: RedosComplexity::Linear, proof: RedosProof::Proven),
            'headline' => 'safe (proven)',
        ];
        yield 'proven without a class falls back on the heuristics' => [
            'analysis' => new RedosAnalysis(RedosSeverity::High, 8, complexity: RedosComplexity::Unknown, proof: RedosProof::Proven),
            'headline' => 'Potential backtracking (heuristic)',
        ];
        yield 'heuristic finding' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Medium, 5),
            'headline' => 'Potential backtracking (heuristic)',
        ];
        yield 'heuristic without finding' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Safe, 0),
            'headline' => 'no risk found (heuristic)',
        ];
        yield 'budget exceeded with a heuristic finding' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Critical, 10, proof: RedosProof::BudgetExceeded),
            'headline' => 'Potential backtracking (heuristic)',
        ];
        yield 'budget exceeded without finding' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Safe, 0, proof: RedosProof::BudgetExceeded),
            'headline' => 'not analyzed (budget exceeded)',
        ];
        yield 'not analyzed' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Safe, 0, proof: RedosProof::NotAnalyzed),
            'headline' => 'not analyzed',
        ];
        yield 'not analyzed after an error' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Unknown, 0, error: 'boom', proof: RedosProof::NotAnalyzed),
            'headline' => 'not analyzed (analysis error)',
        ];
    }

    #[Test]
    #[DataProvider('provideMessages')]
    public function test_message_qualifies_the_headline(RedosAnalysis $analysis, string $message): void
    {
        $this->assertSame($message, RedosVerdict::message($analysis));
    }

    /**
     * @return iterable<string, array{analysis: RedosAnalysis, message: string}>
     */
    public static function provideMessages(): iterable
    {
        yield 'severity and confidence' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Critical, 10, confidence: RedosConfidence::Medium, complexity: RedosComplexity::Exponential, proof: RedosProof::Proven),
            'message' => 'Exponential backtracking (proven). Severity: CRITICAL, confidence: MEDIUM.',
        ];
        yield 'budget exceeded' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Critical, 10, confidence: RedosConfidence::High, proof: RedosProof::BudgetExceeded),
            'message' => 'Potential backtracking (heuristic). Severity: CRITICAL, confidence: HIGH, budget exceeded.',
        ];
        yield 'confirmed by sampling, with its evidence' => [
            'analysis' => self::sampled('backtrack_limit'),
            'message' => 'Potential backtracking (heuristic). Severity: HIGH, confidence: HIGH, confirmed, evidence: backtrack_limit.',
        ];
        yield 'confirmed by sampling, without evidence' => [
            'analysis' => self::sampled(null),
            'message' => 'Potential backtracking (heuristic). Severity: HIGH, confidence: HIGH, confirmed.',
        ];
        yield 'analysis error' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Unknown, 0, error: 'boom', proof: RedosProof::NotAnalyzed),
            'message' => 'not analyzed (analysis error). Severity: UNKNOWN, confidence: LOW, error: boom.',
        ];
    }

    #[Test]
    public function test_evidence_is_empty_without_witness_nor_replay(): void
    {
        $this->assertSame([], RedosVerdict::evidence(new RedosAnalysis(RedosSeverity::Medium, 5)));
    }

    #[Test]
    public function test_evidence_carries_the_escaped_attack(): void
    {
        $witness = new RedosWitness('', "\x1B", '!', false);

        $this->assertSame(['Attack: "\x1B" x n . "!"'], RedosVerdict::evidence(self::exponential($witness, null, null)));
    }

    #[Test]
    public function test_evidence_quotes_the_failing_length_and_the_limit_of_the_replay(): void
    {
        $confirmation = new Confirmation(
            true,
            [new ConfirmationSample(16, 0.1), new ConfirmationSample(17, 0.2, null, \PREG_BACKTRACK_LIMIT_ERROR, 'Backtrack limit exhausted')],
            '0',
            100_000,
            10_000,
            1,
            50.0,
        );

        $lines = RedosVerdict::evidence(self::exponential(new RedosWitness('', 'a', '!', false), $confirmation, true));

        $this->assertSame('Replayed on PCRE2 10.49: preg_match fails from length 17 (backtrack_limit 100000, JIT off).', $lines[1]);
    }

    /**
     * The replay line names the limit the failing sample actually hit, not
     * the backtrack limit the replay was configured with.
     */
    #[Test]
    public function test_evidence_of_a_replay_names_the_recursion_limit_it_hit(): void
    {
        $confirmation = new Confirmation(
            true,
            [new ConfirmationSample(16, 0.1), new ConfirmationSample(17, 0.2, null, \PREG_RECURSION_LIMIT_ERROR, 'Recursion limit exhausted')],
            '0',
            100_000,
            10_000,
            1,
            50.0,
            evidence: 'recursion_limit',
        );

        $lines = RedosVerdict::evidence(self::exponential(new RedosWitness('', 'a', '!', false), $confirmation, true));

        $this->assertSame('Replayed on PCRE2 10.49: preg_match fails from length 17 (recursion_limit 10000, JIT off).', $lines[1]);
    }

    /**
     * The JIT stack has no ini setting to quote: the line names the JIT
     * stack limit, the length that hit it, and never the backtrack limit.
     */
    #[Test]
    public function test_evidence_of_a_replay_names_the_jit_stack_limit_it_hit(): void
    {
        $confirmation = new Confirmation(
            true,
            [new ConfirmationSample(16, 0.1), new ConfirmationSample(17, 0.2, null, \PREG_JIT_STACKLIMIT_ERROR, 'JIT stack limit exhausted')],
            '1',
            100_000,
            10_000,
            1,
            50.0,
            evidence: 'jit_stack_limit',
        );

        $lines = RedosVerdict::evidence(self::exponential(new RedosWitness('', 'a', '!', false), $confirmation, true));

        $this->assertStringStartsWith('Replayed on PCRE2 10.49: preg_match fails from length 17 (', $lines[1]);
        $this->assertStringContainsString('JIT stack limit', $lines[1]);
        $this->assertStringNotContainsString('backtrack_limit', $lines[1]);
    }

    #[Test]
    public function test_evidence_of_a_replay_leaves_out_what_the_confirmation_does_not_say(): void
    {
        $confirmation = new Confirmation(true, [], null, null, null, 1, 50.0);

        $lines = RedosVerdict::evidence(self::exponential(new RedosWitness('', 'a', '!', false), $confirmation, true));

        $this->assertSame('Replayed on PCRE2 10.49: preg_match fails.', $lines[1]);
    }

    #[Test]
    public function test_evidence_of_a_replay_names_a_jit_left_on(): void
    {
        $confirmation = new Confirmation(true, [], '1', 100_000, 10_000, 1, 50.0);

        $lines = RedosVerdict::evidence(self::exponential(new RedosWitness('', 'a', '!', false), $confirmation, true));

        $this->assertSame('Replayed on PCRE2 10.49: preg_match fails (backtrack_limit 100000, JIT on).', $lines[1]);
    }

    #[Test]
    public function test_evidence_says_when_the_engine_defuses_the_attack(): void
    {
        $confirmation = new Confirmation(false, [], '0', 100_000, 10_000, 1, 50.0);

        $lines = RedosVerdict::evidence(self::exponential(new RedosWitness('', 'a', '!', false), $confirmation, false));

        $this->assertSame("Not reproduced on PCRE2 10.49 (PCRE's optimisations defuse it).", $lines[1]);
    }

    private static function exponential(RedosWitness $witness, ?Confirmation $confirmation, ?bool $replayed): RedosAnalysis
    {
        return new RedosAnalysis(
            RedosSeverity::Critical,
            10,
            mode: null === $confirmation ? RedosMode::Theoretical : RedosMode::Confirmed,
            confirmation: $confirmation,
            complexity: RedosComplexity::Exponential,
            proof: RedosProof::Proven,
            witness: $witness,
            replayed: $replayed,
            pcreVersion: '10.49',
        );
    }

    private static function sampled(?string $evidence): RedosAnalysis
    {
        return new RedosAnalysis(
            RedosSeverity::High,
            8,
            confidence: RedosConfidence::High,
            mode: RedosMode::Confirmed,
            confirmation: new Confirmation(true, [], '0', 100_000, 10_000, 1, 50.0, evidence: $evidence),
        );
    }
}
