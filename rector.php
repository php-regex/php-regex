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

use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Php73\Rector\FuncCall\RegexDashEscapeRector;
use Rector\PHPUnit\CodeQuality\Rector\ClassMethod\AddInstanceofAssertForNullableInstanceRector;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\Privatization\Rector\Class_\FinalizeTestCaseClassRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\ValueObject\PhpVersion;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/bin',
    ]);

    // The core packages and the PHPStan, Psalm and Rector bridges run on
    // PHP 8.1: no rule may write syntax newer than it.
    $rectorConfig->phpVersion(PhpVersion::PHP_81);
    $rectorConfig->importNames();
    $rectorConfig->importShortClasses(false);
    $rectorConfig->removeUnusedImports();
    $rectorConfig->indent(' ', 4);
    $rectorConfig->cacheDirectory('.cache/rector/');
    $rectorConfig->parallel();
    $rectorConfig->editorUrl('phpstorm://open?file=%file%&line=%line%');

    $rectorConfig->sets([
        LevelSetList::UP_TO_PHP_81,

        // rector-phpunit dropped its per-version sets: COMPOSER_BASED reads
        // the installed PHPUnit and applies what suits it.
        PHPUnitSetList::COMPOSER_BASED,
        PHPUnitSetList::ANNOTATIONS_TO_ATTRIBUTES,
        PHPUnitSetList::PHPUNIT_CODE_QUALITY,
    ]);

    $rectorConfig->skip([
        RegexDashEscapeRector::class,
        FinalizeTestCaseClassRector::class,
        AddInstanceofAssertForNullableInstanceRector::class,
        __DIR__.'/tests/Fixtures',
        __DIR__.'/tests/Benchmark/Fixtures',
        // Psalm reads these as they are written
        __DIR__.'/tests/Integration/Bridge/Psalm/Fixtures',
        // Laravel bridge tests use facades which must be called statically
        __DIR__.'/tests/Integration/Bridge/Laravel',
        __DIR__.'/src/Laravel',
        // The upgrade map names classes in full: imported, two CharSet would collide
        __DIR__.'/src/Toolkit/Upgrade/UpgradeMap.php',
        // The CLI package runs without the language server: it names the
        // server's class, never imports it
        StringClassNameToClassConstantRector::class => [__DIR__.'/src/Cli/Command/LspCommand.php'],
    ]);
};
