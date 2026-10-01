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

namespace PHPRegex\Tests\Support;

use PHPRegex\Toolkit\Upgrade\UpgradeMap;

/**
 * The tables of UPGRADE-2.0.md that UpgradeMap holds, between two markers.
 */
final class UpgradeGuide
{
    public const START = '<!-- upgrade-map:start (php tests/Tools/write_upgrade_map.php) -->';

    public const END = '<!-- upgrade-map:end -->';

    public static function render(): string
    {
        $lines = [self::START, '', '| 1.3 | 2.0 |', '|---|---|'];
        foreach (UpgradeMap::RENAMED as $old => $new) {
            $lines[] = \sprintf('| `%s` | `%s` |', $old, $new);
        }
        foreach (UpgradeMap::REMOVED as $old => $instead) {
            $lines[] = \sprintf('| `%s` | removed: %s |', $old, self::code($instead));
        }

        $lines[] = '';
        $lines[] = '| 1.3 enum case | 2.0 |';
        $lines[] = '|---|---|';
        foreach (UpgradeMap::ENUM_CASES as $enum => $cases) {
            $short = substr((string) strrchr('\\'.UpgradeMap::RENAMED[$enum], '\\'), 1);
            $old = substr((string) strrchr('\\'.$enum, '\\'), 1);
            foreach ($cases as $from => $to) {
                $lines[] = \sprintf('| `%s::%s` | `%s::%s` |', $old, $from, $short, $to);
            }
        }

        $lines[] = '';
        $lines[] = '| 1.3 method | 2.0 |';
        $lines[] = '|---|---|';
        foreach (UpgradeMap::METHODS as [$class, $from, $to]) {
            $short = substr((string) strrchr('\\'.UpgradeMap::RENAMED[$class], '\\'), 1);
            $old = substr((string) strrchr('\\'.$class, '\\'), 1);
            $lines[] = \sprintf('| `%s::%s()` | `%s::%s()` |', $old, $from, $short, $to);
        }

        $lines[] = '';
        $lines[] = self::END;

        return implode("\n", $lines);
    }

    /**
     * A class name in the reason, in backticks.
     */
    private static function code(string $text): string
    {
        return (string) preg_replace('~PHPRegex\\\\[\w\\\\]+~', '`$0`', $text);
    }
}
