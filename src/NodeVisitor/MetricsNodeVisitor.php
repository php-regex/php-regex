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

namespace RegexParser\NodeVisitor;

use RegexParser\Node;
use RegexParser\Node\AlternationNode;
use RegexParser\Node\AnchorNode;
use RegexParser\Node\AssertionNode;
use RegexParser\Node\BackrefNode;
use RegexParser\Node\CalloutNode;
use RegexParser\Node\CharClassNode;
use RegexParser\Node\CharLiteralNode;
use RegexParser\Node\CharTypeNode;
use RegexParser\Node\ClassSetOperationNode;
use RegexParser\Node\CommentNode;
use RegexParser\Node\ConditionalNode;
use RegexParser\Node\ControlCharNode;
use RegexParser\Node\DefineNode;
use RegexParser\Node\DotNode;
use RegexParser\Node\ExtendedCharClassNode;
use RegexParser\Node\GroupNode;
use RegexParser\Node\KeepNode;
use RegexParser\Node\LimitMatchNode;
use RegexParser\Node\LiteralNode;
use RegexParser\Node\NodeInterface;
use RegexParser\Node\PcreVerbNode;
use RegexParser\Node\PosixClassNode;
use RegexParser\Node\QuantifierNode;
use RegexParser\Node\RangeNode;
use RegexParser\Node\RegexNode;
use RegexParser\Node\ScriptRunNode;
use RegexParser\Node\SequenceNode;
use RegexParser\Node\SubroutineNode;
use RegexParser\Node\UnicodePropNode;
use RegexParser\Node\VersionConditionNode;

/**
 * Collects structural metrics about an AST (node counts and depth).
 *
 * @extends AbstractNodeVisitor<array{counts: array<string, int>, total: int, maxDepth: int}>
 */
final class MetricsNodeVisitor extends AbstractNodeVisitor
{
    /**
     * @var array<string, int>
     */
    private array $counts = [];

    private int $total = 0;

    private int $maxDepth = 0;

    private int $currentDepth = 0;

    #[\Override]
    public function visitRegex(RegexNode $node): array
    {
        return $this->record($node, function () use ($node): void {
            $this->visitChild($node->pattern);
        });
    }

    #[\Override]
    public function visitAlternation(AlternationNode $node): array
    {
        return $this->record($node, function () use ($node): void {
            $this->visitChildren($node->alternatives);
        });
    }

    #[\Override]
    public function visitSequence(SequenceNode $node): array
    {
        return $this->record($node, function () use ($node): void {
            $this->visitChildren($node->children);
        });
    }

    #[\Override]
    public function visitGroup(GroupNode $node): array
    {
        return $this->record($node, function () use ($node): void {
            $this->visitChild($node->child);
        });
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): array
    {
        return $this->record($node, function () use ($node): void {
            $this->visitChild($node->node);
        });
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): array
    {
        return $this->record($node);
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): array
    {
        return $this->record($node);
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): array
    {
        return $this->record($node);
    }

    #[\Override]
    public function visitDot(DotNode $node): array
    {
        return $this->record($node);
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node): array
    {
        return $this->record($node);
    }

    #[\Override]
    public function visitAssertion(AssertionNode $node): array
    {
        return $this->record($node);
    }

    #[\Override]
    public function visitKeep(KeepNode $node): array
    {
        return $this->record($node);
    }

    #[\Override]
    public function visitCharClass(CharClassNode $node): array
    {
        return $this->record($node, function () use ($node): void {
            $parts = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];
            $this->visitChildren($parts);
        });
    }

    #[\Override]
    public function visitRange(RangeNode $node): array
    {
        return $this->record($node, function () use ($node): void {
            $this->visitChild($node->start);
            $this->visitChild($node->end);
        });
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): array
    {
        return $this->record($node);
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): array
    {
        return $this->record($node);
    }

    #[\Override]
    public function visitPosixClass(PosixClassNode $node): array
    {
        return $this->record($node);
    }

    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node): array
    {
        return $this->record($node, function () use ($node): void {
            $this->visitChild($node->expression);
        });
    }

    #[\Override]
    public function visitClassSetOperation(ClassSetOperationNode $node): array
    {
        return $this->record($node, function () use ($node): void {
            $this->visitChildren(array_values(array_filter([$node->left, $node->right])));
        });
    }

    #[\Override]
    public function visitControlChar(ControlCharNode $node): array
    {
        return $this->record($node);
    }

    #[\Override]
    public function visitScriptRun(ScriptRunNode $node): array
    {
        return $this->record($node, function () use ($node): void {
            if (null !== $node->content) {
                $this->visitChild($node->content);
            }
        });
    }

    #[\Override]
    public function visitVersionCondition(VersionConditionNode $node): array
    {
        return $this->record($node);
    }

    #[\Override]
    public function visitComment(CommentNode $node): array
    {
        return $this->record($node);
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): array
    {
        return $this->record($node, function () use ($node): void {
            $this->visitChild($node->condition);
            $this->visitChild($node->yes);
            $this->visitChild($node->no);
        });
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): array
    {
        return $this->record($node);
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): array
    {
        return $this->record($node);
    }

    #[\Override]
    public function visitDefine(DefineNode $node): array
    {
        return $this->record($node, function () use ($node): void {
            $this->visitChild($node->content);
        });
    }

    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node): array
    {
        return $this->record($node);
    }

    #[\Override]
    public function visitCallout(CalloutNode $node): array
    {
        return $this->record($node);
    }

    /**
     * @return array{counts: array<string, int>, total: int, maxDepth: int}
     */
    private function record(NodeInterface $node, ?callable $traverse = null): array
    {
        $this->total++;
        $type = $this->shortName($node::class);
        $this->counts[$type] = ($this->counts[$type] ?? 0) + 1;

        $this->currentDepth++;
        $this->maxDepth = max($this->maxDepth, $this->currentDepth);

        if (null !== $traverse) {
            $traverse();
        }

        $this->currentDepth--;

        return $this->snapshot();
    }

    private function visitChild(NodeInterface $child): void
    {
        $child->accept($this);
    }

    /**
     * @param array<Node\NodeInterface> $children
     */
    private function visitChildren(array $children): void
    {
        foreach ($children as $child) {
            $this->visitChild($child);
        }
    }

    private function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');

        return false === $pos ? $class : substr($class, $pos + 1);
    }

    /**
     * @return array{counts: array<string, int>, total: int, maxDepth: int}
     */
    private function snapshot(): array
    {
        return [
            'counts' => $this->counts,
            'total' => $this->total,
            'maxDepth' => $this->maxDepth,
        ];
    }
}
