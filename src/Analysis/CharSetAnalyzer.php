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

namespace RegexParser\Analysis;

use RegexParser\Node\AlternationNode;
use RegexParser\Node\CharClassNode;
use RegexParser\Node\CharTypeNode;
use RegexParser\Node\ClassSetOperationNode;
use RegexParser\Node\ClassSetOperator;
use RegexParser\Node\DotNode;
use RegexParser\Node\ExtendedCharClassNode;
use RegexParser\Node\GroupNode;
use RegexParser\Node\LiteralNode;
use RegexParser\Node\NodeInterface;
use RegexParser\Node\QuantifierNode;
use RegexParser\Node\RangeNode;
use RegexParser\Node\RegexNode;
use RegexParser\Node\SequenceNode;

/**
 * Approximates leading/trailing character sets for AST nodes.
 *
 * Used to detect mutually exclusive boundaries that avoid catastrophic backtracking.
 */
final readonly class CharSetAnalyzer
{
    private bool $unicodeMode;

    public function __construct(string $flags = '')
    {
        $this->unicodeMode = str_contains($flags, 'u');
    }

    public function firstChars(NodeInterface $node): CharSet
    {
        return $this->walk($node, true);
    }

    public function lastChars(NodeInterface $node): CharSet
    {
        return $this->walk($node, false);
    }

    private function walk(NodeInterface $node, bool $fromStart): CharSet
    {
        if ($node instanceof RegexNode) {
            return $this->walk($node->pattern, $fromStart);
        }

        if ($node instanceof LiteralNode) {
            $length = \strlen($node->value);
            if (0 === $length) {
                return CharSet::empty();
            }

            $char = $fromStart ? $node->value[0] : $node->value[$length - 1];

            return CharSet::fromChar($char);
        }

        if ($node instanceof CharTypeNode) {
            return $this->fromCharType($node->value);
        }

        if ($node instanceof DotNode) {
            return CharSet::full();
        }

        if ($node instanceof QuantifierNode) {
            return $this->walk($node->node, $fromStart);
        }

        if ($node instanceof RangeNode) {
            $start = $this->literalCodepoint($node->start);
            $end = $this->literalCodepoint($node->end);

            if (null === $start || null === $end) {
                return CharSet::unknown();
            }

            return CharSet::fromRange(min($start, $end), max($start, $end));
        }

        if ($node instanceof CharClassNode) {
            $set = CharSet::empty();

            $parts = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];
            foreach ($parts as $part) {
                $candidate = $this->walk($part, $fromStart);
                $set = $set->union($candidate);
            }

            return $node->isNegated ? $set->complement() : $set;
        }

        if ($node instanceof GroupNode) {
            return $this->walk($node->child, $fromStart);
        }

        if ($node instanceof ExtendedCharClassNode) {
            return $this->walk($node->expression, $fromStart);
        }

        if ($node instanceof ClassSetOperationNode) {
            $right = $this->walk($node->right, $fromStart);
            if (null === $node->left) {
                return $right->complement();
            }

            $left = $this->walk($node->left, $fromStart);

            return match ($node->operator) {
                ClassSetOperator::INTERSECTION => $left->intersect($right),
                ClassSetOperator::DIFFERENCE => $left->intersect($right->complement()),
                ClassSetOperator::SYMMETRIC_DIFFERENCE => $left->union($right)->intersect($left->intersect($right)->complement()),
                default => $left->union($right),
            };
        }

        if ($node instanceof AlternationNode) {
            $set = CharSet::empty();
            foreach ($node->alternatives as $alt) {
                $set = $set->union($this->walk($alt, $fromStart));
            }

            return $set;
        }

        if ($node instanceof SequenceNode) {
            $children = $fromStart ? $node->children : array_reverse($node->children);
            $set = CharSet::empty();

            foreach ($children as $child) {
                $candidate = $this->walk($child, $fromStart);
                $set = $set->union($candidate);

                if (!$this->isOptionalNode($child, $fromStart)) {
                    break;
                }
            }

            return $set;
        }

        return CharSet::unknown();
    }

    private function fromCharType(string $type): CharSet
    {
        if ($this->unicodeMode && \in_array($type, ['d', 'D', 'w', 'W'], true)) {
            return CharSet::unknown();
        }

        return match ($type) {
            'd' => CharSet::fromRange(\ord('0'), \ord('9')),
            'D' => CharSet::fromRange(\ord('0'), \ord('9'))->complement(),
            'w' => CharSet::fromRange(\ord('0'), \ord('9'))
                ->union(CharSet::fromRange(\ord('A'), \ord('Z')))
                ->union(CharSet::fromRange(\ord('a'), \ord('z')))
                ->union(CharSet::fromChar('_')),
            'W' => CharSet::fromRange(\ord('0'), \ord('9'))
                ->union(CharSet::fromRange(\ord('A'), \ord('Z')))
                ->union(CharSet::fromRange(\ord('a'), \ord('z')))
                ->union(CharSet::fromChar('_'))
                ->complement(),
            's' => $this->whitespace(),
            'S' => $this->whitespace()->complement(),
            default => CharSet::unknown(),
        };
    }

    private function whitespace(): CharSet
    {
        $set = CharSet::empty();
        foreach ([9, 10, 11, 12, 13, 32] as $code) {
            $set = $set->union(CharSet::fromRange($code, $code));
        }

        return $set;
    }

    private function literalCodepoint(NodeInterface $node): ?int
    {
        if (!$node instanceof LiteralNode) {
            return null;
        }

        if ('' === $node->value) {
            return null;
        }

        return \ord($node->value[0]);
    }

    private function isOptional(QuantifierNode $node): bool
    {
        return 0 === $this->quantifierMin($node->quantifier);
    }

    private function quantifierMin(string $quantifier): int
    {
        if (str_contains($quantifier, '*') || str_contains($quantifier, '?')) {
            return 0;
        }

        if (str_contains($quantifier, '+')) {
            return 1;
        }

        if (preg_match('/\{(\d++)(?:,(\d++)?)?\}/', $quantifier, $matches)) {
            return (int) $matches[1];
        }

        return 1;
    }

    private function isOptionalNode(NodeInterface $node, bool $fromStart): bool
    {
        if ($node instanceof LiteralNode) {
            return '' === $node->value;
        }

        if ($node instanceof QuantifierNode) {
            return $this->isOptional($node);
        }

        if ($node instanceof GroupNode) {
            return $this->isOptionalNode($node->child, $fromStart);
        }

        if ($node instanceof SequenceNode) {
            $children = $fromStart ? $node->children : array_reverse($node->children);
            foreach ($children as $child) {
                if ($this->isOptionalNode($child, $fromStart)) {
                    continue;
                }

                return false;
            }

            return true;
        }

        return false;
    }
}
