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

use PHPRegex\Parser\Engine\IniFlag;

/**
 * Represents evidence gathered from a bounded confirmation run.
 */
final class Confirmation implements \JsonSerializable
{
    /**
     * The note of a replay made with preg_match() without $matches: PHP
     * retries an empty match there, and the witness needs that call.
     */
    public const WITHOUT_MATCHES = 'preg_match() without $matches';

    /**
     * The evidence of a run skipped because the engine cannot set its
     * limits, as where ini_set() is disabled: nothing ran, and a verdict
     * stands on its proof alone.
     */
    public const LIMITS_UNAVAILABLE = 'engine limits unavailable';

    /**
     * @internal built by ConfirmationRunner::confirm() and RedosAnalyzer::analyze() in confirmed mode
     *
     * @param array<ConfirmationSample> $samples
     */
    public function __construct(
        public readonly bool $confirmed,
        public readonly array $samples,
        public readonly ?string $jitSetting,
        public readonly ?int $backtrackLimit,
        public readonly ?int $recursionLimit,
        public readonly int $iterations,
        public readonly float $timeoutMs,
        public readonly bool $timedOut = false,
        public readonly ?string $evidence = null,
        public readonly ?string $note = null,
        public readonly ?string $error = null,
    ) {}

    /**
     * Whether nothing ran because the engine could not set the limits of
     * the run: not confirmed, no sample, no iteration, and the evidence
     * LIMITS_UNAVAILABLE. False for any run that happened, whether it
     * reproduced or not.
     */
    public function wasSkipped(): bool
    {
        return !$this->confirmed
            && [] === $this->samples
            && 0 === $this->iterations
            && self::LIMITS_UNAVAILABLE === $this->evidence;
    }

    /**
     * @return array{confirmed: bool, samples: array<int|string, ConfirmationSample>, jit_setting: bool|null, backtrack_limit: int|null, recursion_limit: int|null, iterations: int, timeout_ms: float, timed_out: bool, evidence: string|null, note: string|null, error: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'confirmed' => $this->confirmed,
            'samples' => $this->samples,
            'jit_setting' => null === $this->jitSetting ? null : IniFlag::isOn($this->jitSetting),
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
