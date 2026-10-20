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

namespace PHPRegex\Linter\Formatter;

use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\LintReport;
use PHPRegex\Parser\Engine\LibraryPcre;

/**
 * What the console formatters, the CLI's and the bridges', show of a
 * result: its pattern, and each message with the caret snippet under it.
 *
 * @phpstan-import-type LintResult from LintReport
 *
 * @internal
 */
final class ConsoleResultText
{
    /**
     * The pattern a result quotes: its own, else the original of its first
     * optimization; null when neither has one.
     *
     * @phpstan-param LintResult $result
     */
    public static function pattern(array $result): ?string
    {
        $pattern = $result['pattern'];
        if (\is_string($pattern) && '' !== $pattern) {
            return $pattern;
        }

        $first = $result['optimizations'][0] ?? null;

        return null === $first ? null : $first['optimization']->original;
    }

    /**
     * The message of an issue in display form, and under it the caret
     * snippet its validation carries apart: the line of the pattern with the
     * character at fault, spelled in the pattern's mode.
     */
    public static function messageWithSnippet(LintIssue $issue, ?string $pattern): string
    {
        $message = ReportSpelling::displayText($issue->message);
        $validation = $issue->validation;
        if (null === $validation || null === $validation->caretSnippet || '' === $validation->caretSnippet) {
            return $message;
        }

        return $message."\n".ReportSpelling::displaySnippet($validation->caretSnippet, $pattern);
    }

    /**
     * The text with each "Line N:" that starts a line blanked out, so the
     * text under it keeps its column.
     */
    public static function withoutLineLabels(string $message): string
    {
        return LibraryPcre::replaceCallback(
            '/^Line \d+:/m',
            static fn (array $matches): string => str_repeat(' ', \strlen($matches[0])),
            $message,
        ) ?? $message;
    }
}
