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

use PHPRegex\Parser\Analysis\GroupNumbering;
use PHPRegex\Parser\Analysis\GroupNumberingCollector;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GroupNumberingTest extends TestCase
{
    #[Test]
    public function test_construct(): void
    {
        $groupNumbering = new GroupNumbering(2, [1, 2], ['name' => [1]]);
        $this->assertSame(2, $groupNumbering->maxGroupNumber);
        $this->assertSame([1, 2], $groupNumbering->captureSequence);
        $this->assertSame(['name' => [1]], $groupNumbering->namedGroups);
    }

    #[Test]
    public function test_has_named_group(): void
    {
        $groupNumbering = new GroupNumbering(1, [1], ['name' => [1]]);
        $this->assertTrue($groupNumbering->hasNamedGroup('name'));
        $this->assertFalse($groupNumbering->hasNamedGroup('nonexistent'));
    }

    #[Test]
    public function test_get_named_group_numbers(): void
    {
        $groupNumbering = new GroupNumbering(1, [1], ['name' => [1]]);
        $this->assertSame([1], $groupNumbering->getNamedGroupNumbers('name'));
        $this->assertSame([], $groupNumbering->getNamedGroupNumbers('nonexistent'));
    }

    #[Test]
    public function test_get_written_group_count(): void
    {
        $groupNumbering = new GroupNumbering(2, [1, 2], []);
        $this->assertSame(2, $groupNumbering->getWrittenGroupCount());
    }

    /**
     * The groups as written, not PCRE2's capture count: a branch reset
     * writes two groups that share number 1, and preg_match() fills one.
     */
    #[Test]
    public function test_a_branch_reset_counts_each_group_written(): void
    {
        $pattern = '/(?|(a)|(b))/';
        $this->assertSame(1, preg_match($pattern, 'b', $matches));
        $this->assertCount(2, $matches, 'PCRE2 counts one capture.');

        $numbering = (new GroupNumberingCollector())->collect(RegexParser::create()->parse($pattern));

        $this->assertSame(2, $numbering->getWrittenGroupCount());
        $this->assertSame(1, $numbering->maxGroupNumber);
    }
}
