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
     * The least and the most repetitions $quantifier reads as, null when it
     * is no quantifier PCRE reads (a node built by hand may hold one).
     *
     */
    public ?QuantifierBounds $bounds;
    /**
     */
    public NodeInterface $node;
    /**
     */
    public string $quantifier;
    /**
     */
    public QuantifierType $type;

    public function __construct(
        NodeInterface $node,
        string $quantifier,
        QuantifierType $type,
        int $startPosition,
        int $endPosition
    ) {
        $this->node = $node;
        $this->quantifier = $quantifier;
        $this->type = $type;
        parent::__construct($startPosition, $endPosition);
        $this->bounds = QuantifierBounds::parse($quantifier);
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
