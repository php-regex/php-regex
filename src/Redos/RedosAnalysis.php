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

use PHPRegex\Parser\Node\NodeInterface;

/**
 * Encapsulates the results of a Regular Expression Denial of Service (ReDoS) analysis.
 *
 * The findings, hotspots, recommendations, culprit and trigger come from the
 * structural heuristics: a result whose attempt is proven linear carries none
 * of them, as the proof settles the verdict.
 *
 * @api
 *
 * @phpstan-import-type WitnessArray from RedosWitness
 * @phpstan-import-type SearchCostArray from RedosSearchCost
 */
final class RedosAnalysis implements \JsonSerializable
{
    /**
     * The PCRE2 release the verdict was computed with: verdicts are
     * deterministic per release and analysis version.
     */
    public readonly string $pcreVersion;

    /**
     * The PHP release that ran the analysis: under "iu" the proof pairs a
     * character with its other case through its mbstring tables, which
     * follow the PHP release, not the PCRE2 one.
     */
    public readonly string $phpVersion;

    /**
     * @internal built by RedosAnalyzer::analyze() and Regex::redos()
     *
     * @param array<string>        $recommendations
     * @param array<Finding>       $findings
     * @param array<Hotspot>       $hotspots
     * @param int|null             $degree           the degree of a polynomial verdict, 2 or more; null otherwise
     * @param bool|null            $replayed         whether the witness made the running engine fail; null when no replay was attempted
     * @param list<string>         $abstractions     what the model analysed differently from the pattern as written
     * @param string|null          $pcreVersion      the PCRE2 release; the running one when null
     * @param int|null             $upperBoundDegree the degree d of a proven bound n^d on the steps of one attempt; null when no polynomial bound is proven
     * @param RedosSearchCost|null $searchCost       the witness of a quadratic unanchored search, looked for only when one attempt is proven linear; null when none was found, never a proof of a linear search
     */
    public function __construct(
        public readonly RedosSeverity $severity,
        public readonly int $score,
        public readonly ?string $vulnerablePart = null,
        public readonly array $recommendations = [],
        public readonly ?string $error = null,
        public readonly ?string $trigger = null,
        public readonly RedosConfidence $confidence = RedosConfidence::Low,
        public readonly ?string $falsePositiveRisk = null,
        public readonly array $findings = [],
        public readonly ?string $suggestedRewrite = null,
        public readonly ?NodeInterface $culpritNode = null,
        public readonly array $hotspots = [],
        public readonly RedosMode $mode = RedosMode::Theoretical,
        public readonly ?Confirmation $confirmation = null,
        public readonly RedosComplexity $complexity = RedosComplexity::Unknown,
        public readonly ?int $degree = null,
        public readonly RedosProof $proof = RedosProof::Heuristic,
        public readonly ?RedosWitness $witness = null,
        public readonly ?bool $replayed = null,
        public readonly array $abstractions = [],
        ?string $pcreVersion = null,
        public readonly string $analysisVersion = RedosAnalyzer::ANALYSIS_VERSION,
        public readonly ?int $upperBoundDegree = null,
        public readonly ?RedosSearchCost $searchCost = null,
    ) {
        $this->pcreVersion = $pcreVersion ?? explode(' ', \PCRE_VERSION)[0];
        $this->phpVersion = \PHP_VERSION;
    }

    /**
     * The analysis of a pattern left out: the analysis is off, or the
     * pattern is one the caller ignores. Nothing ran, so nothing is at risk.
     *
     * @internal built by RedosAnalyzer::analyze()
     */
    public static function skipped(RedosMode $mode): self
    {
        return new self(RedosSeverity::Safe, 0, confidence: RedosConfidence::Low, mode: $mode, proof: RedosProof::NotAnalyzed);
    }

    /**
     * The analysis of a pattern the library refuses, or could not finish:
     * the error says why, and the one recommendation what was not done.
     *
     * @internal built by RedosAnalyzer::analyze()
     */
    public static function failed(RedosMode $mode, string $error, ?string $recommendation = null): self
    {
        // Scored as a medium risk, as the analyzer scores an unknown
        // severity: a failure is never ranked with the safe patterns.
        return new self(
            RedosSeverity::Unknown,
            5,
            recommendations: [$recommendation ?? 'Analysis incomplete: '.$error],
            error: $error,
            confidence: RedosConfidence::Low,
            mode: $mode,
            proof: RedosProof::NotAnalyzed,
        );
    }

    /**
     * The same analysis once the running engine was asked: its confirmation,
     * the confidence it leaves, the witness it replayed and whether that
     * witness reproduced. Every other field is kept.
     *
     * @internal built by RedosAnalyzer::analyze()
     */
    public function withConfirmation(?Confirmation $confirmation, RedosConfidence $confidence, ?RedosWitness $witness, ?bool $replayed): self
    {
        return new self(
            $this->severity,
            $this->score,
            $this->vulnerablePart,
            $this->recommendations,
            $this->error,
            $this->trigger,
            $confidence,
            $this->falsePositiveRisk,
            $this->findings,
            $this->suggestedRewrite,
            $this->culpritNode,
            $this->hotspots,
            $this->mode,
            $confirmation,
            $this->complexity,
            $this->degree,
            $this->proof,
            $witness,
            $replayed,
            $this->abstractions,
            $this->pcreVersion,
            $this->analysisVersion,
            $this->upperBoundDegree,
            $this->searchCost,
        );
    }

    /**
     * Whether the severity is safe or low. A pattern the caller asked not to
     * analyse, ignored or with the analysis off, reads safe, as asked: its
     * proof is NotAnalyzed, and isProvenSafe() is what proves a pattern.
     */
    public function isSafe(): bool
    {
        return RedosSeverity::Safe === $this->severity || RedosSeverity::Low === $this->severity;
    }

    /**
     * Whether the model proved that no input drives one match attempt
     * beyond a linear number of steps.
     */
    public function isProvenSafe(): bool
    {
        return RedosProof::Proven === $this->proof && RedosComplexity::Linear === $this->complexity;
    }

    /**
     * The verdict of one match attempt in a few words, the same for every
     * consumer: whether it was proven, the class, or why there is none. The
     * cost of the search that retries the attempt is $searchCost.
     */
    public function headline(): string
    {
        if (RedosProof::NotAnalyzed === $this->proof) {
            return null === $this->error ? 'not analyzed' : 'not analyzed (analysis error)';
        }

        if (RedosProof::Proven === $this->proof) {
            switch ($this->complexity) {
                case RedosComplexity::Linear:
                    return 'safe (proven)';
                case RedosComplexity::Exponential:
                    return 'Exponential backtracking (proven)';
                case RedosComplexity::Polynomial:
                    return null === $this->degree
                        ? 'Polynomial backtracking (proven)'
                        : \sprintf('Polynomial backtracking, degree %d (proven)', $this->degree);
                case RedosComplexity::Unknown:
                default:
                    // A proof without a class never says safe: the heuristics speak.
                    break;
            }
        }

        if (RedosSeverity::Safe !== $this->severity) {
            return 'Potential backtracking (heuristic)';
        }

        return RedosProof::BudgetExceeded === $this->proof ? 'not analyzed (budget exceeded)' : 'no risk found (heuristic)';
    }

    public function isConfirmed(): bool
    {
        return RedosMode::Confirmed === $this->mode && (null !== $this->confirmation && $this->confirmation->confirmed);
    }

    /**
     * The hotspot of the highest severity, the first one among equals.
     */
    public function primaryHotspot(): ?Hotspot
    {
        $best = null;
        $bestRank = -1;

        foreach ($this->hotspots as $hotspot) {
            $rank = $hotspot->severity->rank();
            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = $hotspot;
            }
        }

        return $best;
    }

    public function exceedsThreshold(RedosSeverity $threshold): bool
    {
        return $this->severity->rank() >= $threshold->rank();
    }

    /**
     * @return array{severity: string, score: int, mode: string, confirmed: bool, confidence: string, vulnerable_part: string|null, trigger: string|null, false_positive_risk: string|null, suggested_rewrite: string|null, recommendations: array<int|string, string>, error: string|null, findings: array<int|string, Finding>, hotspots: array<int|string, Hotspot>, confirmation: Confirmation|null, complexity: string, degree: int|null, proof: string, witness: WitnessArray|null, replayed: bool|null, abstractions: list<string>, pcre_version: string, php_version: string, analysis_version: string, search_cost: SearchCostArray|null, upper_bound_degree: int|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'severity' => $this->severity->value,
            'score' => $this->score,
            'mode' => $this->mode->value,
            'confirmed' => $this->isConfirmed(),
            'confidence' => $this->confidence->value,
            'vulnerable_part' => $this->vulnerablePart,
            'trigger' => $this->trigger,
            'false_positive_risk' => $this->falsePositiveRisk,
            'suggested_rewrite' => $this->suggestedRewrite,
            'recommendations' => $this->recommendations,
            'error' => $this->error,
            'findings' => $this->findings,
            'hotspots' => $this->hotspots,
            'confirmation' => $this->confirmation,
            'complexity' => $this->complexity->value,
            'degree' => $this->degree,
            'proof' => $this->proof->value,
            'witness' => $this->witness?->toArray(),
            'replayed' => $this->replayed,
            'abstractions' => $this->abstractions,
            'pcre_version' => $this->pcreVersion,
            'php_version' => $this->phpVersion,
            'analysis_version' => $this->analysisVersion,
            'search_cost' => $this->searchCost?->toArray(),
            'upper_bound_degree' => $this->upperBoundDegree,
        ];
    }
}
