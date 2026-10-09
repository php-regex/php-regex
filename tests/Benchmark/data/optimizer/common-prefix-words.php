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
    'pattern' => '/(?:'.implode('|', array_map(static fn (int $i): string => 'prefix_shared_'.$i, range(1, 300))).')/',
    'origin' => 'synthetic',
    'note' => '300 alternatives sharing a long prefix: prefix factoring.',
];
