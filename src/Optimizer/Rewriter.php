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

use PHPRegex\Parser\AbstractNodeVisitor;
use PHPRegex\Parser\Node;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ClassSetOperationNode;
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
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Node\VersionConditionNode;

/**
 * Transforms the AST to apply optimizations, returning a new, simplified AST.
 *
 * The rewriter walks the tree and decides where each rewrite applies; a
 * part for each family of rewrites does the work: ClassRewrites for the
 * character classes, RepeatRewrites for counted runs and quantifiers,
 * AlternationRewrites for duplicate and shared alternatives,
 * PossessiveRewrites for possessive repeats, FlagRewrites for the flags of
 * the pattern. They are built once, with the options, and share one
 * RewriteState, the modes of the pattern visited and the rewrites made.
 *
 * @extends AbstractNodeVisitor<Node\NodeInterface>
 *
 * @internal
 */
final class Rewriter extends AbstractNodeVisitor
{
    /*
     * The kinds of rewrite, as OptimizationResult::$changes names them.
     */
    public const DIGITS = 'Wrote [0-9] as \\d.';
    public const WORD = 'Wrote [a-zA-Z0-9_] as \\w.';
    public const CLASS_MEMBERS = 'Sorted and merged the members of a class.';
    public const ALTERNATION_TO_CLASS = 'Wrote an alternation of single characters as a class.';
    public const MERGED_CLASSES = 'Merged the classes of an alternation into one.';
    public const DUPLICATE_ALTERNATIVE = 'Removed an alternative written twice.';
    public const FACTORED_PREFIX = 'Factored out the start the alternatives share.';
    public const FACTORED_SUFFIX = 'Factored out the end the alternatives share.';
    public const GROUP_REMOVED = 'Removed a group that changes nothing.';
    public const RUN_COUNTED = 'Wrote a run of the same item as a count.';
    public const SHORT_QUANTIFIER = 'Wrote a quantifier in its short form.';
    public const COUNT_OF_ONE = 'Removed a quantifier that repeats once.';
    public const REPEAT_OF_ZERO = 'Removed an item repeated zero times.';
    public const POSSESSIVE = 'Made a quantifier possessive where nothing after it takes back what it matched.';
    public const UNUSED_FLAG = 'Removed a flag nothing in the pattern reads.';
    public const RESPELLED = 'Wrote a character without a backslash it does not need.';

    private bool $isInsideQuantifier = false;

    /**
     * Whether a quantifier may be made possessive here: the option is on,
     * no "U" makes a plain quantifier lazy, and no inline flag changes what
     * a node matches, which the character sets, reading the flags the
     * pattern is written with, would not see.
     */
    private bool $possessify = false;

    private readonly RewriteState $state;

    private readonly ClassRewrites $classRewrites;

    private readonly AlternationRewrites $alternationRewrites;

    private readonly RepeatRewrites $repeatRewrites;

    private readonly PossessiveRewrites $possessiveRewrites;

    private readonly FlagRewrites $flagRewrites;

    /**
     * @param int         $minQuantifierCount how many repetitions of an item
     *                                        become a count, at least 2, as
     *                                        OptimizerOptions checks
     * @param string|null $locale             the LC_CTYPE locale the pattern
     *                                        runs under, when it is known:
     *                                        "\w" stands for "[a-zA-Z0-9_]"
     *                                        under "C" and "POSIX" only, and
     *                                        null, the locale unknown, writes
     *                                        no "\w"
     */
    public function __construct(
        bool $optimizeDigits = true,
        bool $optimizeWord = true,
        bool $ranges = true,
        bool $canonicalizeCharClasses = true,
        /**
         * Whether to automatically convert greedy quantifiers to possessive
         * when followed by a disjoint character set. This is safe in most cases
         * but can change semantics when backreferences are involved.
         * Default is false to ensure semantic preservation.
         */
        private readonly bool $possessive = false,
        /**
         * Whether to perform string-based alternation factorization.
         * This can make verbose (/x) patterns harder to read.
         */
        private readonly bool $factorize = false,
        int $minQuantifierCount = 4,
        ?string $locale = null,
    ) {
        $this->state = new RewriteState();
        $this->classRewrites = new ClassRewrites($this->state, $optimizeDigits, $optimizeWord, $ranges, $canonicalizeCharClasses, $locale);
        $this->alternationRewrites = new AlternationRewrites($this->state);
        $this->repeatRewrites = new RepeatRewrites($this->state, $minQuantifierCount);
        $this->possessiveRewrites = new PossessiveRewrites($this->state, $this->repeatRewrites);
        $this->flagRewrites = new FlagRewrites();
        // Read again from the flags of each pattern visited whole.
        $this->possessify = $possessive;
    }

    #[\Override]
    public function visitRegex(RegexNode $node): NodeInterface
    {
        $this->state->start($node, $this->flagRewrites->containsInlineFlags($node->pattern));
        $this->possessify = $this->possessive && !$this->state->lazyByDefault && !$this->state->inlineFlags;
        $optimizedPattern = $node->pattern->accept($this);

        // Remove useless flags
        $optimizedFlags = $this->flagRewrites->removeUselessFlags($node->flags, $optimizedPattern);
        if ($optimizedFlags !== $node->flags) {
            $this->state->changed(self::UNUSED_FLAG);
        }

        if ($optimizedPattern === $node->pattern && $optimizedFlags === $node->flags) {
            return $node;
        }

        return new RegexNode($optimizedPattern, $optimizedFlags, $node->delimiter, $node->startPosition, $node->endPosition);
    }

    /**
     * The kinds of rewrite the last pattern visited went through, each
     * once, in the order first made.
     *
     * @return list<string>
     */
    public function changes(): array
    {
        return $this->state->changes();
    }

    #[\Override]
    public function visitAlternation(AlternationNode $node): NodeInterface
    {
        $optimizedAlts = [];
        $hasChanged = false;

        foreach ($node->alternatives as $alt) {
            $optimizedAlt = $alt->accept($this);

            if ($optimizedAlt instanceof AlternationNode) {
                array_push($optimizedAlts, ...$optimizedAlt->alternatives);
                $hasChanged = true;
            } else {
                $optimizedAlts[] = $optimizedAlt;
            }

            if ($optimizedAlt !== $alt) {
                $hasChanged = true;
            }
        }

        // Try to merge adjacent character class nodes
        $mergedAlts = $this->classRewrites->mergeAdjacentCharClasses($optimizedAlts);
        if ($mergedAlts !== $optimizedAlts) {
            $this->state->changed(self::MERGED_CLASSES);
            $hasChanged = true;
            $optimizedAlts = $mergedAlts;
        }

        $deduplicatedAlts = $this->alternationRewrites->deduplicateAlternation($optimizedAlts);
        if (\count($deduplicatedAlts) !== \count($optimizedAlts)) {
            $this->state->changed(self::DUPLICATE_ALTERNATIVE);
            $hasChanged = true;
            $optimizedAlts = $deduplicatedAlts;
        }

        if ($this->classRewrites->canAlternationBeCharClass($optimizedAlts)) {
            $this->state->changed(self::ALTERNATION_TO_CLASS);
            $expression = new AlternationNode($optimizedAlts, $node->startPosition, $node->endPosition);

            return new CharClassNode($expression, false, $node->startPosition, $node->endPosition);
        }

        if (!$hasChanged) {
            return $node;
        }

        if ($this->factorize) {
            $factoredAlts = $this->alternationRewrites->factorizeAlternation($optimizedAlts);

            if ($factoredAlts !== $optimizedAlts) {
                $this->state->changed(self::FACTORED_PREFIX);
                $optimizedAlts = $factoredAlts;
            }

            $suffixFactoredAlts = $this->alternationRewrites->factorizeSuffix($optimizedAlts);

            if ($suffixFactoredAlts !== $optimizedAlts) {
                $this->state->changed(self::FACTORED_SUFFIX);

                return new AlternationNode($suffixFactoredAlts, $node->startPosition, $node->endPosition);
            }
        }

        return new AlternationNode($optimizedAlts, $node->startPosition, $node->endPosition);
    }

    #[\Override]
    public function visitSequence(SequenceNode $node): NodeInterface
    {
        $optimizedChildren = [];
        $hasChanged = false;

        foreach ($node->children as $child) {
            $optimizedChild = $child->accept($this);

            if ($optimizedChild instanceof SequenceNode) {
                array_push($optimizedChildren, ...$optimizedChild->children);
                $hasChanged = true;

                continue;
            }

            if ($optimizedChild instanceof LiteralNode && '' === $optimizedChild->value) {
                $hasChanged = true;

                continue;
            }

            if ($optimizedChild !== $child) {
                $hasChanged = true;
            }

            $optimizedChildren[] = $optimizedChild;
        }

        // Sequence compaction (before merging adjacent literals)
        $originalCount = \count($optimizedChildren);
        /** @var array<NodeInterface> $optimizedChildren */
        $optimizedChildren = $this->repeatRewrites->compactSequence($optimizedChildren);
        if (\count($optimizedChildren) !== $originalCount) {
            $this->state->changed(self::RUN_COUNTED);
            $hasChanged = true;
        }

        // Merge adjacent literal nodes
        $mergedChildren = [];
        foreach ($optimizedChildren as $child) {
            if ($child instanceof LiteralNode && \count($mergedChildren) > 0) {
                $prevNode = $mergedChildren[\count($mergedChildren) - 1];
                if ($prevNode instanceof LiteralNode) {
                    $mergedChildren[\count($mergedChildren) - 1] = new LiteralNode(
                        $prevNode->value.$child->value,
                        $prevNode->startPosition,
                        $child->endPosition,
                    );
                    $hasChanged = true;

                    continue;
                }
            }

            $mergedChildren[] = $child;
        }
        $optimizedChildren = $mergedChildren;

        // Compact repeated literal sequences (only when beneficial)
        $countedChildren = $this->repeatRewrites->countLiteralRuns($optimizedChildren);
        if ($countedChildren !== $optimizedChildren) {
            $hasChanged = true;
            $optimizedChildren = $countedChildren;
        }

        // Auto-possessivization (opt-in, conservative by default)
        // This optimization is opt-in because it can change semantics with backreferences
        if ($this->possessify) {
            $possessiveChildren = $this->possessiveRewrites->possessify($optimizedChildren);
            if ($possessiveChildren !== $optimizedChildren) {
                $hasChanged = true;
                $optimizedChildren = $possessiveChildren;
            }
        }

        if (!$hasChanged) {
            return $node;
        }

        $optimizedChildren = $this->possessiveRewrites->regroupBeforeOptionalPossessiveGroups($optimizedChildren);

        if (1 === \count($optimizedChildren)) {
            return $optimizedChildren[0];
        }

        if (0 === \count($optimizedChildren)) {
            return new LiteralNode('', $node->startPosition, $node->endPosition);
        }

        return new SequenceNode($optimizedChildren, $node->startPosition, $node->endPosition);
    }

    #[\Override]
    public function visitGroup(GroupNode $node): NodeInterface
    {
        $optimizedChild = $node->child->accept($this);

        // Enhanced Group Unwrapping: (?:x) -> x
        // If the group is non-capturing and contains a single atomic node, remove the group.
        // But do not unwrap if we are inside a quantifier and the original child was a sequence or alternation,
        // as unwrapping changes semantics when the group has a quantifier.
        if (
            GroupType::NonCapturing === $node->type
            && !($this->isInsideQuantifier && ($node->child instanceof SequenceNode || $node->child instanceof AlternationNode))
            // A quantifier repeats one character only: "(?:)*", "(?:^)*" or
            // "(?:\b)+" keep their group.
            && !($this->isInsideQuantifier && !$this->repeatRewrites->isSingleCharacter($optimizedChild))
            // PCRE2 makes "\N" repeated right before "\R" possessive, though
            // both match "\r": "\N*\R" misses "a\r\x0Bb\r", "(?:\N)*\R" does not.
            && !($this->isInsideQuantifier && $optimizedChild instanceof CharTypeNode && 'N' === $optimizedChild->value)
            && (
                $optimizedChild instanceof LiteralNode
                || $optimizedChild instanceof CharLiteralNode
                || $optimizedChild instanceof CharTypeNode
                || $optimizedChild instanceof DotNode
                || $optimizedChild instanceof CharClassNode
                || $optimizedChild instanceof AnchorNode
                || $optimizedChild instanceof AssertionNode
                || $optimizedChild instanceof UnicodePropNode
            )
        ) {
            $this->state->changed(self::GROUP_REMOVED);

            return $optimizedChild;
        }

        if ($optimizedChild !== $node->child) {
            return new GroupNode($optimizedChild, $node->type, $node->name, $node->flags, $node->startPosition, $node->endPosition, $node->usePythonSyntax, $node->scannedGroups);
        }

        return $node;
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): NodeInterface
    {
        // The group a quantifier repeats keeps its parentheses however deep
        // a quantifier of its own sits inside it: the flag comes back to
        // what the enclosing item set, never to false.
        $wasInsideQuantifier = $this->isInsideQuantifier;
        $this->isInsideQuantifier = true;

        try {
            $optimizedNode = $node->node->accept($this);
        } finally {
            $this->isInsideQuantifier = $wasInsideQuantifier;
        }

        $ungrouped = $node->node instanceof GroupNode && !$optimizedNode instanceof GroupNode;
        if ($optimizedNode !== $node->node) {
            $node = new QuantifierNode($this->repeatRewrites->repeatable($optimizedNode), $node->quantifier, $node->type, $node->startPosition, $node->endPosition);
        }

        $normalized = $this->repeatRewrites->normalizeQuantifier($node);
        if ($ungrouped && $normalized instanceof QuantifierNode) {
            $this->possessiveRewrites->ungrouped($normalized);
        }

        return $normalized;
    }

    #[\Override]
    public function visitCharClass(CharClassNode $node): NodeInterface
    {
        $parts = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];

        $shorthand = $this->classRewrites->shorthand($node, $parts);
        if (null !== $shorthand) {
            return $shorthand;
        }

        $optimizedParts = [];
        $hasChanged = false;
        foreach ($parts as $part) {
            $optimizedPart = $part->accept($this);
            $optimizedParts[] = $optimizedPart;
            if ($optimizedPart !== $part) {
                $hasChanged = true;
            }
        }

        [$optimizedParts, $normalizedChanged] = $this->classRewrites->canonicalized($optimizedParts);
        $hasChanged = $hasChanged || $normalizedChanged;

        if ($hasChanged) {
            if (1 === \count($optimizedParts)) {
                $expression = $optimizedParts[0];
            } else {
                $expression = new AlternationNode($optimizedParts, $node->startPosition, $node->endPosition);
            }

            return new CharClassNode($expression, $node->isNegated, $node->startPosition, $node->endPosition);
        }

        return $node;
    }

    #[\Override]
    public function visitRange(RangeNode $node): NodeInterface
    {
        return $node;
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): NodeInterface
    {
        $optimizedCond = $node->condition->accept($this);
        $optimizedYes = $node->yes->accept($this);
        $optimizedNo = $node->no->accept($this);

        if ($optimizedCond !== $node->condition || $optimizedYes !== $node->yes || $optimizedNo !== $node->no) {
            return new ConditionalNode($optimizedCond, $optimizedYes, $optimizedNo, $node->startPosition, $node->endPosition);
        }

        return $node;
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): NodeInterface
    {
        return $node;
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): NodeInterface
    {
        return $node;
    }

    #[\Override]
    public function visitDot(DotNode $node): NodeInterface
    {
        return $node;
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node): NodeInterface
    {
        return $node;
    }

    #[\Override]
    public function visitAssertion(AssertionNode $node): NodeInterface
    {
        return $node;
    }

    #[\Override]
    public function visitKeep(KeepNode $node): NodeInterface
    {
        return $node;
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): NodeInterface
    {
        return $node;
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): NodeInterface
    {
        return $node;
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): NodeInterface
    {
        return $node;
    }

    #[\Override]
    public function visitPosixClass(PosixClassNode $node): NodeInterface
    {
        return $node;
    }

    #[\Override]
    public function visitControlChar(ControlCharNode $node): NodeInterface
    {
        return $node;
    }

    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node): NodeInterface
    {
        return $node;
    }

    #[\Override]
    public function visitClassSetOperation(ClassSetOperationNode $node): NodeInterface
    {
        return $node;
    }

    #[\Override]
    public function visitScriptRun(ScriptRunNode $node): NodeInterface
    {
        return $node;
    }

    #[\Override]
    public function visitVersionCondition(VersionConditionNode $node): NodeInterface
    {
        return $node;
    }

    #[\Override]
    public function visitComment(CommentNode $node): NodeInterface
    {
        return $node;
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): NodeInterface
    {
        return $node;
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): NodeInterface
    {
        return $node;
    }

    #[\Override]
    public function visitDefine(DefineNode $node): NodeInterface
    {
        $content = $node->content->accept($this);
        if ($content === $node->content) {
            return $node;
        }

        return new DefineNode($content, $node->startPosition, $node->endPosition);
    }

    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node): NodeInterface
    {
        return $node;
    }

    #[\Override]
    public function visitCallout(CalloutNode $node): NodeInterface
    {
        return $node;
    }
}
