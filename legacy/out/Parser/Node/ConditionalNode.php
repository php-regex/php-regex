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
 * Represents a conditional sub-pattern.
 */
final class ConditionalNode extends AbstractNode
{
    /**
     * @readonly
     * @var \PHPRegex\Parser\Node\NodeInterface
     */
    public $condition;
    /**
     * @readonly
     * @var \PHPRegex\Parser\Node\NodeInterface
     */
    public $yes;
    /**
     * @readonly
     * @var \PHPRegex\Parser\Node\NodeInterface
     */
    public $no;
    public function __construct(
        NodeInterface $condition,
        NodeInterface $yes,
        NodeInterface $no,
        int $startPosition,
        int $endPosition
    ) {
        $this->condition = $condition;
        $this->yes = $yes;
        $this->no = $no;
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
