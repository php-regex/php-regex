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

namespace PHPSTORM_META;

expectedArguments(
    \PHPRegex\Tests\Support\PcreGates::requiresPcre2(),
    0,
    \PHPRegex\Tests\Support\PcreGateLimits::KNOWN_RELEASES
);

expectedArguments(
    \PHPRegex\Tests\Support\PcreGates::requiresPhp(),
    0,
    \PHPRegex\Tests\Support\PcreGateLimits::KNOWN_PHP_VERSIONS
);

expectedArguments(
    \PHPRegex\Tests\Support\PcreGates::forbidsPhp(),
    0,
    \PHPRegex\Tests\Support\PcreGateLimits::KNOWN_PHP_VERSIONS
);
