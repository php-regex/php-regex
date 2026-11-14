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
 * Represents a callout in a regular expression.
 */
final class CalloutNode extends AbstractNode
{
    /**
     *
     * @var int|string|null
     */
    public $identifier;
    /**
     */
    public bool $isStringIdentifier;

    /**
     * @param int|string|null $identifier
     */
    public function __construct(
        $identifier,
        bool $isStringIdentifier,
        int $startPosition,
        int $endPosition
    ) {
        $this->identifier = $identifier;
        $this->isStringIdentifier = $isStringIdentifier;
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitCallout($this);
    }
}
