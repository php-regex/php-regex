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

namespace PhpRegex\Parser;

use PhpRegex\Parser\Node\NodeInterface;

/**
 * Finds the nodes of a tree that pass a test, in the order they stand in the
 * pattern.
 */
final class NodeFinder
{
    /**
     * @param \Closure(NodeInterface):bool $filter
     *
     * @return list<NodeInterface>
     */
    public static function find(NodeInterface $root, \Closure $filter): array
    {
        $found = [];
        NodeWalker::walk($root, static function (NodeInterface $node) use ($filter, &$found): null {
            if ($filter($node)) {
                $found[] = $node;
            }

            return null;
        });

        return $found;
    }

    /**
     * @template T of \PhpRegex\Parser\Node\NodeInterface
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public static function findInstanceOf(NodeInterface $root, string $class): array
    {
        /** @var list<T> $found */
        $found = self::find($root, static fn (NodeInterface $node): bool => $node instanceof $class);

        return $found;
    }

    /**
     * @param \Closure(NodeInterface):bool $filter
     */
    public static function findFirst(NodeInterface $root, \Closure $filter): ?NodeInterface
    {
        $first = null;
        NodeWalker::walk($root, static function (NodeInterface $node) use ($filter, &$first): ?TraversalAction {
            if (!$filter($node)) {
                return null;
            }

            $first = $node;

            return TraversalAction::Stop;
        });

        return $first;
    }
}
