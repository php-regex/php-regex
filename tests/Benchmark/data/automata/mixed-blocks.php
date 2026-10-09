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
    'pattern' => '/[\\x{0600}-\\x{06FF}\\x{1F600}-\\x{1F64F}]{2,}/u',
    'origin' => 'synthetic',
    'note' => 'Two distant Unicode blocks in one class.',
    'expect' => 'complete',
];
