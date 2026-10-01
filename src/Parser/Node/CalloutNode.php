<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Parser\Node;

use PhpRegex\Parser\NodeVisitorInterface;

final readonly class CalloutNode extends AbstractNode
{
    public function __construct(
        public int|string|null $identifier,
        public bool $isStringIdentifier,
        int $startPosition,
        int $endPosition,
    ) {
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor): mixed
    {
        return $visitor->visitCallout($this);
    }
}
