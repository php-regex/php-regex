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

namespace PHPRegex\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class UnicodeDigitMatchingTest extends TestCase
{
    public function test_unicode_digit_matching(): void
    {
        // \d with u flag matches Arabic-Indic digits
        $this->assertMatchesRegularExpression('/^\d$/u', '١');
        // [0-9] with u flag does NOT match Arabic-Indic digits
        $this->assertDoesNotMatchRegularExpression('/^[0-9]$/u', '١');
    }
}
