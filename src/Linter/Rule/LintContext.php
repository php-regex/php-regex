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
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\UnicodePropNode;

/**
 * Per-run lint context: immutable pattern facts plus the mutable traversal
 * cursor (parent stack, alternation branches, active inline flags).
 *
 * Rules read from this context; only the traversal engine mutates it via the
 * {@internal}-tagged mutators.
 *
 * @internal
 */
final class LintContext
{
    /**
     * Groups after which nothing more is matched once their child is.
     */
    private const GROUPS_ENDING_WITH_THEIR_CHILD = [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::Atomic, GroupType::BranchReset, GroupType::InlineFlags];

    /**
     * @var list<NodeInterface>
     */
    private array $parentStack = [];

    /**
     * @var list<array{id: string, index: int}>
     */
    private array $alternationStack = [];

    private string $activeFlags;

    /**
     * @param (\Closure(string): bool)|null $ruleEnabled whether the configuration turns a rule on, by its
     *                                                   id; every rule when none is given
     */
    public function __construct(
        public readonly PatternInfo $pattern,
        public readonly GroupIndex $groups,
        public readonly CharSetAnalyzer $charSetAnalyzer,
        private readonly ?\Closure $ruleEnabled = null,
    ) {
        $this->activeFlags = $pattern->flags;
    }

    /**
     * Whether the configuration turns the rule on, so that a rule deferring
     * to it knows its finding will be reported.
     */
    public function isRuleEnabled(string $ruleId): bool
    {
        return null === $this->ruleEnabled || ($this->ruleEnabled)($ruleId);
    }

    /**
     * Flags in effect at the current traversal position, including inline
     * flag groups such as (?i) and (?-i:...).
     */
    public function activeFlags(): string
    {
        return $this->activeFlags;
    }

    /**
     * The characters the node can start with, each part of it read under
     * the flags in effect there: $flags at its start (the flags at the
     * current traversal position by default), then the inline flags inside
     * it, as the "(?s)" in "(?:(?s).a|\na)" or the one an earlier
     * alternative carries into "\na(?s)|.a".
     */
    public function firstChars(NodeInterface $node, ?string $flags = null): ByteCharSet
    {
        return $this->charsInForce($node, $flags ?? $this->activeFlags, true);
    }

    /**
     * The characters the node can end with, read as firstChars() reads
     * them.
     */
    public function lastChars(NodeInterface $node, ?string $flags = null): ByteCharSet
    {
        return $this->charsInForce($node, $flags ?? $this->activeFlags, false);
    }

    /**
     * The flags in effect at a node inside $within, whose start reads
     * $flags (the flags at the current traversal position by default).
     */
    public function flagsAt(NodeInterface $node, NodeInterface $within, ?string $flags = null): string
    {
        $flags ??= $this->activeFlags;

        return $this->findFlags($within, $node, $flags) ?? $flags;
    }

    /**
     * The flags in effect at each item of a sequence, or at each
     * alternative of an alternation, whose start reads $flags (the flags at
     * the current traversal position by default): a standalone (?s) holds
     * for the items after it and for the alternatives after its own.
     *
     * @return array<string> the flags, keyed as the children are
     */
    public function flagsAtEachChild(SequenceNode|AlternationNode $node, ?string $flags = null): array
    {
        $flags ??= $this->activeFlags;
        $atEachChild = [];
        foreach ($node instanceof SequenceNode ? $node->children : $node->alternatives as $index => $child) {
            $atEachChild[$index] = $flags;
            $flags = self::flagsAfter($child, $flags);
        }

        return $atEachChild;
    }

    /**
     * @return list<NodeInterface>
     */
    public function parents(): array
    {
        return $this->parentStack;
    }

    /**
     * Check if the current node is inside an unbounded quantifier (*, +, {n,}).
     * Used to determine if overlapping alternations pose a ReDoS risk.
     *
     * The search stops at an atomic lookaround: once it holds, the engine
     * never comes back into it, so a loop around it cannot retry its
     * branches.
     */
    public function isInsideUnboundedQuantifier(): bool
    {
        foreach (array_reverse($this->parentStack) as $parent) {
            if ($parent instanceof GroupNode && self::isAtomicLookaround($parent)) {
                return false;
            }

            if ($parent instanceof QuantifierNode) {
                // Skip possessive quantifiers - they don't backtrack
                if (QuantifierType::Possessive === $parent->type) {
                    continue;
                }

                // Check if the quantifier's child is an atomic group - atomic groups don't backtrack
                if ($parent->node instanceof GroupNode && GroupType::Atomic === $parent->node->type) {
                    continue;
                }

                if (QuantifierMath::isUnbounded($parent->quantifier)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether nothing can be matched after the node wherever it runs: where
     * it is written, nothing follows it, and no subroutine call runs it
     * again at another point of the match. With $endAnchorsMayFollow, "$",
     * "\z" and "\Z" may follow it as well.
     */
    public function endsThePatternForEveryCall(NodeInterface $node, bool $endAnchorsMayFollow = false): bool
    {
        return $this->nothingFollows($node, $endAnchorsMayFollow) && !$this->isReenteredBySubroutine($node);
    }

    /**
     * Whether only items that may match nothing, and hold nothing that can
     * fail, can follow the node wherever it runs: "b*" or "(?:b|)", never
     * an anchor, a lookaround, a verb or a reference. Whatever the node
     * matches, the rest of the pattern then matches the empty string after
     * it, at the first try.
     */
    public function onlyEmptyMatchesFollowForEveryCall(NodeInterface $node): bool
    {
        return $this->nothingFollows($node, false, true) && !$this->isReenteredBySubroutine($node);
    }

    /**
     * Whether a subroutine call can run the node again at another point of
     * the match: the node is, or sits inside, a group some call targets, or
     * the pattern recurses into itself whole. What follows the node where it
     * is written then says nothing about what follows it when called.
     *
     * The node is the one being checked; its ancestors are read from the
     * traversal cursor.
     */
    public function isReenteredBySubroutine(NodeInterface $node): bool
    {
        if ($this->groups->recurses) {
            return true;
        }

        if ([] === $this->groups->subroutineTargets) {
            return false;
        }

        foreach ([$node, ...$this->parentStack] as $candidate) {
            if ($candidate instanceof GroupNode && $this->groups->isSubroutineTarget($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, int>
     */
    public function currentAlternationSignature(): array
    {
        $signature = [];
        foreach ($this->alternationStack as $entry) {
            $signature[$entry['id']] = $entry['index'];
        }

        return $signature;
    }

    /**
     * @internal engine-only mutator
     */
    public function setActiveFlags(string $flags): void
    {
        $this->activeFlags = $flags;
    }

    /**
     * @internal engine-only mutator
     */
    public function pushParent(NodeInterface $node): void
    {
        $this->parentStack[] = $node;
    }

    /**
     * @internal engine-only mutator
     */
    public function popParent(): void
    {
        array_pop($this->parentStack);
    }

    /**
     * @internal engine-only mutator
     */
    public function pushAlternationBranch(string $id, int $index): void
    {
        $this->alternationStack[] = ['id' => $id, 'index' => $index];
    }

    /**
     * @internal engine-only mutator
     */
    public function popAlternationBranch(): void
    {
        array_pop($this->alternationStack);
    }

    /**
     * Whether nothing can be matched after the node: each enclosing node
     * ends with it, up to the pattern itself. Only comments may follow it;
     * an anchor, a lookaround or any other assertion after it can fail and
     * send the engine back into it. With $endAnchorsMayFollow, "$", "\z"
     * and "\Z" may follow it as well.
     *
     * The node is the one being checked; its ancestors are read from the
     * traversal cursor.
     */
    private function nothingFollows(NodeInterface $node, bool $endAnchorsMayFollow, bool $emptyMatchesMayFollow = false): bool
    {
        $child = $node;
        foreach (array_reverse($this->parentStack) as $parent) {
            $last = match (true) {
                $parent instanceof SequenceNode => self::onlyCommentsFollow($parent, $child, $endAnchorsMayFollow, $emptyMatchesMayFollow) ? $child : null,
                $parent instanceof AlternationNode => $child,
                $parent instanceof GroupNode && \in_array($parent->type, self::GROUPS_ENDING_WITH_THEIR_CHILD, true) => $parent->child,
                default => null,
            };

            if ($last !== $child) {
                return false;
            }

            $child = $parent;
        }

        return true;
    }

    private static function onlyCommentsFollow(SequenceNode $sequence, NodeInterface $item, bool $endAnchorsMayFollow, bool $emptyMatchesMayFollow = false): bool
    {
        $after = \array_slice($sequence->children, (int) array_search($item, $sequence->children, true) + 1);

        return [] === array_filter(
            $after,
            static fn (NodeInterface $next): bool => !$next instanceof CommentNode
                && !($endAnchorsMayFollow && NodePredicates::isEndAnchorNode($next))
                && !($emptyMatchesMayFollow && self::alwaysMatchesEmptyFirst($next)),
        );
    }

    /**
     * Whether the node may match nothing and holds nothing that can fail
     * where it stands: no anchor, lookaround, verb, reference or call.
     */
    private static function alwaysMatchesEmptyFirst(NodeInterface $node): bool
    {
        return NodePredicates::canBeEmpty($node) && !self::holdsATest($node);
    }

    /**
     * Whether the node holds anything but structure, characters and
     * comments: an anchor, an assertion, a lookaround, a verb, a callout, a
     * conditional, a reference or a call can each fail where it stands.
     */
    private static function holdsATest(NodeInterface $node): bool
    {
        if ($node instanceof LiteralNode || $node instanceof CharClassNode || $node instanceof CharTypeNode
            || $node instanceof DotNode || $node instanceof CharLiteralNode || $node instanceof UnicodePropNode
            || $node instanceof CommentNode
        ) {
            return false;
        }

        if (!$node instanceof SequenceNode && !$node instanceof AlternationNode && !$node instanceof QuantifierNode
            && !($node instanceof GroupNode && NodePredicates::isTransparentGroup($node->type))
        ) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::holdsATest($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The analyzer's sets, with the flags followed through the groups, the
     * sequences and the alternations that can change them. Any other node,
     * and one with no inline flags inside, is read whole by the analyzer
     * under the flags at its start.
     */
    private function charsInForce(NodeInterface $node, string $flags, bool $fromStart): ByteCharSet
    {
        if (self::containsInlineFlags($node)) {
            if ($node instanceof GroupNode) {
                return $this->charsInForce($node->child, self::flagsInside($node, $flags), $fromStart);
            }

            if ($node instanceof QuantifierNode) {
                return $this->charsInForce($node->node, $flags, $fromStart);
            }

            if ($node instanceof AlternationNode) {
                $set = ByteCharSet::empty();
                foreach ($this->flagsAtEachChild($node, $flags) as $index => $flagsAtAlternative) {
                    $set = $set->union($this->charsInForce($node->alternatives[$index], $flagsAtAlternative, $fromStart));
                }

                return $set;
            }

            if ($node instanceof SequenceNode) {
                $flagsAtChild = $this->flagsAtEachChild($node, $flags);
                $indexes = $fromStart ? array_keys($flagsAtChild) : array_reverse(array_keys($flagsAtChild));
                $set = ByteCharSet::empty();
                foreach ($indexes as $index) {
                    $set = $set->union($this->charsInForce($node->children[$index], $flagsAtChild[$index], $fromStart));
                    if (!NodePredicates::isOptionalNode($node->children[$index])) {
                        break;
                    }
                }

                return $set;
            }
        }

        $analyzer = $this->analyzerFor($flags);

        return $fromStart ? $analyzer->firstChars($node) : $analyzer->lastChars($node);
    }

    /**
     * The pattern's analyzer under other flags. No inline flag turns the u
     * modifier off: "(?^)" resets i, m, n, s and x only.
     */
    private function analyzerFor(string $flags): CharSetAnalyzer
    {
        return $this->charSetAnalyzer->withFlags(str_contains($this->pattern->flags, 'u') ? $flags.'u' : $flags);
    }

    /**
     * The flags in effect at $target, searched for inside $node whose start
     * reads $flags; null when $target is not inside $node.
     */
    private function findFlags(NodeInterface $node, NodeInterface $target, string $flags): ?string
    {
        if ($node === $target) {
            return $flags;
        }

        foreach ($this->childrenWithTheirFlags($node, $flags) as [$child, $flagsAtChild]) {
            $found = $this->findFlags($child, $target, $flagsAtChild);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Each child of the node with the flags in effect at its start, the
     * node's own start reading $flags. A standalone (?s) holds for the
     * children after it: the rest of its sequence, the alternatives after
     * its own, and the no branch of a conditional after its yes branch.
     *
     * @return iterable<array{NodeInterface, string}>
     */
    private function childrenWithTheirFlags(NodeInterface $node, string $flags): iterable
    {
        if ($node instanceof GroupNode) {
            yield [$node->child, self::flagsInside($node, $flags)];

            return;
        }

        foreach ($node->getChildren() as $child) {
            yield [$child, $flags];
            $flags = self::flagsAfter($child, $flags);
        }
    }

    /**
     * The flags in effect after a node: a standalone (?s) changes them, as
     * does one at the top level of an alternative; a group restores them
     * at its end.
     */
    private static function flagsAfter(NodeInterface $node, string $flags): string
    {
        if ($node instanceof GroupNode && NodePredicates::isStandaloneInlineFlagsGroup($node)) {
            return NodePredicates::applyInlineFlags($flags, (string) $node->flags);
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                $flags = self::flagsAfter($child, $flags);
            }
        }

        return $flags;
    }

    /**
     * The flags in effect inside a group: a scoped (?s:...) changes them.
     */
    private static function flagsInside(GroupNode $group, string $flags): string
    {
        return GroupType::InlineFlags === $group->type && null !== $group->flags
            ? NodePredicates::applyInlineFlags($flags, $group->flags)
            : $flags;
    }

    private static function containsInlineFlags(NodeInterface $node): bool
    {
        if ($node instanceof GroupNode && GroupType::InlineFlags === $node->type && null !== $node->flags) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::containsInlineFlags($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A lookaround the engine leaves for good once it holds; "(*napla:...)"
     * and "(?*...)" are the non-atomic exceptions, marked "*".
     */
    private static function isAtomicLookaround(GroupNode $group): bool
    {
        return !NodePredicates::isTransparentGroup($group->type)
            && GroupType::ScanSubstring !== $group->type
            && '*' !== $group->flags;
    }
}
