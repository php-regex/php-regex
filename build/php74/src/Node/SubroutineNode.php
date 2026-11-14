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
 * Represents a subroutine call.
 */
final class SubroutineNode extends AbstractNode
{
    /**
     */
    public string $reference;
    /**
     */
    public string $syntax;
    /**
     * @var list<string>
     *
     */
    public array $returnedGroups = [];

    /**
     * @param list<string> $returnedGroups the groups the call returns, as
     *                                     written: "2", "-1", "<name>" or
     *                                     "'name'" in "(?1(2,<name>))"
     *                                     (PCRE2 10.47)
     */
    public function __construct(
        string $reference,
        string $syntax,
        int $startPosition,
        int $endPosition,
        array $returnedGroups = []
    ) {
        $this->reference = $reference;
        $this->syntax = $syntax;
        $this->returnedGroups = $returnedGroups;
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitSubroutine($this);
    }
}
