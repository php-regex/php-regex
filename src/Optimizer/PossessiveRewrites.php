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
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;

/**
 * The possessive repeats of a pattern for Rewriter: a greedy quantifier
 * made possessive where nothing after it can start with a character it
 * gives back, "a+b" written "a++b", and the group a rewrite dropped from a
 * repeat put back where PCRE2 would make the bare repeat possessive behind
 * its back.
 *
 * It shares the rewriter's RewriteState, and reads the items the rewriter
 * has already rewritten. It remembers the repeats whose group the rewriter
 * dropped, for as long as the rewriter lives.
 *
 * @internal
 */
final class PossessiveRewrites
{
    /**
     * The repeats whose group was dropped, "(?:a)+" written "a+".
     *
     * @var \WeakMap<QuantifierNode, true>
     */
    private \WeakMap $ungroupedRepeats;

    public function __construct(private readonly RewriteState $state, private readonly RepeatRewrites $repeats)
    {
        $this->ungroupedRepeats = new \WeakMap();
    }

    /**
     * Records a repeat whose group the rewrite dropped, "(?:a)+" written
     * "a+".
     */
    public function ungrouped(QuantifierNode $node): void
    {
        $this->ungroupedRepeats[$node] = true;
    }

    /**
     * The items of a sequence with each greedy repeat made possessive where
     * what follows it can never start with a character it gives back.
     *
     * @param list<NodeInterface> $children
     *
     * @return list<NodeInterface>
     */
    public function possessify(array $children): array
    {
        for ($i = 0; $i < \count($children); $i++) {
            $current = $children[$i];
            $suffix = array_slice($children, $i + 1);

            if ($current instanceof QuantifierNode && QuantifierType::Greedy === $current->type && $this->isPossessifyCandidate($current) && !empty($suffix)) {
                if ($this->repeats->isCaptureSensitive($current->node) || $this->canMatchEmpty($current->node)) {
                    continue;
                }
                // Compute disjointness against the FIRST-set of the suffix
                $suffixNode = 1 === \count($suffix) ? $suffix[0] : new SequenceNode($suffix, $current->startPosition, $current->endPosition);

                // The sets stop at 0x7F: above it nothing is known disjoint.
                if ($this->mayTakeAboveAscii($current->node) || $this->suffixMayStartAboveAscii($suffix)) {
                    continue;
                }

                $charSets = $this->state->charSets();

                try {
                    $currentLastChars = $charSets->lastChars($current->node);
                    $suffixFirstChars = $charSets->firstChars($suffixNode);
                    if (!$currentLastChars->intersects($suffixFirstChars)) {
                        $children[$i] = new QuantifierNode(
                            $current->node,
                            $current->quantifier,
                            QuantifierType::Possessive,
                            $current->startPosition,
                            $current->endPosition,
                        );
                        $this->state->changed(Rewriter::POSSESSIVE);
                    }
                } catch (\Throwable) {
                    // If analysis fails, don't optimize
                }
            }
        }

        return $children;
    }

    /**
     * PCRE2 makes a character repeated right before a group repeated
     * possessively from zero, or an atomic group that can match empty,
     * possessive, as if the group could not be skipped: "\.a+(?:-b)?+a"
     * misses ".aaaa", which "\.(?:a)+(?:-b)?+a" matches. A repeat whose
     * group the rewrite dropped gets it back there.
     *
     * @param list<NodeInterface> $children
     *
     * @return list<NodeInterface>
     */
    public function regroupBeforeOptionalPossessiveGroups(array $children): array
    {
        foreach ($children as $i => $child) {
            $next = $children[$i + 1] ?? null;
            if ($child instanceof QuantifierNode && isset($this->ungroupedRepeats[$child]) && $this->skippableAtomicGroup($next)) {
                $children[$i] = new QuantifierNode(
                    new GroupNode($child->node, GroupType::NonCapturing, null, null, $child->node->getStartPosition(), $child->node->getEndPosition()),
                    $child->quantifier,
                    $child->type,
                    $child->startPosition,
                    $child->endPosition,
                );
            }
        }

        return $children;
    }

    /**
     * Whether the node is a group the engine may skip without
     * backtracking into it: repeated possessively from zero, or atomic
     * around what can match empty.
     */
    private function skippableAtomicGroup(?NodeInterface $node): bool
    {
        if ($node instanceof QuantifierNode) {
            return QuantifierType::Possessive === $node->type && $node->node instanceof GroupNode && $this->quantifierAllowsZero($node);
        }

        return $node instanceof GroupNode && GroupType::Atomic === $node->type && $this->canMatchEmpty($node->child);
    }

    /**
     * Whether the node may take a character above ASCII, which the
     * character sets, stopping at 0x7F, do not hold: a byte or a code point
     * above it, a dot, a negated class, a type but \\d, \\w and \\s without
     * Unicode properties, a property, a POSIX class, and anything not read
     * here. A locale gives \\w and \\s bytes above ASCII, "\xE9" and "\xA0",
     * but never one to both: the two stay disjoint there as they are below.
     */
    private function mayTakeAboveAscii(NodeInterface $node): bool
    {
        return match (true) {
            $node instanceof LiteralNode => '' !== trim($node->value, "\x00..\x7F"),
            $node instanceof CharLiteralNode, $node instanceof ControlCharNode => $node->codePoint > 0x7F,
            $node instanceof CharTypeNode => $this->state->unicodeProperties || !\in_array($node->value, ['d', 'w', 's'], true),
            $node instanceof DotNode, $node instanceof UnicodePropNode, $node instanceof PosixClassNode => true,
            $node instanceof CharClassNode && $node->isNegated => true,
            $node instanceof AnchorNode, $node instanceof AssertionNode, $node instanceof CommentNode, $node instanceof KeepNode => false,
            [] === $node->getChildren() => true,
            default => $this->anyMayTakeAboveAscii($node->getChildren()),
        };
    }

    /**
     * @param array<NodeInterface> $nodes
     */
    private function anyMayTakeAboveAscii(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($this->mayTakeAboveAscii($node)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the items may start with a character above ASCII: the first
     * that cannot match empty decides, with every optional one before it.
     *
     * @param array<NodeInterface> $suffix
     */
    private function suffixMayStartAboveAscii(array $suffix): bool
    {
        foreach ($suffix as $item) {
            if ($this->mayTakeAboveAscii($item)) {
                return true;
            }

            if (!$this->canMatchEmpty($item)) {
                return false;
            }
        }

        return false;
    }

    private function isPossessifyCandidate(QuantifierNode $node): bool
    {
        if (!\in_array($node->quantifier, ['+', '*'], true)) {
            return $node->bounds?->isUnbounded() ?? false;
        }

        return true;
    }

    private function canMatchEmpty(NodeInterface $node): bool
    {
        $nullable = $this->nullableStatus($node);

        return $nullable ?? true;
    }

    /**
     * @return bool|null true if nullable, false if consumes, null if unknown
     */
    private function nullableStatus(NodeInterface $node): ?bool
    {
        if ($node instanceof LiteralNode) {
            return '' === $node->value;
        }

        if ($node instanceof CharTypeNode
            || $node instanceof DotNode
            || $node instanceof CharClassNode
            || $node instanceof RangeNode
            || $node instanceof UnicodePropNode
            || $node instanceof CharLiteralNode
            || $node instanceof PosixClassNode
        ) {
            return false;
        }

        if ($node instanceof AnchorNode
            || $node instanceof AssertionNode
            || $node instanceof KeepNode
            || $node instanceof CommentNode
            || $node instanceof CalloutNode
            || $node instanceof LimitMatchNode
            || $node instanceof PcreVerbNode
        ) {
            return true;
        }

        if ($node instanceof BackrefNode || $node instanceof SubroutineNode) {
            return null;
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

            return $this->nullableStatus($node->child);
        }

        if ($node instanceof QuantifierNode) {
            if ($this->quantifierAllowsZero($node)) {
                return true;
            }

            return $this->nullableStatus($node->node);
        }

        if ($node instanceof SequenceNode) {
            $unknown = false;
            foreach ($node->children as $child) {
                $childNullable = $this->nullableStatus($child);
                if (false === $childNullable) {
                    return false;
                }
                if (null === $childNullable) {
                    $unknown = true;
                }
            }

            return $unknown ? null : true;
        }

        if ($node instanceof AlternationNode) {
            $unknown = false;
            foreach ($node->alternatives as $alt) {
                $altNullable = $this->nullableStatus($alt);
                if (true === $altNullable) {
                    return true;
                }
                if (null === $altNullable) {
                    $unknown = true;
                }
            }

            return $unknown ? null : false;
        }

        if ($node instanceof ConditionalNode) {
            $yes = $this->nullableStatus($node->yes);
            $no = $this->nullableStatus($node->no);

            if (true === $yes || true === $no) {
                return true;
            }
            if (false === $yes && false === $no) {
                return false;
            }

            return null;
        }

        if ($node instanceof DefineNode) {
            return $this->nullableStatus($node->content);
        }

        return null;
    }

    private function quantifierAllowsZero(QuantifierNode $node): bool
    {
        return 0 === $node->bounds?->min;
    }
}
