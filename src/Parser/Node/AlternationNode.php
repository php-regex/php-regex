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

/**
 * Represents an alternation in a regular expression.
 */
final readonly class AlternationNode extends AbstractNode
{
    /**
     * @param array<\PhpRegex\Parser\Node\NodeInterface> $alternatives
     */
    public function __construct(
        public array $alternatives,
        int $startPosition,
        int $endPosition
    ) {
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitAlternation($this);
    }

    #[\Override]
    public function getChildren(): array
    {
        return array_values($this->alternatives);
    }
}
