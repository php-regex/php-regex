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

namespace PHPRegex\Tests\Unit\Lint\Internal;

use PHPRegex\Linter\Internal\LintStatsCounter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LintStatsCounterTest extends TestCase
{
    #[Test]
    public function test_count_names_each_kind_of_error_apart(): void
    {
        $regex = Regex::create();
        $invalid = $regex->validate('/(/');
        $redos = $regex->redos('/^(a+)+$/');

        $results = [
            [
                'file' => 'a.php',
                'line' => 1,
                'pattern' => '/(/',
                'issues' => [
                    ['type' => 'error', 'message' => 'invalid', 'file' => 'a.php', 'line' => 1, 'validation' => $invalid],
                    ['type' => 'error', 'message' => 'redos', 'file' => 'a.php', 'line' => 1, 'analysis' => $redos],
                    ['type' => 'error', 'message' => 'lint rule', 'file' => 'a.php', 'line' => 1],
                    ['type' => 'warning', 'message' => 'warning', 'file' => 'a.php', 'line' => 1],
                    ['type' => 'info', 'message' => 'info', 'file' => 'a.php', 'line' => 1],
                    ['type' => 'info', 'message' => 'info', 'file' => 'a.php', 'line' => 1],
                ],
                'optimizations' => [],
                'problems' => [],
            ],
        ];

        $this->assertSame(
            ['errors' => 3, 'warnings' => 1, 'optimizations' => 0, 'redos' => 1, 'infos' => 2, 'lintErrors' => 1],
            LintStatsCounter::count($results),
        );
    }

    #[Test]
    public function test_count_leaves_out_the_kinds_at_zero(): void
    {
        $results = [
            [
                'file' => 'a.php',
                'line' => 1,
                'pattern' => '/a/',
                'issues' => [
                    ['type' => 'warning', 'message' => 'warning', 'file' => 'a.php', 'line' => 1],
                ],
                'optimizations' => [],
                'problems' => [],
            ],
        ];

        $this->assertSame(['errors' => 0, 'warnings' => 1, 'optimizations' => 0], LintStatsCounter::count($results));
        $this->assertSame(['errors' => 0, 'warnings' => 0, 'optimizations' => 0], LintStatsCounter::count([]));
    }
}
