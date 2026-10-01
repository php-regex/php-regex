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

namespace PhpRegex\Parser;

use PhpRegex\Parser\Node\NodeInterface;

/**
 * Walks a tree depth first, in the order its nodes stand in the pattern,
 * without a visitor for each kind of node: a callback on the way in, with
 * the node's ancestors, and one on the way out.
 *
 * The walk keeps its own stack, so a pattern nested as deep as the parser
 * allows does not exhaust PHP's.
 */
final class NodeWalker
{
    /**
     * @param \Closure(\PhpRegex\Parser\Node\NodeInterface, list<\PhpRegex\Parser\Node\NodeInterface>):((\PhpRegex\Parser\TraversalAction|null))      $enter called before the node's children
     * @param \Closure(\PhpRegex\Parser\Node\NodeInterface, list<\PhpRegex\Parser\Node\NodeInterface>):((\PhpRegex\Parser\TraversalAction|null))|null $leave called after them
     */
    public static function walk(NodeInterface $root, \Closure $enter, ?\Closure $leave = null): void
    {
        /** @var list<array{\PhpRegex\Parser\Node\NodeInterface, list<\PhpRegex\Parser\Node\NodeInterface>, bool}> $stack a node, its ancestors, and whether it was entered */
        $stack = [[$root, [], false]];

        while ([] !== $stack) {
            [$node, $ancestors, $entered] = array_pop($stack);

            if ($entered) {
                if (null !== $leave && TraversalAction::Stop === $leave($node, $ancestors)) {
                    return;
                }

                continue;
            }

            $action = $enter($node, $ancestors);
            if (TraversalAction::Stop === $action) {
                return;
            }

            $stack[] = [$node, $ancestors, true];
            if (TraversalAction::SkipChildren === $action) {
                continue;
            }

            $path = [...$ancestors, $node];
            foreach (array_reverse($node->getChildren()) as $child) {
                $stack[] = [$child, $path, false];
            }
        }
    }
}
