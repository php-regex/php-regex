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

/*
 * Writes tests/Fixtures/EngineClaims/engine-claims.json: what the library
 * claims the engines of PHP 7.3 to 8.6 do, for
 * tests/Tools/verify_engine_claims.php to check on each of them. Run it on
 * any PHP the library runs on, after a change the fixture test reports.
 */

use PHPRegex\Tests\TestUtils\EngineClaims;

require \dirname(__DIR__, 2).'/vendor/autoload.php';
require \dirname(__DIR__, 2).'/tools/phpunit/vendor/autoload.php';

$fixture = EngineClaims::encode(EngineClaims::build());
$current = is_file(EngineClaims::FIXTURE) ? file_get_contents(EngineClaims::FIXTURE) : null;
if ($fixture === $current) {
    echo "The engine claims are up to date.\n";

    exit(0);
}

file_put_contents(EngineClaims::FIXTURE, $fixture);
echo "The engine claims are written.\n";
