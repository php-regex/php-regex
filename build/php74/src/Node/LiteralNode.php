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
 * Represents a literal character or sequence in a regex.
 */
final class LiteralNode extends AbstractNode
{
    /**
     */
    public string $value;
    /**
     */
    public bool $isRaw = false;

    public function __construct(
        string $value,
        int $startPosition,
        int $endPosition,
        bool $isRaw = false
    ) {
        $this->value = $value;
        $this->isRaw = $isRaw;
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitLiteral($this);
    }
}
