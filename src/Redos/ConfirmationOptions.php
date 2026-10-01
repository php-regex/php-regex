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
 * Limits and controls the confirmation (runtime) probe.
 */
final readonly class ConfirmationOptions
{
    public int $minInputLength;

    public int $maxInputLength;

    public int $steps;

    public int $iterations;

    public float $timeoutMs;

    public int $backtrackLimit;

    public int $recursionLimit;

    public int $previewLength;

    public function __construct(
        int $minInputLength = 16,
        int $maxInputLength = 128,
        int $steps = 3,
        int $iterations = 3,
        float $timeoutMs = 50.0,
        int $backtrackLimit = 100_000,
        int $recursionLimit = 10_000,
        int $previewLength = 64,
    ) {
        $this->minInputLength = max(1, $minInputLength);
        $this->maxInputLength = max($this->minInputLength, $maxInputLength);
        $this->steps = max(1, $steps);
        $this->iterations = max(1, $iterations);
        $this->timeoutMs = max(1.0, $timeoutMs);
        $this->backtrackLimit = max(1, $backtrackLimit);
        $this->recursionLimit = max(1, $recursionLimit);
        $this->previewLength = max(0, $previewLength);
    }
}
