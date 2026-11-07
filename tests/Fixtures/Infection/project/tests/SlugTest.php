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

namespace Fixture\Tests;

use Fixture\Slug;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    public function test_a_slug(): void
    {
        $this->assertTrue(Slug::isValid('php-regex'));
        $this->assertFalse(Slug::isValid('PHP Regex'));
    }

    public function test_a_version(): void
    {
        $this->assertSame('2.0', Slug::version('v2.0'));
    }
}
