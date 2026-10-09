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
    'pattern' => '#^/(?<locale>[a-z]{2})/blog/(?<year>\\d{4})/(?<slug>[\\w-]+)(?:/page-(?<page>\\d+))?/?$#i',
    'origin' => 'synthetic',
    'note' => 'A route pattern under a # delimiter, so slashes stay unescaped.',
];
