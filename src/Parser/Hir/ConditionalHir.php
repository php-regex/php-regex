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
 * A conditional group: the yes branch where the condition holds, the no
 * branch where it does not. The condition stays the AST node it was written
 * as.
 *
 * @internal
 */
final readonly class ConditionalHir extends Hir
{
    public function __construct(
        public NodeInterface $condition,
        public Hir $yes,
        public Hir $no,
        int $conditionCaptureCount = 0,
        int $startPosition = 0,
        int $endPosition = 0,
    ) {
        parent::__construct(Properties::conditional($yes->properties, $no->properties, $conditionCaptureCount), $startPosition, $endPosition);
    }

    public function children(): array
    {
        return [$this->yes, $this->no];
    }
}
