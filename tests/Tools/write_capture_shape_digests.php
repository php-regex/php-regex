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
 * Records the digest of every string CaptureShape writes for each pattern of
 * the parity corpus, with the analysis version they describe. Run it when
 * CaptureShapeAnalyzer::ANALYSIS_VERSION rises, never to make a refactoring
 * pass.
 */

use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Tests\Support\CaptureShapeDigest;

require \dirname(__DIR__, 2).'/vendor/autoload.php';

$lines = [];
foreach (CaptureShapeDigest::patterns() as $pattern) {
    $lines[] = '    '.var_export($pattern, true).' => '.var_export(CaptureShapeDigest::of($pattern), true).',';
}

$header = <<<'PHP_WRAP'
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
     * Written by tests/Tools/write_capture_shape_digests.php: the digest of
     * every string CaptureShape writes for each pattern of the parity corpus.
     *
     * @return array{version: string, digests: array<string, string>}
     */

    PHP_WRAP;

file_put_contents(
    CaptureShapeDigest::FILE,
    $header."return [\n    'version' => ".var_export(CaptureShapeAnalyzer::ANALYSIS_VERSION, true).",\n    'digests' => [\n".implode("\n", array_map(static fn (string $line): string => '    '.$line, $lines))."\n    ],\n];\n",
);

echo \count($lines)." digests written.\n";
