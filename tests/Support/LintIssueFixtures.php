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

namespace PHPRegex\Tests\Support;

use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\RedosVerdict;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;

/**
 * Lint issues of each kind, for the tests of the reports: a finding of a
 * rule, an invalid pattern with its caret snippet and fix, and a ReDoS
 * verdict with its attack and advice. The reports place an issue where its
 * result lies, so each one sits in test.php on line 1.
 */
final class LintIssueFixtures
{
    /**
     * The pattern invalid() reads: a quantifier with nothing to repeat.
     */
    public const INVALID_PATTERN = '/a**/';

    public const INVALID_CODE = 'regex.quantifier.nothing_to_repeat';

    public const INVALID_HINT = 'Put the quantifier after the item it repeats, or escape it with a backslash to match it literally.';

    /**
     * The pattern redos() analyses.
     */
    public const REDOS_PATTERN = '/(a+)+$/';

    public const REDOS_EVIDENCE = 'Attack: "a" x n . "!"';

    /**
     * A finding of a lint rule.
     */
    public static function finding(LintSeverity $severity, string $message, ?string $code = null, ?string $hint = null): LintIssue
    {
        return new LintIssue($severity, $message, 'test.php', 1, issueId: $code, hint: $hint);
    }

    /**
     * An invalid pattern: a syntax error, its caret snippet under the
     * message and its fix as the suggestion.
     */
    public static function invalid(string $message = 'Invalid regex pattern', LintSeverity $severity = LintSeverity::Error): LintIssue
    {
        return new LintIssue($severity, $message, 'test.php', 1, validation: RegexParser::create(['cache' => null])->validate(self::INVALID_PATTERN));
    }

    /**
     * A ReDoS verdict: its attack under the message, the first advice of
     * the analysis as the suggestion.
     */
    public static function redos(LintSeverity $severity, string $message, ?string $code = RedosVerdict::ISSUE_ID): LintIssue
    {
        $analysis = (new RedosAnalyzer(RegexParser::create(['cache' => null])))->analyze(self::REDOS_PATTERN, RedosSeverity::Low, RedosMode::Theoretical);

        return new LintIssue($severity, $message, 'test.php', 1, issueId: $code, analysis: $analysis);
    }

    /**
     * The first advice of the analysis redos() carries.
     */
    public static function redosAdvice(): string
    {
        return (string) self::redos(LintSeverity::Error, '')->diagnostic()->suggestion;
    }
}
