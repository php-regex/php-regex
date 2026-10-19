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
    'pattern' => '/\p{Han}|\p{Hiragana}|\p{Katakana}|\p{Hangul}/u',
    'origin' => 'synthetic',
    'note' => 'Four script properties, each set read from the engine over every code point, cold: the cost of a Unicode class set the first time a process meets it.',
    'expect' => 'proven',
];
