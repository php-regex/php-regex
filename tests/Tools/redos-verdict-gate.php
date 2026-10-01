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

use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosProof;

/*
 * The release check of the ReDoS verdict's cost: every pattern of the
 * repository corpus analysed once to warm the caches, then each one warmed
 * again and timed, the theoretical way PHPStan and the linter run it.
 *
 * Usage: php -d xdebug.mode=off tests/Tools/redos-verdict-gate.php [--max-p99=5]
 *
 * Prints the 50th and 99th percentiles of the per-pattern time, the share of
 * each proof and of the patterns over the analysis budget, and exits 1 when
 * the 99th percentile is above the limit (5 ms unless given).
 */

require_once __DIR__.'/../../vendor/autoload.php';

$argv = $_SERVER['argv'] ?? [];
$limit = 5.0;
foreach (\is_array($argv) ? \array_slice($argv, 1) : [] as $argument) {
    if (\is_string($argument) && 1 === preg_match('/^--max-p99=(\d+(?:\.\d+)?)$/', $argument, $match)) {
        $limit = (float) $match[1];
    }
}

$corpus = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/Corpus/lint-expectations.json'), true, 512, \JSON_THROW_ON_ERROR);
if (!is_array($corpus)) {
    fwrite(\STDERR, "The corpus is not a list.\n");

    exit(2);
}

$patterns = [];
foreach ($corpus as $entry) {
    if (is_array($entry) && is_string($entry['pattern'] ?? null)) {
        $patterns[] = $entry['pattern'];
    }
}

$analyzer = new RedosAnalyzer(RegexParser::create());

// The warm-up pass fills the class-set caches every pattern shares. Each
// pattern is then analysed once more right before it is timed: the caches
// keyed by pattern hold a thousand entries, fewer than the corpus, so the
// first pass alone would leave half of them cold.
foreach ($patterns as $pattern) {
    $analyzer->analyze($pattern, null, RedosMode::Theoretical);
}

$times = [];
$proofs = [];
foreach ($patterns as $pattern) {
    $analyzer->analyze($pattern, null, RedosMode::Theoretical);
    $start = hrtime(true);
    $analysis = $analyzer->analyze($pattern, null, RedosMode::Theoretical);
    $times[] = (hrtime(true) - $start) / 1_000_000;
    $proofs[$analysis->proof->value] = ($proofs[$analysis->proof->value] ?? 0) + 1;
}

sort($times);
$count = count($times);
if (0 === $count) {
    fwrite(\STDERR, "The corpus holds no pattern.\n");

    exit(2);
}

$percentile = static fn (float $rank): float => $times[(int) floor(($count - 1) * $rank)];
$p50 = $percentile(0.5);
$p99 = $percentile(0.99);

printf("patterns: %d\n", $count);
printf("p50: %.3f ms\n", $p50);
printf("p99: %.3f ms (limit %.1f ms)\n", $p99, $limit);
printf("max: %.3f ms\n", $times[$count - 1]);
foreach (RedosProof::cases() as $proof) {
    $seen = $proofs[$proof->value] ?? 0;
    printf("%s: %d (%.2f%%)\n", $proof->value, $seen, 100 * $seen / $count);
}

if ($p99 > $limit) {
    printf("FAIL: the 99th percentile is above %.1f ms.\n", $limit);

    exit(1);
}

echo "OK\n";
