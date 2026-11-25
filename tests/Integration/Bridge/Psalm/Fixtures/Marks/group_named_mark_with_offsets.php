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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\Marks;

// preg_match('/(?<MARK>a)(*MARK:m)/', 'a', $n, PREG_OFFSET_CAPTURE) -> [['a', 0], 'MARK' => 'm', ['a', 0]]

function group_named_mark_beside_a_verb_with_offsets(string $s): void
{
    if (preg_match('/(?<MARK>a)(*MARK:m)/', $s, $n, \PREG_OFFSET_CAPTURE)) {
        /** @psalm-trace $n */
    }
}
