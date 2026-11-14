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

namespace PHPRegex\Parser\Hir;

use PHPRegex\Parser\Node\NodeInterface;

/**
 * A construct the normal form does not take apart: a backreference, a
 * subroutine call, a verb, a callout, "\X", a script run. It keeps the AST
 * node, and the properties worked out for it.
 *
 * @internal
 */
final class OpaqueHir extends Hir
{
    /**
     */
    public NodeInterface $node;
    /**
     */
    public ?Hir $body = null;

    public function __construct(
        NodeInterface $node,
        Properties $properties,
        ?Hir $body = null,
        int $startPosition = 0,
        int $endPosition = 0
    ) {
        $this->node = $node;
        $this->body = $body;
        parent::__construct($properties, $startPosition, $endPosition);
    }

    public function children(): array
    {
        return null === $this->body ? [] : [$this->body];
    }
}
