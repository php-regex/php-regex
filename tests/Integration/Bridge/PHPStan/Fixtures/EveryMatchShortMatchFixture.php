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

final class EveryMatchShortMatchFixture
{
    public function verdicts(string $subject): void
    {
        preg_match_all('/a*b|a/', $subject); // every short match reads the rest of the run first
        preg_split('/a(?:a+\d)?/', $subject);
        preg_match('/a*b|a/', $subject); // the first match ends the search
        preg_replace('/a(?:a+\d)?/', '', $subject, 1); // the limit bounds the loop
        preg_match_all('/a(?:a+\d)??/', $subject); // the attempt matches before it reads the run
    }
}
