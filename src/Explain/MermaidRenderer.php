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

namespace PhpRegex\Explain;

use PhpRegex\Parser\AbstractNodeVisitor;
use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\AnchorNode;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\Node\CalloutNode;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\ClassSetOperationNode;
use PhpRegex\Parser\Node\CommentNode;
use PhpRegex\Parser\Node\ConditionalNode;
use PhpRegex\Parser\Node\DefineNode;
use PhpRegex\Parser\Node\DotNode;
use PhpRegex\Parser\Node\ExtendedCharClassNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\KeepNode;
use PhpRegex\Parser\Node\LimitMatchNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\PcreVerbNode;
use PhpRegex\Parser\Node\PosixClassNode;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\RangeNode;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\Node\SubroutineNode;
use PhpRegex\Parser\Node\UnicodePropNode;

/**
 * Generates a Mermaid.js flowchart to visualize the regex structure.
 *
 * @extends AbstractNodeVisitor<string>
 */
final class MermaidRenderer extends AbstractNodeVisitor
{
    private int $nodeCounter = 0;

    /**
     * @var array<string>
     */
    private array $lines = [];

    #[\Override]
    public function visitRegex(RegexNode $node): string
    {
        $this->nodeCounter = 0;
        $this->lines = [];
        $this->lines[] = 'graph TD;';

        $nodeId = $this->nextNodeId();
        $flags = $node->flags ?: 'none';
        $this->lines[] = \sprintf('    %s["Regex: %s"]', $nodeId, $this->escape($flags));

        $childId = $node->pattern->accept($this);
        $this->lines[] = \sprintf('    %s --> %s', $nodeId, $childId);

        return implode("\n", $this->lines);
    }

    #[\Override]
    public function visitAlternation(AlternationNode $node): string
    {
        $nodeId = $this->nextNodeId();
        $this->lines[] = \sprintf('    %s{"Alternation"}', $nodeId);

        foreach ($node->alternatives as $child) {
            $childId = $child->accept($this);
            $this->lines[] = \sprintf('    %s --> %s', $nodeId, $childId);
        }

        return $nodeId;
    }

    #[\Override]
    public function visitSequence(SequenceNode $node): string
    {
        $nodeId = $this->nextNodeId();
        $this->lines[] = \sprintf('    %s["Sequence"]', $nodeId);

        foreach ($node->children as $child) {
            $childId = $child->accept($this);
            $this->lines[] = \sprintf('    %s --> %s', $nodeId, $childId);
        }

        return $nodeId;
    }

    #[\Override]
    public function visitGroup(GroupNode $node): string
    {
        $nodeId = $this->nextNodeId();
        $label = \sprintf('Group: %s', $node->type->value);
        $name = $node->name ? ' ('.$node->name.')' : '';
        $this->lines[] = \sprintf('    %s("%s%s")', $nodeId, $this->escape($label), $this->escape($name));

        $childId = $node->child->accept($this);
        $this->lines[] = \sprintf('    %s --> %s', $nodeId, $childId);

        return $nodeId;
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): string
    {
        $nodeId = $this->nextNodeId();
        $label = \sprintf('Quantifier: %s', $node->quantifier);
        $this->lines[] = \sprintf('    %s["%s"]', $nodeId, $this->escape($label));

        $childId = $node->node->accept($this);
        $this->lines[] = \sprintf('    %s --> %s', $nodeId, $childId);

        return $nodeId;
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): string
    {
        $nodeId = $this->nextNodeId();
        $value = '' === $node->value ? '(empty)' : $node->value;
        $this->lines[] = \sprintf('    %s["Literal: %s"]', $nodeId, $this->escape($value));

        return $nodeId;
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): string
    {
        $nodeId = $this->nextNodeId();
        $this->lines[] = \sprintf('    %s["CharType: \\%s"]', $nodeId, $this->escape($node->value));

        return $nodeId;
    }

    #[\Override]
    public function visitDot(DotNode $node): string
    {
        $nodeId = $this->nextNodeId();
        $this->lines[] = \sprintf('    %s["Dot: any char"]', $nodeId);

        return $nodeId;
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node): string
    {
        $nodeId = $this->nextNodeId();
        $this->lines[] = \sprintf('    %s(("Anchor: %s"))', $nodeId, $this->escape($node->value));

        return $nodeId;
    }

    #[\Override]
    public function visitAssertion(AssertionNode $node): string
    {
        $nodeId = $this->nextNodeId();
        $this->lines[] = \sprintf('    %s["Assertion: %s"]', $nodeId, $this->escape($node->value));

        return $nodeId;
    }

    #[\Override]
    public function visitKeep(KeepNode $node): string
    {
        $nodeId = $this->nextNodeId();
        $this->lines[] = \sprintf('    %s["Keep: \\K"]', $nodeId);

        return $nodeId;
    }

    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node): string
    {
        $nodeId = $this->nextNodeId();
        $this->lines[] = \sprintf('    %s["ExtendedCharClass"]', $nodeId);
        $this->lines[] = \sprintf('    %s --> %s', $nodeId, $node->expression->accept($this));

        return $nodeId;
    }

    #[\Override]
    public function visitClassSetOperation(ClassSetOperationNode $node): string
    {
        $nodeId = $this->nextNodeId();
        $this->lines[] = \sprintf('    %s["%s"]', $nodeId, $node->operator->value);
        foreach (array_filter([$node->left, $node->right]) as $operand) {
            $this->lines[] = \sprintf('    %s --> %s', $nodeId, $operand->accept($this));
        }

        return $nodeId;
    }

    #[\Override]
    public function visitCharClass(CharClassNode $node): string
    {
        $nodeId = $this->nextNodeId();
        $label = 'CharClass'.($node->isNegated ? ' [NOT]' : '');
        $this->lines[] = \sprintf('    %s["%s"]', $nodeId, $label);

        $parts = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];
        foreach ($parts as $child) {
            $childId = $child->accept($this);
            $this->lines[] = \sprintf('    %s --> %s', $nodeId, $childId);
        }

        return $nodeId;
    }

    #[\Override]
    public function visitRange(RangeNode $node): string
    {
        $nodeId = $this->nextNodeId();
        $this->lines[] = \sprintf('    %s["Range"]', $nodeId);

        $startId = $node->start->accept($this);
        $endId = $node->end->accept($this);
        $this->lines[] = \sprintf('    %s -->|from| %s', $nodeId, $startId);
        $this->lines[] = \sprintf('    %s -->|to| %s', $nodeId, $endId);

        return $nodeId;
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): string
    {
        $nodeId = $this->nextNodeId();
        $this->lines[] = \sprintf('    %s["Backref: %s"]', $nodeId, $this->escape($node->ref));

        return $nodeId;
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): string
    {
        $nodeId = $this->nextNodeId();
        $label = $node->type->label().': '.$node->originalRepresentation;
        $this->lines[] = \sprintf('    %s["%s"]', $nodeId, $this->escape($label));

        return $nodeId;
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): string
    {
        $nodeId = $this->nextNodeId();
        $this->lines[] = \sprintf('    %s["UnicodeProp: %s"]', $nodeId, $this->escape($node->prop));

        return $nodeId;
    }

    #[\Override]
    public function visitPosixClass(PosixClassNode $node): string
    {
        $nodeId = $this->nextNodeId();
        $this->lines[] = \sprintf('    %s["PosixClass: %s"]', $nodeId, $this->escape($node->class));

        return $nodeId;
    }

    #[\Override]
    public function visitComment(CommentNode $node): string
    {
        $nodeId = $this->nextNodeId();
        $comment = substr($node->comment, 0, 20);
        $this->lines[] = \sprintf('    %s["Comment: %s"]', $nodeId, $this->escape($comment));

        return $nodeId;
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): string
    {
        $nodeId = $this->nextNodeId();
        $this->lines[] = \sprintf('    %s{{"Conditional"}}', $nodeId);

        $condId = $node->condition->accept($this);
        $this->lines[] = \sprintf('    %s -->|condition| %s', $nodeId, $condId);

        $yesId = $node->yes->accept($this);
        $this->lines[] = \sprintf('    %s -->|yes| %s', $nodeId, $yesId);

        $noId = $node->no->accept($this);
        $this->lines[] = \sprintf('    %s -->|no| %s', $nodeId, $noId);

        return $nodeId;
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): string
    {
        $nodeId = $this->nextNodeId();
        $this->lines[] = \sprintf('    %s["Subroutine: %s"]', $nodeId, $this->escape($node->reference));

        return $nodeId;
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): string
    {
        $nodeId = $this->nextNodeId();

        // Special handling for LIMIT_MATCH verb
        if (str_starts_with($node->verb, 'LIMIT_MATCH=')) {
            $limit = substr($node->verb, 12); // Remove 'LIMIT_MATCH='
            $this->lines[] = \sprintf('    %s["LimitMatch: %s"]', $nodeId, $this->escape($limit));
        } else {
            $this->lines[] = \sprintf('    %s["PcreVerb: %s"]', $nodeId, $this->escape($node->verb));
        }

        return $nodeId;
    }

    #[\Override]
    public function visitDefine(DefineNode $node): string
    {
        $nodeId = $this->nextNodeId();
        $this->lines[] = \sprintf('    %s["DEFINE Block"]', $nodeId);

        $contentId = $node->content->accept($this);
        $this->lines[] = \sprintf('    %s --> %s', $nodeId, $contentId);

        return $nodeId;
    }

    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node): string
    {
        $nodeId = $this->nextNodeId();
        $this->lines[] = \sprintf('    %s["LimitMatch: %d"]', $nodeId, $node->limit);

        return $nodeId;
    }

    #[\Override]
    public function visitCallout(CalloutNode $node): string
    {
        if (null === $node->identifier) {
            $label = '(?C)';
        } else {
            $label = match (true) {
                \is_int($node->identifier) => '(?C'.$node->identifier.')',
                $node->isStringIdentifier => '(?C"'.$node->identifier.'")',
                default => '(?C'.$node->identifier.')',
            };
        }

        $nodeId = $this->nextNodeId();
        $this->lines[] = \sprintf('    %s["Callout: %s"]', $nodeId, $this->escape($label));

        return $nodeId;
    }

    private function nextNodeId(): string
    {
        return 'node'.($this->nodeCounter++);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }
}
