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

namespace PhpRegex\Explain\Highlighter;

use PhpRegex\Parser\AbstractNodeVisitor;
use PhpRegex\Parser\Internal\Ascii;
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
use PhpRegex\Parser\Node\QuantifierType;
use PhpRegex\Parser\Node\RangeNode;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Parser\Node\ScriptRunNode;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\Node\SubroutineNode;
use PhpRegex\Parser\Node\UnicodePropNode;
use PhpRegex\Parser\Node\VersionConditionNode;

/**
 * Base visitor for highlighting regex syntax.
 *
 * @extends AbstractNodeVisitor<string>
 */
abstract class AbstractHighlighter extends AbstractNodeVisitor
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
            GroupType::Capturing => $open.$child.$close,
            GroupType::NonCapturing => $open.$this->wrap('?:', 'group').$child.$close,
            GroupType::Named => $open
                .$this->wrap($this->escape('?<'), 'group')
                .$this->wrapReference($node->name ?? '')
                .$this->wrap($this->escape('>'), 'group')
                .$child
                .$close,
            GroupType::LookaheadPositive => $open.$this->wrap('?=', 'group').$child.$close,
            GroupType::LookaheadNegative => $open.$this->wrap('?!', 'group').$child.$close,
            GroupType::LookbehindPositive => $open.$this->wrap($this->escape('?<='), 'group').$child.$close,
            GroupType::LookbehindNegative => $open.$this->wrap($this->escape('?<!'), 'group').$child.$close,
            GroupType::Atomic => $open.$this->wrap($this->escape('?>'), 'group').$child.$close,
            GroupType::BranchReset => $open.$this->wrap('?|', 'group').$child.$close,
            GroupType::ScanSubstring => $open
                .$this->wrap('*', 'group')
                .$this->wrap($node->name ?? 'scan_substring', 'keyword')
                .$this->wrap(':(', 'group')
                .implode($this->wrap(',', 'group'), array_map($this->wrapReference(...), $node->scannedGroups))
                .$this->wrap(')', 'group')
                .$child
                .$close,
            GroupType::InlineFlags => $this->renderInlineFlagsGroup($flags, $child, $open, $close),
        };
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): string
    {
        $inner = $node->node->accept($this);
        $quant = $node->quantifier;
        if (QuantifierType::Lazy === $node->type) {
            $quant .= '?';
        } elseif (QuantifierType::Possessive === $node->type) {
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
     * @return list<\PhpRegex\Parser\Node\NodeInterface>
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
