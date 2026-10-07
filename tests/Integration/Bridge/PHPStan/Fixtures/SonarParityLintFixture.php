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

final class SonarParityLintFixture
{
    public function calls(string $subject): void
    {
        preg_match('/x(?:a*)+/', $subject);
        preg_match('/^a|b/', $subject);
        preg_match('/a*+a/', $subject);
        preg_match('/a\bb/', $subject);
        preg_match('/(?=a)b/', $subject);
        preg_match('/a(?:)b/', $subject);
        // Style and perf rules, off by default.
        preg_match('/x[a]y/', $subject);
        preg_match('/a  b/', $subject);
        preg_match('/".*?"/', $subject);
    }
}
