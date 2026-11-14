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

/**
 * A capturing group, with its number and, for a named group, its name.
 *
 * @internal
 */
final class CaptureHir extends Hir
{
    /**
     */
    public Hir $body;
    /**
     */
    public int $index;
    /**
     */
    public ?string $name = null;

    public function __construct(
        Hir $body,
        int $index,
        ?string $name = null,
        int $startPosition = 0,
        int $endPosition = 0
    ) {
        $this->body = $body;
        $this->index = $index;
        $this->name = $name;
        parent::__construct($body->properties->withCapture(), $startPosition, $endPosition);
    }

    public function children(): array
    {
        return [$this->body];
    }
}
