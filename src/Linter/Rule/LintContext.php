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

use PHPRegex\Automata\Solver\DfaCacheInterface;
use PHPRegex\Linter\Rule\Support\Continuation;
use PHPRegex\Linter\Rule\Support\FlagScope;
use PHPRegex\Linter\Rule\Support\LanguageQuestions;
use PHPRegex\Linter\Rule\Support\NodePredicates;
use PHPRegex\Linter\Rule\Support\QuantifierMath;
use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\PcreTarget;

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
     * The DFAs the automata build for the rules' questions, kept for the
     * run: the process's cache unless the run brings its own.
     */
    public readonly DfaCacheInterface $dfas;

    /**
     * The PHP and PCRE2 the pattern is judged for: the rules' questions are
     * read as that release reads them.
     */
    public readonly PcreTarget $target;

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
     * What can be matched after the nodes of the pattern.
     */
    private readonly Continuation $continuation;

    /**
     * @param (\Closure(string): bool)|null $ruleEnabled whether the configuration turns a rule on, by its
     *                                                   id; every rule when none is given
     */
    public function __construct(
        public readonly PatternInfo $pattern,
        public readonly GroupIndex $groups,
        public readonly CharSetAnalyzer $charSetAnalyzer,
        private readonly ?\Closure $ruleEnabled = null,
        ?DfaCacheInterface $dfas = null,
        ?PcreTarget $target = null,
    ) {
        $this->activeFlags = $pattern->flags;
        $this->continuation = new Continuation($groups);
        $this->dfas = $dfas ?? LanguageQuestions::dfas();
        $this->target = $target ?? PcreTarget::runtime();
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

        return FlagScope::flagsAt($node, $within, $flags) ?? $flags;
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
        return FlagScope::flagsAtEachChild($node, $flags ?? $this->activeFlags);
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
        $foundDangerous = false;

        foreach (array_reverse($this->parentStack) as $parent) {
            if ($parent instanceof GroupNode && self::isAtomicLookaround($parent)) {
                return false;
            }

            if ($parent instanceof GroupNode && GroupType::Atomic === $parent->type) {
                return false;
            }

            if ($parent instanceof QuantifierNode) {
                if (QuantifierType::Possessive === $parent->type) {
                    if ($foundDangerous) {
                        return false;
                    }

                    continue;
                }

                if ($parent->node instanceof GroupNode && GroupType::Atomic === $parent->node->type) {
                    continue;
                }

                if (QuantifierMath::isUnbounded($parent->quantifier)) {
                    $foundDangerous = true;
                }
            }
        }

        return $foundDangerous;
    }

    /**
     * Whether the current node is directly enclosed by an atomic group or
     * the body of a possessive quantifier. Rules whose dangerous pattern
     * needs an outer backtracking loop can skip their check when this holds:
     * the atomic or possessive context prevents the engine from ever
     * retrying the dangerous structure from outside.
     */
    public function isInsideAtomicOrPossessiveContext(): bool
    {
        foreach ($this->parentStack as $parent) {
            if ($parent instanceof GroupNode && GroupType::Atomic === $parent->type) {
                return true;
            }

            if ($parent instanceof QuantifierNode && QuantifierType::Possessive === $parent->type) {
                return true;
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
        return $this->continuation->endsThePatternForEveryCall($node, $this->parentStack, $endAnchorsMayFollow);
    }

    /**
     * Whether only items that may match nothing, and hold nothing that can
     * fail, can follow the node wherever it runs: "b*" or "(?:b|)", never
     * an anchor, a lookaround, a verb or a reference.
     */
    public function onlyEmptyMatchesFollowForEveryCall(NodeInterface $node): bool
    {
        return $this->continuation->onlyEmptyMatchesFollowForEveryCall($node, $this->parentStack);
    }

    /**
     * Whether a subroutine call can run the node again at another point of
     * the match: the node is, or sits inside, a group some call targets, or
     * the pattern recurses into itself whole.
     */
    public function isReenteredBySubroutine(NodeInterface $node): bool
    {
        return $this->continuation->isReenteredBySubroutine($node, $this->parentStack);
    }

    /**
     * Whether only comments are written after the item in the sequence.
     */
    public function onlyCommentsFollowIn(SequenceNode $sequence, NodeInterface $item): bool
    {
        return $this->continuation->onlyCommentsFollowIn($sequence, $item);
    }

    /**
     * Whether the items written after the node in each sequence around it
     * pass the test, the innermost sequence first.
     *
     * @param \Closure(list<NodeInterface>): bool $test
     */
    public function followersPass(NodeInterface $node, \Closure $test): bool
    {
        return $this->continuation->followersPass($node, $this->parentStack, $test);
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
     * The analyzer's sets, with the flags followed through the groups, the
     * sequences and the alternations that can change them. Any other node,
     * and one with no inline flags inside, is read whole by the analyzer
     * under the flags at its start.
     */
    private function charsInForce(NodeInterface $node, string $flags, bool $fromStart): ByteCharSet
    {
        if (FlagScope::containsInlineFlags($node)) {
            if ($node instanceof GroupNode) {
                return $this->charsInForce($node->child, FlagScope::flagsInside($node, $flags), $fromStart);
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
