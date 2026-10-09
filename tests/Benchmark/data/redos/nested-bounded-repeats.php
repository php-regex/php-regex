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
    'pattern' => '/^(?:(?:a{16}){16}){16}$/',
    'origin' => 'synthetic',
    'note' => '4,096 unrolled states: past the prover budget (tests/Unit/ReDoS/Proven/RedosProofFallbackTest.php).',
    'expect' => 'budget_exceeded',
];
