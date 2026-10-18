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

/**
 * The TestCase methods of the newest PHPUnit the suite runs on, on every
 * PHPUnit it runs on: a test case that calls one of them uses this trait.
 * Where the running PHPUnit has the method, the feature trait is empty and
 * PHPUnit's own runs; elsewhere the feature trait builds it on the older API.
 *
 * To polyfill one more method:
 * 1. write the trait twice, empty in Native\, with the method in Polyfill\, plus a file of that name here that calls PolyfilledMethods::load();
 * 2. add it to PolyfilledMethods::FEATURES, with the release that added the method and the methods it replaces;
 * 3. use it below, and move the tests off the replaced methods.
 */
trait PhpUnitForwardCompat
{
    use ExpectExceptionMessageIsOrContains;
}
