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

final class ReplacementGroupEdgeFixture
{
    public function calls(string $subject, bool $flag): void
    {
        preg_replace('/(a/', '$2', $subject);
        preg_replace('/(?r)(a)/', '$2', $subject);
        preg_replace(['/(a)/', '/b/'], $flag ? ['$1', 'x'] : ['$2', 'x'], $subject);
        preg_replace(subject: $subject, replacement: '$2', pattern: '/(a)/');
        $patterns = ['/(a)/'];
        $replacements = ['$1'];
        if ($flag) {
            $patterns[5] = '/b/';
        } else {
            $replacements[5] = '$1';
        }
        preg_replace($patterns, $replacements, $subject);
        preg_replace('/(a)/', ['$2'], $subject);
    }
}
