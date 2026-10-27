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

namespace PHPRegex\Optimizer;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;

/**
 * The repeats of a pattern for Rewriter: a run of the same item written as
 * a count, "aaaa" as "a{4}"; a quantifier written in its short form,
 * "{0,}" as "*"; a count of one or of zero removed; and what a quantifier
 * may repeat as written, one character, a group or a reference, where
 * anything else goes into a group.
 *
 * It shares the rewriter's RewriteState, and reads the items the rewriter
 * has already rewritten.
 *
 * @internal
 */
final class RepeatRewrites
{
    /**
     * The largest count PCRE takes in a quantifier.
     */
    private const MAX_COUNT = 65535;

    /**
     * @param int $minQuantifierCount how many repetitions of an item become a
     *                                count, at least 2, as OptimizerOptions
     *                                checks
     */
    public function __construct(private readonly RewriteState $state, private readonly int $minQuantifierCount = 4) {}

    /**
     * @param array<NodeInterface> $children
     *
     * @return array<NodeInterface>
     */
    public function compactSequence(array $children): array
    {
        if (empty($children)) {
            return $children;
        }

        $compacted = [];
        $currentNode = null;
        $currentCount = 0;
        $currentFromQuantifier = false;

        foreach ($children as $child) {
            $baseNode = $child;
            $count = 1;
            $fromQuantifier = false;

            if ($child instanceof QuantifierNode) {
                $baseNode = $child->node;
                $parsedCount = $this->parseQuantifierCount($child);
                // A variable quantifier is not merged, nor a possessive one,
                // which is atomic.
                if (null === $parsedCount || QuantifierType::Possessive === $child->type) {
                    $this->flushCompactedSequence($compacted, $currentNode, $currentCount, $currentFromQuantifier);
                    $compacted[] = $child;

                    continue;
                }
                $count = $parsedCount;
                $fromQuantifier = true;
            }

            // Never compact nodes that can affect capture numbering or
            // backreferences, nor an assertion: "$$$$" counted is no shorter,
            // and PCRE refuses "${4}".
            if ($this->isCaptureSensitive($baseNode) || self::isZeroWidth($baseNode)) {
                $this->flushCompactedSequence($compacted, $currentNode, $currentCount, $currentFromQuantifier);
                $compacted[] = $child;

                continue;
            }

            // A run counted past what PCRE takes goes on in a count of its
            // own: "\d\d{65535}" is no "\d{65536}", which PCRE refuses.
            if (null === $currentNode || !$this->areNodesEqual($currentNode, $baseNode) || $currentCount + $count > self::MAX_COUNT) {
                $this->flushCompactedSequence($compacted, $currentNode, $currentCount, $currentFromQuantifier);
                $currentNode = $baseNode;
                $currentCount = $count;
                $currentFromQuantifier = $fromQuantifier;
            } else {
                $currentCount += $count;
                $currentFromQuantifier = $currentFromQuantifier || $fromQuantifier;
            }
        }

        $this->flushCompactedSequence($compacted, $currentNode, $currentCount, $currentFromQuantifier);

        return $compacted;
    }

    /**
     * The items with each literal that repeats one character, as many
     * times as a count takes at least, written as that count: "aaaa" is
     * "a{4}".
     *
     * @param list<NodeInterface> $children
     *
     * @return list<NodeInterface>
     */
    public function countLiteralRuns(array $children): array
    {
        foreach ($children as $i => $child) {
            // \z, not $: "aaa\n" is no run of "a", though $ matches before its newline.
            if ($child instanceof LiteralNode && LibraryPcre::match('/^(.)\1+\z/s', $child->value, $matches)) {
                $char = $matches[1];
                $count = \strlen($child->value);
                // Only compact if count meets the configured minimum (avoids making output longer/less readable)
                if ($count >= $this->minQuantifierCount) {
                    $baseNode = new LiteralNode($char, $child->startPosition, $child->endPosition);
                    $children[$i] = new QuantifierNode($baseNode, '{'.$count.'}', QuantifierType::Greedy, $child->startPosition, $child->endPosition);
                    $this->state->changed(Rewriter::RUN_COUNTED);
                }
            }
        }

        return $children;
    }

    public function normalizeQuantifier(QuantifierNode $node): NodeInterface
    {
        $quantifier = $node->quantifier;

        $short = ['{0,}' => '*', '{1,}' => '+', '{0,1}' => '?'][$quantifier] ?? null;
        if (null !== $short) {
            $this->state->changed(Rewriter::SHORT_QUANTIFIER);

            return new QuantifierNode($node->node, $short, $node->type, $node->startPosition, $node->endPosition);
        }

        // "X{1}+" is possessive, as an atomic group is: it never gives back.
        if (('{1}' === $quantifier || '{1,1}' === $quantifier) && QuantifierType::Possessive !== $node->type) {
            $this->state->changed(Rewriter::COUNT_OF_ONE);

            return $node->node;
        }

        // A group repeated zero times still takes its number, and can still
        // be called or referred to. One holding an assertion still tells
        // PCRE2 where a match may start: "(?:x|(?=a)){0}\w" never matches
        // "\tb", where "\w" does.
        if (('{0}' === $quantifier || '{0,0}' === $quantifier) && !$this->isCaptureSensitive($node->node) && !self::holdsAssertion($node->node)) {
            $this->state->changed(Rewriter::REPEAT_OF_ZERO);

            return new LiteralNode('', $node->startPosition, $node->endPosition);
        }

        return $node;
    }

    /**
     * Whether the node matches exactly one character, so that a quantifier
     * written right after it repeats the same thing it repeated on the group.
     */
    public function isSingleCharacter(NodeInterface $node): bool
    {
        return match (true) {
            // Without /u a character is a byte: "é" is two.
            $node instanceof LiteralNode => 1 === ($this->state->unicodeMode ? mb_strlen($node->value, 'UTF-8') : \strlen($node->value)),
            $node instanceof CharLiteralNode, $node instanceof CharTypeNode, $node instanceof DotNode,
            $node instanceof CharClassNode, $node instanceof UnicodePropNode => true,
            default => false,
        };
    }

    /**
     * The node as a quantifier may repeat it: one character, a group or a
     * reference stands as written, anything else goes into a group, as a
     * quantifier repeats the one item written before it. "ab" counted four
     * times is "(?:ab){4}", never "ab{4}", and nothing repeated twice is
     * "(?:){2}", never "{2}". A DEFINE group stands as written too: PCRE2
     * compiles "(?(DEFINE)a){7000}", and refuses "(?:(?(DEFINE)a)){7000}",
     * where it compiles the group once for each repeat, as too large.
     */
    public function repeatable(NodeInterface $node): NodeInterface
    {
        if ($this->isSingleCharacter($node)
            || $node instanceof GroupNode
            || $node instanceof DefineNode
            || $node instanceof ConditionalNode
            || $node instanceof BackrefNode
            || $node instanceof SubroutineNode
            || $node instanceof ControlCharNode
        ) {
            return $node;
        }

        return new GroupNode($node, GroupType::NonCapturing, null, null, $node->getStartPosition(), $node->getEndPosition());
    }

    public function isCaptureSensitive(NodeInterface $node): bool
    {
        // Never compact nodes that affect capture numbering, backreferences, or complex semantics
        if ($node instanceof GroupNode && \in_array($node->type, [
            GroupType::Capturing,
            GroupType::Named,
            GroupType::BranchReset,
        ], true)) {
            return true;
        }

        if ($node instanceof BackrefNode
            || $node instanceof SubroutineNode
            || $node instanceof ConditionalNode) {
            return true;
        }

        // Recurse into children
        $children = [];
        if ($node instanceof SequenceNode) {
            $children = $node->children;
        } elseif ($node instanceof AlternationNode) {
            $children = $node->alternatives;
        } elseif ($node instanceof GroupNode) {
            $children = [$node->child];
        } elseif ($node instanceof QuantifierNode) {
            $children = [$node->node];
        } elseif ($node instanceof CharClassNode) {
            $children = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];
        } elseif ($node instanceof DefineNode) {
            $children = [$node->content];
        }

        foreach ($children as $child) {
            if ($this->isCaptureSensitive($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<NodeInterface> $compacted
     *
     * @param-out null $currentNode
     */
    private function flushCompactedSequence(
        array &$compacted,
        ?NodeInterface &$currentNode,
        int &$currentCount,
        bool &$currentFromQuantifier
    ): void {
        if (null === $currentNode) {
            return;
        }

        if ($currentCount >= $this->minQuantifierCount || $currentFromQuantifier) {
            $compacted[] = $this->createQuantifiedNode($currentNode, $currentCount);
        } else {
            for ($i = 0; $i < $currentCount; $i++) {
                $compacted[] = $currentNode;
            }
        }

        $currentNode = null;
        $currentCount = 0;
        $currentFromQuantifier = false;
    }

    /**
     * Whether the item matches no character and takes no quantifier
     * written straight after it: an anchor, an assertion, "\K", a verb, a
     * callout or a comment.
     */
    private static function isZeroWidth(NodeInterface $node): bool
    {
        return $node instanceof AnchorNode
            || $node instanceof AssertionNode
            || $node instanceof KeepNode
            || $node instanceof PcreVerbNode
            || $node instanceof LimitMatchNode
            || $node instanceof CalloutNode
            || $node instanceof CommentNode;
    }

    /**
     * Whether an anchor, an assertion or a lookaround stands anywhere under
     * the node.
     */
    private static function holdsAssertion(NodeInterface $node): bool
    {
        if ($node instanceof AnchorNode || $node instanceof AssertionNode
            || ($node instanceof GroupNode && \in_array($node->type, [GroupType::LookaheadPositive, GroupType::LookaheadNegative, GroupType::LookbehindPositive, GroupType::LookbehindNegative, GroupType::ScanSubstring], true))
        ) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::holdsAssertion($child)) {
                return true;
            }
        }

        return false;
    }

    private function parseQuantifierCount(QuantifierNode $node): ?int
    {
        // Only merge exact {n} counts; ranges and * + ? never merge.
        if (!str_starts_with($node->quantifier, '{')) {
            return null;
        }

        $bounds = $node->bounds;
        if (null !== $bounds && $bounds->min === $bounds->max) {
            return $bounds->min;
        }

        return null;
    }

    private function areNodesEqual(NodeInterface $a, NodeInterface $b): bool
    {
        // Simple equality: same type and same string representation
        if ($a::class !== $b::class) {
            return false;
        }

        return $this->state->spelling($a) === $this->state->spelling($b);
    }

    private function createQuantifiedNode(NodeInterface $node, int $count): NodeInterface
    {
        if (1 === $count) {
            return $node;
        }

        return new QuantifierNode(
            $this->repeatable($node),
            '{'.$count.'}',
            QuantifierType::Greedy,
            $node->getStartPosition(),
            $node->getEndPosition(),
        );
    }
}
