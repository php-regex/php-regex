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
 * A set operation in a Perl extended class "(?[...])": "\d - [3]", or
 * "!\n" with no left operand.
 */
final readonly class ClassSetOperationNode extends AbstractNode
{
    /**
     * @param \PhpRegex\Parser\Node\NodeInterface|null $left   the left operand; null for the complement
     * @param string                                   $symbol the operator as written: "+", "|", "&", "-", "^" or "!"
     */
    public function __construct(
        public ClassSetOperator $operator,
        public ?NodeInterface $left,
        public NodeInterface $right,
        public string $symbol,
        int $startPosition,
        int $endPosition,
    ) {
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitClassSetOperation($this);
    }

    #[\Override]
    public function getChildren(): array
    {
        return null === $this->left ? [$this->right] : [$this->left, $this->right];
    }
}
