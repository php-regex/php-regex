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
    'pattern' => '/'.str_repeat('(', 250).'a'.str_repeat(')', 250).'/',
    'origin' => 'synthetic',
    'note' => '250 nested capturing groups, the deepest PCRE compiles (251 fails: parentheses are too deeply nested).',
];
