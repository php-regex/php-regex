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
use PHPRegex\Linter\Rule\Support\QuantifierMath;
use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPRegex\Parser\Hir\ClassSetProvider;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Printer\DisplayEscaper;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Text\Ascii;

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
    public const ID_DOT_NEWLINE = 'regex.lint.alternation.dotNewline';

    public const ID_OVERLAP = 'regex.lint.alternation.overlap';

    public const ID_CHARSET = 'regex.lint.overlap.charset';

    public function getRuleIds(): array
    {
        return [
            self::ID_DOT_NEWLINE,
            self::ID_OVERLAP,
            self::ID_CHARSET,
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
                self::ID_DOT_NEWLINE,
                'Alternation (.|\n) is an anti-pattern for matching any character including newlines.',
                $node->startPosition,
                $hint,
                end: $node->getEndPosition(),
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
                                self::ID_OVERLAP,
                                \sprintf('Alternation branches "%s" and "%s" overlap.', self::spell($a, $context), self::spell($b, $context)),
                                $node->startPosition,
                                'Consider using atomic groups (?>...) to prevent backtracking. Do not reorder overlapping alternatives as it changes match semantics.',
                                end: $node->getEndPosition(),
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
        $anyUnknown = false;
        foreach ($context->flagsAtEachChild($node) as $index => $flags) {
            $charSet = $context->firstChars($node->alternatives[$index], $flags);
            if ($charSet->isUnknown()) {
                $anyUnknown = true;
            }
            $caseless = str_contains($flags, 'i') || NodePredicates::turnsCaselessOn($node->alternatives[$index]);
            $charSets[] = $caseless ? self::foldCharSet($charSet) : $charSet;
        }

        if (!$anyUnknown) {
            $total = \count($charSets);
            for ($i = 0; $i < $total; $i++) {
                for ($j = $i + 1; $j < $total; $j++) {
                    if (!$charSets[$i]->isEmpty() && !$charSets[$j]->isEmpty() && $charSets[$i]->intersects($charSets[$j])) {
                        return $this->makeCharsetViolation($node, $i, $j, $context);
                    }
                }
            }

            return null;
        }

        // At least one charset is unknown (e.g. above-ASCII byte ranges that
        // ByteCharSet cannot model). Fall back to PCRE-queried sets.
        return $this->checkWithClassSetProvider($node, $context);
    }

    /**
     * The overlap read from the characters PCRE gives the first atom of
     * each branch, for sets the byte sets cannot hold (above ASCII). A
     * branch whose first character is not one atom, or that may start
     * with what follows it, is not judged.
     */
    private function checkWithClassSetProvider(AlternationNode $node, LintContext $context): ?RuleViolation
    {
        $startVerbs = StartOptions::of($context->pattern->source);
        $unicodeFlag = str_contains($context->pattern->flags, 'u');

        $flagsAt = $context->flagsAtEachChild($node);
        $sets = [];
        foreach ($node->alternatives as $index => $alternative) {
            $atom = self::firstAtom($alternative, $context->pattern->unicodeMode);
            $modifiers = self::probeModifiers($flagsAt[$index] ?? $context->activeFlags());
            if (null === $atom || null === $modifiers) {
                return null;
            }

            $set = ClassSetProvider::query($atom, $context->pattern->unicodeMode, $modifiers, $startVerbs, $unicodeFlag);
            if (null === $set) {
                return null;
            }

            $sets[] = $set;
        }

        $total = \count($sets);
        for ($i = 0; $i < $total; $i++) {
            for ($j = $i + 1; $j < $total; $j++) {
                if (!$sets[$i]->intersect($sets[$j])->isEmpty()) {
                    return $this->makeCharsetViolation($node, $i, $j, $context);
                }
            }
        }

        return null;
    }

    /**
     * The atom a branch reads its first character with, as the probe takes
     * it, or null: through groups that only capture, the first item of a
     * sequence and a quantifier taken at least once.
     */
    private static function firstAtom(NodeInterface $node, bool $unicode): ?string
    {
        while (true) {
            if ($node instanceof GroupNode && \in_array($node->type, [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named], true)) {
                $node = $node->child;
            } elseif ($node instanceof SequenceNode && [] !== $node->children) {
                $node = $node->children[0];
            } elseif ($node instanceof QuantifierNode && QuantifierMath::parseRange($node)[0] >= 1) {
                $node = $node->node;
            } else {
                break;
            }
        }

        if ($node instanceof LiteralNode) {
            if ('' === $node->value) {
                return null;
            }

            $codePoint = $unicode ? mb_ord(mb_substr($node->value, 0, 1, 'UTF-8'), 'UTF-8') : \ord($node->value[0]);

            return false === $codePoint ? null : \sprintf($unicode ? '\\x{%X}' : '\\x%02X', $codePoint);
        }

        if ($node instanceof CharClassNode || $node instanceof CharTypeNode || $node instanceof DotNode
            || $node instanceof UnicodePropNode || $node instanceof CharLiteralNode
        ) {
            return $node->accept(PatternPrinter::asWritten());
        }

        return null;
    }

    /**
     * The options the probe reads an atom under, from the flags in effect
     * at it, or null for the ASCII-restricting ones it is not told about.
     */
    private static function probeModifiers(string $flags): ?string
    {
        if (str_contains($flags, 'a')) {
            return null;
        }

        return (str_contains($flags, 'i') ? 'i' : '')
            .(str_contains($flags, 's') ? 's' : '')
            .(str_contains($flags, 'xx') ? 'xx' : (str_contains($flags, 'x') ? 'x' : ''))
            .(str_contains($flags, 'r') ? 'r' : '');
    }

    private function makeCharsetViolation(AlternationNode $node, int $i, int $j, LintContext $context): RuleViolation
    {
        return new RuleViolation(
            self::ID_CHARSET,
            \sprintf(
                'Alternation branches "%s" and "%s" have overlapping character sets, which may cause unnecessary backtracking.',
                self::spell(self::asWritten($node->alternatives[$i]), $context),
                self::spell(self::asWritten($node->alternatives[$j]), $context),
            ),
            $node->startPosition,
            'Consider using atomic groups (?>...) to prevent backtracking. Do not reorder overlapping alternatives as it changes match semantics.',
            end: $node->getEndPosition(),
        );
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
        return $context->pattern->unicodeMode ? mb_strtolower($text, 'UTF-8') : Ascii::lower($text);
    }

    /**
     * The character set with the other case of each ASCII letter added, so
     * that two sets containing only ASCII letters can be compared for
     * overlap under the i flag. Returns the input unchanged when it holds
     * a byte above ASCII (those code points are out of range for the set).
     */
    private static function foldCharSet(ByteCharSet $set): ByteCharSet
    {
        if ($set->isUnknown()) {
            return $set;
        }

        for ($byte = 0x80; $byte <= 0xFF; $byte++) {
            if ($set->intersects(ByteCharSet::fromChar(\chr($byte)))) {
                return ByteCharSet::unknown();
            }
        }

        $folded = $set;
        for ($lower = \ord('a'); $lower <= \ord('z'); $lower++) {
            $upper = $lower - 0x20;
            $lowerSet = ByteCharSet::fromChar(\chr($lower));
            $upperSet = ByteCharSet::fromChar(\chr($upper));
            if ($set->intersects($lowerSet) || $set->intersects($upperSet)) {
                $folded = $folded->union($lowerSet)->union($upperSet);
            }
        }

        return $folded;
    }

    /**
     * A branch's text as the pattern could hold it: a backslash doubled, a
     * hidden character "\x{HEX}" in UTF mode and its bytes "\xHH"
     * otherwise.
     */
    /**
     * A branch as the pattern writes it, kept short enough to read: the
     * reader tells the findings of two alternations apart by it.
     */
    private static function asWritten(NodeInterface $node): string
    {
        $text = $node->accept(PatternPrinter::asWritten());

        return \mb_strlen($text) > 30 ? \mb_strcut($text, 0, 30, 'UTF-8').'…' : $text;
    }

    private static function spell(string $text, LintContext $context): string
    {
        return DisplayEscaper::escapeFragment(str_replace('\\', '\\\\', $text), $context->pattern->unicodeMode);
    }
}
