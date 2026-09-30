<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Automata\Model;

/**
 * NFA fragment with a start state and accepting states.
 *
 * @internal
 */
final readonly class NfaFragment
{
    /**
     * @param array<int> $acceptStates
     */
    public function __construct(public int $startState, public array $acceptStates) {}
}
