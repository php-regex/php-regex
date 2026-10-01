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

namespace PHPRegex\Tests\Unit\ReDoS;

use PHPRegex\Redos\Heatmap;
use PHPRegex\Redos\Hotspot;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\TestCase;

final class ReDoSHeatmapTest extends TestCase
{
    public function test_highlight_returns_green_body_for_empty_hotspots(): void
    {
        $heatmap = new Heatmap();

        $output = $heatmap->highlight('abc', [], true);

        $this->assertStringContainsString('abc', $output);
    }

    public function test_highlight_skips_invalid_and_empty_hotspots(): void
    {
        $heatmap = new Heatmap();
        $hotspots = [
            new Hotspot(2, 2, RedosSeverity::Low, 'a', null),
        ];

        $output = $heatmap->highlight('abc', $hotspots, true);

        $this->assertStringContainsString('abc', $output);
    }

    public function test_highlight_returns_empty_body_when_hotspots_present(): void
    {
        $heatmap = new Heatmap();
        $hotspots = [
            new Hotspot(0, 1, RedosSeverity::Low, 'a', null),
        ];

        $output = $heatmap->highlight('', $hotspots, true);

        $this->assertSame('', $output);
    }

    public function test_highlight_uses_red_for_high_severity(): void
    {
        $heatmap = new Heatmap();
        $hotspots = [
            new Hotspot(0, 1, RedosSeverity::High, 'a', null),
        ];

        $output = $heatmap->highlight('abc', $hotspots, true);

        $this->assertStringContainsString("\033[31m", $output);
    }

    public function test_highlight_skips_non_redos_hotspot(): void
    {
        $heatmap = new Heatmap();
        $hotspots = [
            'invalid',
            new Hotspot(0, 1, RedosSeverity::Low, 'a', null),
        ];

        $output = $heatmap->highlight('abc', $hotspots, true);

        $this->assertStringContainsString('a', $output);
        $this->assertStringContainsString('b', $output);
        $this->assertStringContainsString('c', $output);
    }

    public function test_color_for_level_default(): void
    {
        $heatmap = new Heatmap();
        $ref = new \ReflectionClass($heatmap);
        $method = $ref->getMethod('colorForLevel');
        $color = $method->invoke($heatmap, 5);

        $this->assertSame("\033[90m", $color);
    }
}
