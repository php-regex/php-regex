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
 * Collects the distinct patterns of a set of PHP source trees into a JSON
 * list, the input of the performance and behaviour comparisons that need
 * more patterns than the tracked corpus fixture holds.
 *
 * Two readings:
 *   calls     (default) the patterns the linter's extractor finds: the
 *             constant first argument of preg_* calls, the assertion and
 *             validation helpers it knows.
 *   literals  every constant string literal the library reads as a
 *             delimited pattern (a delimiter and its closing one, known
 *             flags): the data providers of a test suite, for instance.
 *
 * The output does not depend on the machine: directories are read in byte
 * order, vendor/, node_modules/ and .git/ are skipped, symbolic links are not
 * followed, files over 2 MB are skipped, and each pattern is kept once, where
 * it is first met. A pattern that is not valid UTF-8 is written as
 * {"base64": "..."} so no byte is lost. --sample=N keeps N of them, drawn
 * with a seeded generator (--seed, 1 by default) and listed in the order
 * they were met, the same N for the same input on every PHP.
 *
 * Nothing it reads is tracked by this repository: the patterns of third-party
 * code stay on the machine that holds that code.
 *
 * Usage:
 *   php tests/Tools/harvest_patterns.php [--literals] [--sample=N] [--seed=S] --out=FILE DIR...
 */

namespace PHPRegex\Tests\Tools;

use PHPRegex\Linter\Config\LintExtractorFactory;
use PHPRegex\Linter\Extraction\PhpStringLiteral;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\PatternParser;

require_once __DIR__.'/../../vendor/autoload.php';

const SKIPPED_DIRECTORIES = ['vendor', 'node_modules', '.git'];
const MAX_FILE_SIZE = 2_000_000;

/**
 * @return \Generator<int, string>
 */
function phpFiles(string $directory): \Generator
{
    $entries = scandir($directory);
    if (false === $entries) {
        return;
    }

    sort($entries, \SORT_STRING);
    foreach ($entries as $entry) {
        if ('.' === $entry || '..' === $entry || \in_array($entry, SKIPPED_DIRECTORIES, true)) {
            continue;
        }

        $path = $directory.'/'.$entry;
        if (is_link($path)) {
            continue;
        }

        if (is_dir($path)) {
            yield from phpFiles($path);
        } elseif (str_ends_with($entry, '.php') && filesize($path) <= MAX_FILE_SIZE) {
            yield $path;
        }
    }
}

/**
 * The constant string literals of a PHP file that split into a delimited
 * pattern and its flags.
 *
 * @return list<string>
 */
function literalPatterns(string $file, PcreTarget $target): array
{
    $code = file_get_contents($file);
    if (false === $code) {
        return [];
    }

    $patterns = [];
    foreach (\PhpToken::tokenize($code) as $token) {
        if (\T_CONSTANT_ENCAPSED_STRING !== $token->id) {
            continue;
        }

        $value = PhpStringLiteral::decode($token->text);
        if (null === $value || \strlen($value) < 2) {
            continue;
        }

        try {
            PatternParser::extractPatternAndFlags($value, $target);
        } catch (ParserException) {
            continue;
        }

        $patterns[] = $value;
    }

    return $patterns;
}

/**
 * @param list<string> $patterns
 *
 * @return list<string>
 */
function seededSample(array $patterns, int $size, int $seed): array
{
    if ($size >= \count($patterns)) {
        return $patterns;
    }

    mt_srand($seed, \MT_RAND_MT19937);
    $indexes = array_keys($patterns);
    for ($i = \count($indexes) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$indexes[$i], $indexes[$j]] = [$indexes[$j], $indexes[$i]];
    }

    $kept = \array_slice($indexes, 0, $size);
    sort($kept);

    return array_map(static fn (int $index): string => $patterns[$index], $kept);
}

$options = getopt('', ['literals', 'sample:', 'seed:', 'out:'], $restIndex);
$directories = \array_slice($argv, $restIndex);
$out = $options['out'] ?? null;
if (!\is_string($out) || [] === $directories) {
    fwrite(\STDERR, "Usage: php tests/Tools/harvest_patterns.php [--literals] [--sample=N] [--seed=S] --out=FILE DIR...\n");

    exit(1);
}

$literals = isset($options['literals']);
$sample = isset($options['sample']) && \is_string($options['sample']) ? (int) $options['sample'] : null;
$seed = isset($options['seed']) && \is_string($options['seed']) ? (int) $options['seed'] : 1;

$extractor = (new LintExtractorFactory())->create();
$target = PcreTarget::runtime();
$seen = [];
$files = 0;

foreach ($directories as $directory) {
    $root = realpath($directory);
    if (false === $root || !is_dir($root)) {
        fwrite(\STDERR, \sprintf("Not a directory: %s\n", $directory));

        exit(1);
    }

    foreach (phpFiles($root) as $file) {
        $files++;
        if ($literals) {
            $found = literalPatterns($file, $target);
        } else {
            try {
                $found = array_map(static fn ($occurrence): string => $occurrence->pattern, $extractor->extract([$file], []));
            } catch (\Throwable) {
                // A file the extractor cannot read gives no pattern.
                continue;
            }
        }

        foreach ($found as $pattern) {
            $seen[$pattern] ??= true;
        }
    }
}

$patterns = array_map(strval(...), array_keys($seen));
if (null !== $sample) {
    $patterns = seededSample($patterns, $sample, $seed);
}

$entries = array_map(
    static fn (string $pattern): string|array => mb_check_encoding($pattern, 'UTF-8') ? $pattern : ['base64' => base64_encode($pattern)],
    $patterns,
);

file_put_contents($out, json_encode($entries, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n");
fwrite(\STDERR, \sprintf("%d files read, %d patterns written to %s\n", $files, \count($entries), $out));
