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
 * PHPUnit knows #[IgnorePhpunitDeprecations] from 11 on: a run with an
 * older one fatals while it reads the attributes of a test class that
 * names it, before a test runs. Declaring the attribute under its own
 * name when PHPUnit does not ship it lets every version load the class;
 * PHPUnit 10 never asks what it means.
 */
final class IgnorePhpunitDeprecationsPolyfill
{
    public static function load(): void
    {
        if (\class_exists('PHPUnit\\Framework\\Attributes\\IgnorePhpunitDeprecations')) {
            return;
        }

        eval('namespace PHPUnit\\Framework\\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class IgnorePhpunitDeprecations
{
}
');
    }
}
