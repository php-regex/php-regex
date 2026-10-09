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
    'pattern' => '/'.str_repeat('[aaaa][0-90-9][a-zA-Za-z]', 200).'/',
    'origin' => 'synthetic',
    'note' => 'Classes with repeated members and ranges, 200 times over.',
];
