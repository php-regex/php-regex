<?php

declare(strict_types=1);

/**
 * Prints one reading of the witness of one pattern for one target, as read,
 * to see what a difference compare.php reports is made of.
 *
 *   php build/verify/show.php AUTOLOAD PATTERNS.json INDEX TARGET READING
 */
require $argv[1];
require __DIR__.'/Witness.php';

use PHPRegex\Build\Verify\Witness;

$list = json_decode((string) file_get_contents($argv[2]), true, 512, \JSON_THROW_ON_ERROR);
$entry = $list['patterns'][(int) $argv[3]];
$pattern = isset($entry['base64']) ? (string) base64_decode($entry['base64'], true) : $entry['text'];
$witness = Witness::of($pattern, $list['targets'][(int) $argv[4]]);
echo json_encode($witness[$argv[5]] ?? null, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), "\n";
