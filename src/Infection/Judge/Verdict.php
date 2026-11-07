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

namespace PHPRegex\Infection\Judge;

/**
 * Whether two patterns were proven alike for a call, and the subjects the
 * automata found that tell them apart.
 *
 * @internal
 */
final class Verdict
{
    /**
     * @param bool|null    $alike    true when proven alike, false when proven apart, null when unproven
     * @param list<string> $subjects the subjects found that tell them apart, shortest first
     */
    public function __construct(public readonly ?bool $alike, public readonly array $subjects = []) {}
}
