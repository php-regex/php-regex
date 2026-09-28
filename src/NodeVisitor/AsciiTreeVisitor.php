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

use RegexParser\Internal\DisplayEscaper;
use RegexParser\Node;
use RegexParser\Node\AlternationNode;
use RegexParser\Node\AnchorNode;
use RegexParser\Node\AssertionNode;
use RegexParser\Node\BackrefNode;
use RegexParser\Node\CalloutNode;
use RegexParser\Node\CharClassNode;
use RegexParser\Node\CharLiteralNode;
use RegexParser\Node\CharTypeNode;
use RegexParser\Node\ClassOperationNode;
use RegexParser\Node\CommentNode;
use RegexParser\Node\ConditionalNode;
use RegexParser\Node\ControlCharNode;
use RegexParser\Node\DefineNode;
use RegexParser\Node\DotNode;
use RegexParser\Node\GroupNode;
use RegexParser\Node\GroupType;
use RegexParser\Node\KeepNode;
use RegexParser\Node\LimitMatchNode;
use RegexParser\Node\LiteralNode;
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
 * Renders an ASCII tree diagram of the regex AST.
 *
 * @extends AbstractNodeVisitor<string>
 */
final class AsciiTreeVisitor extends AbstractNodeVisitor
{
    /**
     * @var array<string>
     */
    private array $lines = [];

    /**
     * @var array<bool>
     */
    private array $branchStack = [];

    #[\Override]
    public function visitRegex(RegexNode $node): string
    {
        $this->lines = [];
        $this->branchStack = [];

        $label = 'Regex';
        if ('' !== $node->flags) {
            $label .= ' (flags: '.$node->flags.')';
        }

        $this->addLine($label);
        $this->visitChildren([$node->pattern]);

        return implode("\n", $this->lines);
    }

    #[\Override]
    public function visitAlternation(AlternationNode $node): string
    {
        $this->addLine('Alternation');
        $this->visitChildren(array_values($node->alternatives));

        return '';
    }

    #[\Override]
    public function visitSequence(SequenceNode $node): string
    {
        $this->addLine('Sequence');
        $this->visitChildren(array_values($node->children));

        return '';
    }

    #[\Override]
    public function visitGroup(GroupNode $node): string
    {
        $label = 'Group ('.$this->describeGroupType($node).')';
        if (GroupType::T_GROUP_NAMED === $node->type && null !== $node->name) {
            $label .= ' name="'.$node->name.'"';
        }
        if (GroupType::T_GROUP_INLINE_FLAGS === $node->type && null !== $node->flags && '' !== $node->flags) {
            $label .= ' flags="'.$node->flags.'"';
        }

        $this->addLine($label);
        $this->visitChildren([$node->child]);

        return '';
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): string
    {
        $label = 'Quantifier ('.$node->quantifier.', '.$node->type->value.')';
        $this->addLine($label);
        $this->visitChildren([$node->node]);

        return '';
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): string
    {
        $value = DisplayEscaper::escape($node->value);
        $this->addLine("Literal ('".$value."')");

        return '';
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): string
    {
        $this->addLine('CharLiteral ('.$node->originalRepresentation.')');

        return '';
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): string
    {
        $this->addLine('CharType (\\'.$node->value.')');

        return '';
    }

    #[\Override]
    public function visitDot(DotNode $node): string
    {
        $this->addLine('Dot (.)');

        return '';
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node): string
    {
        $this->addLine('Anchor ('.$node->value.')');

        return '';
    }

    #[\Override]
    public function visitAssertion(AssertionNode $node): string
    {
        $this->addLine('Assertion (\\'.$node->value.')');

        return '';
    }

    #[\Override]
    public function visitKeep(KeepNode $node): string
    {
        $this->addLine('Keep (\\K)');

        return '';
    }

    #[\Override]
    public function visitCharClass(CharClassNode $node): string
    {
        $label = $node->isNegated ? 'CharClass (negated)' : 'CharClass';
        $this->addLine($label);
        $this->visitChildren([$node->expression]);

        return '';
    }

    #[\Override]
    public function visitRange(RangeNode $node): string
    {
        $this->addLine('Range');
        $this->visitChildren([$node->start, $node->end]);

        return '';
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): string
    {
        $ref = $node->ref;
        $display = str_starts_with($ref, '\\') ? $ref : '\\'.$ref;
        $this->addLine('Backref ('.$display.')');

        return '';
    }

    /**
     * @deprecated the parser no longer builds a ClassOperationNode; this method goes in the next major version
     */
    #[\Override]
    public function visitClassOperation(ClassOperationNode $node): string
    {
        $label = 'ClassOperation ('.$node->type->value.')';
        $this->addLine($label);
        $this->visitChildren([$node->left, $node->right]);

        return '';
    }

    #[\Override]
    public function visitControlChar(ControlCharNode $node): string
    {
        $this->addLine('ControlChar (\\c'.$node->char.')');

        return '';
    }

    #[\Override]
    public function visitScriptRun(ScriptRunNode $node): string
    {
        $this->addLine('ScriptRun ('.$node->script.')');

        return '';
    }

    #[\Override]
    public function visitVersionCondition(VersionConditionNode $node): string
    {
        $this->addLine('VersionCondition ('.$node->operator.' '.$node->version.')');

        return '';
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): string
    {
        $inner = $node->hasBraces ? trim($node->prop, '{}') : $node->prop;
        $display = '{'.$inner.'}';
        $this->addLine('UnicodeProperty (\\p'.$display.')');

        return '';
    }

    #[\Override]
    public function visitPosixClass(PosixClassNode $node): string
    {
        $this->addLine('PosixClass ([:'.$node->class.':])');

        return '';
    }

    #[\Override]
    public function visitComment(CommentNode $node): string
    {
        $this->addLine('Comment');

        return '';
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): string
    {
        $this->addLine('Conditional');
        $this->visitChildren([$node->condition, $node->yes, $node->no]);

        return '';
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): string
    {
        $this->addLine('Subroutine ('.$node->reference.')');

        return '';
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): string
    {
        $this->addLine('PCREVerb (*'.$node->verb.')');

        return '';
    }

    #[\Override]
    public function visitDefine(DefineNode $node): string
    {
        $this->addLine('Define');
        $this->visitChildren([$node->content]);

        return '';
    }

    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node): string
    {
        $this->addLine('LimitMatch (*LIMIT_MATCH='.$node->limit.')');

        return '';
    }

    #[\Override]
    public function visitCallout(CalloutNode $node): string
    {
        if (null === $node->identifier) {
            $label = 'Callout (?C)';
        } elseif ($node->isStringIdentifier) {
            $label = 'Callout (?C="'.$node->identifier.'")';
        } else {
            $label = 'Callout (?C'.$node->identifier.')';
        }

        $this->addLine($label);

        return '';
    }

    /**
     * @param array<Node\NodeInterface> $children
     */
    private function visitChildren(array $children): void
    {
        $total = \count($children);
        foreach ($children as $index => $child) {
            $this->branchStack[] = $index === $total - 1;
            $child->accept($this);
            array_pop($this->branchStack);
        }
    }

    private function addLine(string $label): void
    {
        $depth = \count($this->branchStack);
        $prefix = '';
        for ($i = 0; $i < $depth - 1; $i++) {
            $prefix .= $this->branchStack[$i] ? '    ' : '|   ';
        }

        if ($depth > 0) {
            $prefix .= $this->branchStack[$depth - 1] ? '\\-- ' : '|-- ';
        }

        $this->lines[] = $prefix.$label;
    }

    private function describeGroupType(GroupNode $node): string
    {
        return match ($node->type) {
            GroupType::T_GROUP_CAPTURING => 'capturing',
            GroupType::T_GROUP_NON_CAPTURING => 'non-capturing',
            GroupType::T_GROUP_NAMED => 'named',
            GroupType::T_GROUP_LOOKAHEAD_POSITIVE => 'positive lookahead',
            GroupType::T_GROUP_LOOKAHEAD_NEGATIVE => 'negative lookahead',
            GroupType::T_GROUP_LOOKBEHIND_POSITIVE => 'positive lookbehind',
            GroupType::T_GROUP_LOOKBEHIND_NEGATIVE => 'negative lookbehind',
            GroupType::T_GROUP_INLINE_FLAGS => 'inline flags',
            GroupType::T_GROUP_ATOMIC => 'atomic',
            GroupType::T_GROUP_BRANCH_RESET => 'branch reset',
        };
    }
}
