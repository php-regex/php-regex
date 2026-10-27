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
 * Represents an inline comment in a regular expression.
 */
final class CommentNode extends AbstractNode
{
    /**
     * @param bool $isExtended whether the comment was written as a "# ..." line
     *                         under /x rather than as a "(?#...)" group
     */
    public function __construct(
        public readonly string $comment,
        int $startPosition,
        int $endPosition,
        public readonly bool $isExtended = false
    ) {
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitComment($this);
    }
}
