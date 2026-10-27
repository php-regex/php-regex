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
 * Writes src/Parser/Validation/PropertyByteTables.php from pcre2test of
 * PCRE2 releases.
 *
 * From 10.45 a class sets in its map the characters below 256 each of its
 * properties matches, which decides whether an extended class, or a class
 * of every character past 255, matches every character. This asks
 * pcre2test of each release, one match per character and property name,
 * keeps one table for each run of releases that match the same, and writes
 * them.
 *
 * Usage: php tests/Tools/write_property_bytes.php [--check] [--output=<file>] <pcre2test>...
 *
 * Each <pcre2test> is the pcre2test of a PCRE2 release, every release from
 * 10.45 on, each the release tarball of https://github.com/PCRE2Project/pcre2/releases
 * extracted and built ("./configure --disable-shared --enable-unicode &&
 * make pcre2test"). With --check it writes nothing and exits non-zero when
 * the file is out of date; --output writes another file than the committed
 * one.
 */

require_once __DIR__.'/../../vendor/autoload.php';

use PHPRegex\Tests\TestUtils\PropertyByteTablesWriter;

$arguments = array_values(array_filter(is_array($_SERVER['argv'] ?? null) ? array_slice($_SERVER['argv'], 1) : [], is_string(...)));
$check = in_array('--check', $arguments, true);
$output = PropertyByteTablesWriter::FILE;
$binaries = [];
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $output = substr($argument, strlen('--output='));
    } elseif ('--check' !== $argument) {
        $binaries[] = $argument;
    }
}

if ([] === $binaries) {
    fwrite(\STDERR, 'Usage: php tests/Tools/write_property_bytes.php [--check] [--output=<file>] <pcre2test>...'.\PHP_EOL);

    exit(2);
}

$php = PropertyByteTablesWriter::render(PropertyByteTablesWriter::build(array_map(PropertyByteTablesWriter::probe(...), $binaries)));
$current = is_file($output) ? file_get_contents($output) : false;
if ($php === $current) {
    echo 'PropertyByteTables.php is up to date.', \PHP_EOL;

    exit(0);
}

if ($check) {
    fwrite(\STDERR, 'PropertyByteTables.php differs from what pcre2test of the releases matches. Run "php tests/Tools/write_property_bytes.php" with pcre2test of each release and commit src/Parser/Validation/PropertyByteTables.php.'.\PHP_EOL);

    exit(1);
}

file_put_contents($output, $php);

echo 'PropertyByteTables.php written.', \PHP_EOL;
