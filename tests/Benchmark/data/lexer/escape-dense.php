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
    'pattern' => '/'.str_repeat('\\x41\\d\\w\\s\\.\\/\\t\\x{263A}', 500).'/u',
    'origin' => 'synthetic',
    'note' => 'Every token an escape, under /u: the escape scanner on its slowest path.',
];
