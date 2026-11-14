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
 * Represents a DEFINE block in a regular expression.
 */
final class DefineNode extends AbstractNode
{
    /**
     */
    public NodeInterface $content;

    public function __construct(
        NodeInterface $content,
        int $startPosition,
        int $endPosition
    ) {
        $this->content = $content;
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitDefine($this);
    }

    #[\Override]
    public function getChildren(): array
    {
        return [$this->content];
    }
}
