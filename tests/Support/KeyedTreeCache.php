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

namespace PHPRegex\Tests\Support;

use PHPRegex\Parser\Cache\CacheInterface;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;

/**
 * A cache that hands back, for every tree the parser stores, the same tree
 * built again with keyed arrays: each sequence and alternation goes through
 * its public constructor with keys that are not 0, 1, 2, ..., and every
 * other node is copied property by property, so only those two constructors
 * shape the copy.
 *
 * The first parse of a pattern builds and stores it; every later parse of
 * the same pattern, by any consumer holding this parser, reads the keyed
 * copy.
 */
final class KeyedTreeCache implements CacheInterface
{
    /**
     * Keys "k0", "k1", ...
     */
    public const STRING_KEYS = 'string';

    /**
     * Keys 10, 20, ...
     */
    public const SHIFTED_INT_KEYS = 'int';

    /**
     * Keys 0, 1, ...: the copy the parser built, through the same path.
     */
    public const LIST_KEYS = 'list';

    /**
     * @var array<string, RegexNode>
     */
    private array $trees = [];

    public function __construct(private readonly string $scheme) {}

    public function generateKey(string $seed): string
    {
        return hash('xxh128', $seed);
    }

    public function write(string $key, RegexNode $ast): void
    {
        $this->trees[$key] = self::rebuild($ast, $this->scheme);
    }

    public function load(string $key): ?RegexNode
    {
        return $this->trees[$key] ?? null;
    }

    public static function rebuild(RegexNode $tree, string $scheme): RegexNode
    {
        $copy = self::copy($tree, $scheme);
        \assert($copy instanceof RegexNode);

        return $copy;
    }

    private static function copy(mixed $value, string $scheme): mixed
    {
        if (\is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::copy($item, $scheme), $value);
        }

        if (!$value instanceof NodeInterface) {
            return $value;
        }

        if ($value instanceof SequenceNode) {
            return new SequenceNode(self::keyed(self::nodes($value->children, $scheme), $scheme), $value->startPosition, $value->endPosition);
        }

        if ($value instanceof AlternationNode) {
            return new AlternationNode(self::keyed(self::nodes($value->alternatives, $scheme), $scheme), $value->startPosition, $value->endPosition);
        }

        $class = new \ReflectionClass($value);
        $copy = $class->newInstanceWithoutConstructor();
        for ($declaring = $class; false !== $declaring; $declaring = $declaring->getParentClass()) {
            foreach ($declaring->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $declaring->getName()) {
                    continue;
                }

                $property->setValue($copy, self::copy($property->getValue($value), $scheme));
            }
        }

        return $copy;
    }

    /**
     * @param array<NodeInterface> $nodes
     *
     * @return list<NodeInterface>
     */
    private static function nodes(array $nodes, string $scheme): array
    {
        $copies = [];
        foreach ($nodes as $node) {
            $copy = self::copy($node, $scheme);
            \assert($copy instanceof NodeInterface);
            $copies[] = $copy;
        }

        return $copies;
    }

    /**
     * @param list<NodeInterface> $nodes
     *
     * @return array<NodeInterface>
     */
    private static function keyed(array $nodes, string $scheme): array
    {
        $keyed = [];
        foreach ($nodes as $position => $node) {
            $key = match ($scheme) {
                self::STRING_KEYS => 'k'.$position,
                self::SHIFTED_INT_KEYS => ($position + 1) * 10,
                default => $position,
            };
            $keyed[$key] = $node;
        }

        return $keyed;
    }
}
