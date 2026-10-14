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

namespace PHPRegex\Parser\Printer\Internal;

use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;

/**
 * What a pattern means as a tree, with what a layout may change taken out:
 * comments, positions, how a run of literal text is split into nodes and
 * spelled ("\x41", "\101" or "A"), nested sequences, an unquantified "(?:)"
 * or "(?:...)" holding only comments (which matches the empty string and
 * changes nothing), and the x flag.
 * Two patterns with the same fingerprint are the same pattern.
 *
 * @internal
 */
final class StructuralFingerprint
{
    private const POSITIONS = ['startPosition' => true, 'endPosition' => true];

    public static function of(RegexNode $regex): string
    {
        return serialize([
            $regex->delimiter,
            str_replace('x', '', $regex->flags),
            self::canonical($regex->pattern, $regex->isUnicode()),
        ]);
    }

    private static function canonical(NodeInterface $node, bool $unicode): mixed
    {
        if ($node instanceof SequenceNode) {
            return self::canonicalSequence($node, $unicode);
        }

        if (($node instanceof CharLiteralNode && ($unicode || $node->codePoint < 256)) || $node instanceof ControlCharNode) {
            return ['literal', false, $unicode ? mb_chr($node->codePoint, 'UTF-8') : \chr($node->codePoint)];
        }

        if ($node instanceof LiteralNode) {
            return ['literal', $node->isRaw, $node->value];
        }

        if ($node instanceof CommentNode) {
            return ['literal', false, ''];
        }

        $canonical = [$node::class];
        foreach (get_object_vars($node) as $name => $value) {
            if (isset(self::POSITIONS[$name])) {
                continue;
            }

            $canonical[$name] = match (true) {
                $value instanceof NodeInterface => self::canonical($value, $unicode),
                \is_array($value) => array_map(static fn (mixed $item): mixed => $item instanceof NodeInterface ? self::canonical($item, $unicode) : $item, $value),
                $value instanceof \UnitEnum => $value->name,
                default => $value,
            };
        }

        // A "(?:...)" whose body matches nothing but the empty string, "(?:)"
        // or one holding only comments, is empty wherever it stands, the
        // whole pattern or the body of a group as much as in a sequence. Its
        // body was read first, so nested groups cost one pass.
        if ($node instanceof GroupNode && GroupType::NonCapturing === $node->type && self::isEmptyLiteral($canonical['child'] ?? null)) {
            return ['literal', false, ''];
        }

        return $canonical;
    }

    /**
     * A sequence flattened, without what matches only the empty string (its
     * comments and empty groups), its neighbouring literals joined; a
     * sequence of one item is that item.
     */
    private static function canonicalSequence(SequenceNode $sequence, bool $unicode): mixed
    {
        $items = [];
        foreach (self::flatten($sequence) as $child) {
            $item = self::canonical($child, $unicode);
            if (self::isEmptyLiteral($item)) {
                continue;
            }

            $last = array_key_last($items);
            if (null !== $last && self::isLiteral($item) && self::isLiteral($items[$last]) && $item[1] === $items[$last][1]) {
                $items[$last] = ['literal', $item[1], $items[$last][2].$item[2]];

                continue;
            }

            $items[] = $item;
        }

        return match (\count($items)) {
            0 => ['literal', false, ''],
            1 => $items[0],
            default => ['sequence', $items],
        };
    }

    /**
     * @return list<NodeInterface>
     */
    private static function flatten(SequenceNode $sequence): array
    {
        $flat = [];
        foreach ($sequence->children as $child) {
            if ($child instanceof SequenceNode) {
                array_push($flat, ...self::flatten($child));
            } else {
                $flat[] = $child;
            }
        }

        return $flat;
    }

    /**
     * @phpstan-assert-if-true array{0: 'literal', 1: bool, 2: string} $item
     */
    private static function isLiteral(mixed $item): bool
    {
        return \is_array($item) && 'literal' === ($item[0] ?? null) && \is_bool($item[1] ?? null) && \is_string($item[2] ?? null);
    }

    private static function isEmptyLiteral(mixed $item): bool
    {
        return self::isLiteral($item) && '' === $item[2];
    }
}
