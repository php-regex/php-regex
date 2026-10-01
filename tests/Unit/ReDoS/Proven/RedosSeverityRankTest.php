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

namespace PHPRegex\Tests\Unit\ReDoS\Proven;

use PHPRegex\Redos\Heatmap;
use PHPRegex\Redos\Hotspot;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One order of severities, owned by the enum, read by every consumer:
 * safe < low < unknown < medium < high < critical.
 */
final class RedosSeverityRankTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRanks')]
    public function test_severity_rank_is_the_documented_order(RedosSeverity $severity, int $rank): void
    {
        $this->assertSame($rank, $severity->rank());
    }

    /**
     * @return iterable<string, array{RedosSeverity, int}>
     */
    public static function provideRanks(): iterable
    {
        yield 'safe' => [RedosSeverity::Safe, 0];
        yield 'low' => [RedosSeverity::Low, 1];
        yield 'unknown, between low and medium' => [RedosSeverity::Unknown, 2];
        yield 'medium' => [RedosSeverity::Medium, 3];
        yield 'high' => [RedosSeverity::High, 4];
        yield 'critical' => [RedosSeverity::Critical, 5];
    }

    #[Test]
    #[DataProvider('provideSeverityPairs')]
    public function test_severity_threshold_follows_the_rank(RedosSeverity $severity, RedosSeverity $threshold): void
    {
        $analysis = new RedosAnalysis($severity, 0);

        $this->assertSame($severity->rank() >= $threshold->rank(), $analysis->exceedsThreshold($threshold));
    }

    #[Test]
    #[DataProvider('provideSeverityPairs')]
    public function test_severity_primary_hotspot_follows_the_rank(RedosSeverity $first, RedosSeverity $second): void
    {
        $firstHotspot = new Hotspot(0, 1, $first, 'a');
        $secondHotspot = new Hotspot(1, 2, $second, 'b');
        $analysis = new RedosAnalysis(RedosSeverity::Critical, 10, hotspots: [$firstHotspot, $secondHotspot]);

        $expected = $second->rank() > $first->rank() ? $secondHotspot : $firstHotspot;

        $this->assertSame($expected, $analysis->getPrimaryHotspot());
    }

    /**
     * Two overlapping hotspots: the span takes the colour of the higher
     * ranked one, whichever comes first.
     */
    #[Test]
    #[DataProvider('provideSeverityPairs')]
    public function test_severity_heatmap_follows_the_rank(RedosSeverity $first, RedosSeverity $second): void
    {
        $heatmap = new Heatmap();
        $higher = $second->rank() > $first->rank() ? $second : $first;

        $both = $heatmap->highlight('ab', [new Hotspot(0, 2, $first, 'ab'), new Hotspot(0, 2, $second, 'ab')]);
        $reversed = $heatmap->highlight('ab', [new Hotspot(0, 2, $second, 'ab'), new Hotspot(0, 2, $first, 'ab')]);
        $alone = $heatmap->highlight('ab', [new Hotspot(0, 2, $higher, 'ab')]);

        $this->assertSame($alone, $both);
        $this->assertSame($alone, $reversed);
    }

    /**
     * Unknown now outranks low in the heatmap as everywhere else: a span
     * judged unknown is not painted like a span judged low: unknown has
     * its own colour.
     */
    #[Test]
    public function test_severity_heatmap_tells_unknown_from_low(): void
    {
        $heatmap = new Heatmap();

        $this->assertNotSame(
            $heatmap->highlight('ab', [new Hotspot(0, 2, RedosSeverity::Low, 'ab')]),
            $heatmap->highlight('ab', [new Hotspot(0, 2, RedosSeverity::Unknown, 'ab')]),
        );
    }

    /**
     * @return iterable<string, array{RedosSeverity, RedosSeverity}>
     */
    public static function provideSeverityPairs(): iterable
    {
        foreach (RedosSeverity::cases() as $first) {
            foreach (RedosSeverity::cases() as $second) {
                yield $first->value.' then '.$second->value => [$first, $second];
            }
        }
    }
}
