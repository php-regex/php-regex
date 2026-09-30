<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Node;

use RegexParser\NodeVisitor\NodeVisitorInterface;

/**
 * Represents a DEFINE block in a regular expression.
 */
final readonly class DefineNode extends AbstractNode
{
    public function __construct(
        public NodeInterface $content,
        int $startPosition,
        int $endPosition
    ) {
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor): mixed
    {
        return $visitor->visitDefine($this);
    }

    #[\Override]
    public function getChildren(): array
    {
        return [$this->content];
    }
}
