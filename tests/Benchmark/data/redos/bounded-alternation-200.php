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
    'pattern' => '/^(?:'.implode('|', array_map(static fn (int $i): string => sprintf('k%03d\\d{16}', $i), range(0, 199))).')$/',
    'origin' => 'synthetic',
    'note' => '200 alternatives of 20 states each (tests/Unit/ReDoS/Proven/RedosProofFallbackTest.php).',
    'expect' => 'budget_exceeded',
];
