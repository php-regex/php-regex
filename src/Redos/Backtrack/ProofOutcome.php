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
 * What one proof gives back, however it ended: the verdict it proved or the
 * limit of the model it met, never both; what the model analysed
 * differently from the pattern as written, all of it after a proof and what
 * was read before the limit otherwise; the degree of a proven bound n^d on
 * the steps of one attempt, which needs no witness; and the automaton of
 * the pattern's own search with the budget it shares, for the search-cost
 * proof.
 *
 * @internal
 */
final class ProofOutcome
{
    /**
     * @param list<string>                      $abstractions
     * @param array{ItemAutomaton, Budget}|null $search
     */
    private function __construct(
        public readonly ?ProofResult $result,
        public readonly ?ModelLimit $limit,
        public readonly array $abstractions,
        public readonly ?int $stepBound,
        public readonly ?array $search,
    ) {}

    /**
     * @param list<string>                      $abstractions
     * @param array{ItemAutomaton, Budget}|null $search
     */
    public static function proven(ProofResult $result, array $abstractions, ?int $stepBound, ?array $search): self
    {
        return new self($result, null, $abstractions, $stepBound, $search);
    }

    /**
     * @param list<string>                      $abstractions
     * @param array{ItemAutomaton, Budget}|null $search
     */
    public static function limited(ModelLimit $limit, array $abstractions, ?int $stepBound, ?array $search): self
    {
        return new self(null, $limit, $abstractions, $stepBound, $search);
    }
}
