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

namespace PHPRegex\Tests\Support;

/**
 * The releases and PHP versions a test gate may name. The ceiling is the
 * newest release a cutoff may pin: a gate above it skips its row on every
 * engine the matrix runs, and no test row would see it run again.
 */
final class PcreGateLimits
{
    public const MAX_KNOWN_RELEASE = '10.49';

    public const KNOWN_RELEASES = ['10.40', '10.41', '10.42', '10.43', '10.44', '10.45', '10.46', '10.47', '10.48', '10.49'];

    public const KNOWN_PHP_VERSIONS = ['8.2', '8.3', '8.4', '8.5'];

    private function __construct() {}
}
