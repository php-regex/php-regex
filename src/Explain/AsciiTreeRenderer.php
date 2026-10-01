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
use PhpRegex\Parser\Internal\DisplayEscaper;
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
use PhpRegex\Parser\Node\ControlCharNode;
use PhpRegex\Parser\Node\DefineNode;
use PhpRegex\Parser\Node\DotNode;
use PhpRegex\Parser\Node\ExtendedCharClassNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\KeepNode;
use PhpRegex\Parser\Node\LimitMatchNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\NodeInterface;
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
 * Renders an ASCII tree diagram of the regex AST.
 *
 * @extends AbstractNodeVisitor<string>
 */
final class AsciiTreeRenderer extends AbstractNodeVisitor
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
        if (GroupType::Named === $node->type && null !== $node->name) {
            $label .= ' name="'.$node->name.'"';
        }
        if (GroupType::InlineFlags === $node->type && null !== $node->flags && '' !== $node->flags) {
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

    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node): string
    {
        $this->addLine('ExtendedCharClass');
        $this->visitChildren([$node->expression]);

        return '';
    }

    #[\Override]
    public function visitClassSetOperation(ClassSetOperationNode $node): string
    {
        $this->addLine('ClassSetOperation ('.$node->operator->value.')');
        $this->visitChildren(array_values(array_filter([$node->left, $node->right])));

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
     * @param array<NodeInterface> $children
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
            GroupType::Capturing => 'capturing',
            GroupType::NonCapturing => 'non-capturing',
            GroupType::Named => 'named',
            GroupType::LookaheadPositive => 'positive lookahead',
            GroupType::LookaheadNegative => 'negative lookahead',
            GroupType::LookbehindPositive => 'positive lookbehind',
            GroupType::LookbehindNegative => 'negative lookbehind',
            GroupType::InlineFlags => 'inline flags',
            GroupType::Atomic => 'atomic',
            GroupType::BranchReset => 'branch reset',
            GroupType::ScanSubstring => 'scan of groups '.implode(', ', $node->scannedGroups),
        };
    }
}
