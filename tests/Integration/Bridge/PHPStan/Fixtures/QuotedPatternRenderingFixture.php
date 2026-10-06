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

final class QuotedPatternRenderingFixture
{
    public function trivialMatches(string $subject): void
    {
        preg_match('/^xéééééééééééééééééééééééééééééééééééééééééééééééééééééééééééé/', $subject); // byte 50 falls inside an "é"
        preg_match("/^ab # letters\n cd/x", $subject); // a comment and a line break under x
        preg_match("/^\u{202E}ab/", $subject); // a right-to-left override, byte mode
        preg_match("/^ab(?#\u{202E})/", $subject); // a right-to-left override in a comment
    }

    public function optimizations(string $subject): void
    {
        preg_match('/[0-9]+xyéééééééééééééééééééééééééééééééééééééééééééééééééééééééééééé/u', $subject, $matches); // byte 50 falls inside an "é"
        preg_match("/[0-9]+ # digits\n x/x", $subject, $matches); // a comment and a line break under x
        preg_match("/\u{202E}\u{202E}\u{202E}\u{202E}/u", $subject, $matches); // a right-to-left override under u
        preg_match("/[0-9]+ # digits\n\u{202E}abcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyz # letters\n x/x", $subject, $matches); // a long pattern, a comment and a right-to-left override under x
    }

    public function escapesAtTheCut(string $subject): void
    {
        preg_match("/^\\.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\u{202E}b/", $subject); // "\xE2" starts at character 49 and ends after 50
        preg_match("/^\\.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\u{202E}b/", $subject); // "\xE2" ends at character 50
    }
    public function cutBoundaries(string $subject): void
    {
        preg_match('/^aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/', $subject); // 50 characters: shown whole
        preg_match('/^aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/', $subject); // 51 characters: cut after the 50th
        preg_match('/^aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\\\\b/', $subject); // an escaped backslash ends on the 50th character
        preg_match("/^aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\\\\\u{202E}b/", $subject); // an escaped backslash, then "\xE2" across the 50th character
        preg_match("/^aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\u{202E}b/u", $subject); // "\x{202E}" across the 50th character
        preg_match('/[0-9]+aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\p{Lu}/', $subject, $matches); // "\p{Lu}" across the 50th character
    }

    public function escapesWithAnOperandAtTheCut(string $subject): void
    {
        preg_match('/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\cA[0-9]+/', $subject, $matches); // "\cA" across the 50th character
        preg_match('/(?<n>b)aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\k<n>[0-9]+/', $subject, $matches); // "\k<n>" across the 50th character
        preg_match('/(?<n>b)aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\k\'n\'[0-9]+/', $subject, $matches); // "\k'n'" across the 50th character
        preg_match('/(?<n>b)aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\g<n>[0-9]+/', $subject, $matches); // "\g<n>" across the 50th character
        preg_match('/(b)aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\g-1[0-9]+/', $subject, $matches); // "\g-1" across the 50th character
        preg_match('/(b)aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\g1[0-9]+/', $subject, $matches); // "\g1" across the 50th character
        preg_match('/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\12[0-9]+/', $subject, $matches); // the octal "\12" across the 50th character
        preg_match('/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\012[0-9]+/', $subject, $matches); // the octal "\012" across the 50th character
        preg_match('/(b)(c)(d)(e)(f)(g)(h)(i)(j)(k)aaaaaaaaaaaaaaaaa\10[0-9]+/', $subject, $matches); // the back reference "\10" across the 50th character
        preg_match('/(?<n>b)aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\k<n>[0-9]+/', $subject, $matches); // "\k<n>" ending on the 50th character
    }

    public function unbracedPropertiesAtTheCut(string $subject): void
    {
        preg_match('/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\pL[0-9]+/', $subject, $matches); // "\pL" across the 50th character
        preg_match('/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\PL[0-9]+/', $subject, $matches); // "\PL" across the 50th character
        preg_match('/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\pZs[0-9]+/', $subject, $matches); // "\pZ" across the 50th character, then "s"
        preg_match('/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\pL[0-9]+/', $subject, $matches); // "\pL" ending on the 50th character
        preg_match('/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\pZs[0-9]+/', $subject, $matches); // "\pZ" ending on the 50th character, "s" after it
    }
}
