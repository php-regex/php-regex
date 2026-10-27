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

namespace PHPRegex\Tests\Unit\Redos;

use PHPRegex\Redos\Finding;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The condition of "(?(1)…)", "(?(<name>)…)" or "(?(R)…)" asks whether a
 * group is set, or whether a recursion runs: it reads no character and
 * calls nothing, so the heuristics read it neither as a backreference nor
 * as a subroutine call.
 */
final class RedosConditionReferenceTest extends TestCase
{
    /**
     * Oracle (PCRE2 10.49, JIT off, start optimizations off): 5 steps
     * before the "!" fails the match, at 6, 16 and 64 letters.
     */
    #[Test]
    #[DataProvider('provideConditionsOnTheRepeatedGroup')]
    public function test_a_condition_on_a_group_is_not_a_backreference(string $pattern, string $measured): void
    {
        $measured = PcreInterpreter::pattern($measured);
        $this->assertSame(self::steps($measured, str_repeat('b', 6)), self::steps($measured, str_repeat('b', 64)), 'Oracle: constant steps.');

        $analysis = Regex::create(['cache' => null])->redos($pattern);

        $this->assertNotSame(RedosSeverity::Critical, $analysis->severity);
        $this->assertSame([], array_filter($analysis->findings, static fn (Finding $finding): bool => str_contains($finding->message, 'backreferences')));
    }

    /**
     * @return iterable<string, array{pattern: string, measured: string}>
     */
    public static function provideConditionsOnTheRepeatedGroup(): iterable
    {
        yield 'a numbered condition' => ['pattern' => '/((?(1)|b))+/', 'measured' => '/(*NO_START_OPT)((?(1)|b))+!/'];
        yield 'a named condition' => ['pattern' => '/(?<n>(?(<n>)|b))+/', 'measured' => '/(*NO_START_OPT)(?<n>(?(<n>)|b))+!/'];
    }

    #[Test]
    public function test_a_recursion_condition_is_not_a_subroutine_call(): void
    {
        $analysis = Regex::create(['cache' => null])->redos('/((?(R)|b))+/');

        $this->assertNotSame([], $analysis->findings);
        $this->assertSame([], array_filter($analysis->findings, static fn (Finding $finding): bool => str_contains($finding->message, 'Subroutines')));
    }

    /**
     * A backreference inside the loop keeps its verdict, the condition
     * around it or not.
     */
    #[Test]
    public function test_a_backreference_in_a_conditional_branch_is_still_read(): void
    {
        $this->assertSame(RedosSeverity::Critical, Regex::create(['cache' => null])->redos('/((?(1)(a?)\2+|b))+/')->severity);
    }

    /**
     * The smallest backtrack limit under which the match attempt ends.
     */
    private static function steps(string $pattern, string $subject): int
    {
        $saved = (string) ini_get('pcre.backtrack_limit');

        try {
            $low = 1;
            $high = 1 << 24;
            while ($low < $high) {
                $middle = intdiv($low + $high, 2);
                ini_set('pcre.backtrack_limit', (string) $middle);
                if (false === @preg_match($pattern, $subject)) {
                    $low = $middle + 1;
                } else {
                    $high = $middle;
                }
            }

            return $low;
        } finally {
            ini_set('pcre.backtrack_limit', $saved);
        }
    }
}
