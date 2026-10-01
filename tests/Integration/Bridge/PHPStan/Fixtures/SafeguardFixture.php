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

final class SafeguardFixture
{
    public function test(): void
    {
        // Patterns that should not suggest optimization because optimized version is invalid
        preg_match('#\r?\n#', 'bar'); // Line 18: Should not suggest optimization to empty pattern
        preg_match('/\r?\n$/', 'bar'); // Line 19: Should not suggest optimization to broken anchor
        preg_match('/-- (.+)\n/', 'bar'); // Line 20: Should not suggest optimization that breaks formatting
    }
}
