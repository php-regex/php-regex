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

use PHPRegex\Parser\DelimitedPattern;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Engine\PcreLimits;
use PHPRegex\Redos\Internal\Backtrack\WitnessReplayer;
use PHPRegex\Redos\Internal\InputGenerator;

/**
 * Runs the pattern on inputs growing in length, through the engine: without
 * the JIT, under the limits of the options, the ini left as it was found.
 *
 * An exponential verdict with a witness is replayed: the witness is built
 * with one pump, then two, and so on up to 64, until preg_match() gives up
 * at the backtrack limit of the options, within a fixed budget of work. The
 * failing sample is the shortest build that gives up there: the same input
 * does on every run, whatever the machine.
 */
final readonly class ConfirmationRunner implements ConfirmationRunnerInterface
{
    /**
     * The engine runs every pattern without the JIT: this is the setting the
     * confirmation reports, whatever the ini says and whether or not the
     * options asked for it.
     */
    private const JIT_SETTING = '0';

    public function __construct(private PcreEngine $engine = new PcreEngine()) {}

    public function confirm(string $regex, RedosAnalysis $analysis, ?ConfirmationOptions $options = null): Confirmation
    {
        $options ??= new ConfirmationOptions();
        $limits = new PcreLimits($options->backtrackLimit, $options->recursionLimit);

        if (null !== $analysis->witness && RedosComplexity::Exponential === $analysis->complexity) {
            return $this->replay($regex, $analysis->witness, $options);
        }

        [$baseChar, $suffixChar, $baseLength] = $this->resolveBaseInput($regex, $analysis, $options);
        $lengths = $this->buildLengths($baseLength, $options);

        $samples = [];
        $confirmed = false;
        $timedOut = false;
        $evidence = null;

        foreach ($lengths as $length) {
            $input = $this->buildInput($baseChar, $suffixChar, $length);
            $preview = $options->previewLength > 0 ? substr($input, 0, $options->previewLength) : null;

            $durationMs = 0.0;
            $iterationsRun = 0;
            $pregErrorCode = null;
            $pregError = null;

            for ($i = 0; $i < $options->iterations; $i++) {
                $iterationsRun++;
                $start = hrtime(true);
                $match = $this->engine->match($regex, $input, $limits);
                $elapsed = (hrtime(true) - $start) / 1_000_000;
                $durationMs += $elapsed;

                $errorCode = $match->errorCode;
                if (\PREG_NO_ERROR !== $errorCode) {
                    $pregErrorCode = $errorCode;
                    $pregError = $match->error;
                }

                $evidenceForError = $this->evidenceForError($errorCode);
                if (null !== $evidenceForError) {
                    $confirmed = true;
                    $evidence = $evidenceForError;

                    break;
                }

                if (($durationMs / $iterationsRun) > $options->timeoutMs) {
                    $timedOut = true;

                    break;
                }
            }

            $averageMs = $iterationsRun > 0 ? $durationMs / $iterationsRun : 0.0;
            $samples[] = new ConfirmationSample(
                $length,
                $averageMs,
                $preview,
                $pregErrorCode,
                $pregError,
            );

            if ($confirmed || $timedOut) {
                break;
            }
        }

        return new Confirmation(
            $confirmed,
            $samples,
            self::JIT_SETTING,
            $options->backtrackLimit,
            $options->recursionLimit,
            $options->iterations,
            $options->timeoutMs,
            $timedOut,
            $evidence,
            null,
            null,
        );
    }

    /**
     * The shortest build of the witness that makes the engine give up, or
     * the longest one tried when none does.
     */
    private function replay(string $regex, RedosWitness $witness, ConfirmationOptions $options): Confirmation
    {
        return (new WitnessReplayer($this->engine))->replay($regex, [$witness], $options, false)[0];
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private function resolveBaseInput(string $regex, RedosAnalysis $analysis, ConfirmationOptions $options): array
    {
        $flags = '';

        try {
            $patternInfo = DelimitedPattern::fromDelimited($regex);
            $flags = $patternInfo->flags;
        } catch (\Throwable) {
            $flags = '';
        }

        $baseInput = null;
        $culprit = $analysis->getCulpritNode();
        if (null !== $culprit) {
            $baseInput = (new InputGenerator())->generate($culprit, $flags, $analysis->severity);
        }

        if (null === $baseInput || '' === $baseInput) {
            $baseInput = 'a!';
        }

        $baseChar = $baseInput[0] ?? 'a';
        $suffixChar = $baseInput[\strlen($baseInput) - 1] ?? '!';

        $length = max(\strlen($baseInput), $options->minInputLength);

        return [$baseChar, $suffixChar, $length];
    }

    /**
     * @return array<int>
     */
    private function buildLengths(int $baseLength, ConfirmationOptions $options): array
    {
        $lengths = [];
        $length = min($baseLength, $options->maxInputLength);
        for ($i = 0; $i < $options->steps; $i++) {
            $lengths[] = $length;
            if ($length >= $options->maxInputLength) {
                break;
            }
            $length = min($length * 2, $options->maxInputLength);
            if (\in_array($length, $lengths, true)) {
                break;
            }
        }

        return $lengths;
    }

    private function buildInput(string $baseChar, string $suffixChar, int $length): string
    {
        $prefixLength = max(1, $length - 1);

        return str_repeat($baseChar, $prefixLength).$suffixChar;
    }

    private function evidenceForError(int $errorCode): ?string
    {
        if (\PREG_BACKTRACK_LIMIT_ERROR === $errorCode) {
            return 'backtrack_limit';
        }

        if (\PREG_RECURSION_LIMIT_ERROR === $errorCode) {
            return 'recursion_limit';
        }

        if (\defined('PREG_JIT_STACKLIMIT_ERROR') && \PREG_JIT_STACKLIMIT_ERROR === $errorCode) {
            return 'jit_stack_limit';
        }

        return null;
    }
}
