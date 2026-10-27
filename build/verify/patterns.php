<?php

declare(strict_types=1);

/**
 * Writes the patterns and the targets the twin is compared on, in a stable
 * order: every pattern the repository's corpora hold (the corpus fixture,
 * the capture-shape parity cases, the PCRE and php-src pattern lists, the
 * twin's own corpus) and every delimited pattern written as a literal in
 * the test suite; each target pins the PHP version, the PCRE2 release and
 * the locale, so no reading depends on the engine of the machine.
 *
 * Runs on the original, PHP 8.x, from the repository root:
 *   php build/verify/patterns.php > patterns.json
 */
require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/../corpus.php';

use PHPRegex\Parser\PcreTarget;

$root = dirname(__DIR__, 2);
$patterns = [];

foreach (corpus() as $body) {
    $patterns[] = '/'.$body.'/';
}

foreach (json_decode((string) file_get_contents($root.'/tests/Fixtures/Corpus/lint-expectations.json'), true, 512, \JSON_THROW_ON_ERROR) as $row) {
    $patterns[] = $row['pattern'];
}

foreach (require $root.'/tests/Fixtures/CaptureShapeParity/cases.php' as $row) {
    $patterns[] = $row['pattern'];
}

foreach (require $root.'/tests/Fixtures/php_pcre_comprehensive.php' as $row) {
    $patterns[] = $row['pattern'];
}

foreach (require $root.'/tests/Fixtures/pcre_patterns.php' as $pattern) {
    $patterns[] = $pattern;
}

$harvest = tempnam(sys_get_temp_dir(), 'twin-patterns');
passthru(escapeshellarg(\PHP_BINARY).' '.escapeshellarg($root.'/tests/Tools/harvest_patterns.php').' --literals --out='.escapeshellarg($harvest).' '.escapeshellarg($root.'/tests').' > /dev/null', $status);
if (0 !== $status) {
    fwrite(\STDERR, "patterns.php: harvesting the test literals failed\n");
    exit(1);
}

foreach (json_decode((string) file_get_contents($harvest), true, 512, \JSON_THROW_ON_ERROR) as $entry) {
    $patterns[] = \is_array($entry) ? base64_decode($entry['base64'], true) : $entry;
}

unlink($harvest);

$patterns = array_values(array_unique(array_filter($patterns, 'is_string')));
sort($patterns, \SORT_STRING);

// the PHP versions the twin serves, each with the PCRE2 it bundles, and the
// newest release on the newest PHP
$targets = [];
foreach ([70400, 80000, 80100, 80400, 80600] as $php) {
    $targets[] = ['php' => $php, 'pcre' => explode('/', PcreTarget::bundledWith($php)->key())[1], 'locale' => 'C'];
}

$targets[] = ['php' => 80600, 'pcre' => '10.49', 'locale' => 'C'];
$targets = array_values(array_unique($targets, \SORT_REGULAR));

echo json_encode([
    'targets' => $targets,
    'patterns' => array_map(static fn (string $pattern): array => 1 === preg_match('//u', $pattern) ? ['text' => $pattern] : ['base64' => base64_encode($pattern)], $patterns),
], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT), "\n";
