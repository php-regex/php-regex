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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan\Fixtures;

/**
 * "(?a)" needs PCRE2 10.43: PHP 8.2 (10.40) and 8.3 (10.42) refuse it.
 * "\K" in a lookbehind: PHP 8.5 refuses it. The range is PHP 8.2 to 8.5.
 */
final class TargetMarkerFixture
{
    public function run(string $s): void
    {
        preg_match('/(?a)\d+/', $s); // @regex-target php>=8.4
        preg_match('/(?a)\d+/', $s);
        // @regex-target-next-line pcre>=10.43
        preg_match('/(?a)\d+/', $s);
        preg_match('/(?a)\d+/', $s); // @regex-target php>=9.0
        preg_match('/(?a)\d+/', $s); // @regex-target php>=eight
        if (PHP_VERSION_ID < 80500) {
            preg_match('/(?<=a\Kb)c/', $s); // @regex-target php>=8.4
        }
    }

    /**
     * @regex-target php<8.5
     */
    public function keep(string $s): void
    {
        preg_match('/(?<=a\Kb)c/', $s);
    }
}
