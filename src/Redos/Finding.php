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

/**
 * Describes a specific ReDoS risk detection.
 */
final class Finding implements \JsonSerializable
{
    /**
     * @internal built by RedosAnalyzer::analyze(), for RedosAnalysis::$findings
     */
    public function __construct(
        public readonly RedosSeverity $severity,
        public readonly string $message,
        public readonly string $pattern,
        public readonly ?string $trigger = null,
        public readonly ?string $suggestedRewrite = null,
        public readonly RedosConfidence $confidence = RedosConfidence::Medium,
        public readonly ?string $falsePositiveRisk = null,
    ) {}

    /**
     * @return array{
     *     severity: string,
     *     message: string,
     *     pattern: string,
     *     trigger: string|null,
     *     suggested_rewrite: string|null,
     *     confidence: string,
     *     false_positive_risk: string|null,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'severity' => $this->severity->value,
            'message' => $this->message,
            'pattern' => $this->pattern,
            'trigger' => $this->trigger,
            'suggested_rewrite' => $this->suggestedRewrite,
            'confidence' => $this->confidence->value,
            'false_positive_risk' => $this->falsePositiveRisk,
        ];
    }
}
