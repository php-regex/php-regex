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
 * Writes the tables UpgradeMap holds into UPGRADE-2.0.md, between their
 * markers.
 */

use PHPRegex\Tests\Support\UpgradeGuide;

require \dirname(__DIR__, 2).'/vendor/autoload.php';

$path = \dirname(__DIR__, 2).'/UPGRADE-2.0.md';
$guide = (string) file_get_contents($path);
$start = strpos($guide, UpgradeGuide::START);
$end = strpos($guide, UpgradeGuide::END);
if (false === $start || false === $end) {
    fwrite(\STDERR, "UPGRADE-2.0.md has no upgrade-map markers.\n");

    exit(1);
}

$updated = substr($guide, 0, $start).UpgradeGuide::render().substr($guide, $end + \strlen(UpgradeGuide::END));
if ($updated === $guide) {
    echo "UPGRADE-2.0.md is up to date.\n";

    exit(0);
}

file_put_contents($path, $updated);
echo "UPGRADE-2.0.md updated.\n";
