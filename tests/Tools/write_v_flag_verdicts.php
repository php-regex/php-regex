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

use PHPRegex\Tests\Unit\Transpiler\VModeCompatibilityTest;

/*
 * Writes what Node compiles of each value VModeCompatibilityTest lists, as
 * the HTML pattern attribute compiles it: new RegExp(value, "v").
 *
 *   php tests/Tools/write_v_flag_verdicts.php
 *
 * The test reads its "compiles" column against this record: the column is
 * the browser's word, not the checker's. Run it again after adding a value.
 */

require __DIR__.'/../../tools/phpunit/vendor/autoload.php';
require __DIR__.'/../../vendor/autoload.php';

$values = [];
foreach (VModeCompatibilityTest::provideValues() as [$value]) {
    $values[] = $value;
}

$script = 'const values = JSON.parse(require("fs").readFileSync(0, "utf8"));'
    .'process.stdout.write(JSON.stringify(values.map(v => { try { new RegExp(v, "v"); return true; } catch (e) { return false; } })));';
$process = proc_open(['node', '-e', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) {
    fwrite(\STDERR, "Node could not be started.\n");

    exit(1);
}

fwrite($pipes[0], json_encode($values, \JSON_THROW_ON_ERROR));
fclose($pipes[0]);
$verdicts = json_decode((string) stream_get_contents($pipes[1]), true, 512, \JSON_THROW_ON_ERROR);
fclose($pipes[1]);
$version = trim((string) shell_exec('node --version'));
if (0 !== proc_close($process) || !is_array($verdicts) || count($verdicts) !== count($values)) {
    fwrite(\STDERR, "Node gave no verdict for every value.\n");

    exit(1);
}

$compiles = [];
foreach ($values as $index => $value) {
    $compiles[$value] = true === $verdicts[$index];
}

file_put_contents(__DIR__.'/../Fixtures/Transpiler/v-flag-compiles.json', json_encode(['node' => $version, 'compiles' => $compiles], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n");
echo count($compiles), ' values, Node ', $version, ".\n";
