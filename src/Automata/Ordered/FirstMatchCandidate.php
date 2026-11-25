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
 * One candidate of a first-match list: the strings it takes from the
 * candidates after it, the strings it keeps, and the candidates before it
 * that may take one of its strings. A string is a tuple, one part per
 * field (a method, a scheme, a host, a path), each field read by one
 * delimited pattern.
 */
final class FirstMatchCandidate
{
    /**
     * @param list<list<string>>      $takes  the tuples of patterns it takes, one pattern per field, united
     * @param list<list<string>>|null $keeps  the tuples it keeps when it takes strings it does not keep, as a route takes
     *                                        the paths it redirects for their trailing slash and keeps those it matches
     *                                        as is; the tuples it takes when null
     * @param list<int>|null          $rivals the places in the list of the earlier candidates that may take one of its
     *                                        strings; every earlier one when null
     */
    public function __construct(
        public readonly array $takes,
        public readonly ?array $keeps = null,
        public readonly ?array $rivals = null,
    ) {}
}
