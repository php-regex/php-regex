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
    'pattern' => '/(?x)'.str_repeat("  a b c # a comment that runs to the end of the line\n", 1000).'/',
    'origin' => 'synthetic',
    'note' => 'Extended mode: whitespace and # comments the lexer skips, line after line.',
];
