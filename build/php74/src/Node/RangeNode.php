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
 * Represents a character range.
 */
final class RangeNode extends AbstractNode
{
    /**
     */
    public NodeInterface $start;
    /**
     */
    public NodeInterface $end;

    public function __construct(
        NodeInterface $start,
        NodeInterface $end,
        int $startPosition,
        int $endPosition
    ) {
        $this->start = $start;
        $this->end = $end;
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitRange($this);
    }

    #[\Override]
    public function getChildren(): array
    {
        return [$this->start, $this->end];
    }
}
