<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Redos;

use PhpRegex\Parser\Node\NodeInterface;

/**
 * Encapsulates the results of a Regular Expression Denial of Service (ReDoS) analysis.
 *
 * @api
 */
final readonly class RedosAnalysis implements \JsonSerializable
{
    public ?string $vulnerableSubpattern;

    /**
     * @param array<string>                  $recommendations
     * @param array<\PhpRegex\Redos\Finding> $findings
     * @param array<\PhpRegex\Redos\Hotspot> $hotspots
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
    ) {
        $this->vulnerableSubpattern = $vulnerableSubpattern ?? $vulnerablePart;
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

            $rank = $this->severityScore($hotspot->severity);
            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = $hotspot;
            }
        }

        return $best;
    }

    public function exceedsThreshold(RedosSeverity $threshold): bool
    {
        return $this->severityScore($this->severity) >= $this->severityScore($threshold);
    }

    /**
     * @return array{severity: string, score: int, mode: string, confirmed: bool, confidence: string, vulnerable_part: string|null, vulnerable_subpattern: string|null, trigger: string|null, false_positive_risk: string|null, suggested_rewrite: string|null, recommendations: array<int|string, string>, error: string|null, findings: array<int|string, \PhpRegex\Redos\Finding>, hotspots: array<int|string, \PhpRegex\Redos\Hotspot>, confirmation: \PhpRegex\Redos\Confirmation|null}
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
        ];
    }

    private function severityScore(RedosSeverity $severity): int
    {
        return match ($severity) {
            RedosSeverity::Safe => 0,
            RedosSeverity::Low => 1,
            RedosSeverity::Unknown => 2,
            RedosSeverity::Medium => 3,
            RedosSeverity::High => 4,
            RedosSeverity::Critical => 5,
        };
    }
}
