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
 * Writes src/Parser/Syntax/PropertyNameTables.php from the property names
 * of PCRE2 releases.
 *
 * Each release lists the names "\p{...}" takes in PRIV(utt) of
 * pcre2_ucptables.c (pcre2_ucptables_inc.h from 10.47): this reads them
 * from the release sources and writes each under the release that added
 * it, with whether it is a script, the one kind "sc:" and "scx:" take.
 *
 * Usage: php tests/Tools/write_property_names.php [--check] <release>...
 *
 * Each <release> is the source tree of a PCRE2 release, every release from
 * 10.40 on, as https://github.com/PCRE2Project/pcre2/releases ships them.
 * With --check it writes nothing and exits non-zero when the file is out of
 * date.
 */

require_once __DIR__.'/../../vendor/autoload.php';

use PHPRegex\Tests\TestUtils\PropertyNameTablesWriter;

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];
$check = in_array('--check', $arguments, true);
$sources = array_values(array_filter(array_slice($arguments, 1), static fn (string $argument): bool => '--check' !== $argument));
if ([] === $sources) {
    fwrite(\STDERR, 'Usage: php tests/Tools/write_property_names.php [--check] <PCRE2 source tree>...'.\PHP_EOL);

    exit(2);
}

$php = PropertyNameTablesWriter::render(PropertyNameTablesWriter::build(array_map(PropertyNameTablesWriter::read(...), $sources)));
$current = is_file(PropertyNameTablesWriter::FILE) ? file_get_contents(PropertyNameTablesWriter::FILE) : false;
if ($php === $current) {
    echo 'PropertyNameTables.php is up to date.', \PHP_EOL;

    exit(0);
}

if ($check) {
    fwrite(\STDERR, 'PropertyNameTables.php differs from the release tables. Run "php tests/Tools/write_property_names.php" with the release sources and commit src/Parser/Syntax/PropertyNameTables.php.'.\PHP_EOL);

    exit(1);
}

file_put_contents(PropertyNameTablesWriter::FILE, $php);

echo 'PropertyNameTables.php written.', \PHP_EOL;
