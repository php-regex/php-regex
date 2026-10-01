<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * The Rector set that moves code written for 1.3 to the 2.0 names: classes,
 * enum cases and renamed methods. Add it to your rector.php:
 *
 *     $rectorConfig->sets([__DIR__.'/vendor/php-regex/toolkit/Resources/rector/upgrade-2.0.php']);
 *
 * What it cannot change (configuration keys, error codes, offsets, removed
 * classes) is listed in UPGRADE-2.0.md.
 */

use PhpRegex\Toolkit\Upgrade\UpgradeMap;
use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\ClassConstFetch\RenameClassConstFetchRector;
use Rector\Renaming\Rector\MethodCall\RenameMethodRector;
use Rector\Renaming\Rector\Name\RenameClassRector;
use Rector\Renaming\ValueObject\MethodCallRename;
use Rector\Renaming\ValueObject\RenameClassAndConstFetch;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->ruleWithConfiguration(RenameClassRector::class, UpgradeMap::RENAMED);

    // A case is renamed whichever name its enum is written with: Rector may
    // have renamed the class before it reaches the case. The class keeps the
    // name it is written with, so its import is renamed with every other one.
    $cases = [];
    foreach (UpgradeMap::ENUM_CASES as $enum => $renames) {
        $new = UpgradeMap::RENAMED[$enum];
        foreach ($renames as $from => $to) {
            $cases[] = new RenameClassAndConstFetch($enum, $from, $enum, $to);
            $cases[] = new RenameClassAndConstFetch($new, $from, $new, $to);
        }
    }
    $rectorConfig->ruleWithConfiguration(RenameClassConstFetchRector::class, $cases);

    $methods = [];
    foreach (UpgradeMap::METHODS as [$class, $from, $to]) {
        $methods[] = new MethodCallRename($class, $from, $to);
        $methods[] = new MethodCallRename(UpgradeMap::RENAMED[$class], $from, $to);
    }
    $rectorConfig->ruleWithConfiguration(RenameMethodRector::class, $methods);
};
