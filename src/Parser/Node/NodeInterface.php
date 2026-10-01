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

namespace PhpRegex\Parser\Node;

use PhpRegex\Parser\NodeVisitorInterface;

/**
 * Base interface for all AST nodes.
 */
interface NodeInterface
{
    /**
     * @template-covariant  T
     *
     * @phpstan-template  T
     *
     * @param NodeVisitorInterface<T> $visitor
     *
     * @return T
     */
    public function accept(NodeVisitorInterface $visitor);

    public function getStartPosition(): int;

    public function getEndPosition(): int;

    /**
     * The nodes this one holds, in the order they stand in the pattern; none
     * for a leaf.
     *
     * @return list<self>
     */
    public function getChildren(): array;
}
