<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Integration\Bridge\PHPStan\Fixtures;

final class MyClass
{
    public function a(): void
    {
        // Refused by the running engine: PHPStan core reports them, the rule stays silent
        preg_match('/foo', 'bar'); // Missing delimiter
        preg_match('/a{2,1}/', 'bar'); // Invalid quantifier
        preg_match('/(a+)+$/', 'bar'); // ReDoS (critical) -> regex.redos
        preg_match('/a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*b/', 'bar'); // ReDoS (medium) -> regex.redos

        // Valid
        preg_match('/a/i', 'bar');
        preg_match('/[0-9]+/', 'bar'); // Optimization suggestion -> regex.optimization
        preg_split('/a/', 'bar');
        preg_grep('/a/', ['bar']);
        preg_filter('/a/', 'b', ['bar']);

        // Dynamic / un-analyzable
        $pattern = '/foo'.random_int(1, 10);
        preg_match($pattern, 'bar'); // Refused by the running engine: ignored
    }
}
