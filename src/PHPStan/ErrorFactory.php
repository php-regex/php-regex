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

namespace PHPRegex\PHPStan;

use PHPRegex\Automata\TrivialMatch;
use PHPRegex\Linter\Extraction\TargetMarker;
use PHPRegex\Linter\Rule\RuleCatalog;
use PHPRegex\Optimizer\RedosRepair;
use PHPRegex\Parser\Analysis\GroupNumbering;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Printer\DisplayEscaper;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosProof;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The errors the extension reports, worded once: their message, tip,
 * identifier and line. PHPStan baselines hold the messages as written, so
 * each one stays word for word for all of 2.x; what a tip says may grow.
 *
 * @internal
 *
 * @phpstan-import-type GroupReference from ReplacementReferences
 */
final class ErrorFactory
{
    private const MAX_PATTERN_DISPLAY_LENGTH = 50;

    private const DOC_LINKS = [
        'catastrophic backtracking' => 'https://php-regex.com/reference/rules/#catastrophic-backtracking',
        'possessive quantifiers' => 'https://php-regex.com/tutorial/04-quantifiers/#6-a-note-on-possessive-quantifiers',
        'atomic groups' => 'https://php-regex.com/tutorial/08-performance-redos/#use-atomic-groups-',
    ];

    /**
     * A pattern the engine running PHPStan refuses, passed to a parameter
     * marked as a regex: PHPStan core reports the same in a preg_*() call.
     */
    public static function invalid(string $refusal, int $lineNumber): IdentifierRuleError
    {
        return RuleErrorBuilder::message(\sprintf(
            'Regex pattern is invalid: %s%s',
            $refusal,
            str_ends_with($refusal, '.') ? '' : '.',
        ))
            ->line($lineNumber)
            ->identifier(RegexPatternArgumentRule::IDENTIFIER_INVALID)
            ->build();
    }

    /**
     * A pattern a version judged refuses, with the first line of the
     * refusal and the validation's hint as tip.
     *
     * @param string $label "PHP 8.2 with PCRE2 10.40", the version that refuses the pattern
     */
    public static function invalidForTarget(string $label, string $error, ?string $hint, int $lineNumber): IdentifierRuleError
    {
        $reason = explode("\n", $error)[0];
        $builder = RuleErrorBuilder::message(\sprintf(
            'Regex pattern is invalid for %s: %s%s',
            $label,
            $reason,
            str_ends_with($reason, '.') ? '' : '.',
        ))
            ->line($lineNumber)
            ->identifier(RegexPatternRule::IDENTIFIER_INVALID_FOR_TARGET);
        if (null !== $hint && '' !== $hint) {
            $builder = $builder->tip($hint);
        }

        return $builder->build();
    }

    /**
     * A ReDoS risk at or above the threshold. The message holds the verdict
     * class and the pattern only, frozen for 2.x so that a verdict fix
     * never breaks a baseline; the tip holds the verdict, the attack, the
     * proven repair and the recommendations.
     *
     * @param RedosRepair|null $repair the first rewrite the repairer offers, if any
     */
    public static function redos(string $pattern, RedosAnalysis $analysis, ?RedosRepair $repair, int $lineNumber): IdentifierRuleError
    {
        return RuleErrorBuilder::message(\sprintf(
            self::redosMessageFormat($analysis),
            self::displayPattern($pattern),
        ))
            ->line($lineNumber)
            ->tip(self::redosTip($analysis, $repair))
            ->identifier(RegexPatternRule::IDENTIFIER_REDOS)
            ->build();
    }

    /**
     * One attempt proven linear, the search retrying it is not: its own
     * message, frozen like the three others, and identifier.
     *
     * @param string      $message what the search cost means, as the lint issue says it
     * @param string|null $hint    the attack, as the lint issue says it
     */
    public static function searchCost(string $pattern, string $message, ?string $hint, int $lineNumber): IdentifierRuleError
    {
        return RuleErrorBuilder::message(\sprintf('Quadratic search (ReDoS): %s', self::displayPattern($pattern)))
            ->line($lineNumber)
            ->tip(self::searchCostTip($message, $hint))
            ->identifier(RegexPatternRule::IDENTIFIER_REDOS_SEARCH)
            ->build();
    }

    public static function optimization(string $pattern, string $optimized, int $lineNumber): IdentifierRuleError
    {
        return RuleErrorBuilder::message(\sprintf('Regex pattern can be optimized: "%s"', self::displayPattern($pattern)))
            ->line($lineNumber)
            ->identifier(RegexPatternRule::IDENTIFIER_OPTIMIZATION)
            ->tip(\sprintf('Consider using: %s', DisplayEscaper::escape($optimized)))
            ->build();
    }

    /**
     * A lint issue, under its own identifier: its hint, then the link to
     * the section of the rule reference that explains it.
     */
    public static function lintIssue(string $issueId, string $message, ?string $hint, int $lineNumber): IdentifierRuleError
    {
        $builder = RuleErrorBuilder::message($message)
            ->line($lineNumber)
            ->identifier($issueId);
        $tipParts = [];
        if (null !== $hint && '' !== $hint) {
            $tipParts[] = $hint;
        }
        $docsUrl = RuleCatalog::docsUrl($issueId);
        if (null !== $docsUrl) {
            $tipParts[] = 'Read more: '.$docsUrl;
        }
        if ([] !== $tipParts) {
            $builder = $builder->tip(implode("\n", $tipParts));
        }

        return $builder->build();
    }

    /**
     * A preg_match() a string function answers alike, as the automata prove.
     *
     * @param string $subject the subject argument, as PHP code
     */
    public static function trivialMatch(string $pattern, TrivialMatch $match, string $subject, int $lineNumber): IdentifierRuleError
    {
        return RuleErrorBuilder::message(\sprintf(
            'preg_match() with %s is %s.',
            self::displayPattern($pattern),
            $match->phpExpression($subject),
        ))
            ->line($lineNumber)
            ->identifier(RegexPatternRule::IDENTIFIER_TRIVIAL_MATCH)
            ->tip('Both say yes for exactly the same subjects, as the automata prove, and the function needs no regex engine; preg_match() returns 1 or 0 where the function returns true or false.')
            ->build();
    }

    /**
     * The message and tip for a reference of a replacement to a group the
     * pattern does not have, null for a reference to one of its groups.
     *
     * @param GroupReference $reference
     *
     * @return array{string, string|null}|null
     */
    public static function undefinedReplacementGroup(array $reference, GroupNumbering $numbering, string $pattern, string $functionName): ?array
    {
        $name = $reference['name'];
        if (null !== $name) {
            $numbers = $numbering->getNamedGroupNumbers($name);

            return [
                \sprintf('Replacement text %s is not a group reference: %s() reads group numbers only and leaves it in the result as written.', $reference['raw'], $functionName),
                [] === $numbers
                    ? \sprintf('%s has no group named "%s" either; refer to a group by its number, as in ${1}.', self::displayPattern($pattern), $name)
                    : \sprintf('Write ${%d}, the number of the group "%s".', $numbers[0], $name),
            ];
        }

        $group = (int) $reference['group'];
        $count = $numbering->maxGroupNumber;
        if ($group <= $count) {
            return null;
        }

        $message = \sprintf(
            'Replacement reference %s names group %d, but %s has %s: %s() substitutes an empty string.',
            $reference['raw'],
            $group,
            self::displayPattern($pattern),
            match ($count) {
                0 => 'no capturing group',
                1 => '1 capturing group',
                default => $count.' capturing groups',
            },
            $functionName,
        );

        // "$10" is group 10: group 1 followed by a "0" is written "${1}0".
        if ($group >= 10 && intdiv($group, 10) <= $count && !str_starts_with($reference['raw'], '${')) {
            return [$message, \sprintf('Two digits are read after "%1$s": write ${%2$d}%3$d for group %2$d followed by "%3$d".', $reference['raw'][0], intdiv($group, 10), $group % 10)];
        }

        return [$message, null];
    }

    /**
     * The error of a message and tip undefinedReplacementGroup() gives.
     */
    public static function replacementGroup(string $message, ?string $tip, int $lineNumber): IdentifierRuleError
    {
        $builder = RuleErrorBuilder::message($message)
            ->line($lineNumber)
            ->identifier(RegexPatternRule::IDENTIFIER_REPLACEMENT_UNDEFINED_GROUP);
        if (null !== $tip) {
            $builder = $builder->tip($tip);
        }

        return $builder->build();
    }

    /**
     * A "@regex-target" marker whose constraint cannot be read, with the
     * reason it gives.
     */
    public static function unreadableMarker(TargetMarker $marker): IdentifierRuleError
    {
        return RuleErrorBuilder::message((string) $marker->error)
            ->line($marker->line)
            ->tip('Write the versions the code runs on as a Composer constraint after "php", "pcre", or both.')
            ->identifier(RegexPatternRule::IDENTIFIER_TARGET_INVALID)
            ->build();
    }

    /**
     * A "@regex-target" marker that keeps no version the project is judged
     * for.
     *
     * @param string $judged the versions judged, as a Composer constraint
     */
    public static function emptyMarker(TargetMarker $marker, string $judged): IdentifierRuleError
    {
        return RuleErrorBuilder::message(\sprintf('The %s marker "%s" keeps no version the project is judged for (PHP %s): the patterns it covers are not judged.', $marker->marker, $marker->text, $judged))
            ->line($marker->line)
            ->tip('Widen the marker to versions of the range, or remove the code it covers if it never runs there.')
            ->identifier(RegexPatternRule::IDENTIFIER_TARGET_EMPTY)
            ->build();
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
            $end = self::escapeSequenceEnd($rendered, $offset);
            if ($end > $cut) {
                $cut = $offset;

                break;
            }
            $offset = $end - 1;
        }

        return substr($rendered, 0, $cut).'...';
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
        // The operand is optional: a lone backslash is a sequence of one.
        LibraryPcre::match('/\G\\\\(?:x(?:\{[^}]*\}?|[0-9A-Fa-f]{0,2})|[No]\{[^}]*\}?|[Pp](?:\{[^}]*\}?|[A-Za-z])|[gk](?:\{[^}]*\}?|<[^>]*>?|\'[^\']*\'?)|g[+-]?[0-9]+|c(?:[\xC0-\xFF][\x80-\xBF]*|[\x00-\xFF])?|0[0-7]{0,2}|[1-9][0-9]*|[\xC0-\xFF][\x80-\xBF]*|[\x00-\xFF])?/', $text, $match, 0, $offset);

        return $offset + max(1, \strlen($match[0] ?? ''));
    }

    /**
     * The message of a ReDoS error, frozen for 2.x so that a verdict fix never
     * breaks a baseline: the class only when the model proved it.
     */
    private static function redosMessageFormat(RedosAnalysis $analysis): string
    {
        if (RedosProof::Proven === $analysis->proof) {
            if (RedosComplexity::Exponential === $analysis->complexity) {
                return 'Exponential backtracking (ReDoS): %s';
            }

            if (RedosComplexity::Polynomial === $analysis->complexity) {
                return 'Polynomial backtracking (ReDoS): %s';
            }
        }

        return 'Potential backtracking (ReDoS): %s';
    }

    /**
     * "critical, exponential (proven)", "high, polynomial degree 3 (proven)",
     * "medium, heuristic": the severity, then how the verdict was reached.
     */
    private static function redosVerdict(RedosAnalysis $analysis): string
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
     * What the search cost means and its attack, as the lint issue says
     * them, then the documentation link. A "<" is written "\x3C", as in the
     * per-attempt tip.
     */
    private static function searchCostTip(string $message, ?string $hint): string
    {
        $lines = [str_replace('<', '\x3C', $message)];
        if (null !== $hint && '' !== $hint) {
            $lines[] = str_replace('<', '\x3C', $hint);
        }

        return implode("\n", $lines)."\n\nRead more about catastrophic backtracking: ".self::DOC_LINKS['catastrophic backtracking'];
    }

    /**
     * The verdict, the attack when there is a witness, the repair when it is
     * proven, the recommendations, a blank line, then the documentation
     * links.
     */
    private static function redosTip(RedosAnalysis $analysis, ?RedosRepair $repair): string
    {
        $lines = ['Severity: '.self::redosVerdict($analysis).'.'];
        if (null !== $analysis->witness) {
            // A "<" would open console markup in PHPStan's table output: written
            // "\x3C", the literal stays valid PHP for the same bytes. The renderer
            // prints "<" raw and never inside one of its escape sequences.
            $lines[] = 'Attack: '.str_replace('<', '\x3C', $analysis->witness->render());
        }

        // A rewrite proven to match the same subjects and proven linear.
        if (null !== $repair && $repair->isCertified()) {
            $lines[] = \sprintf('Proven repair: %s (same subjects%s, linear).', str_replace('<', '\x3C', $repair->pattern), true === $repair->sameMatches ? ', same matches' : '');
        }

        $recommendations = implode("\n", $analysis->recommendations);
        if ('' !== $recommendations) {
            $lines[] = $recommendations;
        }

        // Append links for relevant recommendations
        $additionalLinks = [];
        if (str_contains($recommendations, 'possessive')) {
            $additionalLinks[] = 'Read more about possessive quantifiers: '.self::DOC_LINKS['possessive quantifiers'];
        }
        if (str_contains($recommendations, 'atomic groups')) {
            $additionalLinks[] = 'Read more about atomic groups: '.self::DOC_LINKS['atomic groups'];
        }

        // Always append catastrophic backtracking link for ReDoS
        $additionalLinks[] = 'Read more about catastrophic backtracking: '.self::DOC_LINKS['catastrophic backtracking'];

        return implode("\n", $lines)."\n\n".implode("\n", $additionalLinks);
    }
}
