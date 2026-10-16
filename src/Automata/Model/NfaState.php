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

namespace PHPRegex\Automata\Model;

/**
 * Immutable NFA state.
 *
 * @internal
 */
final class NfaState
{
    /**
     * @param array<NfaTransition> $transitions
     * @param array<int>           $epsilonTransitions
     */
    public function __construct(
        public readonly int $id,
        public readonly array $transitions,
        public readonly array $epsilonTransitions,
        public readonly bool $isAccepting,
    ) {}
}
