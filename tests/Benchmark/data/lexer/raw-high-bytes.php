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
    'pattern' => "/[\x80-\xff]+\xff\xfe\xc3(?:\xa9|\xa8)+/",
    'origin' => 'synthetic',
    'note' => 'Raw bytes that are not UTF-8, without /u: read byte by byte.',
];
