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
 * Represents a (*LIMIT_MATCH=d) verb.
 *
 * This verb imposes a limit on the number of internal matches that can occur.
 * It's a safeguard against catastrophic backtracking on certain patterns.
 */
final readonly class LimitMatchNode extends AbstractNode
{
    public function __construct(
        public int $limit,
        int $startPosition,
        int $endPosition,
    ) {
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitLimitMatch($this);
    }
}
