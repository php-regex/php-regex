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

namespace PHPRegex\Linter\Rule\Support;

use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Analysis\LengthRangeCalculator;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\UnicodePropNode;

/**
 * Pure structural predicates over AST nodes shared by lint rules.
 *
 * @internal
 */
final class NodePredicates
{
    private function __construct() {}

    public static function isConsuming(NodeInterface $node): bool
    {
        if ($node instanceof LiteralNode) {
            return true;
        }
        if ($node instanceof CharClassNode) {
            return true;
        }
        if ($node instanceof CharTypeNode) {
            return true;
        }
        if ($node instanceof DotNode) {
            return true;
        }
        if ($node instanceof CharLiteralNode) {
            return true;
        }
        if ($node instanceof UnicodePropNode) {
            return true;
        }
        if ($node instanceof PosixClassNode) {
            return true;
        }
        if ($node instanceof QuantifierNode) {
            return self::isConsuming($node->node);
        }
        if ($node instanceof GroupNode) {
            // Lookarounds don't consume
            return !(GroupType::LookaheadPositive === $node->type
                || GroupType::LookaheadNegative === $node->type
                || GroupType::LookbehindPositive === $node->type
                || GroupType::LookbehindNegative === $node->type
                || GroupType::ScanSubstring === $node->type);
        }
        if ($node instanceof AlternationNode) {
            // If any alternative consumes, consider it consuming
            foreach ($node->alternatives as $alt) {
                if (self::isConsuming($alt)) {
                    return true;
                }
            }

            return false;
        }
        if ($node instanceof SequenceNode) {
            // If any child consumes, consider it consuming
            foreach ($node->children as $child) {
                if (self::isConsuming($child)) {
                    return true;
                }
            }

            return false;
        }

        // Anchors, assertions, etc. don't consume
        return false;
    }

    /**
     * Determine if the given sequence can match an empty string.
     *
     * @param array<int, NodeInterface> $nodes
     */
    public static function sequenceCanBeEmpty(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (!self::canBeEmpty($node)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the node needs to read no character: an anchor, a
     * lookaround or a verb counts as empty, even one that always fails,
     * such as "(*FAIL)" or "(?!)" ("$(*SKIP)(*FAIL)" reads nothing after
     * the anchor).
     */
    public static function canBeEmpty(NodeInterface $node): bool
    {
        if ($node instanceof AnchorNode
            || $node instanceof AssertionNode
            || $node instanceof KeepNode
            || $node instanceof CommentNode
            || $node instanceof CalloutNode
            || $node instanceof ScriptRunNode
            || $node instanceof DefineNode
            || $node instanceof PcreVerbNode
            || $node instanceof LimitMatchNode
        ) {
            return true;
        }

        if ($node instanceof LiteralNode) {
            return '' === $node->value;
        }

        if ($node instanceof QuantifierNode) {
            [$min] = QuantifierMath::parseRange($node->quantifier);

            return 0 === $min || self::canBeEmpty($node->node);
        }

        if ($node instanceof SequenceNode) {
            return self::sequenceCanBeEmpty(array_values($node->children));
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alt) {
                if (self::canBeEmpty($alt)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof GroupNode) {
            if (\in_array($node->type, [
                GroupType::LookaheadPositive,
                GroupType::LookaheadNegative,
                GroupType::LookbehindPositive,
                GroupType::LookbehindNegative,
                GroupType::ScanSubstring,
            ], true)) {
                return true;
            }

            return self::canBeEmpty($node->child);
        }

        if ($node instanceof ConditionalNode) {
            return self::canBeEmpty($node->yes) || self::canBeEmpty($node->no);
        }

        return false;
    }

    /**
     * Whether the node fails wherever it stands: "(*FAIL)", "(*F)", with a
     * mark name or not, or a negative lookaround with nothing inside, as
     * "(?!)".
     */
    public static function alwaysFails(NodeInterface $node): bool
    {
        if ($node instanceof PcreVerbNode) {
            return \in_array(explode(':', $node->verb, 2)[0], ['F', 'FAIL'], true);
        }

        return $node instanceof GroupNode
            && \in_array($node->type, [GroupType::LookaheadNegative, GroupType::LookbehindNegative], true)
            && (($node->child instanceof LiteralNode && '' === $node->child->value)
                || ($node->child instanceof SequenceNode && [] === $node->child->children));
    }

    public static function isOptionalNode(NodeInterface $node): bool
    {
        if ($node instanceof LiteralNode) {
            return '' === $node->value;
        }

        if ($node instanceof QuantifierNode) {
            [$min] = QuantifierMath::parseRange($node->quantifier);

            return 0 === $min;
        }

        if ($node instanceof GroupNode) {
            if (self::isTransparentGroup($node->type)) {
                return self::isOptionalNode($node->child);
            }

            return true;
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                if (!self::isOptionalNode($child)) {
                    return false;
                }
            }

            return true;
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alt) {
                if (self::isOptionalNode($alt)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof ConditionalNode) {
            return self::isOptionalNode($node->yes) || self::isOptionalNode($node->no);
        }

        return !self::isConsuming($node);
    }

    public static function isTransparentGroup(GroupType $type): bool
    {
        return !\in_array($type, [
            GroupType::LookaheadPositive,
            GroupType::LookaheadNegative,
            GroupType::LookbehindPositive,
            GroupType::LookbehindNegative,
            GroupType::ScanSubstring,
        ], true);
    }

    /**
     * Whether the node is a bare (?flags) group that only toggles flags for
     * what follows it, up to the end of the enclosing group: the rest of its
     * alternative and the alternatives after it.
     *
     * An empty scoped group, "(?s:)", holds the same empty child but sets
     * its flags inside itself only. The span alone tells them apart: "(?s)"
     * is its flags and three bytes, "(?s:)" one byte longer, and a scoped
     * group with a body longer still.
     */
    public static function isStandaloneInlineFlagsGroup(NodeInterface $node): bool
    {
        return $node instanceof GroupNode
            && GroupType::InlineFlags === $node->type
            && null !== $node->flags
            && $node->getEndPosition() - $node->getStartPosition() === \strlen($node->flags) + 3;
    }

    /**
     * Whether an inline flag group anywhere in the nodes turns the i flag
     * on, as "(?i)" does in "(?!(?i)A)" or "(?i:A)". "(?-i)", "(?^)" and
     * "(?i-i)" leave it off.
     */
    public static function turnsCaselessOn(NodeInterface ...$nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node instanceof GroupNode && GroupType::InlineFlags === $node->type && null !== $node->flags
                && str_contains(self::applyInlineFlags('', $node->flags), 'i')
            ) {
                return true;
            }

            if (self::turnsCaselessOn(...$node->getChildren())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fold an inline (?flags-flags) marker into the flags accumulated so
     * far. A leading ^ first resets i, m, n, s and x; the other modifiers,
     * such as U, u and D, stay.
     */
    public static function applyInlineFlags(string $baseFlags, string $inlineFlags): string
    {
        $resetAll = str_starts_with($inlineFlags, '^');
        if ($resetAll) {
            $baseFlags = str_replace(['i', 'm', 'n', 's', 'x'], '', $baseFlags);
            $inlineFlags = substr($inlineFlags, 1);
        }

        [$setFlags, $unsetFlags] = str_contains($inlineFlags, '-')
            ? explode('-', $inlineFlags, 2)
            : [$inlineFlags, ''];

        $flags = [];
        foreach (str_split($baseFlags) as $flag) {
            if ('' !== $flag) {
                $flags[$flag] = true;
            }
        }

        foreach (str_split($setFlags) as $flag) {
            if ('' !== $flag) {
                $flags[$flag] = true;
            }
        }

        foreach (str_split($unsetFlags) as $flag) {
            if ('' !== $flag) {
                unset($flags[$flag]);
            }
        }

        return implode('', array_keys($flags));
    }

    public static function unwrapTransparentNode(NodeInterface $node): NodeInterface
    {
        if ($node instanceof GroupNode && self::isTransparentGroup($node->type)) {
            return self::unwrapTransparentNode($node->child);
        }

        if ($node instanceof SequenceNode && 1 === \count($node->children)) {
            return self::unwrapTransparentNode($node->children[0]);
        }

        return $node;
    }

    public static function isStartAnchorNode(NodeInterface $node): bool
    {
        if ($node instanceof AnchorNode) {
            return '^' === $node->value;
        }

        if ($node instanceof AssertionNode) {
            return \in_array($node->value, ['A', 'G'], true);
        }

        return false;
    }

    public static function isEndAnchorNode(NodeInterface $node): bool
    {
        if ($node instanceof AnchorNode) {
            return '$' === $node->value;
        }

        if ($node instanceof AssertionNode) {
            return \in_array($node->value, ['z', 'Z'], true);
        }

        return false;
    }

    public static function anchorDisplay(NodeInterface $node): string
    {
        if ($node instanceof AnchorNode) {
            return $node->value;
        }

        if ($node instanceof AssertionNode) {
            return '\\'.$node->value;
        }

        return '';
    }

    /**
     * Whether the tail of a sequence can start with a newline: the
     * continuation a multiline `$` admits before any line end. The first
     * consuming node decides; a lookahead or an unknown charset cannot
     * vouch for the newline, so such a tail stays reported, not silenced.
     *
     * @param array<int, NodeInterface> $nodes
     */
    public static function tailCanStartWithNewline(array $nodes, CharSetAnalyzer $analyzer, bool $dotAll): bool
    {
        $newline = ByteCharSet::fromChar("\n");

        foreach ($nodes as $node) {
            if ($node instanceof GroupNode
                && null !== $node->flags
                && self::isStandaloneInlineFlagsGroup($node)) {
                $dotAll = str_contains(self::applyInlineFlags($dotAll ? 's' : '', $node->flags), 's');

                continue;
            }

            if ($node instanceof QuantifierNode && $node->node instanceof DotNode) {
                // The analyzer's dot set reads the outer flags only, so a
                // quantified dot resolves through the folded scope instead.
                // Under dotall the dot can consume the newline whether or
                // not it is optional; without it, an optional dot consumes
                // nothing and the next node decides.
                [$min] = QuantifierMath::parseRange($node->quantifier);
                if (0 === $min && !$dotAll) {
                    continue;
                }

                return $dotAll;
            }

            if ($node instanceof DotNode) {
                return $dotAll;
            }

            if ($node instanceof GroupNode && !self::isTransparentGroup($node->type)) {
                return false;
            }

            $set = $analyzer->firstChars($node);
            if ($set->isUnknown()) {
                return false;
            }

            if ($set->intersects($newline)) {
                return true;
            }

            if (!self::canBeEmpty($node)) {
                return false;
            }
        }

        // Every node is optional: the tail can continue by matching nothing.
        return true;
    }

    /**
     * Whether a sibling tail as a whole can match exactly one newline: the
     * continuation `$` (without /m) and `\Z` admit before the subject's
     * final newline. The other siblings must be guaranteed to match empty —
     * a `\b` or a lookahead can fail there and would sink the match. What
     * "there" means differs per side: before the newline it is the anchored
     * position, after it the end of the subject (where `$`, `\z` and `\Z`
     * all hold).
     *
     * @param array<int, NodeInterface> $nodes
     */
    public static function tailCanMatchNewline(array $nodes, CharSetAnalyzer $analyzer, bool $dotAll): bool
    {
        // Right-to-left and left-to-right aggregates so the loop below stays
        // linear: a tail of thousands of optional siblings must not become
        // quadratic.
        $count = \count($nodes);
        $emptyAfter = [];
        $emptyBefore = [];
        $ok = true;
        for ($i = $count - 1; $i >= 0; $i--) {
            $emptyAfter[$i] = $ok;
            $ok = $ok && self::nodeAlwaysMatchesEmpty($nodes[$i], true);
        }

        $ok = true;
        for ($i = 0; $i < $count; $i++) {
            $emptyBefore[$i] = $ok;
            $ok = $ok && self::nodeAlwaysMatchesEmpty($nodes[$i], false);
        }

        foreach ($nodes as $index => $node) {
            if ($node instanceof GroupNode
                && null !== $node->flags
                && self::isStandaloneInlineFlagsGroup($node)) {
                $dotAll = str_contains(self::applyInlineFlags($dotAll ? 's' : '', $node->flags), 's');

                continue;
            }

            if (!self::canMatchNewline($node, $analyzer, $dotAll)) {
                continue;
            }

            if ($emptyBefore[$index] && $emptyAfter[$index]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a node's language contains the single string "\n".
     */
    public static function canMatchNewline(NodeInterface $node, CharSetAnalyzer $analyzer, bool $dotAll): bool
    {
        if ($node instanceof LiteralNode) {
            return "\n" === $node->value;
        }

        if ($node instanceof CharLiteralNode || $node instanceof ControlCharNode) {
            return 0x0A === $node->codePoint;
        }

        if ($node instanceof DotNode) {
            return $dotAll;
        }

        if ($node instanceof CharClassNode
            || $node instanceof CharTypeNode
            || $node instanceof ExtendedCharClassNode
            || $node instanceof UnicodePropNode) {
            // Single-character constructs: "\n" is matchable iff it can start
            // a match; an unknown set (backrefs aside: \N, \p under /u) says
            // nothing, so the tail stays reported. POSIX classes only occur
            // inside a CharClassNode, whose own set already includes them.
            $set = $analyzer->firstChars($node);

            return !$set->isUnknown() && $set->intersects(ByteCharSet::fromChar("\n"));
        }

        if ($node instanceof QuantifierNode) {
            [$min, $max] = QuantifierMath::parseRange($node->quantifier);

            return $min <= 1 && (null === $max || $max >= 1) && self::canMatchNewline($node->node, $analyzer, $dotAll);
        }

        if ($node instanceof GroupNode) {
            if (!self::isTransparentGroup($node->type)) {
                return false;
            }

            $innerDotAll = $dotAll;
            if (null !== $node->flags) {
                $innerDotAll = str_contains(self::applyInlineFlags($innerDotAll ? 's' : '', $node->flags), 's');
            }

            return self::canMatchNewline($node->child, $analyzer, $innerDotAll);
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alternative) {
                if (self::canMatchNewline($alternative, $analyzer, $dotAll)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof SequenceNode) {
            return self::tailCanMatchNewline(array_values($node->children), $analyzer, $dotAll);
        }

        if ($node instanceof ConditionalNode) {
            return self::canMatchNewline($node->yes, $analyzer, $dotAll)
                || self::canMatchNewline($node->no, $analyzer, $dotAll);
        }

        // Assertions, keep marks, comments, backrefs and other constructs
        // are treated as never matching a newline: an exotic tail then stays
        // reported rather than silenced.
        return false;
    }

    public static function isSyntacticallyEmptyAlternative(NodeInterface $node): bool
    {
        if ($node instanceof LiteralNode) {
            return '' === $node->value;
        }

        if ($node instanceof CommentNode) {
            return true;
        }

        if ($node instanceof SequenceNode) {
            if ([] === $node->children) {
                return true;
            }

            foreach ($node->children as $child) {
                if (!$child instanceof CommentNode) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Whether the node always matches exactly one character, read from its
     * length alone; its character set is not looked at. A node holding a
     * lookaround or a scan-substring group anywhere, a conditional's
     * assertion included, is never one: `.(?!x)` matches one character but
     * not every `.`. A backreference or a subroutine call has no upper
     * length, so a node holding one is never one either, except as the
     * condition of a conditional, which matches no text: `(?(1)a|b)` is one,
     * and its character set is unknown.
     */
    public static function nodeIsSingleChar(NodeInterface $node): bool
    {
        if (self::readsBeyondItsCharacter($node)) {
            return false;
        }

        [$min, $max] = $node->accept(new LengthRangeCalculator());

        return 1 === $min && 1 === $max;
    }

    /**
     * Whether the node holds a lookaround or a scan-substring group, whose
     * success depends on more than the character the node consumes:
     * `(?<!b)a` takes an "a" only after anything but a "b".
     */
    public static function readsBeyondItsCharacter(NodeInterface $node): bool
    {
        if ($node instanceof GroupNode && !self::isTransparentGroup($node->type)) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::readsBeyondItsCharacter($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether every node always matches the empty string — unlike
     * canBeEmpty(), a `\b` or a lookaround is not guaranteed to succeed, so
     * it disqualifies. $atEnd says where the nodes would sit: after the
     * subject's final newline (true) or at the anchored position before it.
     *
     * @param array<int, NodeInterface> $nodes
     */
    public static function alwaysMatchesEmpty(array $nodes, bool $atEnd): bool
    {
        foreach ($nodes as $node) {
            if (!self::nodeAlwaysMatchesEmpty($node, $atEnd)) {
                return false;
            }
        }

        return true;
    }

    private static function nodeAlwaysMatchesEmpty(NodeInterface $node, bool $atEnd): bool
    {
        if ($node instanceof LiteralNode) {
            return '' === $node->value;
        }

        if ($node instanceof AnchorNode) {
            // `$` holds both at the anchored position and at the end.
            return '$' === $node->value;
        }

        if ($node instanceof AssertionNode) {
            // `\Z` holds at both positions, `\z` only at the end.
            return 'Z' === $node->value || ('z' === $node->value && $atEnd);
        }

        if ($node instanceof KeepNode) {
            // `\K` resets the match start; it never fails.
            return true;
        }

        if ($node instanceof QuantifierNode) {
            [$min] = QuantifierMath::parseRange($node->quantifier);

            return 0 === $min || self::nodeAlwaysMatchesEmpty($node->node, $atEnd);
        }

        if ($node instanceof GroupNode) {
            return self::isTransparentGroup($node->type) && self::nodeAlwaysMatchesEmpty($node->child, $atEnd);
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alternative) {
                if (self::nodeAlwaysMatchesEmpty($alternative, $atEnd)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof SequenceNode) {
            return self::alwaysMatchesEmpty(array_values($node->children), $atEnd);
        }

        if ($node instanceof ConditionalNode) {
            return self::nodeAlwaysMatchesEmpty($node->yes, $atEnd) && self::nodeAlwaysMatchesEmpty($node->no, $atEnd);
        }

        // Callouts and comments run without consuming and without failing;
        // (*FAIL) is the one verb that always fails. Everything else (`^`,
        // `\b`, lookarounds, backrefs, consuming characters) can fail at the
        // position a final newline leaves behind.
        return $node instanceof CalloutNode
            || $node instanceof CommentNode
            || ($node instanceof PcreVerbNode && !\in_array($node->verb, ['FAIL', 'F'], true));
    }
}
