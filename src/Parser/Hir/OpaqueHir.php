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
final readonly class OpaqueHir extends Hir
{
    public function __construct(
        public NodeInterface $node,
        Properties $properties,
        public ?Hir $body = null,
        int $startPosition = 0,
        int $endPosition = 0,
    ) {
        parent::__construct($properties, $startPosition, $endPosition);
    }

    public function children(): array
    {
        return null === $this->body ? [] : [$this->body];
    }
}
