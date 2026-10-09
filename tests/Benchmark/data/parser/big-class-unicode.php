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
    'pattern' => '/['.implode('', array_map(static fn (int $cp): string => sprintf('\\x{%04X}-\\x{%04X}', $cp, $cp + 8), range(0x0400, 0x2000, 16))).'\\p{L}\\p{Nd}\\p{Han}]+/u',
    'origin' => 'synthetic',
    'note' => 'A class of ~450 code-point ranges and Unicode properties, under /u.',
];
