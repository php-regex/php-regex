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

use PhpRegex\Parser\NodeVisitorInterface;

/**
 * Represents a conditional sub-pattern.
 */
final readonly class ConditionalNode extends AbstractNode
{
    public function __construct(
        public NodeInterface $condition,
        public NodeInterface $yes,
        public NodeInterface $no,
        int $startPosition,
        int $endPosition
    ) {
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitConditional($this);
    }

    #[\Override]
    public function getChildren(): array
    {
        return [$this->condition, $this->yes, $this->no];
    }
}
