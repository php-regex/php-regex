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

namespace PHPRegex\Linter\Rule\Support;

use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\SequenceNode;

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
     * @return list<NodeInterface>
     */
    public static function collectParts(NodeInterface $node): array
    {
        if ($node instanceof AlternationNode) {
            return array_values($node->alternatives);
        }

        if ($node instanceof SequenceNode) {
            return array_values($node->children);
        }

        return [$node];
    }

    public static function partCharSet(NodeInterface $node, bool $unicodeMode): ?ByteCharSet
    {
        if ($node instanceof LiteralNode || $node instanceof CharLiteralNode) {
            $codePoint = CodePoints::fromNode($node, $unicodeMode);
            if (null === $codePoint) {
                return null;
            }

            $set = ByteCharSet::fromRange($codePoint, $codePoint);

            return $set->isUnknown() ? null : $set;
        }

        if ($node instanceof RangeNode) {
            $start = CodePoints::fromNode($node->start, $unicodeMode);
            $end = CodePoints::fromNode($node->end, $unicodeMode);
            if (null === $start || null === $end) {
                return null;
            }

            $set = ByteCharSet::fromRange(min($start, $end), max($start, $end));

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

    public static function fromCharType(string $type, bool $unicodeMode): ?ByteCharSet
    {
        if ($unicodeMode && \in_array($type, ['d', 'D', 'w', 'W'], true)) {
            return null;
        }

        $set = match ($type) {
            'd' => ByteCharSet::fromRange(\ord('0'), \ord('9')),
            'D' => ByteCharSet::fromRange(\ord('0'), \ord('9'))->complement(),
            'w' => ByteCharSet::fromRange(\ord('0'), \ord('9'))
                ->union(ByteCharSet::fromRange(\ord('A'), \ord('Z')))
                ->union(ByteCharSet::fromRange(\ord('a'), \ord('z')))
                ->union(ByteCharSet::fromRange(\ord('_'), \ord('_'))),
            'W' => ByteCharSet::fromRange(\ord('0'), \ord('9'))
                ->union(ByteCharSet::fromRange(\ord('A'), \ord('Z')))
                ->union(ByteCharSet::fromRange(\ord('a'), \ord('z')))
                ->union(ByteCharSet::fromRange(\ord('_'), \ord('_')))
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

    public static function fromPosixClass(string $class): ?ByteCharSet
    {
        $negated = str_starts_with($class, '^');
        $normalized = strtolower(ltrim($class, '^'));

        $set = match ($normalized) {
            'digit' => ByteCharSet::fromRange(\ord('0'), \ord('9')),
            'lower' => ByteCharSet::fromRange(\ord('a'), \ord('z')),
            'upper' => ByteCharSet::fromRange(\ord('A'), \ord('Z')),
            'alpha' => ByteCharSet::fromRange(\ord('A'), \ord('Z'))
                ->union(ByteCharSet::fromRange(\ord('a'), \ord('z'))),
            'alnum' => ByteCharSet::fromRange(\ord('0'), \ord('9'))
                ->union(ByteCharSet::fromRange(\ord('A'), \ord('Z')))
                ->union(ByteCharSet::fromRange(\ord('a'), \ord('z'))),
            'word' => ByteCharSet::fromRange(\ord('0'), \ord('9'))
                ->union(ByteCharSet::fromRange(\ord('A'), \ord('Z')))
                ->union(ByteCharSet::fromRange(\ord('a'), \ord('z')))
                ->union(ByteCharSet::fromRange(\ord('_'), \ord('_'))),
            'xdigit' => ByteCharSet::fromRange(\ord('0'), \ord('9'))
                ->union(ByteCharSet::fromRange(\ord('A'), \ord('F')))
                ->union(ByteCharSet::fromRange(\ord('a'), \ord('f'))),
            'space' => self::asciiWhitespace(),
            default => null,
        };

        if (null === $set || $set->isUnknown()) {
            return null;
        }

        return $negated ? $set->complement() : $set;
    }

    public static function asciiWhitespace(): ByteCharSet
    {
        $set = ByteCharSet::empty();
        foreach ([9, 10, 11, 12, 13, 32] as $code) {
            $set = $set->union(ByteCharSet::fromRange($code, $code));
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

    public static function isSubset(ByteCharSet $candidate, ByteCharSet $other): bool
    {
        if ($candidate->isUnknown() || $other->isUnknown()) {
            return false;
        }

        return !$candidate->intersects($other->complement());
    }
}
