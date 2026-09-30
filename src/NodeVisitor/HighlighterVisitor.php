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

use RegexParser\Internal\Ascii;
use RegexParser\Node\AlternationNode;
use RegexParser\Node\AnchorNode;
use RegexParser\Node\AssertionNode;
use RegexParser\Node\BackrefNode;
use RegexParser\Node\CalloutNode;
use RegexParser\Node\CharClassNode;
use RegexParser\Node\CharLiteralNode;
use RegexParser\Node\CharTypeNode;
use RegexParser\Node\ClassOperationNode;
use RegexParser\Node\ClassOperationType;
use RegexParser\Node\ClassSetOperationNode;
use RegexParser\Node\CommentNode;
use RegexParser\Node\ConditionalNode;
use RegexParser\Node\ControlCharNode;
use RegexParser\Node\DefineNode;
use RegexParser\Node\DotNode;
use RegexParser\Node\ExtendedCharClassNode;
use RegexParser\Node\GroupNode;
use RegexParser\Node\GroupType;
use RegexParser\Node\KeepNode;
use RegexParser\Node\LimitMatchNode;
use RegexParser\Node\LiteralNode;
use RegexParser\Node\NodeInterface;
use RegexParser\Node\PcreVerbNode;
use RegexParser\Node\PosixClassNode;
use RegexParser\Node\QuantifierNode;
use RegexParser\Node\QuantifierType;
use RegexParser\Node\RangeNode;
use RegexParser\Node\RegexNode;
use RegexParser\Node\ScriptRunNode;
use RegexParser\Node\SequenceNode;
use RegexParser\Node\SubroutineNode;
use RegexParser\Node\UnicodePropNode;
use RegexParser\Node\VersionConditionNode;

/**
 * Base visitor for highlighting regex syntax.
 *
 * @extends AbstractNodeVisitor<string>
 */
abstract class HighlighterVisitor extends AbstractNodeVisitor
{
    #[\Override]
    public function visitRegex(RegexNode $node): string
    {
        return $node->pattern->accept($this);
    }

    #[\Override]
    public function visitAlternation(AlternationNode $node): string
    {
        $parts = [];
        foreach ($node->alternatives as $alt) {
            $parts[] = $alt->accept($this);
        }

        return implode($this->wrap('|', 'meta'), $parts);
    }

    #[\Override]
    public function visitSequence(SequenceNode $node): string
    {
        $parts = [];
        foreach ($node->children as $child) {
            $parts[] = $child->accept($this);
        }

        return implode('', $parts);
    }

    #[\Override]
    public function visitGroup(GroupNode $node): string
    {
        $child = $node->child->accept($this);
        $open = $this->wrap('(', 'group');
        $close = $this->wrap(')', 'group');
        $flags = $node->flags ?? '';

        return match ($node->type) {
            GroupType::T_GROUP_CAPTURING => $open.$child.$close,
            GroupType::T_GROUP_NON_CAPTURING => $open.$this->wrap('?:', 'group').$child.$close,
            GroupType::T_GROUP_NAMED => $open
                .$this->wrap($this->escape('?<'), 'group')
                .$this->wrapReference($node->name ?? '')
                .$this->wrap($this->escape('>'), 'group')
                .$child
                .$close,
            GroupType::T_GROUP_LOOKAHEAD_POSITIVE => $open.$this->wrap('?=', 'group').$child.$close,
            GroupType::T_GROUP_LOOKAHEAD_NEGATIVE => $open.$this->wrap('?!', 'group').$child.$close,
            GroupType::T_GROUP_LOOKBEHIND_POSITIVE => $open.$this->wrap($this->escape('?<='), 'group').$child.$close,
            GroupType::T_GROUP_LOOKBEHIND_NEGATIVE => $open.$this->wrap($this->escape('?<!'), 'group').$child.$close,
            GroupType::T_GROUP_ATOMIC => $open.$this->wrap($this->escape('?>'), 'group').$child.$close,
            GroupType::T_GROUP_BRANCH_RESET => $open.$this->wrap('?|', 'group').$child.$close,
            GroupType::T_GROUP_SCAN_SUBSTRING => $open
                .$this->wrap('*', 'group')
                .$this->wrap($node->name ?? 'scan_substring', 'keyword')
                .$this->wrap(':(', 'group')
                .implode($this->wrap(',', 'group'), array_map($this->wrapReference(...), $node->scannedGroups))
                .$this->wrap(')', 'group')
                .$child
                .$close,
            GroupType::T_GROUP_INLINE_FLAGS => $this->renderInlineFlagsGroup($flags, $child, $open, $close),
        };
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): string
    {
        $inner = $node->node->accept($this);
        $quant = $node->quantifier;
        if (QuantifierType::T_LAZY === $node->type) {
            $quant .= '?';
        } elseif (QuantifierType::T_POSSESSIVE === $node->type) {
            $quant .= '+';
        }

        return $inner.$this->wrap($this->escape($quant), 'quantifier');
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): string
    {
        if ('' === $node->value) {
            return '';
        }

        return $this->wrap($this->escape($node->value), 'literal');
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): string
    {
        if ('' === $node->originalRepresentation) {
            return '';
        }

        return $this->wrap($this->escape($node->originalRepresentation), 'escape');
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): string
    {
        return $this->wrap($this->escape('\\'.$node->value), 'escape');
    }

    #[\Override]
    public function visitDot(DotNode $node): string
    {
        return $this->wrap('.', 'meta');
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node): string
    {
        return $this->wrap($this->escape($node->value), 'anchor');
    }

    #[\Override]
    public function visitAssertion(AssertionNode $node): string
    {
        return $this->wrap($this->escape('\\'.$node->value), 'anchor');
    }

    #[\Override]
    public function visitKeep(KeepNode $node): string
    {
        return $this->wrap($this->escape('\\K'), 'escape');
    }

    #[\Override]
    public function visitCharClass(CharClassNode $node): string
    {
        $parts = $node->expression instanceof AlternationNode
            ? $node->expression->alternatives
            : [$node->expression];
        $inner = '';
        foreach ($parts as $part) {
            $inner .= $part->accept($this);
        }
        $neg = $node->isNegated ? $this->wrap('^', 'meta') : '';

        return $this->wrap('[', 'meta').$neg.$inner.$this->wrap(']', 'meta');
    }

    #[\Override]
    public function visitRange(RangeNode $node): string
    {
        $start = $node->start->accept($this);
        $end = $node->end->accept($this);

        return $start.$this->wrap('-', 'meta').$end;
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): string
    {
        $reference = $this->formatBackref($node);
        if ('' === $reference) {
            return '';
        }

        return $this->wrap($this->escape($reference), 'backref');
    }

    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node): string
    {
        $text = $node->text;
        if ('' === $text) {
            return $this->wrap('(?[', 'group').$node->expression->accept($this).$this->wrap('])', 'group');
        }

        // The operands highlighted, and what the class writes between them;
        // the operands count their offsets as the class does.
        $start = $node->getStartPosition();
        $highlighted = $this->wrap('(?[', 'group');
        $at = 3;
        foreach ($this->operandsOf($node->expression) as $operand) {
            $highlighted .= $this->highlightLayout(substr($text, $at, $operand->getStartPosition() - $start - $at));
            $highlighted .= $operand->accept($this);
            $at = $operand->getEndPosition() - $start;
        }

        return $highlighted.$this->highlightLayout(substr($text, $at, \strlen($text) - 2 - $at)).$this->wrap('])', 'group');
    }

    #[\Override]
    public function visitClassSetOperation(ClassSetOperationNode $node): string
    {
        $operator = $this->wrap($this->escape($node->symbol), 'meta');
        if (null === $node->left) {
            return $operator.$node->right->accept($this);
        }

        return $this->wrap('(', 'group').$node->left->accept($this).$operator.$node->right->accept($this).$this->wrap(')', 'group');
    }

    /**
     * @deprecated the parser no longer builds a ClassOperationNode; this method goes in the next major version
     */
    #[\Override]
    public function visitClassOperation(ClassOperationNode $node): string
    {
        $left = $node->left->accept($this);
        $right = $node->right->accept($this);
        $op = ClassOperationType::INTERSECTION === $node->type ? '&&' : '--';

        return $left.$this->wrap($this->escape($op), 'meta').$right;
    }

    #[\Override]
    public function visitControlChar(ControlCharNode $node): string
    {
        return $this->wrap($this->escape('\\c'.$node->char), 'escape');
    }

    #[\Override]
    public function visitScriptRun(ScriptRunNode $node): string
    {
        return $this->wrap('(*', 'group')
            .$this->wrap($node->atomic ? 'atomic_script_run' : 'script_run', 'keyword')
            .$this->wrap(':', 'meta')
            .$this->wrapReference($node->script)
            .$this->wrap(')', 'group');
    }

    #[\Override]
    public function visitVersionCondition(VersionConditionNode $node): string
    {
        return $this->wrap('VERSION', 'keyword')
            .$this->wrap($this->escape($node->operator), 'meta')
            .$this->wrap($this->escape($node->version), 'number');
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): string
    {
        $inner = $node->hasBraces ? trim($node->prop, '{}') : $node->prop;
        $isNegated = str_starts_with($inner, '^');
        $inner = ltrim($inner, '^');
        $prefix = $isNegated ? 'P' : 'p';
        $display = '{'.$inner.'}';

        return $this->wrap($this->escape('\\'.$prefix.$display), 'escape');
    }

    #[\Override]
    public function visitPosixClass(PosixClassNode $node): string
    {
        return $this->wrap($this->escape('[:'.$node->class.':]'), 'escape');
    }

    #[\Override]
    public function visitComment(CommentNode $node): string
    {
        return $this->wrap('(?#', 'meta')
            .$this->wrap($this->escape($node->comment), 'comment')
            .$this->wrap(')', 'meta');
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): string
    {
        if ($node->condition instanceof BackrefNode) {
            $condition = $this->wrap($this->escape($node->condition->ref), 'backref');
        } else {
            $condition = $node->condition->accept($this);
        }

        $yes = $node->yes->accept($this);
        $no = $node->no->accept($this);
        $noPart = '' !== $no ? $this->wrap('|', 'meta').$no : '';

        return $this->wrap('(?(', 'group')
            .$condition
            .$this->wrap(')', 'group')
            .$yes
            .$noPart
            .$this->wrap(')', 'group');
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): string
    {
        // "(?1(2,<name>))": the groups the call returns.
        $returned = [] === $node->returnedGroups ? '' : $this->wrap('(', 'group')
            .implode($this->wrap(',', 'group'), array_map($this->wrapReference(...), $node->returnedGroups))
            .$this->wrap(')', 'group');

        return match ($node->syntax) {
            '&' => $this->wrap('(?', 'group')
                .$this->wrap($this->escape('&'), 'keyword')
                .$this->wrapReference($node->reference)
                .$returned
                .$this->wrap(')', 'group'),
            'P>' => $this->wrap('(?', 'group')
                .$this->wrap($this->escape('P>'), 'keyword')
                .$this->wrapReference($node->reference)
                .$returned
                .$this->wrap(')', 'group'),
            'g' => $this->wrap($this->escape('\\g<'), 'escape')
                .$this->wrapReference($node->reference)
                .$this->wrap($this->escape('>'), 'escape'),
            default => $this->wrap('(?', 'group')
                .$this->wrapReference($node->reference)
                .$returned
                .$this->wrap(')', 'group'),
        };
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): string
    {
        if (str_contains($node->verb, ':')) {
            [$verb, $arg] = explode(':', $node->verb, 2);

            return $this->wrap('(*', 'group')
                .$this->wrap($this->escape($verb), 'keyword')
                .$this->wrap(':', 'meta')
                .$this->wrapReference($arg)
                .$this->wrap(')', 'group');
        }

        return $this->wrap('(*', 'group')
            .$this->wrap($this->escape($node->verb), 'keyword')
            .$this->wrap(')', 'group');
    }

    #[\Override]
    public function visitDefine(DefineNode $node): string
    {
        $inner = $node->content->accept($this);

        return $this->wrap('(?(', 'group')
            .$this->wrap('DEFINE', 'keyword')
            .$this->wrap(')', 'group')
            .$inner
            .$this->wrap(')', 'group');
    }

    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node): string
    {
        return $this->wrap('(*', 'group')
            .$this->wrap('LIMIT_MATCH', 'keyword')
            .$this->wrap('=', 'meta')
            .$this->wrap((string) $node->limit, 'number')
            .$this->wrap(')', 'group');
    }

    #[\Override]
    public function visitCallout(CalloutNode $node): string
    {
        $content = '';
        if (null !== $node->identifier) {
            if ($node->isStringIdentifier) {
                $content = $this->wrap('"', 'meta')
                    .$this->wrap($this->escape((string) $node->identifier), 'identifier')
                    .$this->wrap('"', 'meta');
            } elseif (\is_int($node->identifier)) {
                $content = $this->wrap((string) $node->identifier, 'number');
            } else {
                $content = $this->wrap($this->escape((string) $node->identifier), 'identifier');
            }
        }

        return $this->wrap('(?', 'group')
            .$this->wrap('C', 'keyword')
            .$content
            .$this->wrap(')', 'group');
    }

    abstract protected function wrap(string $content, string $type): string;

    abstract protected function escape(string $string): string;

    /**
     * @return list<NodeInterface>
     */
    private function operandsOf(NodeInterface $node): array
    {
        if (!$node instanceof ClassSetOperationNode) {
            return [$node];
        }

        return [...(null === $node->left ? [] : $this->operandsOf($node->left)), ...$this->operandsOf($node->right)];
    }

    /**
     * Operators, parentheses and blanks between the operands of an extended class.
     */
    private function highlightLayout(string $text): string
    {
        return preg_replace_callback(
            '/[!&+|\-^]|[()]|[^!&+|\-^()]++/',
            fn (array $part): string => match (true) {
                '(' === $part[0], ')' === $part[0] => $this->wrap($part[0], 'group'),
                1 === \strlen($part[0]) && str_contains('!&+|-^', $part[0]) => $this->wrap($this->escape($part[0]), 'meta'),
                default => $this->escape($part[0]),
            },
            $text,
        ) ?? $this->escape($text);
    }

    private function renderInlineFlagsGroup(string $flags, string $child, string $open, string $close): string
    {
        $flagToken = '' !== $flags ? $this->wrap($this->escape($flags), 'flag') : '';

        if ('' === $child) {
            return $open.$this->wrap('?', 'group').$flagToken.$close;
        }

        return $open
            .$this->wrap('?', 'group')
            .$flagToken
            .$this->wrap(':', 'group')
            .$child
            .$close;
    }

    private function formatBackref(BackrefNode $node): string
    {
        $ref = $node->ref;
        if ('' === $ref) {
            return '';
        }

        if (Ascii::isDigit($ref)) {
            return '\\'.$ref;
        }

        return $ref;
    }

    private function wrapReference(string $reference): string
    {
        if ('' === $reference) {
            return '';
        }

        if (1 === preg_match('/^[+-]?\d+$/', $reference)) {
            return $this->wrap($this->escape($reference), 'number');
        }

        return $this->wrap($this->escape($reference), 'identifier');
    }
}
