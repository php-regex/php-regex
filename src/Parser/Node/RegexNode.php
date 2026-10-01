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
 * Root node of the regex AST.
 */
final readonly class RegexNode extends AbstractNode
{
    /**
     * @param string|null $source the pattern body the AST was parsed from,
     *                            used to reproduce the whitespace that /x
     *                            makes ignorable and that no node carries
     */
    public function __construct(
        public NodeInterface $pattern,
        public string $flags,
        public string $delimiter,
        int $startPosition,
        int $endPosition,
        public ?string $source = null
    ) {
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitRegex($this);
    }

    #[\Override]
    public function getChildren(): array
    {
        return [$this->pattern];
    }
}
