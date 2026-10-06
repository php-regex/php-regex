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

final class RedosMessageRenderingFixture
{
    public function verdicts(string $subject): void
    {
        preg_match('/(?:xéééééééééééééééééééééééééééééééééééééééééééééééééééééééééééé)?(a+)+$/u', $subject); // byte 50 falls inside an "é"
        preg_match("/(a+)+ # one or more runs\n \$/x", $subject); // a comment and a line break under x
        preg_match("/(?:\u{202E})?(a+)+\$/u", $subject); // a right-to-left override
        preg_match("/(?:\u{85})?(a+)+\$/u", $subject); // a next-line control under u
        preg_match("/(?:\u{202E})?(a+)+\$/", $subject); // a right-to-left override, byte mode
    }
}
