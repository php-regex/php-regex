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

namespace PHPRegex\Tests\Integration\Bridge\Psalm\Fixtures\Target;

// (?a...) is PCRE2 10.43: PHP 8.2 bundles 10.40, and refuses it. Read under
// Psalm's PHP 8.2 by PsalmPluginTargetMarkerTest.

function target_guards(string $s): void
{
    if (PHP_VERSION_ID >= 80400) {
        preg_match('/(?aD)(x)/', $s);
    }
    preg_match('/(?aD)(y)/', $s);
    if (\function_exists('array_find')) {
        preg_match('/(?aD)(z)/', $s);
    }
    if (\PHP_VERSION_ID >= 80205) {
        preg_match('/(?aD)(w)/', $s);
    }
    if (PHP_VERSION_ID >= 80300 || '' === $s) {
        preg_match('/(?aD)(v)/', $s);
    }
}

function target_early_exit(string $s): void
{
    if (PHP_VERSION_ID < 80400) {
        return;
    }
    preg_match('/(?aD)(u)/', $s);
}

function target_guard_and_marker(string $s): void
{
    if (PHP_VERSION_ID >= 80205) {
        preg_match('/(?aD)(t)/', $s); // @regex-target php<8.2.3
    }
    if (PHP_VERSION_ID >= 80400) {
        preg_match('/(?aD)(s)/', $s); // @regex-target php>=8.2
    }
    if (PHP_VERSION_ID >= 80205) {
        preg_match('/(?aD)(r)/', $s); // @regex-target php>=8.2
    }
}
