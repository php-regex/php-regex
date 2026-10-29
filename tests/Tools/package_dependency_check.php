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
 * Checks the manifest of one package installed alone in a bare project
 * against what its installed sources use, with the dependency analyser:
 * every class, function and extension the sources reach must come from
 * the manifest's require, or from a package or extension it suggests.
 *
 *     php tests/Tools/package_dependency_check.php <package> <project-dir>
 *
 * The package is a directory name under src/ ("Parser", "Symfony"); the
 * project directory holds the Composer install of that package, and is a
 * throwaway: the check writes the analyser's files into it and requires
 * what the manifest suggests beside the package. Exits with the analyser's
 * code (1 on a finding), 2 on a wrong argument.
 */

namespace PHPRegex\Tests\Tools;

use PHPRegex\Tests\TestUtils\PackageDependencyConfig;

require __DIR__.'/../TestUtils/PackageDependencyConfig.php';

$package = $argv[1] ?? '';
$project = $argv[2] ?? '';
$source = __DIR__.'/../../src/'.$package.'/composer.json';
if ('' === $package || !is_file($source) || !is_file($project.'/vendor/autoload.php')) {
    fwrite(\STDERR, "usage: php tests/Tools/package_dependency_check.php <package> <project-dir>\n");
    fwrite(\STDERR, "       the package is a directory under src/ with a composer.json;\n");
    fwrite(\STDERR, "       the directory holds a Composer install of that package.\n");
    exit(2);
}
// absolute from here: the analyser runs from inside the project
$project = (string) realpath($project);

$fail = static function (string $message): never {
    fwrite(\STDERR, $message."\n");
    exit(1);
};

$analyser = __DIR__.'/../../tools/dep-analyser/vendor/bin/composer-dependency-analyser';
if (!is_file($analyser)) {
    $fail('The dependency analyser is not installed: composer install -d tools/dep-analyser.');
}

$manifest = json_decode((string) file_get_contents($source), true);
$name = \is_array($manifest) && \is_string($manifest['name'] ?? null) ? $manifest['name'] : $fail('src/'.$package.'/composer.json names no package.');

// the installed copy, as Packagist serves it, and its manifest beside the
// project's vendor: the analyser resolves vendor/ next to the manifest it
// is given
$installed = 'vendor/'.$name;
$installedManifest = json_decode((string) @file_get_contents($project.'/'.$installed.'/composer.json'), true);
if (!\is_array($installedManifest)) {
    $fail($name.' is not installed in '.$project.'.');
}
copy($project.'/'.$installed.'/composer.json', $project.'/package.json') || $fail('Could not copy the manifest of '.$name.' beside the project.');
file_put_contents($project.'/dependency-analyser.php', PackageDependencyConfig::configuration($installedManifest, $installed));

// what the manifest suggests, and what a phar provides at runtime, is
// installed beside the package for the analysis to read it: known and
// allowed, rather than unknown
$helpers = array_map(static fn (string $helper): string => $helper.':*', PackageDependencyConfig::helpers($installedManifest));
if ([] !== $helpers) {
    exec(implode(' ', array_map(escapeshellarg(...), ['composer', 'require', '-d', $project, '--dev', '--no-interaction', '--no-progress', ...$helpers])).' 2>&1', $lines, $code);
    0 === $code || $fail('Could not install the helpers of '.$name.': '.implode("\n", $lines));
}

chdir($project) || $fail('Could not enter '.$project.'.');
passthru(implode(' ', array_map(escapeshellarg(...), [\PHP_BINARY, $analyser, '--composer-json='.$project.'/package.json', '--config='.$project.'/dependency-analyser.php'])).' 2>&1', $code);
exit($code);
