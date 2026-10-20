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
 * Checks one package installed alone in a bare project, with a class of its
 * own: the class must load through the project's autoloader from the
 * package's installed copy, not from a sibling or the monorepo, and answer
 * the least call tests/TestUtils/PackageSmokeChecks.php names for it. Each
 * binary the installed manifest declares must then run "--version" through
 * the proxy Composer wrote in vendor/bin.
 *
 *     php tests/Tools/package_smoke_check.php <package> <project-dir>
 *
 * The package is a directory name under src/ ("Parser", "Symfony"); the
 * project directory holds the Composer install of that package. Exits 1 on
 * the first check that fails, 2 on a wrong argument.
 */

namespace PHPRegex\Tests\Tools;

use Composer\InstalledVersions;
use PHPRegex\Tests\TestUtils\PackageSmokeChecks;

require __DIR__.'/../TestUtils/PackageSmokeChecks.php';

$package = $argv[1] ?? '';
$project = $argv[2] ?? '';
$checks = PackageSmokeChecks::all();
if (!isset($checks[$package]) || '' === $project || !is_file($project.'/vendor/autoload.php')) {
    fwrite(\STDERR, "usage: php tests/Tools/package_smoke_check.php <package> <project-dir>\n");
    fwrite(\STDERR, '       the package is one of '.implode(', ', array_keys($checks)).";\n");
    fwrite(\STDERR, "       the directory holds a Composer install of that package.\n");
    exit(2);
}

$fail = static function (string $message): never {
    fwrite(\STDERR, $message."\n");
    exit(1);
};

$manifest = json_decode((string) file_get_contents(__DIR__.'/../../src/'.$package.'/composer.json'), true);
$name = \is_array($manifest) && \is_string($manifest['name'] ?? null) ? $manifest['name'] : $fail('src/'.$package.'/composer.json names no package.');

require $project.'/vendor/autoload.php';

if (!InstalledVersions::isInstalled($name)) {
    $fail($name.' is not installed in '.$project.'.');
}
$installed = realpath((string) InstalledVersions::getInstallPath($name));
if (false === $installed) {
    $fail($name.' has no install path in '.$project.'.');
}

[$class, $check] = $checks[$package];
if (!class_exists($class)) {
    $fail($class.' does not load through the autoloader of '.$project.'.');
}
$file = (string) (new \ReflectionClass($class))->getFileName();
if (!str_starts_with($file, $installed.\DIRECTORY_SEPARATOR)) {
    $fail($class.' loads from '.$file.', not from the installed copy of '.$name.' in '.$installed.'.');
}
if (true !== $check()) {
    $fail($class.' loads from the installed copy of '.$name.', but does not answer its check.');
}
echo $name.': '.$class." loads from the installed package and answers.\n";

$installedManifest = json_decode((string) file_get_contents($installed.'/composer.json'), true);
$binaries = \is_array($installedManifest) && \is_array($installedManifest['bin'] ?? null) ? $installedManifest['bin'] : [];
foreach (array_filter($binaries, \is_string(...)) as $binary) {
    $binary = basename($binary);
    $proxy = $project.'/vendor/bin/'.$binary;
    $process = proc_open([\PHP_BINARY, $proxy, '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!\is_resource($process)) {
        $fail('vendor/bin/'.$binary.' does not start.');
    }
    $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (0 !== proc_close($process)) {
        $fail('vendor/bin/'.$binary.' --version failed: '.$output);
    }
    echo $name.': vendor/bin/'.$binary." --version runs.\n";
}
