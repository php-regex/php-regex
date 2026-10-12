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

final class InvisibleCharacterFixture
{
    public function calls(string $subject): void
    {
        preg_match('/a	b/', $subject);
        preg_match("/a\tb/", $subject);
        preg_match('/a' . '	b/', $subject);
        preg_replace_callback_array(['/c	d/' => static fn (array $m): string => '', "/e\tf/" => static fn (array $m): string => ''], $subject);
        $pattern = '/g	h/';
        preg_match($pattern, $subject);
        preg_match('/a' . "\tb/", $subject);
    }
}
