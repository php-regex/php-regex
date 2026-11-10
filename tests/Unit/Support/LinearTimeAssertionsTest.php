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

namespace PHPRegex\Tests\Unit\Support;

use PHPRegex\Tests\Support\CpuClock;
use PHPRegex\Tests\Support\LinearTimeAssertions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The cycle collector runs once the engine has counted enough values that
 * may be garbage, and each run walks what those values reach: its cost is
 * that of the process, not of the read. In the process of the whole suite a
 * linear read that set it off read ten to twelve times its smaller twin,
 * and four to five with the collector held. A timed read is read with the
 * collector held.
 */
final class LinearTimeAssertionsTest extends TestCase
{
    use LinearTimeAssertions;

    private bool $collects = true;

    protected function setUp(): void
    {
        $this->collects = gc_enabled();
        gc_enable();
    }

    protected function tearDown(): void
    {
        $this->collects ? gc_enable() : gc_disable();
    }

    #[Test]
    public function test_the_collector_is_held_while_a_read_is_timed(): void
    {
        $collecting = [];
        $this->assertLinearTime(static function (int $size) use (&$collecting): void {
            $collecting[] = gc_enabled();
        }, 100, 'A read that asks whether the collector runs');

        $this->assertSame([false], array_values(array_unique($collecting)));
        $this->assertTrue(gc_enabled(), 'The collector was not given back.');
    }

    #[Test]
    public function test_a_collector_that_was_off_is_left_off(): void
    {
        gc_disable();

        $seconds = CpuClock::time(static function (): void {});

        $this->assertGreaterThanOrEqual(0.0, $seconds);
        $this->assertFalse(gc_enabled(), 'A collector the process had turned off was turned on.');
    }

    #[Test]
    public function test_the_collector_is_given_back_when_the_read_throws(): void
    {
        try {
            CpuClock::time(static function (): never {
                throw new \LogicException('The read failed.');
            });
            $this->fail('The failure of the read was lost.');
        } catch (\LogicException $e) {
            $this->assertSame('The read failed.', $e->getMessage());
        }

        $this->assertTrue(gc_enabled(), 'The collector was not given back.');
    }

    /**
     * A read may leave cycles behind: with the collector held they would
     * add up from one read to the next, and the last reads of a measure
     * would run in a larger process than the first.
     */
    #[Test]
    public function test_the_cycles_a_read_leaves_are_collected_before_the_next_read(): void
    {
        $previous = null;
        $collected = [];
        $read = static function () use (&$previous, &$collected): void {
            if ($previous instanceof \WeakReference) {
                $collected[] = null === $previous->get();
            }
            $cycle = new \stdClass();
            $cycle->self = $cycle;
            $previous = \WeakReference::create($cycle);
        };

        CpuClock::time($read);
        CpuClock::time($read);
        CpuClock::time($read);

        $this->assertSame([true, true], $collected);
    }

    #[Test]
    public function test_the_time_of_a_read_is_the_time_the_process_computed(): void
    {
        $seconds = CpuClock::time(static function (): void {
            $until = CpuClock::nanoseconds() + 20_000_000;
            while (CpuClock::nanoseconds() < $until) {
                hash('crc32b', 'x');
            }
        });

        $this->assertGreaterThanOrEqual(0.02, $seconds);
        $this->assertLessThan(1.0, $seconds);
    }
}
