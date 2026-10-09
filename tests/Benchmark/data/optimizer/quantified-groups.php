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
    'pattern' => '/(?:(?:ab){1}(?:cd){1,1}(?:ef)?(?:gh)*(?:ij)+){1}x{1,}y{0,}z{0,1}/',
    'origin' => 'synthetic',
    'note' => 'Quantifiers the optimizer simplifies: {1}, {1,1}, {1,}, {0,}, {0,1}.',
];
