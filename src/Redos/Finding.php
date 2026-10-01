<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Redos;

/**
 * Describes a specific ReDoS risk detection.
 */
final readonly class Finding implements \JsonSerializable
{
    public function __construct(
        public RedosSeverity $severity,
        public string $message,
        public string $pattern,
        public ?string $trigger = null,
        public ?string $suggestedRewrite = null,
        public RedosConfidence $confidence = RedosConfidence::Medium,
        public ?string $falsePositiveRisk = null,
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
