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
    'pattern' => '/[\\x{0600}-\\x{06FF}]{2,}/u',
    'origin' => 'synthetic',
    'note' => 'A Unicode block: the effective alphabet keeps the DFA small.',
    'expect' => 'complete',
];
