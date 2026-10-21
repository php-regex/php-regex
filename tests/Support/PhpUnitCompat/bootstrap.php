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
 * Loads every feature trait for the PHPUnit already loaded, with no
 * Composer autoloader: the static analysis runs it after PHPUnit's
 * autoloader, then reads each feature as the implementation the suite runs.
 */

namespace PHPRegex\Tests\Support\PhpUnitCompat;

require_once __DIR__.'/PolyfilledMethods.php';
require_once __DIR__.'/IgnorePhpunitDeprecationsPolyfill.php';

IgnorePhpunitDeprecationsPolyfill::load();

foreach (array_keys(PolyfilledMethods::FEATURES) as $trait) {
    PolyfilledMethods::load($trait);
}
