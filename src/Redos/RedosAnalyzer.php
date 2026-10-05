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

namespace PHPRegex\Redos;

use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Internal\PatternParser;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\Internal\Backtrack\BacktrackProver;
use PHPRegex\Redos\Internal\Backtrack\ModelLimit;
use PHPRegex\Redos\Internal\Backtrack\ProofResult;
use PHPRegex\Redos\Internal\Backtrack\WitnessReplayer;

/**
 * The ReDoS verdict of a pattern. The backtracking model proves the
 * complexity class of one match attempt and builds the witness; outside
 * the model, or over its budget, the structural heuristics decide, and the
 * result says which of the two it is.
 */
final class RedosAnalyzer
{
    /**
     * The version of the model a verdict was computed with, bumped when the
     * model changes.
     */
    public const ANALYSIS_VERSION = '1';

    /**
     * @var array<string>
     */
    private array $ignoredPatternsNormalized = [];

    private readonly RedosOptions $options;

    /**
     * @param array<string> $ignoredPatterns
     */
    public function __construct(
        private readonly ?RegexParser $parser = null,
        /**
         * @var array<string>
         */
        private array $ignoredPatterns = [],
        private readonly RedosSeverity $threshold = RedosSeverity::High,
        private readonly ?ConfirmationRunnerInterface $confirmationRunner = null,
        ?RedosOptions $options = null,
    ) {
        $this->ignoredPatterns = array_values(array_unique($this->ignoredPatterns));
        $this->ignoredPatternsNormalized = $this->normalizeIgnoredPatterns($this->ignoredPatterns);
        $this->options = $options ?? new RedosOptions();
    }

    public function analyze(
        string $regex,
        ?RedosSeverity $threshold = null,
        RedosMode $mode = RedosMode::Theoretical,
        ?ConfirmationOptions $confirmOptions = null,
    ): RedosAnalysis {
        $threshold ??= $this->threshold;

        if (RedosMode::Off === $mode || $this->shouldIgnore($regex)) {
            return new RedosAnalysis(
                RedosSeverity::Safe,
                0,
                confidence: RedosConfidence::Low,
                mode: $mode,
                proof: RedosProof::NotAnalyzed,
            );
        }

        try {
            $parser = $this->parser ?? RegexParser::create();
            $ast = $parser->parse($regex);

            // A pattern the library's static checks reject is never proven
            // safe: PCRE refuses to compile it.
            $validation = $parser->validate($regex);
            if (!$validation->isValid) {
                return self::notAnalyzed($mode, $validation->error ?? 'The pattern is invalid.');
            }

            $visitor = new RedosProfiler(CharSetAnalyzer::forRegex($ast));
            $ast->accept($visitor);
            $heuristics = $visitor->getResult();

            $prover = new BacktrackProver(
                $this->options->maxStates,
                $this->options->maxSteps,
                $this->options->boundedRepeatCutoff,
            );

            $keepsAbstractions = false;
            $reason = null;

            try {
                $proof = $prover->prove($ast);
                $proofKind = RedosProof::Proven;
            } catch (ModelLimit $limit) {
                $proof = null;
                $proofKind = $limit->budgetExceeded ? RedosProof::BudgetExceeded : RedosProof::Heuristic;
                $keepsAbstractions = $limit->budgetExceeded || $limit->approximated;
                $reason = $limit->abstraction;
            }

            $severity = null === $proof ? $heuristics['severity'] : self::provenSeverity($proof);
            $witness = null === $proof || null === $proof->pump
                ? null
                : new RedosWitness((string) $proof->prefix, $proof->pump, (string) $proof->suffix, $proof->unicode);

            $analysis = new RedosAnalysis(
                $severity,
                self::score($severity),
                $heuristics['vulnerablePattern'],
                array_values($heuristics['recommendations']),
                null,
                $heuristics['vulnerablePattern'],
                $heuristics['trigger'],
                null === $proof ? ($heuristics['confidence'] ?? RedosConfidence::Low) : self::provenConfidence($proof, null),
                $heuristics['falsePositiveRisk'],
                array_values($heuristics['findings']),
                $heuristics['suggestedRewrite'],
                culpritNode: $visitor->getCulpritNode(),
                hotspots: $visitor->getHotspots(),
                mode: $mode,
                confirmation: null,
                complexity: $proof->complexity ?? RedosComplexity::Unknown,
                degree: $proof?->degree,
                proof: $proofKind,
                witness: $witness,
                abstractions: RedosProof::Proven === $proofKind || $keepsAbstractions
                    ? [...$prover->abstractions(), ...(null === $reason ? [] : [$reason])]
                    : [],
                upperBoundDegree: $prover->stepBound(),
            );

            if (RedosMode::Confirmed !== $mode || !$analysis->exceedsThreshold($threshold)) {
                return $analysis;
            }

            if (null !== $proof) {
                return $this->replay($regex, $analysis, $proof, $confirmOptions);
            }

            $runner = $this->confirmationRunner ?? new ConfirmationRunner();
            $confirmation = $runner->confirm($regex, $analysis, $confirmOptions);

            return self::with(
                $analysis,
                $confirmation,
                $confirmation->confirmed ? RedosConfidence::High : $analysis->confidenceLevel(),
                $analysis->witness,
                null,
            );
        } catch (\Throwable $e) {
            return self::notAnalyzed($mode, $e::class.': '.$e->getMessage(), 'Analysis incomplete: '.$e->getMessage());
        }
    }

    private static function notAnalyzed(RedosMode $mode, string $error, ?string $recommendation = null): RedosAnalysis
    {
        return new RedosAnalysis(
            RedosSeverity::Unknown,
            0,
            null,
            [$recommendation ?? 'Analysis incomplete: '.$error],
            $error,
            null,
            null,
            RedosConfidence::Low,
            null,
            [],
            null,
            null,
            [],
            $mode,
            null,
            proof: RedosProof::NotAnalyzed,
        );
    }

    /**
     * Replays an exponential witness on the running engine. PCRE gives up at
     * once on a subject without a literal every match needs: when the bare
     * witness does not reproduce, the replay tries the suffix the model
     * found to reject while ending with that literal, and publishes the
     * witness that reproduced. A polynomial verdict is never replayed.
     */
    private function replay(string $regex, RedosAnalysis $analysis, ProofResult $proof, ?ConfirmationOptions $options): RedosAnalysis
    {
        $witness = $analysis->witness;
        if (RedosComplexity::Exponential !== $proof->complexity || null === $witness) {
            return $analysis;
        }

        $candidates = [$witness];
        foreach ($proof->otherSuffixes as $suffix) {
            if ($suffix !== $witness->suffix) {
                $candidates[] = new RedosWitness($witness->prefix, $witness->pump, $suffix, $witness->unicode);
            }
        }

        if (null === $this->confirmationRunner) {
            // One budget of work for every candidate; the call without
            // $matches when the witness needs PHP's retry of an empty match.
            [$confirmation, $reproduced] = (new WitnessReplayer())->replay($regex, $candidates, $options ?? new ConfirmationOptions(), $proof->withoutMatches);
            if (null !== $reproduced) {
                return self::with($analysis, $confirmation, self::provenConfidence($proof, true), $reproduced, true);
            }

            return self::with($analysis, $confirmation, self::provenConfidence($proof, false), $witness, false);
        }

        $first = null;
        foreach ($candidates as $candidate) {
            $confirmation = $this->confirmationRunner->confirm($regex, self::with($analysis, null, $analysis->confidenceLevel(), $candidate, null), $options);
            $first ??= $confirmation;
            if (self::reproduced($confirmation)) {
                return self::with($analysis, $confirmation, self::provenConfidence($proof, true), $candidate, true);
            }
        }

        return self::with($analysis, $first, self::provenConfidence($proof, false), $witness, false);
    }

    /**
     * Whether the confirmation ran the witness into the backtrack limit: the
     * only evidence of a replay, whatever runner produced it (a recursion
     * limit or a JIT stack fails for another reason).
     */
    private static function reproduced(Confirmation $confirmation): bool
    {
        $errors = false;
        foreach ($confirmation->samples as $sample) {
            if (\PREG_BACKTRACK_LIMIT_ERROR === $sample->pregErrorCode) {
                return true;
            }

            $errors = $errors || null !== $sample->pregErrorCode;
        }

        // A runner that records no error code in its samples speaks through
        // its evidence.
        return !$errors && $confirmation->confirmed && 'backtrack_limit' === $confirmation->evidence;
    }

    private static function with(RedosAnalysis $analysis, ?Confirmation $confirmation, RedosConfidence $confidence, ?RedosWitness $witness, ?bool $replayed): RedosAnalysis
    {
        return new RedosAnalysis(
            $analysis->severity,
            $analysis->score,
            $analysis->vulnerablePart,
            $analysis->recommendations,
            $analysis->error,
            $analysis->vulnerableSubpattern,
            $analysis->trigger,
            $confidence,
            $analysis->falsePositiveRisk,
            $analysis->findings,
            $analysis->suggestedRewrite,
            culpritNode: $analysis->getCulpritNode(),
            hotspots: $analysis->hotspots,
            mode: $analysis->mode,
            confirmation: $confirmation,
            complexity: $analysis->complexity,
            degree: $analysis->degree,
            proof: $analysis->proof,
            witness: $witness,
            replayed: $replayed,
            abstractions: $analysis->abstractions,
            pcreVersion: $analysis->pcreVersion,
            analysisVersion: $analysis->analysisVersion,
            upperBoundDegree: $analysis->upperBoundDegree,
        );
    }

    private static function provenSeverity(ProofResult $proof): RedosSeverity
    {
        return match ($proof->complexity) {
            RedosComplexity::Exponential => RedosSeverity::Critical,
            RedosComplexity::Polynomial => ($proof->degree ?? 2) >= 3 ? RedosSeverity::High : RedosSeverity::Medium,
            default => RedosSeverity::Safe,
        };
    }

    /**
     * A proven safe verdict is certain; a proven vulnerable one only once
     * the engine reproduced it.
     */
    private static function provenConfidence(ProofResult $proof, ?bool $replayed): RedosConfidence
    {
        if (RedosComplexity::Linear === $proof->complexity) {
            return RedosConfidence::High;
        }

        return true === $replayed ? RedosConfidence::High : RedosConfidence::Medium;
    }

    private static function score(RedosSeverity $severity): int
    {
        return match ($severity) {
            RedosSeverity::Safe => 0,
            RedosSeverity::Low => 2,
            RedosSeverity::Medium => 5,
            RedosSeverity::High => 8,
            RedosSeverity::Critical => 10,
            RedosSeverity::Unknown => 5,
        };
    }

    private function shouldIgnore(string $regex): bool
    {
        if ([] === $this->ignoredPatterns) {
            return false;
        }

        $normalized = $this->normalizePattern($regex);

        return \in_array($normalized, $this->ignoredPatternsNormalized, true)
            || \in_array($normalized, $this->ignoredPatterns, true)
            || \in_array($regex, $this->ignoredPatterns, true);
    }

    private function normalizePattern(string $regex): string
    {
        try {
            [$pattern] = PatternParser::extractPatternAndFlags($regex, $this->parser?->target());

            return $pattern;
        } catch (\Throwable) {
            return $regex;
        }
    }

    /**
     * @param array<string> $patterns
     *
     * @return array<string>
     */
    private function normalizeIgnoredPatterns(array $patterns): array
    {
        $normalized = [];
        foreach ($patterns as $pattern) {
            $normalized[] = $this->normalizePattern($pattern);
        }

        return array_values(array_unique($normalized));
    }
}
