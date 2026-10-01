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
 * Represents a subroutine call.
 */
final readonly class SubroutineNode extends AbstractNode
{
    /**
     * @param list<string> $returnedGroups the groups the call returns, as
     *                                     written: "2", "-1", "<name>" or
     *                                     "'name'" in "(?1(2,<name>))"
     *                                     (PCRE2 10.47)
     */
    public function __construct(
        public string $reference,
        public string $syntax,
        int $startPosition,
        int $endPosition,
        public array $returnedGroups = [],
    ) {
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitSubroutine($this);
    }
}
