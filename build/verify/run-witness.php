<?php

declare(strict_types=1);

/**
 * Writes the witness of each pattern, one JSON line per pattern holding one
 * witness per target, for the copy of the parser package the autoloader
 * loads: the original (vendor/autoload.php) or the twin.
 *
 *   php build/verify/run-witness.php AUTOLOAD PATTERNS.json [I/N] [--hash] > witnesses.jsonl
 *
 * I/N keeps every Nth pattern from the Ith, to share one list across
 * processes. --hash writes the md5 of each reading instead of the reading,
 * the reference a twin is compared to. PHP 7.4 code: it runs against the
 * twin.
 */
if ($argc < 3) {
    fwrite(\STDERR, "Usage: php build/verify/run-witness.php AUTOLOAD PATTERNS.json [I/N]\n");
    exit(2);
}

require $argv[1];
require __DIR__.'/Witness.php';

use PHPRegex\Build\Verify\Witness;

$list = json_decode((string) file_get_contents($argv[2]), true, 512, \JSON_THROW_ON_ERROR);
$arguments = array_slice($argv, 3);
$hash = in_array('--hash', $arguments, true);
$sliceArgument = array_values(array_diff($arguments, ['--hash']))[0] ?? '0/1';
[$slice, $slices] = array_map('intval', explode('/', $sliceArgument));

foreach ($list['patterns'] as $index => $entry) {
    if ($index % $slices !== $slice) {
        continue;
    }

    $pattern = isset($entry['base64']) ? (string) base64_decode($entry['base64'], true) : $entry['text'];
    $witnesses = [];
    foreach ($list['targets'] as $target) {
        $witness = Witness::of($pattern, $target);
        $witnesses[] = $hash ? Witness::hashes($witness) : $witness;
    }

    echo json_encode(['index' => $index, 'witnesses' => $witnesses], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION), "\n";
}
