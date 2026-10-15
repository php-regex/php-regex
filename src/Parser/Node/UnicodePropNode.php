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
 * Represents a Unicode property match.
 */
final class UnicodePropNode extends AbstractNode
{
    public function __construct(
        public readonly string $prop,
        public readonly bool $hasBraces,
        int $startPosition,
        ?int $endPosition = null,
        /**
         * Whether the source used the \P negated syntax (vs \p{^...}).
         */
        public readonly bool $negatedSyntax = false,
    ) {
        parent::__construct($startPosition, $endPosition ?? $startPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitUnicodeProp($this);
    }
}
