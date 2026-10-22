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
 * Represents a PCRE verb.
 */
final class PcreVerbNode extends AbstractNode
{
    /**
     * @readonly
     * @var string
     */
    public $verb;
    public function __construct(
        string $verb,
        int $startPosition,
        int $endPosition
    ) {
        $this->verb = $verb;
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitPcreVerb($this);
    }
}
