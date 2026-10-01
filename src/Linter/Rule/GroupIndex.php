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

namespace PHPRegex\Linter\Rule;

use PHPRegex\Parser\Node\GroupNode;

/**
 * Immutable capturing-group facts collected in a pre-pass over the pattern.
 */
final readonly class GroupIndex
{
    /**
     * @param array<string, bool>                                                                                                         $definedNamedGroups
     * @param array<int, array{node: GroupNode, start: int, end: int, alternation: array<string, int>, alwaysEmpty: bool}>                $capturingGroups
     * @param array<string, array<int, array{node: GroupNode, start: int, end: int, alternation: array<string, int>, alwaysEmpty: bool}>> $capturingGroupsByName
     */
    public function __construct(
        public int $maxCapturingGroup,
        public array $definedNamedGroups,
        public array $capturingGroups,
        public array $capturingGroupsByName,
        public bool $containsBranchReset,
    ) {}
}
