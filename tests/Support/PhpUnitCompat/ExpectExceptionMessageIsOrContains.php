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

namespace PHPRegex\Tests\Support\PhpUnitCompat;

// The trait of Native\ or Polyfill\ the running PHPUnit calls for, under this name.
PolyfilledMethods::load(ExpectExceptionMessageIsOrContains::class);
