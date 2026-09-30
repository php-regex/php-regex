<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Lint\Rule;

use RegexParser\Analysis\CharSetAnalyzer;
use RegexParser\Lint\Rule\Support\QuantifierMath;
use RegexParser\Node\GroupNode;
use RegexParser\Node\GroupType;
use RegexParser\Node\NodeInterface;
use RegexParser\Node\QuantifierNode;
use RegexParser\Node\QuantifierType;

/**
 * Per-run lint context: immutable pattern facts plus the mutable traversal
 * cursor (parent stack, alternation branches, active inline flags).
 *
 * Rules read from this context; only the traversal engine mutates it via the
 * {@internal}-tagged mutators.
 */
final class LintContext
{
    /**
     * @var list<NodeInterface>
     */
    private array $parentStack = [];

    /**
     * @var list<array{id: string, index: int}>
     */
    private array $alternationStack = [];

    private string $activeFlags;

    public function __construct(
        public readonly PatternInfo $pattern,
        public readonly GroupIndex $groups,
        public readonly CharSetAnalyzer $charSetAnalyzer,
    ) {
        $this->activeFlags = $pattern->flags;
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
     * @return list<NodeInterface>
     */
    public function parents(): array
    {
        return $this->parentStack;
    }

    /**
     * Check if the current node is inside an unbounded quantifier (*, +, {n,}).
     * Used to determine if overlapping alternations pose a ReDoS risk.
     */
    public function isInsideUnboundedQuantifier(): bool
    {
        foreach ($this->parentStack as $parent) {
            if ($parent instanceof QuantifierNode) {
                // Skip possessive quantifiers - they don't backtrack
                if (QuantifierType::T_POSSESSIVE === $parent->type) {
                    continue;
                }

                // Check if the quantifier's child is an atomic group - atomic groups don't backtrack
                if ($parent->node instanceof GroupNode && GroupType::T_GROUP_ATOMIC === $parent->node->type) {
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
}
