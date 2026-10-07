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

final class ReplacementGroupFixture
{
    public function undefinedGroups(string $subject, bool $flag): void
    {
        preg_replace('/(a)/', '$2', $subject);
        preg_replace('/(a)/', '$10', $subject);
        preg_replace('/(?<name>a)/', '${name}', $subject);
        preg_replace('/(a)/', '${name}', $subject);
        preg_replace('/(a)/', '\2', $subject);
        preg_replace('/(a)/', "\\2", $subject);
        preg_replace('/(a)/', '\\\\$2', $subject);
        preg_replace('/(a)/', '${2}', $subject);
        preg_filter('/(a)/', '$2', $subject);
        preg_replace(['/(a)/', '/b/'], ['$1', '$1'], $subject);
        preg_replace(['/(a)/', '/b/'], '$1', $subject);
        preg_replace('/(a)/', $flag ? '$1' : '$2', $subject);
        preg_replace($flag ? '/(a)/' : '/b/', '$1', $subject);
        preg_replace('/(?|(a)|(b))/', '$2', $subject);
    }

    public function definedGroups(string $subject, string $dynamic): void
    {
        preg_replace('/(a)/', '$0 \0 ${0} $1 ${1} \1 ${1}0 $01', $subject);
        preg_replace('/(a)/', "\1", $subject);
        preg_replace('/(a)/', '\$2', $subject);
        preg_replace('/(a)/', '\\\\2', $subject);
        preg_replace('/(a)/', 'cost: $ and $x', $subject);
        preg_replace('/(?<n>a)/', '$1', $subject);
        preg_replace('/(?|(a)|(b))/', '$1', $subject);
        preg_replace(['/(a)/', '/(b)(c)/'], ['$1', '$2'], $subject);
        preg_replace(['/(a)/', '/b/'], ['$1'], $subject);
        preg_replace('/(a)/', $dynamic, $subject);
        preg_replace_callback('/(a)/', static fn (array $matches): string => $matches[0], $subject);
    }

    public function groupCounts(string $subject): void
    {
        preg_replace('/(a)(b)/', '$3', $subject);
    }
}
