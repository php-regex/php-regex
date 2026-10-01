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

use PhpRegex\Parser\RegexParser;

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Writes RegexParser::CACHE_VERSION from the code that builds an AST.
 *
 * The version is not a number to remember to raise: a cached tree is only
 * worth restoring while the current code would build the same one, so the
 * version is the fingerprint of that code. This recomputes it.
 *
 * Usage: php tests/Tools/update_cache_version.php [--check]
 *
 * With --check it writes nothing and exits non-zero when the constant is out
 * of date, which is what CI asks.
 */

require_once __DIR__.'/../../vendor/autoload.php';
use PhpRegex\Tests\Support\AstFingerprint;

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];

$check = \in_array('--check', $arguments, true);
$path = AstFingerprint::root().'/src/Parser/RegexParser.php';
$fingerprint = AstFingerprint::compute();
$current = RegexParser::CACHE_VERSION;

if ($fingerprint === $current) {
    echo 'Cache version is up to date: ', $current, \PHP_EOL;

    exit(0);
}

if ($check) {
    fwrite(\STDERR, \sprintf(
        'The code that builds the AST changed.%s  cache version: %s%s  fingerprint:   %s%sRun "task cache-version" and commit src/Parser/RegexParser.php.%s',
        \PHP_EOL,
        $current,
        \PHP_EOL,
        $fingerprint,
        \PHP_EOL,
        \PHP_EOL,
    ));

    exit(1);
}

$source = file_get_contents($path);
if (false === $source) {
    fwrite(\STDERR, 'Unable to read '.$path.\PHP_EOL);

    exit(1);
}

$updated = preg_replace(
    '/(public const CACHE_VERSION = )\'[^\']*\';/',
    '$1'.var_export($fingerprint, true).';',
    $source,
    1,
    $replaced,
);

if (null === $updated || 1 !== $replaced) {
    fwrite(\STDERR, 'Unable to find CACHE_VERSION in '.$path.\PHP_EOL);

    exit(1);
}

file_put_contents($path, $updated);

printf('Cache version updated: %s -> %s%s', $current, $fingerprint, \PHP_EOL);
