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
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
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
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Node\VersionConditionNode;
use PHPRegex\Parser\Printer\PatternPrinter;

/**
 * Transforms the AST to apply optimizations, returning a new, simplified AST.
 *
 * @extends AbstractNodeVisitor<Node\NodeInterface>
 *
 * @internal
 */
final class Rewriter extends AbstractNodeVisitor
{
    private const CHAR_CLASS_META = [']' => true, '\\' => true, '^' => true, '-' => true];

    private string $flags = '';

    private CharSetAnalyzer $charSetAnalyzer;

    private bool $unicodeMode = false;

    private bool $isInsideQuantifier = false;

    private ?PatternPrinter $compiler = null;

    private readonly int $minQuantifierCount;

    public function __construct(
        private readonly bool $optimizeDigits = true,
        private readonly bool $optimizeWord = true,
        private readonly bool $ranges = true,
        private readonly bool $canonicalizeCharClasses = true,
        /**
         * Whether to automatically convert greedy quantifiers to possessive
         * when followed by a disjoint character set. This is safe in most cases
         * but can change semantics when backreferences are involved.
         * Default is false to ensure semantic preservation.
         */
        private readonly bool $autoPossessify = false,
        /**
         * Whether to perform string-based alternation factorization.
         * This can make verbose (/x) patterns harder to read.
         */
        private readonly bool $allowAlternationFactorization = false,
        int $minQuantifierCount = 4
    ) {
        $this->minQuantifierCount = max(2, $minQuantifierCount);
        $this->charSetAnalyzer = new CharSetAnalyzer();
    }

    #[\Override]
    public function visitRegex(RegexNode $node): NodeInterface
    {
        $this->flags = $node->flags;
        $this->unicodeMode = str_contains($this->flags, 'u');
        $this->charSetAnalyzer = new CharSetAnalyzer($this->flags);
        $optimizedPattern = $node->pattern->accept($this);

        // Remove useless flags
        $optimizedFlags = $this->removeUselessFlags($node->flags, $optimizedPattern);

        if ($optimizedPattern === $node->pattern && $optimizedFlags === $node->flags) {
            return $node;
        }

        return new RegexNode($optimizedPattern, $optimizedFlags, $node->delimiter, $node->startPosition, $node->endPosition);
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
        $mergedAlts = $this->mergeAdjacentCharClasses($optimizedAlts);
        if ($mergedAlts !== $optimizedAlts) {
            $hasChanged = true;
            $optimizedAlts = $mergedAlts;
        }

        $deduplicatedAlts = $this->deduplicateAlternation($optimizedAlts);
        if (\count($deduplicatedAlts) !== \count($optimizedAlts)) {
            $hasChanged = true;
            $optimizedAlts = $deduplicatedAlts;
        }

        if ($this->canAlternationBeCharClass($optimizedAlts)) {
            /* @var array<Node\LiteralNode> $optimizedAlts */
            $expression = new AlternationNode($optimizedAlts, $node->startPosition, $node->endPosition);

            return new CharClassNode($expression, false, $node->startPosition, $node->endPosition);
        }

        // Try to convert simple alternations to character classes
        $charClass = $this->tryConvertAlternationToCharClass($optimizedAlts, $node->startPosition, $node->endPosition);
        if (null !== $charClass) {
            return $charClass;
        }

        if (!$hasChanged) {
            return $node;
        }

        $deduplicatedAlts = $this->deduplicateAlternation($optimizedAlts);
        if (\count($deduplicatedAlts) !== \count($optimizedAlts)) {
            $hasChanged = true;
            $optimizedAlts = $deduplicatedAlts;
        }

        if ($this->allowAlternationFactorization) {
            $factoredAlts = $this->factorizeAlternation($optimizedAlts);

            if ($factoredAlts !== $optimizedAlts) {
                $hasChanged = true;
                $optimizedAlts = $factoredAlts;
            }

            $suffixFactoredAlts = $this->factorizeSuffix($optimizedAlts);

            if ($suffixFactoredAlts !== $optimizedAlts) {
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
        $optimizedChildren = $this->compactSequence($optimizedChildren);
        if (\count($optimizedChildren) !== $originalCount) {
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
        foreach ($optimizedChildren as $i => $child) {
            // \z, not $: "aaa\n" is no run of "a", though $ matches before its newline.
            if ($child instanceof LiteralNode && preg_match('/^(.)\1+\z/s', $child->value, $matches)) {
                $char = $matches[1];
                $count = \strlen($child->value);
                // Only compact if count meets the configured minimum (avoids making output longer/less readable)
                if ($count >= $this->minQuantifierCount) {
                    $baseNode = new LiteralNode($char, $child->startPosition, $child->endPosition);
                    $optimizedChildren[$i] = new QuantifierNode($baseNode, '{'.$count.'}', QuantifierType::Greedy, $child->startPosition, $child->endPosition);
                    $hasChanged = true;
                }
            }
        }

        // Auto-possessivization (opt-in, conservative by default)
        // This optimization is opt-in because it can change semantics with backreferences
        if ($this->autoPossessify) {
            for ($i = 0; $i < \count($optimizedChildren); $i++) {
                $current = $optimizedChildren[$i];
                $suffix = array_slice($optimizedChildren, $i + 1);

                if ($current instanceof QuantifierNode && QuantifierType::Greedy === $current->type && $this->isPossessifyCandidate($current) && !empty($suffix)) {
                    if ($this->isCaptureSensitive($current->node) || $this->canMatchEmpty($current->node)) {
                        continue;
                    }
                    // Compute disjointness against the FIRST-set of the suffix
                    $suffixNode = 1 === \count($suffix) ? $suffix[0] : new SequenceNode($suffix, $current->startPosition, $current->endPosition);

                    if ($this->containsInlineFlags($current->node) || $this->containsInlineFlags($suffixNode)) {
                        continue;
                    }

                    try {
                        $currentLastChars = $this->charSetAnalyzer->lastChars($current->node);
                        $suffixFirstChars = $this->charSetAnalyzer->firstChars($suffixNode);
                        if (!$currentLastChars->intersects($suffixFirstChars)) {
                            $optimizedChildren[$i] = new QuantifierNode(
                                $current->node,
                                $current->quantifier,
                                QuantifierType::Possessive,
                                $current->startPosition,
                                $current->endPosition,
                            );
                            $hasChanged = true;
                        }
                    } catch (\Throwable) {
                        // If analysis fails, don't optimize
                    }
                }
            }
        }

        if (!$hasChanged) {
            return $node;
        }

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
            && !($this->isInsideQuantifier && !$this->isSingleCharacter($optimizedChild))
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
        $this->isInsideQuantifier = true;
        $optimizedNode = $node->node->accept($this);
        $this->isInsideQuantifier = false;

        if ($optimizedNode !== $node->node) {
            $node = new QuantifierNode($optimizedNode, $node->quantifier, $node->type, $node->startPosition, $node->endPosition);
        }

        $normalized = $this->normalizeQuantifier($node);
        if ($normalized !== $node) {
            return $normalized;
        }

        return $node;
    }

    #[\Override]
    public function visitCharClass(CharClassNode $node): NodeInterface
    {
        $parts = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];

        // We must check !str_contains($this->flags, 'u') because in Unicode mode,
        // \d matches more than just [0-9] (e.g. Arabic digits), so they are not equivalent.
        if ($this->optimizeDigits && !$this->unicodeMode && 1 === \count($parts)) {
            $part = $parts[0];
            if ($part instanceof RangeNode && $part->start instanceof LiteralNode && $part->end instanceof LiteralNode) {
                if ('0' === $part->start->value && '9' === $part->end->value) {
                    return new CharTypeNode($node->isNegated ? 'D' : 'd', $node->startPosition, $node->endPosition);
                }
            }
        }

        if ($this->optimizeWord && !$this->unicodeMode && 4 === \count($parts)) {
            if ($this->isFullWordClass($parts)) {
                return new CharTypeNode($node->isNegated ? 'W' : 'w', $node->startPosition, $node->endPosition);
            }
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

        if ($this->canonicalizeCharClasses) {
            [$optimizedParts, $normalizedChanged] = $this->normalizeCharClassParts($optimizedParts);
            $hasChanged = $hasChanged || $normalizedChanged;
        }

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
        return new DefineNode(
            $node->content->accept($this),
            $node->startPosition,
            $node->endPosition,
        );
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

    /**
     * Whether the node matches exactly one character, so that a quantifier
     * written right after it repeats the same thing it repeated on the group.
     */
    private function isSingleCharacter(NodeInterface $node): bool
    {
        return match (true) {
            $node instanceof LiteralNode => 1 === mb_strlen($node->value, 'UTF-8'),
            $node instanceof CharLiteralNode, $node instanceof CharTypeNode, $node instanceof DotNode,
            $node instanceof CharClassNode, $node instanceof UnicodePropNode => true,
            default => false,
        };
    }

    /**
     * Removes useless flags from the flags string.
     */
    private function removeUselessFlags(string $flags, NodeInterface $pattern): string
    {
        // Remove 's' flag if there are no dots in the pattern
        if (str_contains($flags, 's') && !$this->patternContainsDots($pattern)) {
            $flags = str_replace('s', '', $flags);
        }

        // Remove 'm' flag if there are no ^ or $ anchors
        if (str_contains($flags, 'm') && !$this->patternContainsMultilineAnchors($pattern)) {
            $flags = str_replace('m', '', $flags);
        }

        return $flags;
    }

    /**
     * Whether a dot stands anywhere in the pattern. Every node is walked
     * through its children, so a dot inside a DEFINE body, a script run or
     * any other container still reads the flag.
     */
    private function patternContainsDots(NodeInterface $node): bool
    {
        if ($node instanceof DotNode) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if ($this->patternContainsDots($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a ^ or $ anchor, the two that depend on multiline mode,
     * stands anywhere in the pattern.
     */
    private function patternContainsMultilineAnchors(NodeInterface $node): bool
    {
        if ($node instanceof AnchorNode) {
            return '^' === $node->value || '$' === $node->value;
        }

        foreach ($node->getChildren() as $child) {
            if ($this->patternContainsMultilineAnchors($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The charset analyzer reads the outer flags only, so an inline flag
     * scope can change what a node matches without the disjointness check
     * seeing it; possessification then stays out.
     */
    private function containsInlineFlags(NodeInterface $node): bool
    {
        if ($node instanceof GroupNode && null !== $node->flags) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if ($this->containsInlineFlags($child)) {
                return true;
            }
        }

        return false;
    }

    private function isPossessifyCandidate(QuantifierNode $node): bool
    {
        if (!\in_array($node->quantifier, ['+', '*'], true)) {
            return QuantifierBounds::parse($node->quantifier)?->isUnbounded() ?? false;
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
            if ($this->quantifierAllowsZero($node->quantifier)) {
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

    private function quantifierAllowsZero(string $quantifier): bool
    {
        $bounds = QuantifierBounds::parse($quantifier);

        return null !== $bounds && 0 === $bounds->min;
    }

    /**
     * @param array<NodeInterface> $alternatives
     */
    private function canAlternationBeCharClass(array $alternatives): bool
    {
        if (empty($alternatives)) {
            return false;
        }

        foreach ($alternatives as $alt) {
            if (!$alt instanceof LiteralNode) {
                return false;
            }
            if (1 !== \strlen($alt->value)) {  // Also excludes empty strings
                return false;
            }
            if (isset(self::CHAR_CLASS_META[$alt->value])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<NodeInterface> $parts
     */
    private function isFullWordClass(array $parts): bool
    {
        $partsFound = ['a-z' => false, 'A-Z' => false, '0-9' => false, '_' => false];
        foreach ($parts as $part) {
            if ($part instanceof RangeNode && $part->start instanceof LiteralNode && $part->end instanceof LiteralNode) {
                $range = $part->start->value.'-'.$part->end->value;
                if (isset($partsFound[$range])) {
                    $partsFound[$range] = true;
                }
            } elseif ($part instanceof LiteralNode && '_' === $part->value) {
                $partsFound['_'] = true;
            }
        }

        return !\in_array(false, $partsFound, true);
    }

    /**
     * Classify a character by its ASCII category for range merging.
     *
     * @param int $ord the ASCII ordinal of the character
     *
     * @return int category: 0=other, 1=digits, 2=uppercase, 3=lowercase
     */
    private function getCharCategory(int $ord): int
    {
        if ($ord >= 48 && $ord <= 57) { // 0-9
            return 1;
        }
        if ($ord >= 65 && $ord <= 90) { // A-Z
            return 2;
        }
        if ($ord >= 97 && $ord <= 122) { // a-z
            return 3;
        }

        return 0; // other
    }

    /**
     * @param array<NodeInterface> $parts
     *
     * @return array{0: array<NodeInterface>, 1: bool}
     */
    private function normalizeCharClassParts(array $parts): array
    {
        /** @var array<int, array{start: int, end: int, fromRange: bool}> $scalarChars */
        $scalarChars = [];
        $otherParts = [];
        $changed = false;

        foreach ($parts as $part) {
            if ($part instanceof LiteralNode && 1 === \strlen($part->value)) {
                $ord = mb_ord($part->value);
                if (isset($scalarChars[$ord])) {
                    $scalarChars[$ord]['start'] = min($scalarChars[$ord]['start'], $part->startPosition);
                    $scalarChars[$ord]['end'] = max($scalarChars[$ord]['end'], $part->endPosition);
                    $changed = true;
                } else {
                    $scalarChars[$ord] = ['start' => $part->startPosition, 'end' => $part->endPosition, 'fromRange' => false];
                }

                continue;
            }

            if ($part instanceof RangeNode
                && $part->start instanceof LiteralNode
                && $part->end instanceof LiteralNode
                && 1 === \strlen($part->start->value)
                && 1 === \strlen($part->end->value)
            ) {
                $startOrd = mb_ord($part->start->value);
                $endOrd = mb_ord($part->end->value);
                if ($startOrd > $endOrd) {
                    [$startOrd, $endOrd] = [$endOrd, $startOrd];
                }
                for ($ord = $startOrd; $ord <= $endOrd; $ord++) {
                    if (isset($scalarChars[$ord])) {
                        $scalarChars[$ord]['start'] = min($scalarChars[$ord]['start'], $part->startPosition);
                        $scalarChars[$ord]['end'] = max($scalarChars[$ord]['end'], $part->endPosition);
                        $scalarChars[$ord]['fromRange'] = true;
                    } else {
                        $scalarChars[$ord] = [
                            'start' => $part->startPosition,
                            'end' => $part->endPosition,
                            'fromRange' => true,
                        ];
                    }
                }
                $changed = true;

                continue;
            }

            $otherParts[] = $part;
        }

        if (empty($scalarChars)) {
            return [$parts, $changed];
        }

        ksort($scalarChars);

        $normalized = [];
        $hasRange = false;
        $rangeStart = 0;
        $rangeEnd = 0;
        $rangeStartPos = 0;
        $rangeEndPos = 0;
        $rangeHasExplicit = false;
        $rangeCategory = 0;

        foreach ($scalarChars as $ord => $pos) {
            $ord = (int) $ord;
            $posStart = (int) $pos['start'];
            $posEnd = (int) $pos['end'];
            $fromRange = (bool) $pos['fromRange'];

            if (!$hasRange) {
                $rangeStart = $ord;
                $rangeEnd = $ord;
                $rangeStartPos = $posStart;
                $rangeEndPos = $posEnd;
                $rangeHasExplicit = $fromRange;
                $rangeCategory = $this->getCharCategory($ord);
                $hasRange = true;

                continue;
            }

            $category = $this->getCharCategory($ord);
            $canMerge = $ord === $rangeEnd + 1;
            if ($canMerge && $this->ranges && $category !== $rangeCategory) {
                $canMerge = false;
            }

            if ($canMerge) {
                $rangeEnd = $ord;
                $rangeEndPos = max($rangeEndPos, $posEnd);
                $rangeHasExplicit = $rangeHasExplicit || $fromRange;
                $rangeCategory = $category;

                continue;
            }

            $normalized = array_merge(
                $normalized,
                $this->buildRangeOrLiteral(
                    $rangeStart,
                    $rangeEnd,
                    $rangeStartPos,
                    $rangeEndPos,
                    $this->shouldBuildRange($rangeStart, $rangeEnd, $rangeHasExplicit),
                ),
            );
            $rangeStart = $ord;
            $rangeEnd = $ord;
            $rangeStartPos = $posStart;
            $rangeEndPos = $posEnd;
            $rangeHasExplicit = $fromRange;
            $rangeCategory = $category;
        }

        $normalized = array_merge(
            $normalized,
            $this->buildRangeOrLiteral(
                $rangeStart,
                $rangeEnd,
                $rangeStartPos,
                $rangeEndPos,
                $this->shouldBuildRange($rangeStart, $rangeEnd, $rangeHasExplicit),
            ),
        );

        $finalParts = array_merge($normalized, $otherParts);

        return [$finalParts, $changed || \count($finalParts) !== \count($parts)];
    }

    /**
     * @return array<NodeInterface>
     */
    private function buildRangeOrLiteral(int $startOrd, int $endOrd, int $startPos, int $endPos, bool $allowRange = true): array
    {
        $startLiteral = new LiteralNode($this->charFromCodePoint($startOrd), $startPos, $startPos + 1);

        if ($startOrd === $endOrd) {
            return [$startLiteral];
        }

        if (!$allowRange) {
            return $this->buildLiteralSequence($startOrd, $endOrd, $startPos);
        }

        // Only create a range if it covers 3 or more characters to save space
        $coverage = $endOrd - $startOrd + 1;
        if ($coverage < 3) {
            // For 2 characters, return them as separate literals
            $endLiteral = new LiteralNode($this->charFromCodePoint($endOrd), $endPos, $endPos + 1);

            return [$startLiteral, $endLiteral];
        }

        $endLiteral = new LiteralNode($this->charFromCodePoint($endOrd), $endPos, $endPos + 1);

        return [new RangeNode($startLiteral, $endLiteral, $startPos, $endPos)];
    }

    /**
     * @return array<NodeInterface>
     */
    private function buildLiteralSequence(int $startOrd, int $endOrd, int $startPos): array
    {
        $literals = [];
        $pos = $startPos;
        for ($ord = $startOrd; $ord <= $endOrd; $ord++) {
            $literals[] = new LiteralNode($this->charFromCodePoint($ord), $pos, $pos + 1);
            $pos++;
        }

        return $literals;
    }

    private function shouldBuildRange(int $startOrd, int $endOrd, bool $hasExplicitRange): bool
    {
        if ($hasExplicitRange) {
            return true;
        }

        return 45 !== $startOrd && 45 !== $endOrd;
    }

    private function charFromCodePoint(int $codePoint): string
    {
        $char = mb_chr($codePoint);
        if (false !== $char) {
            return $char;
        }

        if ($codePoint >= 0 && $codePoint <= 0xFF) {
            return \chr($codePoint);
        }

        return '';
    }

    /**
     * @param array<NodeInterface> $children
     *
     * @return array<NodeInterface>
     */
    private function compactSequence(array $children): array
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
                $parsedCount = $this->parseQuantifierCount($child->quantifier);
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

            // Never compact nodes that can affect capture numbering or backreferences
            if ($this->isCaptureSensitive($baseNode)) {
                $this->flushCompactedSequence($compacted, $currentNode, $currentCount, $currentFromQuantifier);
                $compacted[] = $child;

                continue;
            }

            if (null === $currentNode || !$this->areNodesEqual($currentNode, $baseNode)) {
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

    private function parseQuantifierCount(string $quantifier): ?int
    {
        // Only merge exact {n} counts; ranges and * + ? never merge.
        if (!str_starts_with($quantifier, '{')) {
            return null;
        }

        $bounds = QuantifierBounds::parse($quantifier);
        if (null !== $bounds && $bounds->min === $bounds->max) {
            return $bounds->min;
        }

        return null;
    }

    private function isCaptureSensitive(NodeInterface $node): bool
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

    private function areNodesEqual(NodeInterface $a, NodeInterface $b): bool
    {
        // Simple equality: same type and same string representation
        if ($a::class !== $b::class) {
            return false;
        }

        return $this->nodeToString($a) === $this->nodeToString($b);
    }

    private function createQuantifiedNode(NodeInterface $node, int $count): NodeInterface
    {
        if (1 === $count) {
            return $node;
        }

        return new QuantifierNode(
            $node,
            '{'.$count.'}',
            QuantifierType::Greedy,
            $node->getStartPosition(),
            $node->getEndPosition(),
        );
    }

    private function normalizeQuantifier(QuantifierNode $node): NodeInterface
    {
        $quantifier = $node->quantifier;

        if ('{0,}' === $quantifier) {
            return new QuantifierNode($node->node, '*', $node->type, $node->startPosition, $node->endPosition);
        }

        if ('{1,}' === $quantifier) {
            return new QuantifierNode($node->node, '+', $node->type, $node->startPosition, $node->endPosition);
        }

        if ('{0,1}' === $quantifier) {
            return new QuantifierNode($node->node, '?', $node->type, $node->startPosition, $node->endPosition);
        }

        // "X{1}+" is possessive, as an atomic group is: it never gives back.
        if (('{1}' === $quantifier || '{1,1}' === $quantifier) && QuantifierType::Possessive !== $node->type) {
            return $node->node;
        }

        // A group repeated zero times still takes its number, and can still
        // be called or referred to.
        if (('{0}' === $quantifier || '{0,0}' === $quantifier) && !$this->isCaptureSensitive($node->node)) {
            return new LiteralNode('', $node->startPosition, $node->endPosition);
        }

        return $node;
    }

    /**
     * @param array<NodeInterface> $alts
     *
     * @return array<NodeInterface>
     */
    private function deduplicateAlternation(array $alts): array
    {
        $seen = [];
        $unique = [];

        foreach ($alts as $alt) {
            $key = $this->nodeToString($alt);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $alt;
            }
        }

        return $unique;
    }

    /**
     * Factors the start the literal branches share out of an alternation:
     * "ab|ac" is "a(?:b|c)". It works on the strings the literals stand for,
     * never on their spelling: "\r\n|\r" share "\r", not a backslash.
     *
     * @param array<NodeInterface> $alts
     *
     * @return array<NodeInterface>
     */
    private function factorizeAlternation(array $alts): array
    {
        $literals = self::literalBranches($alts);
        if (null === $literals) {
            return $alts;
        }
        $values = array_map(static fn (LiteralNode $literal): string => $literal->value, $literals);

        $prefix = self::sharedStart($values);
        if ('' === $prefix) {
            return $alts;
        }

        $rests = [];
        foreach ($literals as $alt) {
            $rest = substr($alt->value, \strlen($prefix));
            $rests[] = '' === $rest ? null : new LiteralNode($rest, $alt->startPosition, $alt->endPosition);
        }

        $first = $literals[0];
        $last = $literals[\count($literals) - 1];
        if ([] === array_filter($rests)) {
            // Every branch is the same string: one is enough.
            return [new LiteralNode($prefix, $first->startPosition, $first->endPosition)];
        }

        $group = $this->optionalGroup($rests);
        if (null === $group) {
            return $alts;
        }

        return [new SequenceNode([new LiteralNode($prefix, $first->startPosition, $first->startPosition), $group], $first->startPosition, $last->endPosition)];
    }

    /**
     * Factors the end the literal branches share out of an alternation:
     * "abc|xbc" is "(?:a|x)bc".
     *
     * @param array<NodeInterface> $alts
     *
     * @return array<NodeInterface>
     */
    private function factorizeSuffix(array $alts): array
    {
        $literals = self::literalBranches($alts);
        if (null === $literals) {
            return $alts;
        }
        $values = array_map(static fn (LiteralNode $literal): string => $literal->value, $literals);

        $suffix = self::sharedEnd($values);
        if (\strlen($suffix) < 2) {
            return $alts;
        }

        $rests = [];
        foreach ($literals as $alt) {
            $rest = substr($alt->value, 0, -\strlen($suffix));
            $rests[] = '' === $rest ? null : new LiteralNode($rest, $alt->startPosition, $alt->endPosition);
        }

        $first = $literals[0];
        $last = $literals[\count($literals) - 1];
        if ([] === array_filter($rests)) {
            return [new LiteralNode($suffix, $first->startPosition, $first->endPosition)];
        }

        $group = $this->optionalGroup($rests);
        if (null === $group) {
            return $alts;
        }

        return [new SequenceNode([$group, new LiteralNode($suffix, $last->endPosition, $last->endPosition)], $first->startPosition, $last->endPosition)];
    }

    /**
     * The branches left once the shared part is gone, as one group, optional
     * when one of them is empty; null when no group keeps their order.
     *
     * @param non-empty-list<LiteralNode|null> $rests at least one not null
     */
    private function optionalGroup(array $rests): ?NodeInterface
    {
        $emptyBranch = self::emptyBranchQuantifier($rests);
        if (false === $emptyBranch) {
            return null;
        }

        $kept = array_values(array_filter($rests, static fn (?LiteralNode $rest): bool => null !== $rest));

        $first = $kept[0];
        $last = $kept[\count($kept) - 1];
        $group = new GroupNode(1 === \count($kept) ? $first : new AlternationNode($kept, $first->startPosition, $last->endPosition), GroupType::NonCapturing);

        return null === $emptyBranch ? $group : new QuantifierNode($group, '?', $emptyBranch, $first->startPosition, $last->endPosition);
    }

    /**
     * The branches, when every one is a plain literal.
     *
     * @param array<NodeInterface> $alts
     *
     * @return non-empty-list<LiteralNode>|null
     */
    private static function literalBranches(array $alts): ?array
    {
        if (\count($alts) < 2) {
            return null;
        }

        $literals = [];
        foreach ($alts as $alt) {
            if (!$alt instanceof LiteralNode || $alt->isRaw) {
                return null;
            }
            $literals[] = $alt;
        }

        return $literals;
    }

    /**
     * The longest start every string shares, cut back to whole characters
     * when they are all UTF-8.
     *
     * @param list<string> $values
     */
    private static function sharedStart(array $values): string
    {
        $prefix = $values[0];
        foreach ($values as $value) {
            $length = 0;
            $limit = min(\strlen($prefix), \strlen($value));
            while ($length < $limit && $prefix[$length] === $value[$length]) {
                $length++;
            }
            $prefix = substr($prefix, 0, $length);
        }

        if (self::allUtf8($values)) {
            while ('' !== $prefix && !mb_check_encoding($prefix, 'UTF-8')) {
                $prefix = substr($prefix, 0, -1);
            }
        }

        return $prefix;
    }

    /**
     * The longest end every string shares, cut back to whole characters
     * when they are all UTF-8.
     *
     * @param list<string> $values
     */
    private static function sharedEnd(array $values): string
    {
        $suffix = strrev(self::sharedStart(array_map(strrev(...), $values)));
        if (self::allUtf8($values)) {
            while ('' !== $suffix && !mb_check_encoding($suffix, 'UTF-8')) {
                $suffix = substr($suffix, 1);
            }
        }

        return $suffix;
    }

    /**
     * @param list<string> $values
     */
    private static function allUtf8(array $values): bool
    {
        foreach ($values as $value) {
            if (!mb_check_encoding($value, 'UTF-8')) {
                return false;
            }
        }

        return true;
    }

    /**
     * How the branches left once the shared part is factored out keep the
     * order PCRE tries them in: an empty branch tried first makes them a lazy
     * option, "a|ab" is "a(?:b)??"; tried last, a greedy one, "ab|a" is
     * "a(?:b)?". Null when no branch is empty, false when the empty one sits
     * in the middle, where no quantifier keeps the order.
     *
     * @param array<NodeInterface|null> $branches
     */
    private static function emptyBranchQuantifier(array $branches): QuantifierType|false|null
    {
        $empty = array_search(null, $branches, true);

        return match ($empty) {
            false => null,
            0 => QuantifierType::Lazy,
            \count($branches) - 1 => QuantifierType::Greedy,
            default => false,
        };
    }

    private function nodeToString(NodeInterface $node): string
    {
        $this->compiler ??= new PatternPrinter();
        $this->compiler->resetState();

        return $node->accept($this->compiler);
    }

    /**
     * Merges adjacent character class nodes in an alternation.
     * For example: [a-z]|[0-9] becomes [a-z0-9]
     *
     * @param array<NodeInterface> $alternatives
     *
     * @return array<NodeInterface>
     */
    private function mergeAdjacentCharClasses(array $alternatives): array
    {
        if (\count($alternatives) < 2) {
            return $alternatives;
        }

        $merged = [];
        $i = 0;

        while ($i < \count($alternatives)) {
            $current = $alternatives[$i];

            // Check if current is a character class or a char type that can be converted
            $isCharClass = $current instanceof CharClassNode && !$current->isNegated;
            $isCharType = $current instanceof CharTypeNode && $this->canConvertCharTypeToCharClass($current);

            if (!$isCharClass && !$isCharType) {
                $merged[] = $current;
                $i++;

                continue;
            }

            // Look ahead to find adjacent character classes or char types
            $nodesToMerge = [$current];
            $j = $i + 1;

            while ($j < \count($alternatives)) {
                $next = $alternatives[$j];
                $isNextCharClass = $next instanceof CharClassNode && !$next->isNegated;
                $isNextCharType = $next instanceof CharTypeNode && $this->canConvertCharTypeToCharClass($next);

                if ($isNextCharClass || $isNextCharType) {
                    $nodesToMerge[] = $next;
                    $j++;
                } else {
                    break;
                }
            }

            // If we found multiple adjacent character classes/char types, merge them
            if (\count($nodesToMerge) > 1) {
                $merged[] = $this->mergeCharClassesAndCharTypes($nodesToMerge);
                $i = $j;
            } else {
                $merged[] = $current;
                $i++;
            }
        }

        return $merged;
    }

    /**
     * Checks if a CharTypeNode can be converted to a CharClassNode.
     */
    private function canConvertCharTypeToCharClass(CharTypeNode $node): bool
    {
        // For now, only handle \d (digits) which can be converted to [0-9]
        if ('d' !== $node->value) {
            return false;
        }

        if ($this->unicodeMode) {
            return false;
        }

        return $this->optimizeDigits;
    }

    /**
     * Converts a CharTypeNode to an equivalent CharClassNode.
     */
    private function convertCharTypeToCharClass(CharTypeNode $node): CharClassNode
    {
        $startPos = $node->startPosition;
        $endPos = $node->endPosition;

        return match ($node->value) {
            'd' => new CharClassNode(
                new RangeNode(
                    new LiteralNode('0', $startPos, $startPos + 1),
                    new LiteralNode('9', $startPos + 1, $startPos + 2),
                    $startPos,
                    $startPos + 2,
                ),
                false,
                $startPos,
                $endPos,
            ),
            default => throw new \LogicException("Unsupported char type: {$node->value}"),
        };
    }

    /**
     * Merges character classes and char types into a single character class.
     *
     * @param array<NodeInterface> $nodes
     */
    private function mergeCharClassesAndCharTypes(array $nodes): CharClassNode
    {
        $allParts = [];
        $startPos = $nodes[0]->getStartPosition();
        $endPos = $nodes[\count($nodes) - 1]->getEndPosition();

        foreach ($nodes as $node) {
            if ($node instanceof CharClassNode) {
                if ($node->expression instanceof AlternationNode) {
                    $allParts = array_merge($allParts, $node->expression->alternatives);
                } else {
                    $allParts[] = $node->expression;
                }
            } elseif ($node instanceof CharTypeNode) {
                // Convert char type to equivalent char class parts
                $charClass = $this->convertCharTypeToCharClass($node);
                if ($charClass->expression instanceof AlternationNode) {
                    $allParts = array_merge($allParts, $charClass->expression->alternatives);
                } else {
                    $allParts[] = $charClass->expression;
                }
            }
        }

        // Create a new alternation with all parts
        $mergedExpression = new AlternationNode($allParts, $startPos, $endPos);

        return new CharClassNode($mergedExpression, false, $startPos, $endPos);
    }

    /**
     * Tries to convert an alternation to a character class if it's beneficial.
     * Only converts when it's clearly safe (no special char class metacharacters).
     *
     * @param array<NodeInterface> $alternatives
     */
    private function tryConvertAlternationToCharClass(array $alternatives, int $startPos, int $endPos): ?CharClassNode
    {
        if (\count($alternatives) < 3) {
            return null; // Not worth it for small alternations
        }

        $literals = [];
        $other = [];

        foreach ($alternatives as $alt) {
            if ($alt instanceof LiteralNode && 1 === \strlen($alt->value)) {
                $char = $alt->value;
                // Don't convert if it contains char class metacharacters that would change meaning
                if (!isset(self::CHAR_CLASS_META[$char])) {
                    $literals[] = $char;
                } else {
                    return null; // Contains metacharacter, don't convert
                }
            } else {
                $other[] = $alt;
            }
        }

        if (!empty($other)) {
            return null; // Can't convert if there are non-literal parts
        }

        // Only convert if we have enough literals that form a consecutive range
        if (\count($literals) >= 3) {
            $sortedLiterals = $literals;
            sort($sortedLiterals);

            // Check if they form a consecutive range
            $first = $sortedLiterals[0];
            $last = end($sortedLiterals);
            $expected = [];
            for ($i = \ord($first); $i <= \ord($last); $i++) {
                $expected[] = \chr($i);
            }

            if ($sortedLiterals === $expected) {
                // Create a range instead
                $startLiteral = new LiteralNode($first, $startPos, $startPos + 1);
                $endLiteral = new LiteralNode($last, $endPos - 1, $endPos);

                return new CharClassNode(
                    new RangeNode($startLiteral, $endLiteral, $startPos, $endPos),
                    false,
                    $startPos,
                    $endPos,
                );
            }
        }

        return null;
    }
}
