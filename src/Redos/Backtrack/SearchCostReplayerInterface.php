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

namespace PHPRegex\Redos\Backtrack;

/**
 * The replay of a search-cost witness on an engine, which the search-cost
 * proof asks before it reports the witness as replayed. A replay may throw
 * the library's exceptions, as any engine failure does: the proof then
 * reports no witness.
 *
 * @internal
 */
interface SearchCostReplayerInterface
{
    /**
     * Whether the engine starts attempts inside the run that each read what
     * is left of it, and matches nowhere.
     */
    public function replays(string $pattern, string $prefix, string $run, string $breaker): bool;
}
