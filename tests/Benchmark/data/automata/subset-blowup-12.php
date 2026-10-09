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

return [
    'pattern' => '/(a|b)*a(a|b){12}/',
    'origin' => 'synthetic',
    'note' => 'Subset construction at 8,193 states, just under the default DFA limit.',
    'expect' => 'complete',
];
