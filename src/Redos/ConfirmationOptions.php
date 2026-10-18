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

use PHPRegex\Parser\Exception\InvalidRegexOptionException;

/**
 * Limits and controls the confirmation (runtime) probe.
 */
final class ConfirmationOptions
{
    /**
     * @param int   $minInputLength the shortest subject probed
     * @param int   $maxInputLength the longest subject probed, at least $minInputLength
     * @param int   $steps          the subject lengths probed, doubling up to the longest
     * @param int   $iterations     the runs per length
     * @param float $timeoutMs      the average duration of a run that stops the probe, at least 1.0
     * @param int   $backtrackLimit pcre.backtrack_limit during the probe
     * @param int   $recursionLimit pcre.recursion_limit during the probe
     * @param int   $previewLength  the subject bytes kept per sample; 0 keeps none
     *
     * @throws InvalidRegexOptionException when a length, a count or a limit is below 1, the longest subject below the shortest, the timeout below 1.0 or not a number, or the preview below 0
     */
    public function __construct(
        public readonly int $minInputLength = 16,
        public readonly int $maxInputLength = 128,
        public readonly int $steps = 3,
        public readonly int $iterations = 3,
        public readonly float $timeoutMs = 50.0,
        public readonly int $backtrackLimit = 100_000,
        public readonly int $recursionLimit = 10_000,
        public readonly int $previewLength = 64,
    ) {
        $floors = [
            'minInputLength' => [$minInputLength, 1],
            'maxInputLength' => [$maxInputLength, max(1, $minInputLength)],
            'steps' => [$steps, 1],
            'iterations' => [$iterations, 1],
            'backtrackLimit' => [$backtrackLimit, 1],
            'recursionLimit' => [$recursionLimit, 1],
            'previewLength' => [$previewLength, 0],
        ];
        foreach ($floors as $name => [$value, $floor]) {
            if ($value < $floor) {
                throw new InvalidRegexOptionException(\sprintf('"%s" must be at least %d, %d given.', $name, $floor, $value));
            }
        }

        if (!($timeoutMs >= 1.0)) {
            throw new InvalidRegexOptionException(\sprintf('"timeoutMs" must be at least 1.0, %s given.', var_export($timeoutMs, true)));
        }
    }
}
