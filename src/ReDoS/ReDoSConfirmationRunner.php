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

namespace RegexParser\ReDoS;

use RegexParser\Internal\Ascii;
use RegexParser\RegexPattern;

final class ReDoSConfirmationRunner implements ReDoSConfirmationRunnerInterface
{
    public function confirm(string $regex, ReDoSAnalysis $analysis, ?ReDoSConfirmOptions $options = null): ReDoSConfirmation
    {
        $options ??= new ReDoSConfirmOptions();

        $originalJit = ini_get('pcre.jit');
        $originalBacktrack = ini_get('pcre.backtrack_limit');
        $originalRecursion = ini_get('pcre.recursion_limit');

        $note = null;
        $jitDisableRequested = $options->disableJit;

        try {
            if ($options->disableJit) {
                $jitResult = ini_set('pcre.jit', '0');
                if (false === $jitResult) {
                    $note = 'Unable to disable JIT at runtime (pcre.jit may be system-level).';
                }
            }

            ini_set('pcre.backtrack_limit', (string) $options->backtrackLimit);
            ini_set('pcre.recursion_limit', (string) $options->recursionLimit);

            $jitSetting = ini_get('pcre.jit');
            $backtrackLimit = $this->parseIniInt(ini_get('pcre.backtrack_limit'));
            $recursionLimit = $this->parseIniInt(ini_get('pcre.recursion_limit'));

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
                    @preg_match($regex, $input);
                    $elapsed = (hrtime(true) - $start) / 1_000_000;
                    $durationMs += $elapsed;

                    $errorCode = preg_last_error();
                    if (\PREG_NO_ERROR !== $errorCode) {
                        $pregErrorCode = $errorCode;
                        $pregError = preg_last_error_msg();
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
                $samples[] = new ReDoSConfirmationSample(
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

            return new ReDoSConfirmation(
                $confirmed,
                $samples,
                false !== $jitSetting ? (string) $jitSetting : null,
                $backtrackLimit,
                $recursionLimit,
                $options->iterations,
                $options->timeoutMs,
                $timedOut,
                $evidence,
                $note,
                null,
                $jitDisableRequested,
            );
        } catch (\Throwable $e) {
            return new ReDoSConfirmation(
                false,
                [],
                false !== $originalJit ? (string) $originalJit : null,
                $this->parseIniInt($originalBacktrack),
                $this->parseIniInt($originalRecursion),
                $options->iterations,
                $options->timeoutMs,
                false,
                null,
                $note,
                $e->getMessage(),
                $jitDisableRequested,
            );
        } finally {
            if (false !== $originalJit) {
                @ini_set('pcre.jit', (string) $originalJit);
            }
            if (false !== $originalBacktrack) {
                @ini_set('pcre.backtrack_limit', (string) $originalBacktrack);
            }
            if (false !== $originalRecursion) {
                @ini_set('pcre.recursion_limit', (string) $originalRecursion);
            }
        }
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private function resolveBaseInput(string $regex, ReDoSAnalysis $analysis, ReDoSConfirmOptions $options): array
    {
        $flags = '';

        try {
            $patternInfo = RegexPattern::fromDelimited($regex);
            $flags = $patternInfo->flags;
        } catch (\Throwable) {
            $flags = '';
        }

        $baseInput = null;
        $culprit = $analysis->getCulpritNode();
        if (null !== $culprit) {
            $baseInput = (new ReDoSInputGenerator())->generate($culprit, $flags, $analysis->severity);
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
    private function buildLengths(int $baseLength, ReDoSConfirmOptions $options): array
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

    private function parseIniInt(mixed $value): ?int
    {
        if (!\is_string($value) && !\is_int($value)) {
            return null;
        }

        if (\is_string($value)) {
            $value = trim($value);
            if ('' === $value || !Ascii::isDigit($value)) {
                return null;
            }
        }

        return (int) $value;
    }
}
