<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use PhpRegex\Linter\Config\LintConfigSchema;

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Writes regex.schema.json from LintConfigSchema::definition().
 *
 * The lint command validates regex.json against the PHP definition; the
 * file at the repository root is what editors read. It is written from the
 * definition, never edited by hand.
 *
 * Usage: php tests/Tools/write_config_schema.php [--check]
 *
 * With --check it writes nothing and exits non-zero when the file is out of
 * date.
 */

require_once __DIR__.'/../../vendor/autoload.php';

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];

$check = \in_array('--check', $arguments, true);
$path = dirname(__DIR__, 2).'/regex.schema.json';
$json = json_encode(
    LintConfigSchema::definition(),
    \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
);
// Two spaces per level, as the file has always been indented.
$expected = preg_replace_callback('/^(?: {4})+/m', static fn (array $indent): string => str_repeat(' ', intdiv(\strlen($indent[0]), 2)), $json)."\n";
$current = is_file($path) ? file_get_contents($path) : false;

if ($expected === $current) {
    echo 'regex.schema.json is up to date.', \PHP_EOL;

    exit(0);
}

if ($check) {
    fwrite(\STDERR, 'regex.schema.json differs from LintConfigSchema::definition(). Run "php tests/Tools/write_config_schema.php" and commit regex.schema.json.'.\PHP_EOL);

    exit(1);
}

file_put_contents($path, $expected);

echo 'regex.schema.json written.', \PHP_EOL;
