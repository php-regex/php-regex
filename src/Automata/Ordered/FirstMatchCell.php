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

namespace PHPRegex\Automata\Ordered;

/**
 * What one candidate of a first-match list keeps: the strings it matches
 * that no candidate before it matches. A string is a tuple, one part per
 * field of the candidates (a method, a scheme, a host, a path).
 */
final class FirstMatchCell
{
    /**
     * @param int                      $index      the candidate's place in the list
     * @param bool                     $reachable  whether some string reaches it
     * @param list<string>|null        $witness    the parts of the least shortest string it keeps, null when it keeps none
     * @param array<int, list<string>> $takenBy    the earlier candidates that take some of its strings, each with the least shortest one
     * @param list<int>                $unread     the earlier candidates left out, their fields read in another encoding
     * @param string|null              $refused    why the solver gave up on the candidate: it is then read as reachable
     * @param int|null                 $shadowedBy the one earlier candidate that takes all its strings alone, when it keeps none
     *
     * @internal
     */
    public function __construct(
        public readonly int $index,
        public readonly bool $reachable,
        public readonly ?array $witness,
        public readonly array $takenBy = [],
        public readonly array $unread = [],
        public readonly ?string $refused = null,
        public readonly ?int $shadowedBy = null,
    ) {}

    /**
     * Whether earlier candidates take every string of this one, none of
     * them alone: only their union does.
     */
    public function isShadowedCumulatively(): bool
    {
        return !$this->reachable && [] !== $this->takenBy && null === $this->shadowedBy;
    }
}
