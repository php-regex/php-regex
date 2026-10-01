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

namespace PhpRegex\Redos;

/**
 * Renders a terminal heatmap for ReDoS hotspots based on byte offsets.
 */
final class Heatmap
{
    private const RESET = "\033[0m";
    private const GREEN = "\033[32m";
    private const YELLOW = "\033[33m";
    private const RED = "\033[31m";
    private const BRIGHT_RED = "\033[1;31m";
    private const GRAY = "\033[90m";

    /**
     * @param array<Hotspot|mixed> $hotspots
     */
    public function highlight(string $body, array $hotspots, bool $ansi = true): string
    {
        if (!$ansi) {
            return $body;
        }

        if ([] === $hotspots) {
            return '' === $body ? $body : self::GREEN.$body.self::RESET;
        }

        $length = \strlen($body);
        if (0 === $length) {
            return $body;
        }

        $levels = array_fill(0, $length, 0);
        foreach ($hotspots as $hotspot) {
            if (!$hotspot instanceof Hotspot) {
                continue;
            }

            $start = max(0, min($length, $hotspot->start));
            $end = max($start, min($length, $hotspot->end));
            if ($start >= $end) {
                continue;
            }

            $rank = $this->severityRank($hotspot->severity);
            for ($i = $start; $i < $end; $i++) {
                if ($rank > $levels[$i]) {
                    $levels[$i] = $rank;
                }
            }
        }

        $output = '';
        $currentLevel = null;
        for ($i = 0; $i < $length; $i++) {
            $level = $levels[$i];
            if (null === $currentLevel || $level !== $currentLevel) {
                if (null !== $currentLevel) {
                    $output .= self::RESET;
                }
                $output .= $this->colorForLevel($level);
                $currentLevel = $level;
            }

            $output .= $body[$i];
        }

        if (null !== $currentLevel) {
            $output .= self::RESET;
        }

        return $output;
    }

    private function severityRank(RedosSeverity $severity): int
    {
        return match ($severity) {
            RedosSeverity::Safe => 0,
            RedosSeverity::Low => 1,
            RedosSeverity::Medium => 2,
            RedosSeverity::High => 3,
            RedosSeverity::Critical => 4,
            RedosSeverity::Unknown => 1,
        };
    }

    private function colorForLevel(int $level): string
    {
        return match ($level) {
            0, 1 => self::GREEN,
            2 => self::YELLOW,
            3 => self::RED,
            4 => self::BRIGHT_RED,
            default => self::GRAY,
        };
    }
}
