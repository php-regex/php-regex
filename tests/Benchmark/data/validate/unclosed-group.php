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
    'pattern' => '/(abc|def/',
    'origin' => 'synthetic',
    'note' => 'An unclosed group: the error path of validation.',
    'invalid' => true,
];
