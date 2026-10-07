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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\Target;

// One pattern that every target accepts and reads its own way:
// PsalmPluginTargetTest says what the trace holds for each.

function short_quantifier(string $s): void
{
    // {,3} is a quantifier from PCRE2 10.43, literal text before it.
    if (preg_match('/(a{,3})/', $s, $m)) {
        /** @psalm-trace $m */
    }
}
