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

namespace PHPRegex\Linter\Internal;

use PHPRegex\Redos\Confirmation;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;

/**
 * The words of a ReDoS verdict in a lint report: the analysis' headline
 * qualified by its severity and confidence, and the evidence lines, the
 * attack and what the engine did with it.
 *
 * The attack is the witness in its escaped form: a report never receives
 * the raw bytes of the input.
 *
 * @internal
 */
final class RedosVerdict
{
    /**
     * The headline, then the severity and confidence, and what qualifies
     * them: a budget the model ran out of, a confirmation by sampling, an
     * analysis error.
     */
    public static function message(RedosAnalysis $analysis): string
    {
        $details = [
            'severity: '.strtoupper($analysis->severity->value),
            'confidence: '.strtoupper($analysis->confidenceLevel()->value),
        ];

        if (RedosProof::BudgetExceeded === $analysis->proof && RedosSeverity::Safe !== $analysis->severity) {
            $details[] = 'budget exceeded';
        }

        if ($analysis->isConfirmed() && null === $analysis->replayed) {
            $evidence = $analysis->confirmation?->evidence;
            $details[] = null !== $evidence ? 'confirmed, evidence: '.$evidence : 'confirmed';
        }

        if (null !== $analysis->error) {
            $details[] = 'error: '.$analysis->error;
        }

        return \sprintf('%s. %s.', $analysis->headline(), ucfirst(implode(', ', $details)));
    }

    /**
     * The attack, then the outcome of its replay when one was attempted.
     *
     * @return list<string>
     */
    public static function evidence(RedosAnalysis $analysis): array
    {
        $lines = [];

        if (null !== $analysis->witness) {
            $lines[] = 'Attack: '.$analysis->witness->render();
        }

        if (true === $analysis->replayed) {
            $lines[] = self::replayedLine($analysis);
        } elseif (false === $analysis->replayed) {
            $lines[] = \sprintf("Not reproduced on PCRE2 %s (PCRE's optimisations defuse it).", $analysis->pcreVersion);
        }

        return $lines;
    }

    /**
     * "Replayed on PCRE2 10.49: preg_match fails from length 17
     * (backtrack_limit 100000, JIT off)", or "preg_match() without $matches
     * fails" when the replay ran that call: the length is the byte length of
     * the build that failed, and the limit is the one that build actually
     * hit (the backtrack limit, the recursion limit or the JIT stack), with
     * the JIT setting the confirmation reports.
     */
    private static function replayedLine(RedosAnalysis $analysis): string
    {
        $confirmation = $analysis->confirmation;
        $failure = null;

        foreach ($confirmation->samples ?? [] as $sample) {
            if (\in_array($sample->pregErrorCode, [\PREG_BACKTRACK_LIMIT_ERROR, \PREG_RECURSION_LIMIT_ERROR, \PREG_JIT_STACKLIMIT_ERROR], true)) {
                $failure = $sample;

                break;
            }
        }

        $settings = [];
        $limit = match ($failure?->pregErrorCode) {
            \PREG_RECURSION_LIMIT_ERROR => null !== $confirmation?->recursionLimit ? 'recursion_limit '.$confirmation->recursionLimit : null,
            \PREG_JIT_STACKLIMIT_ERROR => 'JIT stack limit',
            default => null !== $confirmation?->backtrackLimit ? 'backtrack_limit '.$confirmation->backtrackLimit : null,
        };
        if (null !== $limit) {
            $settings[] = $limit;
        }

        if (null !== $confirmation?->jitSetting) {
            $settings[] = '0' === $confirmation->jitSetting ? 'JIT off' : 'JIT on';
        }

        return \sprintf(
            'Replayed on PCRE2 %s: %s fails%s%s.',
            $analysis->pcreVersion,
            Confirmation::WITHOUT_MATCHES === $confirmation?->note ? Confirmation::WITHOUT_MATCHES : 'preg_match',
            null !== $failure ? ' from length '.$failure->inputLength : '',
            [] !== $settings ? ' ('.implode(', ', $settings).')' : '',
        );
    }
}
