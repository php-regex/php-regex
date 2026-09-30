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
 * Represents a character class operation (intersection && or subtraction --).
 *
 * @deprecated PHP compiles patterns without PCRE2's extended class syntax, so
 *             "&&" and "--" inside a class are plain members and ranges, and
 *             the parser no longer builds this node. It goes in the next major
 *             version.
 */
final readonly class ClassOperationNode extends AbstractNode
{
    /**
     * Initializes a class operation node.
     *
     * @param ClassOperationType $type          the operation type (intersection or subtraction)
     * @param NodeInterface      $left          the left operand
     * @param NodeInterface      $right         the right operand
     * @param int                $startPosition the start position
     * @param int                $endPosition   the end position
     */
    public function __construct(
        public ClassOperationType $type,
        public NodeInterface $left,
        public NodeInterface $right,
        int $startPosition,
        int $endPosition
    ) {
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitClassOperation($this);
    }

    #[\Override]
    public function getChildren(): array
    {
        return [$this->left, $this->right];
    }
}
