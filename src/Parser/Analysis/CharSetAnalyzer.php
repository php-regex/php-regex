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

namespace PHPRegex\Parser\Analysis;

use PHPRegex\Parser\Internal\StartOptions;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ClassSetOperationNode;
use PHPRegex\Parser\Node\ClassSetOperator;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;

/**
 * Approximates leading/trailing character sets for AST nodes.
 *
 * Used to detect mutually exclusive boundaries that avoid catastrophic backtracking.
 *
 * @internal
 */
final readonly class CharSetAnalyzer
{
    private bool $unicodeMode;

    private ByteCharSet $dot;

    /**
     * @param string $startOptions the options the pattern opens with, such
     *                             as "(*CR)(*UTF)": the newline convention
     *                             they set decides where the dot stops
     */
    public function __construct(string $flags = '', private string $startOptions = '')
    {
        $this->unicodeMode = str_contains($flags, 'u');
        $this->dot = self::dotSet(str_contains($flags, 's'), StartOptions::newline($startOptions));
    }

    /**
     * An analyzer for the pattern as a whole: its flags and the newline
     * convention its start options set.
     */
    public static function forRegex(RegexNode $regex): self
    {
        return new self($regex->flags, StartOptions::of($regex->source ?? ''));
    }

    /**
     * The same pattern under other flags, such as the inline ones in effect
     * at a node.
     */
    public function withFlags(string $flags): self
    {
        return new self($flags, $this->startOptions);
    }

    public function firstChars(NodeInterface $node): ByteCharSet
    {
        return $this->walk($node, true);
    }

    public function lastChars(NodeInterface $node): ByteCharSet
    {
        return $this->walk($node, false);
    }

    private function walk(NodeInterface $node, bool $fromStart): ByteCharSet
    {
        if ($node instanceof RegexNode) {
            return $this->walk($node->pattern, $fromStart);
        }

        if ($node instanceof LiteralNode) {
            $length = \strlen($node->value);
            if (0 === $length) {
                return ByteCharSet::empty();
            }

            $char = $fromStart ? $node->value[0] : $node->value[$length - 1];

            return ByteCharSet::fromChar($char);
        }

        if ($node instanceof CharTypeNode) {
            return $this->fromCharType($node->value);
        }

        if ($node instanceof DotNode) {
            return $this->dot;
        }

        if ($node instanceof QuantifierNode) {
            return $this->walk($node->node, $fromStart);
        }

        if ($node instanceof RangeNode) {
            $start = $this->literalCodepoint($node->start);
            $end = $this->literalCodepoint($node->end);

            if (null === $start || null === $end) {
                return ByteCharSet::unknown();
            }

            return ByteCharSet::fromRange(min($start, $end), max($start, $end));
        }

        if ($node instanceof CharClassNode) {
            $set = ByteCharSet::empty();

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
                ClassSetOperator::Intersection => $left->intersect($right),
                ClassSetOperator::Difference => $left->intersect($right->complement()),
                ClassSetOperator::SymmetricDifference => $left->union($right)->intersect($left->intersect($right)->complement()),
                default => $left->union($right),
            };
        }

        if ($node instanceof AlternationNode) {
            $set = ByteCharSet::empty();
            foreach ($node->alternatives as $alt) {
                $set = $set->union($this->walk($alt, $fromStart));
            }

            return $set;
        }

        if ($node instanceof SequenceNode) {
            $children = $fromStart ? $node->children : array_reverse($node->children);
            $set = ByteCharSet::empty();

            foreach ($children as $child) {
                $candidate = $this->walk($child, $fromStart);
                $set = $set->union($candidate);

                if (!$this->isOptionalNode($child, $fromStart)) {
                    break;
                }
            }

            return $set;
        }

        return ByteCharSet::unknown();
    }

    private function fromCharType(string $type): ByteCharSet
    {
        if ($this->unicodeMode && \in_array($type, ['d', 'D', 'w', 'W'], true)) {
            return ByteCharSet::unknown();
        }

        return match ($type) {
            'd' => ByteCharSet::fromRange(\ord('0'), \ord('9')),
            'D' => ByteCharSet::fromRange(\ord('0'), \ord('9'))->complement(),
            'w' => ByteCharSet::fromRange(\ord('0'), \ord('9'))
                ->union(ByteCharSet::fromRange(\ord('A'), \ord('Z')))
                ->union(ByteCharSet::fromRange(\ord('a'), \ord('z')))
                ->union(ByteCharSet::fromChar('_')),
            'W' => ByteCharSet::fromRange(\ord('0'), \ord('9'))
                ->union(ByteCharSet::fromRange(\ord('A'), \ord('Z')))
                ->union(ByteCharSet::fromRange(\ord('a'), \ord('z')))
                ->union(ByteCharSet::fromChar('_'))
                ->complement(),
            's' => $this->whitespace(),
            'S' => $this->whitespace()->complement(),
            // ASCII-only approximation: the Unicode additions (NEL, LS, PS)
            // sit beyond the byte universe and only under-approximate the set.
            'v' => $this->verticalWhitespace(),
            'V' => $this->verticalWhitespace()->complement(),
            'R' => $this->verticalWhitespace()->union(ByteCharSet::fromChar("\r")),
            'h' => ByteCharSet::fromChar("\t")->union(ByteCharSet::fromChar(' ')),
            'H' => ByteCharSet::fromChar("\t")->union(ByteCharSet::fromChar(' '))->complement(),
            default => ByteCharSet::unknown(),
        };
    }

    private function whitespace(): ByteCharSet
    {
        $set = ByteCharSet::empty();
        foreach ([9, 10, 11, 12, 13, 32] as $code) {
            $set = $set->union(ByteCharSet::fromRange($code, $code));
        }

        return $set;
    }

    private function verticalWhitespace(): ByteCharSet
    {
        $set = ByteCharSet::empty();
        foreach ([10, 11, 12, 13] as $code) {
            $set = $set->union(ByteCharSet::fromRange($code, $code));
        }

        return $set;
    }

    /**
     * Without dotall, the dot matches every character but a newline, which
     * the convention defines. Under (*CRLF) only the pair is a newline, so
     * a single byte of it is an ordinary character. The newlines (*ANY)
     * adds above ASCII lie outside the sets.
     */
    private static function dotSet(bool $dotAll, string $newline): ByteCharSet
    {
        $newlines = match (true) {
            $dotAll, 'CRLF' === $newline => '',
            'CR' === $newline => "\r",
            'NUL' === $newline => "\0",
            'ANYCRLF' === $newline => "\n\r",
            'ANY' === $newline => "\n\v\f\r",
            default => "\n",
        };

        $set = ByteCharSet::empty();
        foreach (str_split($newlines) as $char) {
            $set = $set->union(ByteCharSet::fromChar($char));
        }

        return $set->complement();
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
