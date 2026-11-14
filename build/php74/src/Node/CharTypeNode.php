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
 * Represents a predefined character type.
 */
final class CharTypeNode extends AbstractNode
{
    /**
     */
    public string $value;

    public function __construct(
        string $value,
        int $startPosition,
        int $endPosition
    ) {
        $this->value = $value;
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitCharType($this);
    }
}
