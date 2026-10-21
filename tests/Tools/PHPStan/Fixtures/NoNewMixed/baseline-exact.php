<?php

declare(strict_types=1);

use PHPRegex\Tests\Tools\PHPStan\Fixtures\NoNewMixed\MixedDeclarations;

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

return [
    'MixedDeclarations.php' => [
        MixedDeclarations::class => ['open-options-bag', 1],
        'PHPRegex\Tests\Tools\PHPStan\Fixtures\NoNewMixed\MixedDeclarations::$bag' => ['open-options-bag', 1],
        'PHPRegex\Tests\Tools\PHPStan\Fixtures\NoNewMixed\MixedDeclarations::__construct()' => ['validator-input', 2],
        'PHPRegex\Tests\Tools\PHPStan\Fixtures\NoNewMixed\MixedDeclarations::read()' => ['template-identity', 4],
        'PHPRegex\Tests\Tools\PHPStan\Fixtures\NoNewMixed\SharedDeclarations::shared()' => ['host-contract', 1],
        'PHPRegex\Tests\Tools\PHPStan\Fixtures\NoNewMixed\helper()' => ['callback-opaque', 2],
    ],
];
