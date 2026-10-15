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
 *
 * @phpstan-type CapturingGroupInfo array{node: GroupNode, start: int, end: int, alternation: array<string, int>, alwaysEmpty: bool}
 *
 * @internal
 */
final class GroupIndex
{
    /**
     * @param array<string, bool>                           $definedNamedGroups
     * @param array<int, CapturingGroupInfo>                $capturingGroups
     * @param array<string, array<int, CapturingGroupInfo>> $capturingGroupsByName
     * @param array<int, GroupNode>                         $subroutineTargets     the groups a subroutine call runs again, keyed by object id
     * @param bool                                          $recurses              whether the pattern calls itself whole: (?R), (?0), \g<0>
     */
    public function __construct(
        public readonly int $maxCapturingGroup,
        public readonly array $definedNamedGroups,
        public readonly array $capturingGroups,
        public readonly array $capturingGroupsByName,
        public readonly bool $containsBranchReset,
        public readonly array $subroutineTargets = [],
        public readonly bool $recurses = false,
    ) {}

    /**
     * Whether a subroutine call runs this group again, wherever it stands.
     */
    public function isSubroutineTarget(GroupNode $group): bool
    {
        return isset($this->subroutineTargets[spl_object_id($group)]);
    }
}
