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

namespace PHPRegex\Linter\Advice;

use PHPRegex\Linter\RedosVerdict;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosMode;

/**
 * What a lint issue on a ReDoS risk advises, after the evidence the
 * verdict gives: one line per sentence.
 *
 * @internal
 */
final class RedosHints
{
    /**
     * The prefix of a line that names a rewrite to try.
     */
    public const SUGGESTION = 'Suggested (verify behavior): ';

    private const POSSESSIVE = 'Use possessive quantifiers (*+ instead of *, ++ instead of +, or {m,n}+ instead of {m,n}) to prevent ReDoS.';

    private const TEST_ADVICE = 'Test with adversarial inputs like repeated strings followed by a non-matching character.';

    /**
     * The lines of a ReDoS issue's hint: the evidence, the risky part and
     * its offsets, one line per distinct finding message, one suggestion
     * line per distinct rewrite (the possessive advice when the analysis
     * says nothing at all),
     * the confirmation when it stands alone, the test advice last.
     *
     * @return list<string>
     */
    public static function forAnalysis(RedosAnalysis $analysis, string $pattern): array
    {
        $lines = RedosVerdict::evidence($analysis);

        if (null !== $analysis->vulnerablePart) {
            $lines[] = \sprintf('The risky part is: %s', $analysis->vulnerablePart);
        }

        $hotspot = $analysis->primaryHotspot();
        if (null !== $hotspot) {
            $lines[] = \sprintf('Hotspot offsets: %d-%d', $hotspot->start, $hotspot->end);
        }

        [$messages, $suggestions] = self::advice($analysis);
        $suggestions = array_values(array_unique([...$suggestions, ...self::fixes($pattern, $analysis)]));
        if ([] === $messages && [] === $suggestions) {
            $suggestions = [self::POSSESSIVE];
        }

        foreach ($messages as $message) {
            $lines[] = $message;
        }
        foreach ($suggestions as $suggestion) {
            $lines[] = self::SUGGESTION.$suggestion;
        }

        // A replayed witness already says what the engine did, in its own line.
        // Any other reported confirmation that did not confirm is a skipped
        // one, which ran no check: its message gives the cause.
        if (RedosMode::Confirmed === $analysis->mode && null === $analysis->replayed && true === $analysis->confirmation?->confirmed) {
            $lines[] = 'Confirmation: bounded runtime checks observed evidence of excessive backtracking.';
        }

        $lines[] = self::TEST_ADVICE;

        return $lines;
    }

    /**
     * The distinct messages of the analysis, what each finding says the
     * risk is: the reports that keep a suggestion apart from the evidence
     * carry them after it.
     *
     * @return list<string>
     */
    public static function messages(RedosAnalysis $analysis): array
    {
        return self::advice($analysis)[0];
    }

    /**
     * The distinct messages and the rewrites the analysis found, the
     * rewrites deduplicated by the caller beside the pattern's own fixes:
     * from its findings, or, for an analysis built without them, from its
     * recommendations split at the rewrite they carry.
     *
     * @return array{list<string>, list<string>}
     */
    private static function advice(RedosAnalysis $analysis): array
    {
        $messages = [];
        $suggestions = [];

        if ([] !== $analysis->findings) {
            foreach ($analysis->findings as $finding) {
                $messages[] = $finding->message;
                if (null !== $finding->suggestedRewrite) {
                    $suggestions[] = $finding->suggestedRewrite;
                }
            }
        } else {
            $marker = ' '.self::SUGGESTION;
            foreach ($analysis->recommendations as $recommendation) {
                $at = strpos($recommendation, $marker);
                if (false === $at) {
                    $messages[] = $recommendation;

                    continue;
                }

                $messages[] = substr($recommendation, 0, $at);
                $suggestions[] = substr($recommendation, $at + \strlen($marker));
            }
        }

        return [array_values(array_unique($messages)), $suggestions];
    }

    /**
     * The rewrites the pattern itself calls for, without their prefix.
     *
     * @return list<string>
     */
    private static function fixes(string $pattern, RedosAnalysis $analysis): array
    {
        $fixes = [];

        // Look for common vulnerable patterns and suggest fixes
        if (LibraryPcre::match('/\((?:(?:[^()][^)]*)?\)\+|\([^)]*(?:\+\)\)|\)\+))/', $pattern)) {
            $fixes[] = 'replace nested quantifiers like (a+)+ with atomic groups (?>a+) or possessive quantifiers a++.';
        }

        if (str_contains($pattern, '.*') && str_contains($pattern, '+')) {
            $fixes[] = 'use possessive quantifiers .*+ instead of .* to prevent backtracking.';
        }

        if (LibraryPcre::match('/\([^)]*\*\)/', $pattern)) {
            $fixes[] = 'replace * with *+ in groups to prevent backtracking or wrap in (?>...).';
        }

        // If we have a vulnerable subpattern, try to suggest a specific fix
        if (null !== $analysis->vulnerablePart) {
            $vulnerable = $analysis->vulnerablePart;
            if (LibraryPcre::match('/(\w+)\+(\)\+)/', $vulnerable, $matches)) {
                $char = $matches[1];
                $fixes[] = "replace ($char+)+ with atomic group (?>$char+) or possessive $char++.";
            }
        }

        return $fixes;
    }
}
