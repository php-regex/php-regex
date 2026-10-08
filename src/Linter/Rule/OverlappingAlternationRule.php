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

namespace PHPRegex\Linter\Rule;

use PHPRegex\Linter\Rule\Support\NodePredicates;
use PHPRegex\Parser\Internal\DisplayEscaper;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\SequenceNode;

/**
 * Detects (.|\n) anti-patterns, overlapping literal alternatives, and
 * overlapping alternative character sets.
 *
 * The three rule IDs stay in one rule because a literal-overlap finding
 * suppresses the semantic charset check, preserving the historical
 * emission behavior.
 *
 * @internal
 */
final class OverlappingAlternationRule extends AbstractLintRule
{
    public function getRuleIds(): array
    {
        return [
            'regex.lint.alternation.dotNewline',
            'regex.lint.alternation.overlap',
            'regex.lint.overlap.charset',
        ];
    }

    public function getNodeTypes(): array
    {
        return [AlternationNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof AlternationNode) {
            return [];
        }

        $issues = [];

        // Check for (.|\n) anti-pattern before generic overlap detection.
        // This produces a more specific and actionable message.
        if ($this->isDotNewlineAntiPattern($node)) {
            $hasSFlag = str_contains($context->activeFlags(), 's');
            $hint = $hasSFlag
                ? 'Replace (.|\n) with [\s\S] for clarity, or remove the surrounding group if the "s" flag is already active.'
                : 'Use the "s" (PCRE_DOTALL) flag to make "." match newlines, or use [\s\S] instead of (.|\n).';

            $issues[] = new RuleViolation(
                'regex.lint.alternation.dotNewline',
                'Alternation (.|\n) is an anti-pattern for matching any character including newlines.',
                $node->startPosition,
                $hint,
            );
        }

        // Each literal branch, and whether it reads its letters in either
        // case: under i, or through a group turning i on.
        $literals = [];
        $flags = $context->flagsAtEachChild($node);
        foreach ($node->alternatives as $index => $alt) {
            $literal = $this->extractLiteralSequence($alt);
            if (null === $literal) {
                continue;
            }

            $caseless = str_contains($flags[$index] ?? '', 'i') || NodePredicates::turnsCaselessOn($alt);
            $literals[$literal.'|'.($caseless ? 'i' : '')] = [$literal, $caseless];
        }

        // Check for literal-based overlaps
        if ([] !== $literals) {
            // Only flag overlapping literal branches when they're inside an unbounded quantifier.
            // Overlapping alternations without a quantifier (e.g., /\r\n|\r|\n/ or /^(978|979)/)
            // do not pose a ReDoS risk because there's no exponential backtracking.
            if ($context->isInsideUnboundedQuantifier()) {
                $unique = array_values($literals);
                $total = \count($unique);
                for ($i = 0; $i < $total; $i++) {
                    for ($j = $i + 1; $j < $total; $j++) {
                        [$a, $aCaseless] = $unique[$i];
                        [$b, $bCaseless] = $unique[$j];
                        if ('' === $a || '' === $b) {
                            continue;
                        }

                        // A caseless branch meets the other in either case.
                        [$readA, $readB] = $aCaseless || $bCaseless ? [self::fold($a, $context), self::fold($b, $context)] : [$a, $b];
                        if (str_starts_with($readA, $readB) || str_starts_with($readB, $readA)) {
                            $issues[] = new RuleViolation(
                                'regex.lint.alternation.overlap',
                                \sprintf('Alternation branches "%s" and "%s" overlap.', self::spell($a, $context), self::spell($b, $context)),
                                $node->startPosition,
                                'Consider using atomic groups (?>...) to prevent backtracking. Do not reorder overlapping alternatives as it changes match semantics.',
                            );

                            // A literal overlap suppresses the semantic charset check.
                            return $issues;
                        }
                    }
                }
            }
        }

        // Check for semantic overlaps using character set analysis
        $charsetIssue = $this->checkSemanticOverlaps($node, $context);
        if (null !== $charsetIssue) {
            $issues[] = $charsetIssue;
        }

        return $issues;
    }

    private function checkSemanticOverlaps(AlternationNode $node, LintContext $context): ?RuleViolation
    {
        // Only flag overlapping alternations when they're inside an unbounded quantifier.
        // Overlapping alternations without a quantifier (e.g., /\r\n|\r|\n/ or /^(978|979)/)
        // do not pose a ReDoS risk because there's no exponential backtracking.
        if (!$context->isInsideUnboundedQuantifier()) {
            return null;
        }

        // Each alternative reads the flags in effect there, with the ones an
        // earlier alternative carries into it.
        $charSets = [];
        foreach ($context->flagsAtEachChild($node) as $index => $flags) {
            $charSet = $context->firstChars($node->alternatives[$index], $flags);
            if ($charSet->isUnknown()) {
                // If we can't analyze any charset, skip semantic overlap detection
                return null;
            }
            $charSets[] = $charSet;
        }

        $total = \count($charSets);
        for ($i = 0; $i < $total; $i++) {
            for ($j = $i + 1; $j < $total; $j++) {
                if (!$charSets[$i]->isEmpty() && !$charSets[$j]->isEmpty() && $charSets[$i]->intersects($charSets[$j])) {
                    return new RuleViolation(
                        'regex.lint.overlap.charset',
                        'Alternation branches have overlapping character sets, which may cause unnecessary backtracking.',
                        $node->startPosition,
                        'Consider using atomic groups (?>...) to prevent backtracking. Do not reorder overlapping alternatives as it changes match semantics.',
                    );
                }
            }
        }

        return null;
    }

    private function extractLiteralSequence(NodeInterface $node): ?string
    {
        if ($node instanceof LiteralNode) {
            return $node->value;
        }

        if ($node instanceof GroupNode) {
            if (\in_array($node->type, [
                GroupType::LookaheadPositive,
                GroupType::LookaheadNegative,
                GroupType::LookbehindPositive,
                GroupType::LookbehindNegative,
                GroupType::ScanSubstring,
            ], true)) {
                return null;
            }

            return $this->extractLiteralSequence($node->child);
        }

        if ($node instanceof SequenceNode) {
            $value = '';
            foreach ($node->children as $child) {
                $literal = $this->extractLiteralSequence($child);
                if (null === $literal) {
                    return null;
                }
                $value .= $literal;
            }

            return $value;
        }

        return null;
    }

    /**
     * Detect (.|\n) and (.\n|.) anti-patterns where the developer uses
     * alternation with dot and newline to match any character.
     */
    private function isDotNewlineAntiPattern(AlternationNode $node): bool
    {
        $alts = $node->alternatives;
        if (2 !== \count($alts)) {
            return false;
        }

        $hasDot = false;
        $hasNewline = false;

        foreach ($alts as $alt) {
            if ($alt instanceof DotNode) {
                $hasDot = true;
            } elseif ($this->isNewlineEscape($alt)) {
                $hasNewline = true;
            }
        }

        return $hasDot && $hasNewline;
    }

    private function isNewlineEscape(NodeInterface $node): bool
    {
        if ($node instanceof LiteralNode && "\n" === $node->value) {
            return true;
        }

        if ($node instanceof CharLiteralNode && 0x0A === $node->codePoint) {
            return true;
        }

        return false;
    }

    /**
     * The text with its letters in one case, as PCRE folds them: every
     * letter under u, ASCII ones without.
     */
    private static function fold(string $text, LintContext $context): string
    {
        return $context->pattern->unicodeMode ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }

    /**
     * A branch's text as the pattern could hold it: a backslash doubled, a
     * hidden character "\x{HEX}" in UTF mode and its bytes "\xHH"
     * otherwise.
     */
    private static function spell(string $text, LintContext $context): string
    {
        return DisplayEscaper::escapeFragment(str_replace('\\', '\\\\', $text), $context->pattern->unicodeMode);
    }
}
