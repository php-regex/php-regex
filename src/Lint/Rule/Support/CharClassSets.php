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

namespace RegexParser\Lint\Rule\Support;

use RegexParser\Analysis\CharSet;
use RegexParser\Node\AlternationNode;
use RegexParser\Node\CharLiteralNode;
use RegexParser\Node\CharTypeNode;
use RegexParser\Node\ClassOperationNode;
use RegexParser\Node\LiteralNode;
use RegexParser\Node\NodeInterface;
use RegexParser\Node\PosixClassNode;
use RegexParser\Node\RangeNode;
use RegexParser\Node\SequenceNode;

/**
 * Character-class decomposition and CharSet construction helpers shared by
 * lint rules.
 *
 * @internal
 */
final class CharClassSets
{
    private function __construct() {}

    /**
     * @return list<NodeInterface>|null
     */
    public static function collectParts(NodeInterface $node): ?array
    {
        if ($node instanceof ClassOperationNode) {
            return null;
        }

        if ($node instanceof AlternationNode) {
            return array_values($node->alternatives);
        }

        if ($node instanceof SequenceNode) {
            return array_values($node->children);
        }

        return [$node];
    }

    public static function partCharSet(NodeInterface $node, bool $unicodeMode): ?CharSet
    {
        if ($node instanceof LiteralNode || $node instanceof CharLiteralNode) {
            $codePoint = CodePoints::fromNode($node, $unicodeMode);
            if (null === $codePoint) {
                return null;
            }

            $set = CharSet::fromRange($codePoint, $codePoint);

            return $set->isUnknown() ? null : $set;
        }

        if ($node instanceof RangeNode) {
            $start = CodePoints::fromNode($node->start, $unicodeMode);
            $end = CodePoints::fromNode($node->end, $unicodeMode);
            if (null === $start || null === $end) {
                return null;
            }

            $set = CharSet::fromRange(min($start, $end), max($start, $end));

            return $set->isUnknown() ? null : $set;
        }

        if ($node instanceof CharTypeNode) {
            return self::fromCharType($node->value, $unicodeMode);
        }

        if ($node instanceof PosixClassNode) {
            return self::fromPosixClass($node->class);
        }

        return null;
    }

    public static function fromCharType(string $type, bool $unicodeMode): ?CharSet
    {
        if ($unicodeMode && \in_array($type, ['d', 'D', 'w', 'W'], true)) {
            return null;
        }

        $set = match ($type) {
            'd' => CharSet::fromRange(\ord('0'), \ord('9')),
            'D' => CharSet::fromRange(\ord('0'), \ord('9'))->complement(),
            'w' => CharSet::fromRange(\ord('0'), \ord('9'))
                ->union(CharSet::fromRange(\ord('A'), \ord('Z')))
                ->union(CharSet::fromRange(\ord('a'), \ord('z')))
                ->union(CharSet::fromRange(\ord('_'), \ord('_'))),
            'W' => CharSet::fromRange(\ord('0'), \ord('9'))
                ->union(CharSet::fromRange(\ord('A'), \ord('Z')))
                ->union(CharSet::fromRange(\ord('a'), \ord('z')))
                ->union(CharSet::fromRange(\ord('_'), \ord('_')))
                ->complement(),
            's' => self::asciiWhitespace(),
            'S' => self::asciiWhitespace()->complement(),
            default => null,
        };

        if (null === $set || $set->isUnknown()) {
            return null;
        }

        return $set;
    }

    public static function fromPosixClass(string $class): ?CharSet
    {
        $negated = str_starts_with($class, '^');
        $normalized = strtolower(ltrim($class, '^'));

        $set = match ($normalized) {
            'digit' => CharSet::fromRange(\ord('0'), \ord('9')),
            'lower' => CharSet::fromRange(\ord('a'), \ord('z')),
            'upper' => CharSet::fromRange(\ord('A'), \ord('Z')),
            'alpha' => CharSet::fromRange(\ord('A'), \ord('Z'))
                ->union(CharSet::fromRange(\ord('a'), \ord('z'))),
            'alnum' => CharSet::fromRange(\ord('0'), \ord('9'))
                ->union(CharSet::fromRange(\ord('A'), \ord('Z')))
                ->union(CharSet::fromRange(\ord('a'), \ord('z'))),
            'word' => CharSet::fromRange(\ord('0'), \ord('9'))
                ->union(CharSet::fromRange(\ord('A'), \ord('Z')))
                ->union(CharSet::fromRange(\ord('a'), \ord('z')))
                ->union(CharSet::fromRange(\ord('_'), \ord('_'))),
            'xdigit' => CharSet::fromRange(\ord('0'), \ord('9'))
                ->union(CharSet::fromRange(\ord('A'), \ord('F')))
                ->union(CharSet::fromRange(\ord('a'), \ord('f'))),
            'space' => self::asciiWhitespace(),
            default => null,
        };

        if (null === $set || $set->isUnknown()) {
            return null;
        }

        return $negated ? $set->complement() : $set;
    }

    public static function asciiWhitespace(): CharSet
    {
        $set = CharSet::empty();
        foreach ([9, 10, 11, 12, 13, 32] as $code) {
            $set = $set->union(CharSet::fromRange($code, $code));
        }

        return $set;
    }

    public static function isBasicPart(NodeInterface $node): bool
    {
        if ($node instanceof LiteralNode) {
            return 1 === \strlen($node->value);
        }

        if ($node instanceof RangeNode && $node->start instanceof LiteralNode && $node->end instanceof LiteralNode) {
            return 1 === \strlen($node->start->value) && 1 === \strlen($node->end->value);
        }

        return false;
    }

    public static function isSubset(CharSet $candidate, CharSet $other): bool
    {
        if ($candidate->isUnknown() || $other->isUnknown()) {
            return false;
        }

        return !$candidate->intersects($other->complement());
    }
}
