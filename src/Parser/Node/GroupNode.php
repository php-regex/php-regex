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
 * Represents a grouping construct in a regular expression.
 */
final readonly class GroupNode extends AbstractNode
{
    /**
     * @param string|null  $name          the group's name; for a substring scan,
     *                                    its spelling, "scs" or "scan_substring"
     * @param list<string> $scannedGroups for a substring scan, the groups whose
     *                                    captures it matches, as written: "1",
     *                                    "-1", "<name>" or "'name'"
     */
    public function __construct(
        public NodeInterface $child,
        public GroupType $type,
        public ?string $name = null,
        public ?string $flags = null,
        int $startPosition = 0,
        int $endPosition = 0,
        public bool $usePythonSyntax = false,
        public array $scannedGroups = [],
    ) {
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitGroup($this);
    }

    #[\Override]
    public function getChildren(): array
    {
        return [$this->child];
    }
}
