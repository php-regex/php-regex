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
 * verdict gives.
 *
 * @internal
 */
final class RedosHints
{
    /**
     * The hint of a ReDoS issue: the evidence, then the advice.
     */
    public static function forAnalysis(RedosAnalysis $analysis, string $pattern): string
    {
        // The attack and its replay first: a hint cut short still shows them.
        $hints = RedosVerdict::evidence($analysis);
        $evidenceCount = \count($hints);

        if (!empty($analysis->recommendations)) {
            $hints = array_merge($hints, $analysis->recommendations);
        }

        if (null !== $analysis->vulnerablePart) {
            $hints[] = \sprintf('The risky part is: %s', $analysis->vulnerablePart);
        }

        $hotspot = $analysis->primaryHotspot();
        if (null !== $hotspot) {
            $hints[] = \sprintf('Hotspot offsets: %d-%d', $hotspot->start, $hotspot->end);
        }

        // Try to suggest specific fixes based on the pattern
        $patternHints = self::fixes($pattern, $analysis);
        if (!empty($patternHints)) {
            $hints = array_merge($hints, $patternHints);
        }

        if (\count($hints) === $evidenceCount) {
            $hints[] = 'Use possessive quantifiers (*+ instead of *, ++ instead of +, or {m,n}+ instead of {m,n}) to prevent ReDoS.';
        }

        // A replayed witness already says what the engine did, in its own line.
        // Any other reported confirmation that did not confirm is a skipped
        // one, which ran no check: its message gives the cause.
        if (RedosMode::Confirmed === $analysis->mode && null === $analysis->replayed && true === $analysis->confirmation?->confirmed) {
            $hints[] = 'Confirmation: bounded runtime checks observed evidence of excessive backtracking.';
        }

        $hints[] = 'Test with adversarial inputs like repeated strings followed by a non-matching character.';

        return implode(' ', $hints);
    }

    /**
     * @return array<string>
     */
    private static function fixes(string $pattern, RedosAnalysis $analysis): array
    {
        $hints = [];

        // Look for common vulnerable patterns and suggest fixes
        if (LibraryPcre::match('/\((?:(?:[^()][^)]*)?\)\+|\([^)]*(?:\+\)\)|\)\+))/', $pattern)) {
            $hints[] = 'Suggested (verify behavior): replace nested quantifiers like (a+)+ with atomic groups (?>a+) or possessive quantifiers a++.';
        }

        if (str_contains($pattern, '.*') && str_contains($pattern, '+')) {
            $hints[] = 'Suggested (verify behavior): use possessive quantifiers .*+ instead of .* to prevent backtracking.';
        }

        if (LibraryPcre::match('/\([^)]*\*\)/', $pattern)) {
            $hints[] = 'Suggested (verify behavior): replace * with *+ in groups to prevent backtracking or wrap in (?>...).';
        }

        // If we have a vulnerable subpattern, try to suggest a specific fix
        if (null !== $analysis->vulnerablePart) {
            $vulnerable = $analysis->vulnerablePart;
            if (LibraryPcre::match('/(\w+)\+(\)\+)/', $vulnerable, $matches)) {
                $char = $matches[1];
                $hints[] = "Suggested (verify behavior): replace ($char+)+ with atomic group (?>$char+) or possessive $char++.";
            }
        }

        return $hints;
    }
}
