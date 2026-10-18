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
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Text\Ascii;

/**
 * Modernizes legacy or messy regular expressions into clean, concise PCRE2-compliant patterns.
 *
 * @extends AbstractNodeVisitor<NodeInterface>
 */
final class Modernizer extends AbstractNodeVisitor
{
    /**
     * The code points "\s" matches without Unicode properties.
     */
    private const SPACES = [9, 10, 11, 12, 13, 32];

    private string $delimiter = '/';

    /**
     * Whether "\s" and "\d" read Unicode properties: "/u", or "(*UCP)".
     */
    private bool $unicodeProperties = false;

    #[\Override]
    public function visitRegex(RegexNode $node)
    {
        $this->delimiter = $node->delimiter;
        $this->unicodeProperties = str_contains($node->flags, 'u') || \in_array('UCP', StartOptions::items($node->source ?? ''), true);

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

        // "\s" matches HT, LF, VT, FF, CR and the space, and nothing else, on
        // every PCRE2 release; with Unicode properties, under "/u" or
        // "(*UCP)", it matches more, as U+00A0.
        if (!$node->isNegated && !$this->unicodeProperties && self::SPACES === $this->codePoints($parts)) {
            return new CharTypeNode('s', $node->getStartPosition(), $node->getEndPosition());
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
        if (GroupType::NonCapturing === $node->type && null === $node->name && null === $node->flags) {
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
        // "\1" becomes "\g{1}": the braces end the number, so no digit after
        // it can turn it into another reference or an octal escape.
        $digits = ltrim($node->ref, '\\');
        if (\strlen($node->ref) - \strlen($digits) <= 1 && Ascii::isDigit($digits)) {
            return new BackrefNode('\g{'.$digits.'}', $node->getStartPosition(), $node->getEndPosition());
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
        // The reference of a condition is no back reference: "(?(\g{1})…)"
        // is refused, so it keeps the spelling it was written with.
        $condition = $node->condition instanceof BackrefNode ? $node->condition : $node->condition->accept($this);

        return new ConditionalNode(
            $condition,
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

    /**
     * The code points a class's members match, sorted, each once; null when
     * a member is not a single character or a range of them.
     *
     * @param array<NodeInterface> $parts
     *
     * @return list<int>|null
     */
    private function codePoints(array $parts): ?array
    {
        $codePoints = [];
        foreach ($parts as $part) {
            if ($part instanceof RangeNode) {
                $start = $this->codePoint($part->start);
                $end = $this->codePoint($part->end);
                // A member above 255 is no member of "\s", which is read without
                // Unicode properties here.
                if (null === $start || null === $end || $start > $end || $end > 0xFF) {
                    return null;
                }

                array_push($codePoints, ...range($start, $end));

                continue;
            }

            $codePoint = $this->codePoint($part);
            if (null === $codePoint) {
                return null;
            }

            $codePoints[] = $codePoint;
        }

        $codePoints = array_values(array_unique($codePoints));
        sort($codePoints);

        return $codePoints;
    }

    private function codePoint(NodeInterface $node): ?int
    {
        if ($node instanceof CharLiteralNode) {
            return $node->codePoint;
        }

        return $node instanceof LiteralNode && 1 === \strlen($node->value) ? \ord($node->value) : null;
    }
}
