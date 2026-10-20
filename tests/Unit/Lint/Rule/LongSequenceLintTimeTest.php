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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\RuleCatalog;
use PHPRegex\Tests\Support\CpuClock;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What follows an item, and the flags in force at it, are read once for
 * each sequence: a sequence of thousands of items is linted in time that
 * grows with its length, every rule on.
 */
final class LongSequenceLintTimeTest extends TestCase
{
    #[Test]
    #[DataProvider('provideLongSequences')]
    public function test_a_long_sequence_is_linted_in_linear_time(string $item, int $issues, int $count = 2000): void
    {
        $tree = Regex::create(['cache' => null])->parse('/'.str_repeat($item, $count).'/');
        $linter = new PatternLinter(array_fill_keys(RuleCatalog::lintRuleIds(), true));

        $start = CpuClock::nanoseconds();
        $tree->accept($linter);
        $seconds = (CpuClock::nanoseconds() - $start) / 1e9;

        // About 6 s for the lazy quantifiers, 1 s for the others, when each
        // item read the rest of its sequence again.
        $this->assertLessThan(1.0, $seconds, \sprintf('%.2f s', $seconds));
        $this->assertCount($issues, $linter->getIssues());
    }

    /**
     * @return iterable<string, array{item: string, issues: int, count?: int}>
     */
    public static function provideLongSequences(): iterable
    {
        // Only items that match nothing first follow each "a*?": every one
        // is a lazy quantifier at the end, and the run one concatenation.
        yield 'lazy quantifiers' => ['item' => 'a*?', 'issues' => 2001];
        yield 'classes and letters' => ['item' => '[ab]x', 'issues' => 0];
        yield 'start anchors and letters' => ['item' => '\Ga', 'issues' => 1999];
        yield 'end anchors and newlines' => ['item' => 'a$\n', 'issues' => 1999];
        // Each group asks whether its pattern may match nothing, and what
        // follows it: 4,000 took about 17 s when each read the pattern again.
        yield 'optional capturing groups' => ['item' => '(a)?', 'issues' => 0, 'count' => 4000];
    }
}
