<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Automata\Model;

/**
 * Immutable NFA state.
 *
 * @internal
 */
final readonly class NfaState
{
    /**
     * @param array<NfaTransition> $transitions
     * @param array<int>           $epsilonTransitions
     */
    public function __construct(
        public int $id,
        public array $transitions,
        public array $epsilonTransitions,
        public bool $isAccepting,
    ) {}
}
