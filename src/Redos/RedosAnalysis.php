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
 * @api
 *
 * @phpstan-import-type WitnessArray from RedosWitness
 * @phpstan-import-type SearchCostArray from RedosSearchCost
 */
final readonly class RedosAnalysis implements \JsonSerializable
{
    public ?string $vulnerableSubpattern;

    /**
     * The PCRE2 release the verdict was computed with: verdicts are
     * deterministic per release and analysis version.
     */
    public string $pcreVersion;

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
        public RedosSeverity $severity,
        public int $score,
        public ?string $vulnerablePart = null,
        public array $recommendations = [],
        public ?string $error = null,
        ?string $vulnerableSubpattern = null,
        public ?string $trigger = null,
        public ?RedosConfidence $confidence = null,
        public ?string $falsePositiveRisk = null,
        public array $findings = [],
        public ?string $suggestedRewrite = null,
        private ?NodeInterface $culpritNode = null,
        public array $hotspots = [],
        public RedosMode $mode = RedosMode::Theoretical,
        public ?Confirmation $confirmation = null,
        public RedosComplexity $complexity = RedosComplexity::Unknown,
        public ?int $degree = null,
        public RedosProof $proof = RedosProof::Heuristic,
        public ?RedosWitness $witness = null,
        public ?bool $replayed = null,
        public array $abstractions = [],
        ?string $pcreVersion = null,
        public string $analysisVersion = RedosAnalyzer::ANALYSIS_VERSION,
        public ?int $upperBoundDegree = null,
        public ?RedosSearchCost $searchCost = null,
    ) {
        $this->vulnerableSubpattern = $vulnerableSubpattern ?? $vulnerablePart;
        $this->pcreVersion = $pcreVersion ?? explode(' ', \PCRE_VERSION)[0];
    }

    public function getVulnerableSubpattern(): ?string
    {
        return $this->vulnerableSubpattern ?? $this->vulnerablePart;
    }

    public function getCulpritNode(): ?NodeInterface
    {
        return $this->culpritNode;
    }

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

    public function confidenceLevel(): RedosConfidence
    {
        return $this->confidence ?? RedosConfidence::Low;
    }

    public function getPrimaryHotspot(): ?Hotspot
    {
        $best = null;
        $bestRank = -1;

        foreach ($this->hotspots as $hotspot) {
            if (!$hotspot instanceof Hotspot) {
                continue;
            }

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
     * @return array{severity: string, score: int, mode: string, confirmed: bool, confidence: string, vulnerable_part: string|null, vulnerable_subpattern: string|null, trigger: string|null, false_positive_risk: string|null, suggested_rewrite: string|null, recommendations: array<int|string, string>, error: string|null, findings: array<int|string, Finding>, hotspots: array<int|string, Hotspot>, confirmation: Confirmation|null, complexity: string, degree: int|null, proof: string, witness: WitnessArray|null, replayed: bool|null, abstractions: list<string>, pcre_version: string, analysis_version: string, search_cost: SearchCostArray|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'severity' => $this->severity->value,
            'score' => $this->score,
            'mode' => $this->mode->value,
            'confirmed' => $this->isConfirmed(),
            'confidence' => $this->confidenceLevel()->value,
            'vulnerable_part' => $this->vulnerablePart,
            'vulnerable_subpattern' => $this->vulnerableSubpattern,
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
            'analysis_version' => $this->analysisVersion,
            'search_cost' => $this->searchCost?->toArray(),
        ];
    }
}
