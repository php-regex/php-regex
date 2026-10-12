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

final class PatternArrayFixture
{
    private const PATTERNS = ['/(e+)+$/', '/safe/'];

    private const CALLBACKS = ['/(f+)+$/' => 'strtoupper'];

    public function arrays(string $input, bool $flag): void
    {
        preg_replace(['/(a+)+$/', '/b/'], 'x', $input); // line 24
        preg_replace_callback(['/(b+)+$/'], static fn (array $m): string => '', $input); // line 25
        preg_filter(['/(c+)+$/', '/d/'], ['x', 'y'], $input); // line 26
        $patterns = ['/(d+)+$/'];
        preg_replace($patterns, 'x', $input); // line 28
        preg_replace(self::PATTERNS, 'x', $input); // line 29
        preg_replace_callback_array(['/(g+)+$/' => static fn (array $m): string => ''], $input); // line 30
        preg_replace_callback_array(self::CALLBACKS, $input); // line 31
        preg_replace($flag ? ['/(h+)+$/'] : ['/i/'], 'x', $input); // line 32
        preg_replace(['/(a+)+$/'], 'x', 'aaaa');
        preg_replace(['/safe/', '/also safe/'], 'x', $input);
    }
}
