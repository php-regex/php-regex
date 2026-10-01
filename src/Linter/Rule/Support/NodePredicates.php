<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Linter\Rule\Support;

use PhpRegex\Parser\Analysis\LengthRangeCalculator;
use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\AnchorNode;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\CalloutNode;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\CommentNode;
use PhpRegex\Parser\Node\ConditionalNode;
use PhpRegex\Parser\Node\DefineNode;
use PhpRegex\Parser\Node\DotNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\KeepNode;
use PhpRegex\Parser\Node\LimitMatchNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\Node\PcreVerbNode;
use PhpRegex\Parser\Node\PosixClassNode;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\ScriptRunNode;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\Node\UnicodePropNode;

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

    public static function nodeIsSingleChar(NodeInterface $node): bool
    {
        [$min, $max] = $node->accept(new LengthRangeCalculator());

        return 1 === $min && 1 === $max;
    }
}
