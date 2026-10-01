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
 * Represents a version condition in conditional groups (e.g., (?(VERSION>=10.0)...)).
 *
 * This node represents PCRE2 version conditions that allow
 * conditional matching based on the PCRE library version.
 */
final readonly class VersionConditionNode extends AbstractNode
{
    public function __construct(
        public string $operator,
        public string $version,
        int $startPosition,
        int $endPosition
    ) {
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitVersionCondition($this);
    }
}
