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
 * Represents a character class in a regular expression.
 */
final class CharClassNode extends AbstractNode
{
    /**
     */
    public NodeInterface $expression;
    /**
     */
    public bool $isNegated;

    public function __construct(
        NodeInterface $expression,
        bool $isNegated,
        int $startPosition,
        int $endPosition
    ) {
        $this->expression = $expression;
        $this->isNegated = $isNegated;
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitCharClass($this);
    }

    #[\Override]
    public function getChildren(): array
    {
        return [$this->expression];
    }
}
