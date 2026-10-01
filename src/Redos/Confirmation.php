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
 * Represents evidence gathered from a bounded confirmation run.
 */
final readonly class Confirmation implements \JsonSerializable
{
    /**
     * The note of a replay made with preg_match() without $matches: PHP
     * retries an empty match there, and the witness needs that call.
     */
    public const WITHOUT_MATCHES = 'preg_match() without $matches';

    /**
     * @param array<ConfirmationSample> $samples
     */
    public function __construct(
        public bool $confirmed,
        public array $samples,
        public ?string $jitSetting,
        public ?int $backtrackLimit,
        public ?int $recursionLimit,
        public int $iterations,
        public float $timeoutMs,
        public bool $timedOut = false,
        public ?string $evidence = null,
        public ?string $note = null,
        public ?string $error = null,
    ) {}

    /**
     * @return array{confirmed: bool, samples: array<int|string, ConfirmationSample>, jit_setting: string|null, backtrack_limit: int|null, recursion_limit: int|null, iterations: int, timeout_ms: float, timed_out: bool, evidence: string|null, note: string|null, error: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'confirmed' => $this->confirmed,
            'samples' => $this->samples,
            'jit_setting' => $this->jitSetting,
            'backtrack_limit' => $this->backtrackLimit,
            'recursion_limit' => $this->recursionLimit,
            'iterations' => $this->iterations,
            'timeout_ms' => $this->timeoutMs,
            'timed_out' => $this->timedOut,
            'evidence' => $this->evidence,
            'note' => $this->note,
            'error' => $this->error,
        ];
    }
}
