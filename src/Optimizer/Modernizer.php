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

namespace PhpRegex\Optimizer;

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
 * Modernizes legacy or messy regular expressions into clean, concise PCRE2-compliant patterns.
 *
 * @extends AbstractNodeVisitor<NodeInterface>
 */
final class Modernizer extends AbstractNodeVisitor
{
    private string $delimiter = '/';

    #[\Override]
    public function visitRegex(RegexNode $node)
    {
        $this->delimiter = $node->delimiter;

        return new RegexNode(
            $node->pattern->accept($this),
            $node->flags,
            $node->delimiter,
            $node->getStartPosition(),
            $node->getEndPosition(),
        );
    }

    #[\Override]
    public function visitCharClass(CharClassNode $node)
    {
        $parts = $node->expression instanceof AlternationNode
            ? $node->expression->alternatives
            : [$node->expression];

        // Check for \d equivalent: [0-9]
        if (!$node->isNegated && 1 === \count($parts) && $parts[0] instanceof RangeNode) {
            $range = $parts[0];
            if ($range->start instanceof LiteralNode && $range->end instanceof LiteralNode
                && '0' === $range->start->value && '9' === $range->end->value) {
                return new CharTypeNode('d', $node->getStartPosition(), $node->getEndPosition());
            }
        }

        // Check for \s equivalent: [\t\n\r\f\v]
        if (!$node->isNegated && 5 === \count($parts)) {
            $whitespaceChars = ["\t", "\n", "\r", "\f", "\v"];
            $foundChars = [];
            foreach ($parts as $part) {
                if ($part instanceof LiteralNode && \in_array($part->value, $whitespaceChars, true)) {
                    $foundChars[] = $part->value;
                }
            }
            if (5 === \count($foundChars) && $foundChars === $whitespaceChars) {
                return new CharTypeNode('s', $node->getStartPosition(), $node->getEndPosition());
            }
        }

        // For other cases, keep as is but modernize parts
        $modernizedParts = array_map(fn (NodeInterface $part): NodeInterface => $part->accept($this), $parts);
        $expression = 1 === \count($modernizedParts)
            ? $modernizedParts[0]
            : new AlternationNode($modernizedParts, $node->getStartPosition(), $node->getEndPosition());

        return new CharClassNode($expression, $node->isNegated, $node->getStartPosition(), $node->getEndPosition());
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): NodeInterface
    {
        $value = $node->value;

        // Remove unnecessary escaping
        if (str_starts_with($value, '\\')) {
            $char = substr($value, 1);
            // Meta chars that need escaping: . \ + * ? ^ $ ( ) [ ] { } | / (if delimiter)
            $metaChars = ['.', '\\', '+', '*', '?', '^', '$', '(', ')', '[', ']', '{', '}', '|'];
            if ('/' !== $this->delimiter && $this->delimiter !== $char) {
                $metaChars[] = $this->delimiter;
            }
            if (!\in_array($char, $metaChars, true)) {
                // Safe to unescape
                return new LiteralNode($char, $node->getStartPosition(), $node->getEndPosition());
            }
        }

        return $node;
    }

    #[\Override]
    public function visitGroup(GroupNode $node): NodeInterface
    {
        // Unwrap redundant non-capturing groups: (?:expr) -> expr if not quantified
        // Assume safe for non-capturing groups without name or flags
        if (GroupType::T_GROUP_NON_CAPTURING === $node->type && null === $node->name && null === $node->flags) {
            return $node->child->accept($this);
        }

        return new GroupNode(
            $node->child->accept($this),
            $node->type,
            $node->name,
            $node->flags,
            $node->getStartPosition(),
            $node->getEndPosition(),
            false,
            $node->scannedGroups,
        );
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): NodeInterface
    {
        $ref = $node->ref;
        // Convert \1 to \g{1}
        if (is_numeric($ref)) {
            return new BackrefNode('\g{'.$ref.'}', $node->getStartPosition(), $node->getEndPosition());
        }

        return $node;
    }

    // For other nodes, just recurse or return as is
    #[\Override]
    public function visitAlternation(AlternationNode $node): NodeInterface
    {
        $alternatives = array_map(fn (NodeInterface $alt): NodeInterface => $alt->accept($this), $node->alternatives);

        return new AlternationNode($alternatives, $node->getStartPosition(), $node->getEndPosition());
    }

    #[\Override]
    public function visitSequence(SequenceNode $node): NodeInterface
    {
        $children = array_map(fn (NodeInterface $n): NodeInterface => $n->accept($this), $node->children);

        return new SequenceNode($children, $node->getStartPosition(), $node->getEndPosition());
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): NodeInterface
    {
        return new QuantifierNode(
            $node->node->accept($this),
            $node->quantifier,
            $node->type,
            $node->getStartPosition(),
            $node->getEndPosition(),
        );
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
    public function visitDot(DotNode $node): NodeInterface
    {
        return $node;
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): NodeInterface
    {
        return $node;
    }

    #[\Override]
    public function visitRange(RangeNode $node): NodeInterface
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
    public function visitComment(CommentNode $node): NodeInterface
    {
        return $node;
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node)
    {
        return new ConditionalNode(
            $node->condition->accept($this),
            $node->yes->accept($this),
            $node->no->accept($this),
            $node->getStartPosition(),
            $node->getEndPosition(),
        );
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
        return $node;
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
    public function visitKeep(KeepNode $node): NodeInterface
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
}
