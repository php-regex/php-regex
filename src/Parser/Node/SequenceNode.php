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

namespace PhpRegex\Parser\Node;

use PhpRegex\Parser\NodeVisitorInterface;

/**
 * Represents a sequence of atoms.
 */
final readonly class SequenceNode extends AbstractNode
{
    /**
     * @param array<\PhpRegex\Parser\Node\NodeInterface> $children
     */
    public function __construct(
        public array $children,
        int $startPosition,
        int $endPosition
    ) {
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitSequence($this);
    }

    #[\Override]
    public function getChildren(): array
    {
        return array_values($this->children);
    }
}
