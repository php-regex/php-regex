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
use PHPRegex\Tests\Support\LinearTimeAssertions;
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
    use LinearTimeAssertions;

    #[Test]
    #[DataProvider('provideLongSequences')]
    public function test_a_long_sequence_is_linted_in_linear_time(string $item, int $issues, int $count = 2000): void
    {
        $tree = Regex::create(['cache' => null])->parse('/'.str_repeat($item, $count).'/');
        $linter = new PatternLinter(array_fill_keys(RuleCatalog::lintRuleIds(), true));

        $seconds = CpuClock::time(static function () use ($tree, $linter): void {
            $tree->accept($linter);
        });

        // About 6 s for the lazy quantifiers, 1 s for the others, when each
        // item read the rest of its sequence again.
        $this->assertLessThan(1.0, $seconds, \sprintf('%.2f s', $seconds));
        $this->assertCount($issues, $linter->getIssues());
    }

    /**
     * The rules on nested quantifiers and nested dot-stars ask of every
     * quantifier whether it ends an atomic group, which reads the last item
     * of each sequence around it: four times the quantifiers took some
     * fourteen times as long when that read copied the whole sequence.
     */
    #[Test]
    #[DataProvider('provideSequencesOfQuantifiers')]
    public function test_the_nesting_rules_read_a_long_sequence_in_linear_time(string $item): void
    {
        // Parsed before: only the reading of the rules is timed.
        $tree = Regex::create(['cache' => null])->parse('/'.str_repeat($item, 4 * 4000).'/');

        $this->assertLinearTime(static function () use ($tree): void {
            $tree->accept(new PatternLinter(['quantifier.nested' => true, 'dotstar.nested' => true] + array_fill_keys(RuleCatalog::lintRuleIds(), false)));
        }, 4000, $item.' read by the rules on nesting');
    }

    /**
     * The rule on nested quantifiers reads past the items of a loop's body
     * that may match nothing, to the loops behind them, once each: a body
     * of thousands of lookaheads before "b?c+" is read in linear time.
     */
    #[Test]
    public function test_the_items_before_the_loops_of_a_long_body_are_read_once(): void
    {
        // Parsed before: only the reading of the rule is timed.
        $tree = Regex::create(['cache' => null])->parse('/(?:'.str_repeat('(?=b)', 4 * 4000).'b?c+)+$/');

        $this->assertLinearTime(static function () use ($tree): void {
            $tree->accept(new PatternLinter(['quantifier.nested' => true] + array_fill_keys(RuleCatalog::lintRuleIds(), false)));
        }, 4000, 'lookaheads before the loops of a body');
    }

    /**
     * @return iterable<string, array{item: string}>
     */
    public static function provideSequencesOfQuantifiers(): iterable
    {
        yield 'lazy quantifiers' => ['item' => 'a*?'];
        yield 'optional capturing groups' => ['item' => '(a)?'];
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
