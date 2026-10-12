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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\Newline;

// $ and \Z match before a final "\n" too: preg_match('/^(?:yes|no)$/', "no\n") -> 1,
// preg_match('/^(?:yes|no)\Z/', "no\n") -> 1, preg_match('/^(?:yes|no)$/', "no\n\n") -> 0;
// under /m, $ matches before any "\n": preg_match('/^(?:yes|no)$/m', "x\nno\ny") -> 1 (PHP 8.4.26).

function dollar(string $s): void
{
    if (preg_match('/^(?:yes|no)$/', $s)) {
        /** @psalm-trace $s */
    }
}

function end_or_final_newline(string $s): void
{
    if (preg_match('/^(?:yes|no)\Z/', $s)) {
        /** @psalm-trace $s */
    }
}

function multiline(string $s): void
{
    if (preg_match('/^(?:yes|no)$/m', $s)) {
        /** @psalm-trace $s */
    }
}
