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

namespace PHPRegex\Tests\Unit\Parser\Analysis;

use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A set of bytes read from a range, 0x00 to 0xFF.
 */
final class ByteCharSetTest extends TestCase
{
    /**
     * A range with an end outside the bytes is not known, whichever end it
     * is, and whichever way the range runs.
     */
    #[Test]
    #[DataProvider('provideRangesWithAnEndOutsideTheBytes')]
    public function test_a_range_with_an_end_outside_the_bytes_is_not_known(int $start, int $end): void
    {
        $set = ByteCharSet::fromBytes($start, $end);

        $this->assertTrue($set->isUnknown(), \sprintf('%d to %d', $start, $end));
        $this->assertFalse($set->isEmpty(), \sprintf('%d to %d', $start, $end));
    }

    /**
     * @return iterable<string, array{start: int, end: int}>
     */
    public static function provideRangesWithAnEndOutsideTheBytes(): iterable
    {
        yield 'a start past 0xFF, the range reversed' => ['start' => 256, 'end' => 255];
        yield 'an end below 0x00, the range reversed' => ['start' => 0, 'end' => -1];
        yield 'both ends past 0xFF, the range reversed' => ['start' => 300, 'end' => 256];
        yield 'both ends below 0x00, the range reversed' => ['start' => -1, 'end' => -2];
        yield 'a start below 0x00' => ['start' => -1, 'end' => 0x41];
        yield 'an end past 0xFF' => ['start' => 0x41, 'end' => 256];
        yield 'a start past 0xFF' => ['start' => 256, 'end' => 300];
        yield 'an end below 0x00' => ['start' => -2, 'end' => -1];
    }

    /**
     * Between 0x00 and 0xFF a range holds the bytes it runs over, and a
     * reversed one none.
     */
    #[Test]
    public function test_a_range_within_the_bytes_holds_the_bytes_it_runs_over(): void
    {
        $this->assertSame([[0x80, 0xFF]], ByteCharSet::fromBytes(0x80, 0xFF)->ranges());
        $this->assertSame([[0x00, 0x00]], ByteCharSet::fromBytes(0x00, 0x00)->ranges());
        $this->assertTrue(ByteCharSet::fromBytes(0xFF, 0x00)->isEmpty());
        $this->assertTrue(ByteCharSet::fromBytes(0x81, 0x80)->isEmpty());
        $this->assertFalse(ByteCharSet::fromBytes(0x81, 0x80)->isUnknown());
    }
}
