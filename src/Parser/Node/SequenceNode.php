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

namespace PHPRegex\Parser\Node;

use PHPRegex\Parser\NodeVisitorInterface;

/**
 * Represents a sequence of atoms.
 */
final class SequenceNode extends AbstractNode
{
    /**
     * @param array<NodeInterface> $children
     */
    public function __construct(
        public readonly array $children,
        int $startPosition,
        int $endPosition
    ) {
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitSequence($this);
    }

    #[\Override]
    public function getChildren(): array
    {
        return array_values($this->children);
    }
}
