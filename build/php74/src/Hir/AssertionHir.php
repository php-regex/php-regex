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
 * A zero-width condition on where the match stands.
 *
 * @internal
 */
final class AssertionHir extends Hir
{
    /**
     */
    public AssertionKind $kind;
    /**
     */
    public ?CharSet $wordSet = null;

    /**
     * @param CharSet|null $wordSet for a word boundary, the characters the engine reads as \w here
     */
    public function __construct(
        AssertionKind $kind,
        int $startPosition = 0,
        int $endPosition = 0,
        ?CharSet $wordSet = null
    ) {
        $this->kind = $kind;
        $this->wordSet = $wordSet;
        parent::__construct(Properties::zeroWidth(), $startPosition, $endPosition);
    }

    public function children(): array
    {
        return [];
    }
}
