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

final class ReplacementGroupCorrelationFixture
{
    private const PATTERNS = ['range' => '/^(\d+)-(\d+)$/', 'single' => '/^(\d+)$/'];

    private const REPLACEMENTS = ['range' => '$1:$2', 'single' => '<$1>'];

    public function correlated(string $subject, bool $strict, string $key): void
    {
        $pattern = $strict ? '/^(\d+)-(\d+)$/' : '/^(\d+)$/';
        $replacement = $strict ? '$1:$2' : '$1';
        preg_replace($pattern, $replacement, $subject);
        preg_replace(self::PATTERNS[$key], self::REPLACEMENTS[$key], $subject);
        if ($strict) {
            $p = '/^(\d+)-(\d+)$/';
            $r = '$1:$2';
        } else {
            $p = '/^(\d+)$/';
            $r = '$1';
        }
        preg_replace($p, $r, $subject);
        preg_replace(['/(a)/', $strict ? '/(b)(c)/' : '/(d)/'], ['$1', $strict ? '$2' : '$1'], $subject);
        preg_replace($strict ? '/(a/' : '/(b)/', $strict ? '$2' : '$1', $subject);
    }

    public function undefinedInEveryPattern(string $subject, bool $strict): void
    {
        $pattern = $strict ? '/^(\d+)-(\d+)$/' : '/^(\d+)$/';
        $replacement = $strict ? '$1:$3' : '$3';
        preg_replace($pattern, $replacement, $subject);
        preg_replace(['/(a)/', $strict ? '/(b)(c)/' : '/(d)/'], ['$1', $strict ? '$3' : '$1'], $subject);
        preg_replace($pattern, '$2', $subject);
    }
}
