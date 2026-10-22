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
 * Represents a quantifier.
 */
final class QuantifierNode extends AbstractNode
{
    /**
     * @readonly
     * @var \PHPRegex\Parser\Node\NodeInterface
     */
    public $node;
    /**
     * @readonly
     * @var string
     */
    public $quantifier;
    /**
     * @readonly
     * @var \PHPRegex\Parser\Node\QuantifierType
     */
    public $type;
    /**
     * @param mixed $type
     */
    public function __construct(
        NodeInterface $node,
        string $quantifier,
        $type,
        int $startPosition,
        int $endPosition
    ) {
        $this->node = $node;
        $this->quantifier = $quantifier;
        $this->type = $type;
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitQuantifier($this);
    }

    #[\Override]
    public function getChildren(): array
    {
        return [$this->node];
    }
}
