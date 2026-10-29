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

namespace PHPRegex\Tests\Benchmark\Support;

/**
 * The "table" group: the corpus subjects CI compares on a pull request
 * against the base commit measured on the same runner, and keeps as an
 * artifact on a push. Each subject carries the advisory assertion below;
 * the noop floor of each class is in the group too, for the peak memory
 * to be read against.
 */
final class Table
{
    /**
     * A subject taking 50 ms or more in the base is marked when it runs
     * more than 30 % slower; a faster one, or a shorter one, never is. The
     * mark advises: CI tolerates the failure and the job stays green.
     */
    public const ADVISORY = 'mode(baseline.time.avg) < 50 milliseconds or mode(variant.time.avg) < mode(baseline.time.avg) +/- 30%';
}
