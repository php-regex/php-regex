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
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
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
use PHPRegex\Parser\Text\Bytes;

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
            return self::sequenceCanBeEmpty($node->children);
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

    /**
     * Whether an "(*ACCEPT)" stands anywhere under the node: the match may
     * end there, whatever is written after it.
     */
    public static function holdsAccept(NodeInterface $node): bool
    {
        if ($node instanceof PcreVerbNode) {
            return 'ACCEPT' === explode(':', $node->verb, 2)[0];
        }

        foreach ($node->getChildren() as $child) {
            if (self::holdsAccept($child)) {
                return true;
            }
        }

        return false;
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
     *
     * @phpstan-assert-if-true GroupNode $node
     */
    public static function isStandaloneInlineFlagsGroup(NodeInterface $node): bool
    {
        return $node instanceof GroupNode && self::isStandaloneInlineFlags($node);
    }

    /**
     * The kind half of isStandaloneInlineFlagsGroup(), for a node the
     * narrowing has already recognised: the inline-flags type, flags set,
     * and the span of the flags and three bytes that tells a bare "(?s)"
     * from a scoped "(?s:)" with the same empty child.
     */
    public static function isStandaloneInlineFlags(GroupNode $node): bool
    {
        return GroupType::InlineFlags === $node->type
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
     * far. A leading ^ first resets i, m, n, r, s and x; the other
     * modifiers, such as U, u and D, stay.
     */
    public static function applyInlineFlags(string $baseFlags, string $inlineFlags): string
    {
        $resetAll = str_starts_with($inlineFlags, '^');
        if ($resetAll) {
            $baseFlags = str_replace(['i', 'm', 'n', 'r', 's', 'x'], '', $baseFlags);
            $inlineFlags = substr($inlineFlags, 1);
        }

        [$setFlags, $unsetFlags] = str_contains($inlineFlags, '-')
            ? explode('-', $inlineFlags, 2)
            : [$inlineFlags, ''];

        $flags = [];
        foreach (Bytes::split($baseFlags) as $flag) {
            if ('' !== $flag) {
                $flags[$flag] = true;
            }
        }

        foreach (Bytes::split($setFlags) as $flag) {
            if ('' !== $flag) {
                $flags[$flag] = true;
            }
        }

        foreach (Bytes::split($unsetFlags) as $flag) {
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

    /**
     * @phpstan-assert-if-true AnchorNode|AssertionNode $node
     */
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

    /**
     * Whether a start anchor ("^", "\A", "\G") stands anywhere under the
     * node: a search from offset 0 then says nothing of the searches
     * preg_match_all() goes on with, nor of one given an offset.
     */
    public static function holdsAStartAnchor(NodeInterface $node): bool
    {
        if (self::isStartAnchorNode($node)) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::holdsAStartAnchor($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @phpstan-assert-if-true AnchorNode|AssertionNode|GroupNode $node
     */
    public static function isEndAnchorNode(NodeInterface $node): bool
    {
        if ($node instanceof AnchorNode) {
            return '$' === $node->value;
        }

        if ($node instanceof AssertionNode) {
            return \in_array($node->value, ['z', 'Z'], true);
        }

        if ($node instanceof GroupNode && GroupType::LookaheadPositive === $node->type) {
            $child = self::unwrapTransparentNode($node->child);

            return self::isEndAnchorNode($child);
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
}
