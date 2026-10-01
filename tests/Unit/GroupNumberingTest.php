<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit;

use PhpRegex\Parser\Analysis\GroupNumbering;
use PHPUnit\Framework\TestCase;

final class GroupNumberingTest extends TestCase
{
    public function test_construct(): void
    {
        $groupNumbering = new GroupNumbering(2, [1, 2], ['name' => [1]]);
        $this->assertSame(2, $groupNumbering->maxGroupNumber);
        $this->assertSame([1, 2], $groupNumbering->captureSequence);
        $this->assertSame(['name' => [1]], $groupNumbering->namedGroups);
    }

    public function test_has_named_group(): void
    {
        $groupNumbering = new GroupNumbering(1, [1], ['name' => [1]]);
        $this->assertTrue($groupNumbering->hasNamedGroup('name'));
        $this->assertFalse($groupNumbering->hasNamedGroup('nonexistent'));
    }

    public function test_get_named_group_numbers(): void
    {
        $groupNumbering = new GroupNumbering(1, [1], ['name' => [1]]);
        $this->assertSame([1], $groupNumbering->getNamedGroupNumbers('name'));
        $this->assertSame([], $groupNumbering->getNamedGroupNumbers('nonexistent'));
    }

    public function test_get_capture_count(): void
    {
        $groupNumbering = new GroupNumbering(2, [1, 2], []);
        $this->assertSame(2, $groupNumbering->getCaptureCount());
    }
}
