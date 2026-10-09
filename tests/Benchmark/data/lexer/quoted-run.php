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
    'pattern' => '/\\Q'.str_repeat('.*+?[](){}|^$', 2000).'\\E/',
    'origin' => 'synthetic',
    'note' => 'A long \\Q...\\E run of metacharacters the lexer reads as literals.',
];
