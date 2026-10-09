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
    'pattern' => '/(?:a|a){20}b/',
    'origin' => 'synthetic',
    'note' => 'Ambiguous alternation under a counted repeat (tests/Unit/Automata/TrivialMatchClassifierTest.php).',
    'expect' => 'complete',
];
