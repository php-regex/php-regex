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
final readonly class SequenceNode extends AbstractNode
{
    /**
     * @var list<NodeInterface>
     */
    public array $children;

    /**
     * @param array<NodeInterface> $children Renumbered in the order given
     */
    public function __construct(
        array $children,
        int $startPosition,
        int $endPosition
    ) {
        parent::__construct($startPosition, $endPosition);
        $this->children = array_values($children);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitSequence($this);
    }

    #[\Override]
    public function getChildren(): array
    {
        return $this->children;
    }
}
