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

namespace PHPRegex\Redos\Internal\Backtrack;

use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Engine\PcreLimits;
use PHPRegex\Redos\Confirmation;
use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\ConfirmationSample;
use PHPRegex\Redos\RedosWitness;

/**
 * Replays witnesses on the running engine: each candidate built with one
 * pump, then two, and so on up to 64, until preg_match() gives up at the
 * backtrack limit; then each once more, at 5,000 bytes or just past, where
 * PCRE2 stops looking for the code unit an anchored pattern requires before
 * it backtracks. Every call, of every candidate, draws on one budget of
 * work, counted as the subject's length times the backtrack limit: the
 * replay ends on the same call on every machine, and within seconds.
 *
 * Where ini_set() is disabled the engine can neither set the limits nor
 * turn the JIT off: nothing is run, and the confirmation says why.
 *
 * @internal
 */
final readonly class WitnessReplayer
{
    /**
     * The JIT setting every replay runs under: the engine turns it off.
     */
    public const JIT_SETTING = '0';

    /**
     * The note a replay made without $matches carries: PHP retries an empty
     * match there, and the witness needs that call.
     */
    public const WITHOUT_MATCHES = Confirmation::WITHOUT_MATCHES;

    private const MAX_PUMPS = 64;

    /**
     * The subject length, in code units, from which PCRE2 (10.49) no longer
     * looks for the code unit an anchored pattern requires before it tries
     * the pattern; 5,000,000 for an unanchored one.
     */
    private const REQUIRED_UNIT_CAP = 5000;

    /**
     * The work every call of one replay may take together, in subject
     * bytes times the backtrack limit.
     */
    private const MAX_WORK = 4_000_000_000;

    public function __construct(private PcreEngine $engine = new PcreEngine()) {}

    /**
     * The confirmation of the first candidate the engine gives up on, with
     * that candidate; or the confirmation of the first one when none does.
     *
     * @param non-empty-list<RedosWitness> $candidates
     *
     * @return array{Confirmation, RedosWitness|null}
     */
    public function replay(string $regex, array $candidates, ConfirmationOptions $options, bool $withoutMatches): array
    {
        $skipped = self::skipped($options);
        if (null !== $skipped) {
            // Reached only where ini_set() is disabled; the tests run that case in a child PHP process.
            return [$skipped, null];
        }

        $limits = new PcreLimits($options->backtrackLimit, $options->recursionLimit);
        $work = 0;
        $first = null;

        foreach ($candidates as $candidate) {
            $last = null;
            for ($repetitions = 1; $repetitions <= self::MAX_PUMPS; $repetitions++) {
                $input = $candidate->build($repetitions);
                $work += \strlen($input) * $options->backtrackLimit;
                if ($work > self::MAX_WORK) {
                    break 2;
                }

                $sample = $this->sample($regex, $input, $limits, $options, $withoutMatches);
                if (\PREG_BACKTRACK_LIMIT_ERROR === $sample->pregErrorCode) {
                    return [$this->confirmation(true, [$sample], $options, $withoutMatches), $candidate];
                }

                $last = $sample;
            }

            $first ??= $this->confirmation(false, [$last], $options, $withoutMatches);
        }

        // Below the cap, PCRE2 rejects a subject without the code unit an
        // anchored pattern requires before it backtracks: each candidate
        // once more, past it.
        foreach ($candidates as $candidate) {
            $repetitions = max(1, (int) ceil((self::REQUIRED_UNIT_CAP - \strlen($candidate->build(0))) / max(1, \strlen($candidate->pump))));
            $input = $candidate->build($repetitions);
            $work += \strlen($input) * $options->backtrackLimit;
            if ($work > self::MAX_WORK) {
                break;
            }

            $sample = $this->sample($regex, $input, $limits, $options, $withoutMatches);
            if (\PREG_BACKTRACK_LIMIT_ERROR === $sample->pregErrorCode) {
                return [$this->confirmation(true, [$sample], $options, $withoutMatches), $candidate];
            }
        }

        return [$first ?? $this->confirmation(false, [], $options, $withoutMatches), null];
    }

    /**
     * The confirmation of a run the engine cannot make under the limits of
     * the options, with nothing run; null when it can.
     */
    public static function skipped(ConfirmationOptions $options): ?Confirmation
    {
        if (\function_exists('ini_set')) {
            return null;
        }

        // Reached only where ini_set() is disabled; the tests run that case in a child PHP process.
        return new Confirmation(false, [], null, null, null, 0, $options->timeoutMs, false, Confirmation::LIMITS_UNAVAILABLE);
    }

    /**
     * One call of the engine on the input, timed.
     */
    private function sample(string $regex, string $input, PcreLimits $limits, ConfirmationOptions $options, bool $withoutMatches): ConfirmationSample
    {
        $start = hrtime(true);
        $match = $withoutMatches
            ? $this->engine->test($regex, $input, $limits)
            : $this->engine->match($regex, $input, $limits);
        $durationMs = (hrtime(true) - $start) / 1_000_000;
        $preview = $options->previewLength > 0 ? substr($input, 0, $options->previewLength) : null;
        $code = \PREG_NO_ERROR === $match->errorCode ? null : $match->errorCode;

        return new ConfirmationSample(\strlen($input), $durationMs, $preview, $code, $match->error);
    }

    /**
     * @param list<ConfirmationSample> $samples
     */
    private function confirmation(bool $reproduced, array $samples, ConfirmationOptions $options, bool $withoutMatches): Confirmation
    {
        return new Confirmation(
            $reproduced,
            $samples,
            self::JIT_SETTING,
            $options->backtrackLimit,
            $options->recursionLimit,
            1,
            $options->timeoutMs,
            false,
            $reproduced ? 'backtrack_limit' : null,
            $withoutMatches ? self::WITHOUT_MATCHES : null,
            null,
        );
    }
}
