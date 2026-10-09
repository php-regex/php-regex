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
    'pattern' => '/'.str_repeat('abcdefghij', 2000).'/',
    'origin' => 'synthetic',
    'note' => 'A 20,000-byte literal: one token per byte, the longest stream the lexer builds from plain text (50,000 bytes is too large for PCRE).',
];
