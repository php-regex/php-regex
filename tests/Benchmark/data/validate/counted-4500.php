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
    'pattern' => '/a{4500}/',
    'origin' => 'synthetic',
    'note' => 'A large counted repeat: validation reads the bound without expanding the repeat, and costs no more than a{10}.',
];
