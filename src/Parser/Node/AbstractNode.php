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

namespace PhpRegex\Parser\Node;

/**
 * Abstract base class for all AST nodes.
 */
abstract readonly class AbstractNode implements NodeInterface
{
    public function __construct(public int $startPosition, public int $endPosition) {}

    public function getStartPosition(): int
    {
        return $this->startPosition;
    }

    public function getEndPosition(): int
    {
        return $this->endPosition;
    }

    /**
     * @return list<\PhpRegex\Parser\Node\NodeInterface>
     */
    public function getChildren(): array
    {
        return [];
    }
}
