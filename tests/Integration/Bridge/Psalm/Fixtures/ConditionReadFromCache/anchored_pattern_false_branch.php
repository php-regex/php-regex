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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\ConditionReadFromCache;

// A Psalm that reads a preg_match() condition from its cache of assertions
// alone reads, for a pattern anchored at both ends that removes taints, what
// holds on the subject beside what holds on $matches: where the call is
// false, it knows only that one of the two does not hold, and $matches keeps
// the stub's type. preg_match('/^(\d+)$/D', 'x', $m) -> 0, $m = [] (PHP 8.4.28).

function anchored_pattern_false_branch(string $s): void
{
    if (!preg_match('/^(\d+)$/D', $s, $m)) {
        /**
         * @psalm-check-type-exact $m = array<array-key, string>
         * @psalm-trace $m
         */
        return;
    }
}
