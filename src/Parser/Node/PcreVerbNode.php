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
 * Represents a PCRE verb.
 */
final readonly class PcreVerbNode extends AbstractNode
{
    public function __construct(
        public string $verb,
        int $startPosition,
        int $endPosition
    ) {
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitPcreVerb($this);
    }
}
