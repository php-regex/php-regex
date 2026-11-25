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

namespace PHPRegex\Linter;

use PHPRegex\Optimizer\RedosRepair;
use PHPRegex\Optimizer\RedosRepairer;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Printer\DisplayEscaper;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;

/**
 * The words the static analysis bridges give a ReDoS finding at a call, the
 * PHPStan extension and the Psalm plugin alike: a headline, the verdict
 * class and the pattern as a console shows it, frozen for 2.x so that a
 * verdict fix never breaks a baseline holding it, and the details, the
 * severity and how the verdict was reached, the attack and a proven repair,
 * or what a search cost means and its attack.
 *
 * A "<" in a detail is written "\x3C": a console reads it as markup, and
 * the literal stays valid PHP for the same byte.
 *
 * @internal
 */
final class RedosFinding
{
    /**
     * Where the headline cuts a pattern, in characters.
     */
    public const MAX_PATTERN_DISPLAY_LENGTH = 50;

    /**
     * The headline of a verdict on one attempt: the class only when the
     * model proved it.
     */
    public static function headline(string $pattern, RedosAnalysis $analysis): string
    {
        $class = 'Potential';
        if (RedosProof::Proven === $analysis->proof && RedosComplexity::Exponential === $analysis->complexity) {
            $class = 'Exponential';
        } elseif (RedosProof::Proven === $analysis->proof && RedosComplexity::Polynomial === $analysis->complexity) {
            $class = 'Polynomial';
        }

        return \sprintf('%s backtracking (ReDoS): %s', $class, self::displayPattern($pattern));
    }

    /**
     * The headline of a search cost: one attempt is linear, the search
     * retrying it is not.
     */
    public static function searchHeadline(string $pattern): string
    {
        return \sprintf('Quadratic search (ReDoS): %s', self::displayPattern($pattern));
    }

    /**
     * The details of a verdict on one attempt: the severity and how it was
     * reached, the attack when there is a witness, the repair when it is
     * proven.
     *
     * @return non-empty-list<string>
     */
    public static function details(RedosAnalysis $analysis, ?RedosRepair $repair): array
    {
        $lines = ['Severity: '.self::verdict($analysis).'.'];
        if (null !== $analysis->witness) {
            // The renderer prints "<" raw and never inside one of its escape
            // sequences.
            $lines[] = 'Attack: '.str_replace('<', '\x3C', $analysis->witness->render());
        }

        // A rewrite proven to match the same subjects and proven linear.
        if (null !== $repair && $repair->isCertified()) {
            $lines[] = \sprintf('Proven repair: %s (same subjects%s, linear).', str_replace('<', '\x3C', $repair->pattern), true === $repair->sameMatches ? ', same matches' : '');
        }

        return $lines;
    }

    /**
     * The details of a search cost: what it means and its attack, as the
     * lint issue says them.
     *
     * @param string      $message the message of the lint issue
     * @param string|null $hint    its hint
     *
     * @return non-empty-list<string>
     */
    public static function searchDetails(string $message, ?string $hint): array
    {
        $lines = [str_replace('<', '\x3C', $message)];
        if (null !== $hint && '' !== $hint) {
            $lines[] = str_replace('<', '\x3C', $hint);
        }

        return $lines;
    }

    /**
     * The first rewrite of the pattern the repairer offers, if any.
     */
    public static function repair(RegexParser $parser, string $pattern): ?RedosRepair
    {
        return (new RedosRepairer($parser))->firstRepair($pattern);
    }

    /**
     * The threshold a configured value names, lowercase: low, medium, high
     * or critical in any case. "safe" and "unknown" are the verdicts a
     * pattern gets, not thresholds.
     *
     * @throws InvalidRegexOptionException when the value names no threshold
     */
    public static function threshold(string $value): string
    {
        return RedosSeverity::fromConfig($value)->value;
    }

    /**
     * The pattern as the console shows it, on one line with the characters
     * that move or hide text escaped in the pattern's own mode, cut after
     * its first 50 characters (bytes when the rendering is not valid UTF-8)
     * and followed by "..." when cut. The cut never splits a character, nor
     * an escape sequence such as "\x{202E}", "\xC2", "\p{L}", "\pL" or "\d": the
     * text stops before the sequence it would otherwise fall inside.
     */
    public static function displayPattern(string $pattern, int $length = self::MAX_PATTERN_DISPLAY_LENGTH): string
    {
        $rendered = DisplayEscaper::escape($pattern);
        $modifiers = 1 === LibraryPcre::match('//u', $rendered) ? 'su' : 's';
        if (1 !== LibraryPcre::match('/\A.{'.$length.'}(?=.)/'.$modifiers, $rendered, $head)) {
            return $rendered;
        }

        $cut = \strlen($head[0]);
        for ($offset = 0; $offset < $cut; $offset++) {
            if ('\\' !== $rendered[$offset]) {
                continue;
            }
            // A sequence past the cut moves the cut before it; the loop,
            // past the sequence, ends.
            $end = self::escapeSequenceEnd($rendered, $offset);
            if ($end > $cut) {
                $cut = $offset;
            }
            $offset = $end - 1;
        }

        return substr($rendered, 0, $cut).'...';
    }

    /**
     * "critical, exponential (proven)", "high, polynomial degree 3 (proven)",
     * "medium, heuristic": the severity, then how the verdict was reached.
     */
    private static function verdict(RedosAnalysis $analysis): string
    {
        $how = match ($analysis->proof) {
            // The degree is only set on a polynomial.
            RedosProof::Proven => $analysis->complexity->value
                .(null === $analysis->degree ? '' : ' degree '.$analysis->degree)
                .' (proven)',
            RedosProof::Heuristic => 'heuristic',
            RedosProof::BudgetExceeded => 'heuristic (budget exceeded)',
            RedosProof::NotAnalyzed => 'not analyzed',
        };

        return $analysis->severity->value.', '.$how;
    }

    /**
     * The offset just past the escape sequence a backslash at $offset opens:
     * "\x" with its braced code point or up to two hex digits; "\N" or "\o"
     * with its braced operand ("\N{U+41}"); "\p" or "\P" with its braced
     * operand ("\p{L}") or the one letter naming a property ("\pL", "\pZs"
     * being "\pZ" then "s"); "\g" or
     * "\k" with its operand in braces, angle brackets or quotes ("\g{1}",
     * "\k<n>", "\k'n'"), or "\g" with an optionally signed number ("\g1",
     * "\g-1", "\g+1"); "\c" with the character it controls; "\0" with up
     * to two more octal digits; "\1" to "\9" with every digit after it, the
     * octal escape or back reference PCRE reads there being no longer; an
     * operand left open runs to the end of the text. Otherwise the backslash
     * and the whole character after it.
     */
    private static function escapeSequenceEnd(string $text, int $offset): int
    {
        // The operand is optional: a lone backslash is a sequence of one,
        // and the backslash at $offset always matches.
        LibraryPcre::match('/\G\\\\(?:x(?:\{[^}]*\}?|[0-9A-Fa-f]{0,2})|[No]\{[^}]*\}?|[Pp](?:\{[^}]*\}?|[A-Za-z])|[gk](?:\{[^}]*\}?|<[^>]*>?|\'[^\']*\'?)|g[+-]?[0-9]+|c(?:[\xC0-\xFF][\x80-\xBF]*|[\x00-\xFF])?|0[0-7]{0,2}|[1-9][0-9]*|[\xC0-\xFF][\x80-\xBF]*|[\x00-\xFF])?/', $text, $match, 0, $offset);

        return $offset + \strlen($match[0]);
    }
}
