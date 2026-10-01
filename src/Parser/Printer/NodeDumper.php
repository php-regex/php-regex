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

namespace PhpRegex\Parser\Printer;

use PhpRegex\Parser\AbstractNodeVisitor;
use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\AnchorNode;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\Node\CalloutNode;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharLiteralType;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\ClassSetOperationNode;
use PhpRegex\Parser\Node\CommentNode;
use PhpRegex\Parser\Node\ConditionalNode;
use PhpRegex\Parser\Node\ControlCharNode;
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
use PhpRegex\Parser\Node\ScriptRunNode;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\Node\SubroutineNode;
use PhpRegex\Parser\Node\UnicodePropNode;
use PhpRegex\Parser\Node\VersionConditionNode;

/**
 * Dumps the AST as a human-readable string.
 *
 * @extends AbstractNodeVisitor<string>
 */
final class NodeDumper extends AbstractNodeVisitor
{
    private int $indent = 0;

    #[\Override]
    public function visitRegex(RegexNode $node): string
    {
        $str = "Regex(delimiter: {$node->delimiter}, flags: {$node->flags})\n";
        $this->indent += 2;
        $str .= $node->pattern->accept($this);
        $this->indent -= 2;

        return $str;
    }

    #[\Override]
    public function visitAlternation(AlternationNode $node): string
    {
        $str = str_repeat(' ', $this->indent)."Alternation:\n";
        $this->indent += 2;
        foreach ($node->alternatives as $alt) {
            $str .= str_repeat(' ', $this->indent).$alt->accept($this)."\n";
        }
        $this->indent -= 2;

        return rtrim($str, "\n");
    }

    #[\Override]
    public function visitSequence(SequenceNode $node): string
    {
        $str = str_repeat(' ', $this->indent)."Sequence:\n";
        $this->indent += 2;
        foreach ($node->children as $child) {
            $str .= str_repeat(' ', $this->indent).$child->accept($this)."\n";
        }
        $this->indent -= 2;

        return rtrim($str, "\n");
    }

    #[\Override]
    public function visitGroup(GroupNode $node): string
    {
        $name = $node->name ?? '';
        $flags = $node->flags ?? '';

        $nameStr = ('' !== $name) ? " name: {$name}" : '';
        $str = "Group(type: {$node->type->value}{$nameStr} flags: {$flags})\n";
        $this->indent += 2;
        $str .= $node->child->accept($this);
        $this->indent -= 2;

        return $str;
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): string
    {
        return "Quantifier(quant: {$node->quantifier}, type: {$node->type->value})\n".$this->indent(
            $node->node->accept($this),
        );
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): string
    {
        return "Literal('{$node->value}')";
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): string
    {
        return "CharType('\\{$node->value}')";
    }

    #[\Override]
    public function visitDot(DotNode $node): string
    {
        return 'Dot(.)';
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node): string
    {
        return "Anchor({$node->value})";
    }

    #[\Override]
    public function visitAssertion(AssertionNode $node): string
    {
        return "Assertion(\\{$node->value})";
    }

    #[\Override]
    public function visitKeep(KeepNode $node): string
    {
        return 'Keep(\K)';
    }

    #[\Override]
    public function visitCharClass(CharClassNode $node): string
    {
        $neg = $node->isNegated ? '^' : '';
        $str = "CharClass({$neg})\n";
        $this->indent += 2;
        $parts = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];
        foreach ($parts as $part) {
            $str .= $this->indent($part->accept($this))."\n";
        }
        $this->indent -= 2;

        return $str;
    }

    #[\Override]
    public function visitRange(RangeNode $node): string
    {
        return "Range({$node->start->accept($this)} - {$node->end->accept($this)})";
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): string
    {
        return "Backref(\\{$node->ref})";
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): string
    {
        $type = match ($node->type) {
            CharLiteralType::Octal => 'Octal',
            CharLiteralType::OctalLegacy => 'OctalLegacy',
            CharLiteralType::Unicode => 'Unicode',
            CharLiteralType::UnicodeNamed => 'UnicodeNamed',
        };

        return "{$type}({$node->originalRepresentation})";
    }

    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node): string
    {
        return 'ExtendedCharClass('.$node->expression->accept($this).')';
    }

    #[\Override]
    public function visitClassSetOperation(ClassSetOperationNode $node): string
    {
        return 'ClassSetOperation('.$node->symbol.', '.($node->left?->accept($this) ?? '').', '.$node->right->accept($this).')';
    }

    #[\Override]
    public function visitControlChar(ControlCharNode $node): string
    {
        return "ControlChar({$node->char})";
    }

    #[\Override]
    public function visitScriptRun(ScriptRunNode $node): string
    {
        return "ScriptRun({$node->script})";
    }

    #[\Override]
    public function visitVersionCondition(VersionConditionNode $node): string
    {
        return "VersionCondition({$node->operator}, {$node->version})";
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): string
    {
        $inner = $node->hasBraces
            ? trim($node->prop, '{}')
            : $node->prop;

        return "UnicodeProp(\\p{{$inner}})";
    }

    #[\Override]
    public function visitPosixClass(PosixClassNode $node): string
    {
        return "PosixClass([[:{$node->class}:]])";
    }

    #[\Override]
    public function visitComment(CommentNode $node): string
    {
        return "Comment('{$node->comment}')";
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): string
    {
        $str = "Conditional:\n";
        $this->indent += 2;
        $str .= $this->indent('Condition: '.$node->condition->accept($this))."\n";
        $str .= $this->indent('Yes: '.$node->yes->accept($this))."\n";
        $str .= $this->indent('No: '.$node->no->accept($this))."\n";
        $this->indent -= 2;

        return $str;
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): string
    {
        return "Subroutine(ref: {$node->reference}, syntax: '{$node->syntax}')";
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): string
    {
        return "PcreVerb(value: {$node->verb})";
    }

    #[\Override]
    public function visitDefine(DefineNode $node): string
    {
        $str = "Define:\n";
        $this->indent += 2;
        $str .= $this->indent('Content: '.$node->content->accept($this))."\n";
        $this->indent -= 2;

        return $str;
    }

    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node): string
    {
        return "LimitMatch(limit: {$node->limit})";
    }

    #[\Override]
    public function visitCallout(CalloutNode $node): string
    {
        $identifier = \is_string($node->identifier) ? "'{$node->identifier}'" : $node->identifier;

        return "Callout({$identifier})";
    }

    private function indent(string $str): string
    {
        $indentStr = str_repeat(' ', $this->indent);

        return $indentStr.str_replace("\n", "\n".$indentStr, $str);
    }
}
