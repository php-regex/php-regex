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
 * Represents a single character literal expressed via escape syntax (unicode or octal).
 */
final class CharLiteralNode extends AbstractNode
{
    /**
     * @readonly
     * @var string
     */
    public $originalRepresentation;
    /**
     * @readonly
     * @var int
     */
    public $codePoint;
    /**
     * @readonly
     * @var \PHPRegex\Parser\Node\CharLiteralType
     */
    public $type;
    /**
     * @param mixed $type
     */
    public function __construct(
        string $originalRepresentation,
        int $codePoint,
        $type,
        int $startPosition,
        int $endPosition
    ) {
        $this->originalRepresentation = $originalRepresentation;
        $this->codePoint = $codePoint;
        $this->type = $type;
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitCharLiteral($this);
    }
}
