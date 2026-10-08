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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Tests\Support\LinearTimeAssertions;
use PHPRegex\Tests\TestUtils\PcreMessageCodes;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A lookbehind is measured once, whatever it calls: a group called twice by
 * the group it stands for, down k levels, is 2^(k-1) letters long, and is
 * not walked letter by letter. Nor is a lookbehind inside n others measured
 * again for each of them.
 *
 * When the pattern is refused further on, the lookbehinds read before the
 * error are judged too, and must cost no more than when it compiles.
 */
final class LookbehindMeasureTimeTest extends TestCase
{
    use LinearTimeAssertions;

    /**
     * Two more levels make the lookbehind four times as long: measured
     * letter by letter, they take four times as long.
     */
    private const MAX_GROWTH = 2.0;

    /**
     * A refusal or a verdict on a pattern of a few hundred bytes takes a few
     * milliseconds; this leaves room for a busy machine or a coverage
     * driver.
     */
    private const BUDGET = 0.5;

    /**
     * Measured when the calls were walked letter by letter: k = 20 took
     * 3.5 s and k = 22 took 14 s, where reading the pattern alone takes
     * 4 ms. PCRE refuses the "(?<" with no name at the end of the pattern.
     */
    #[Test]
    #[DataProvider('provideLookbehindsBeforeAnError')]
    public function test_validate_refuses_an_error_after_a_lookbehind_of_deep_calls_within_budget(string $lookbehind, bool $named): void
    {
        $pattern = self::deepCalls(20, $named).$lookbehind.'(?<';
        $regex = '/'.$pattern.'/';

        $pcre = PcreMessageCodes::read(PcreMessageCodes::warningOf($regex) ?? 'compiles');
        $this->assertArrayHasKey($pcre['message'], PcreMessageCodes::CODES, \sprintf('Oracle: "%s" is not a message the code map knows.', $pcre['message']));
        $allowed = PcreMessageCodes::CODES[$pcre['message']];
        $pinned = '10.49' === self::runningRelease();
        if ($pinned) {
            $this->assertSame(\strlen($pattern), $pcre['offset'], \sprintf('Oracle: %s (%s).', $lookbehind, $pcre['message']));
            $this->assertContains(ErrorCode::GroupNameExpected->value, $allowed, \sprintf('Oracle: "%s".', $pcre['message']));
        }

        $time = self::bestValidationTime($regex);
        $this->assertLessThan(self::BUDGET, $time, \sprintf('%s after 20 levels of calls: %.3f s.', $lookbehind, $time));

        $result = Regex::create(['cache' => null])->validate($regex);
        $said = \sprintf('%s: PCRE says "%s" at %s, the library "%s" (%s) at %s.', $lookbehind, $pcre['message'], var_export($pcre['offset'], true), (string) $result->error, $result->errorCode?->value, var_export($result->offset, true));

        $this->assertFalse($result->isValid, $said);
        $this->assertSame($pcre['offset'], $result->offset, $said);
        $this->assertContains($result->errorCode?->value, $allowed, $said);
        if ($pinned) {
            $this->assertSame(ErrorCode::GroupNameExpected, $result->errorCode, $said);
        }
    }

    /**
     * Measured when the calls were walked letter by letter: k = 16 then 18
     * took 0.22 s then 0.86 s.
     */
    #[Test]
    #[DataProvider('provideLookbehindsBeforeAnError')]
    public function test_validate_refuses_an_error_after_a_lookbehind_of_deep_calls_in_time_growing_with_the_text(string $lookbehind, bool $named): void
    {
        $this->assertSlowGrowth(
            static fn (int $levels): string => '/'.self::deepCalls($levels, $named).$lookbehind.'(?</',
            16,
            $lookbehind.'(?<',
        );
    }

    /**
     * @return iterable<string, array{lookbehind: string, named: bool}>
     */
    public static function provideLookbehindsBeforeAnError(): iterable
    {
        yield 'a call by number' => ['lookbehind' => '(?<=(?1)', 'named' => false];
        yield 'a back reference' => ['lookbehind' => '(?<=\\1', 'named' => false];
        yield 'a call by name' => ['lookbehind' => '(?<=(?&g1)', 'named' => true];
        yield 'a negative lookbehind' => ['lookbehind' => '(?<!(?1)', 'named' => false];
        yield 'an alphabetic lookbehind' => ['lookbehind' => '(*plb:(?1)', 'named' => false];
    }

    /**
     * The same lookbehind in a pattern PCRE compiles: 2^13 then 2^15
     * letters, under PCRE's ceiling of 65 535. Measured at 0.055 s then
     * 0.22 s, the calls walked letter by letter.
     */
    #[Test]
    public function test_validate_measures_a_lookbehind_of_deep_calls_in_time_growing_with_the_text(): void
    {
        $build = static fn (int $levels): string => '/(?<=(?1))'.self::deepCalls($levels, false).'/';

        foreach ([14, 16] as $levels) {
            $regex = $build($levels);
            $this->assertNull(PcreMessageCodes::warningOf($regex), \sprintf('Oracle: %d levels compile.', $levels));
            $this->assertTrue(Regex::create(['cache' => null])->validate($regex)->isValid, \sprintf('%d levels compile.', $levels));
        }

        $this->assertSlowGrowth($build, 14, '(?<=(?1)) before the calls');
    }

    /**
     * Measured when each lookbehind was measured again for each one around
     * it: 120 then 240 nested took 0.047 s then 0.16 s, 0.027 s then 0.26 s
     * holding a call. 240 is within PCRE's nesting limit.
     */
    #[Test]
    #[DataProvider('provideNestedLookbehinds')]
    public function test_validate_refuses_an_error_after_nested_lookbehinds_in_linear_time(string $prefix, string $unit): void
    {
        $this->assertLinearTime(
            static function (int $size) use ($prefix, $unit): void {
                Regex::create(['cache' => null])->validate('/'.$prefix.str_repeat($unit, $size).'(?</');
            },
            120,
            $unit,
            2.5,
        );

        $regex = '/'.$prefix.str_repeat($unit, 240).'(?</';
        $pcre = PcreMessageCodes::read(PcreMessageCodes::warningOf($regex) ?? 'compiles');
        $result = Regex::create(['cache' => null])->validate($regex);
        $said = \sprintf('%s x 240: PCRE says "%s" at %s, the library "%s" (%s) at %s.', $unit, $pcre['message'], var_export($pcre['offset'], true), (string) $result->error, $result->errorCode?->value, var_export($result->offset, true));

        $this->assertFalse($result->isValid, $said);
        $this->assertSame($pcre['offset'], $result->offset, $said);
        $this->assertContains($result->errorCode?->value, PcreMessageCodes::CODES[$pcre['message']] ?? [], $said);
    }

    /**
     * @return iterable<string, array{prefix: string, unit: string}>
     */
    public static function provideNestedLookbehinds(): iterable
    {
        yield 'lookbehinds' => ['prefix' => '', 'unit' => '(?<=a'];
        yield 'negative lookbehinds' => ['prefix' => '', 'unit' => '(?<!a'];
        yield 'alphabetic lookbehinds' => ['prefix' => '', 'unit' => '(*plb:a'];
        yield 'lookbehinds holding a call' => ['prefix' => '(a)', 'unit' => '(?<=(?1)'];
    }

    /**
     * A measure kept for a group or a lookbehind is not taken again where a
     * group it called is being measured: there the call recurses, and PCRE
     * finds no bound. The lookbehind of the condition in group 2 is not
     * measured with group 2, so the first lookbehind keeps group 1, which
     * calls group 2, before the condition reaches it from inside group 2.
     */
    #[Test]
    #[DataProvider('provideKeptMeasuresCallingAGroupBeingMeasured')]
    public function test_validate_measures_again_a_kept_measure_that_calls_a_group_being_measured(string $pattern, int $offset): void
    {
        // What the engine says of a kept measure that calls a group being
        // measured changed at PCRE2 10.43: before that, another error, at
        // another offset.
        if (\in_array($pattern, [
            '/(?<=(?1))(a(?2))(c(?(?<=b(?1))x))/',
            '/(?<=(?1))((?3)(?<!a))()((?(?<!(?1))a))/',
            '/(?<=(?1))(a(?2))(c(?(?C1)(?<=(?1))x))/',
            '/(?<=(?1))(a(?2))(c(?(?=(?<=(?1)))x))/',
        ], true) && !PcreTarget::runtime()->pcreAtLeast('10.43')) {
            $this->markTestSkipped(sprintf('%s is verified against PCRE2 10.43 and later; PCRE2 %s reports it differently.', $pattern, \PCRE_VERSION));
        }

        $pcre = PcreMessageCodes::read(PcreMessageCodes::warningOf($pattern) ?? 'compiles');
        $this->assertArrayHasKey($pcre['message'], PcreMessageCodes::CODES, \sprintf('Oracle: %s, "%s" is not a message the code map knows.', $pattern, $pcre['message']));
        $allowed = PcreMessageCodes::CODES[$pcre['message']];
        $pinned = '10.49' === self::runningRelease();
        if ($pinned) {
            $this->assertSame($offset, $pcre['offset'], \sprintf('Oracle: %s (%s).', $pattern, $pcre['message']));
            $this->assertContains(ErrorCode::LookbehindUnbounded->value, $allowed, \sprintf('Oracle: "%s".', $pcre['message']));
        }

        $result = Regex::create(['cache' => null])->validate($pattern);
        $said = \sprintf('%s: PCRE says "%s" at %s, the library "%s" (%s) at %s.', $pattern, $pcre['message'], var_export($pcre['offset'], true), (string) $result->error, $result->errorCode?->value, var_export($result->offset, true));

        $this->assertFalse($result->isValid, $said);
        $this->assertSame($pcre['offset'], $result->offset, $said);
        $this->assertContains($result->errorCode?->value, $allowed, $said);
        if ($pinned) {
            $this->assertSame(ErrorCode::LookbehindUnbounded, $result->errorCode, $said);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideKeptMeasuresCallingAGroupBeingMeasured(): iterable
    {
        // PCRE: "length of lookbehind assertion is not limited", at the
        // lookbehind of the condition. Group 1 is kept, calling group 2.
        yield 'a group kept, called from a lookbehind condition' => ['pattern' => '/(?<=(?1))(a(?2))(c(?(?<=(?1))x|y))/', 'offset' => 20];
        yield 'a group kept, called from a negative lookbehind condition' => ['pattern' => '/(?<=(?1))(a(?2))(c(?(?<!(?1))x|y))/', 'offset' => 20];
        yield 'a group kept, called from an alphabetic lookbehind condition' => ['pattern' => '/(?<=(?1))(a(?2))(c(?(*plb:(?1))x|y))/', 'offset' => 22];
        yield 'a group kept, called from a lookbehind condition after a callout' => ['pattern' => '/(?<=(?1))(a(?2))(c(?(?C1)(?<=(?1))x))/', 'offset' => 25];
        yield 'a group kept, called from a lookbehind in a lookahead condition' => ['pattern' => '/(?<=(?1))(a(?2))(c(?(?=(?<=(?1)))x))/', 'offset' => 23];
        yield 'a group kept, called after a letter in a lookbehind condition' => ['pattern' => '/(?<=(?1))(a(?2))(c(?(?<=b(?1))x))/', 'offset' => 20];
        // At the lookbehind in group 1. It is kept, calling group 2; group 2
        // reaches it again through the group in its condition.
        yield 'a lookbehind kept, reached again from the group it calls' => ['pattern' => '/((?<=(?2))a)(b(?(?<=((?1)))x))/', 'offset' => 1];
        yield 'a lookbehind kept after a letter, reached again from the group it calls' => ['pattern' => '/(a(?<=(?2)))(b(?(?<=(c(?1)))x|y))/', 'offset' => 2];
        // At the lookbehind inside the group a first lookbehind calls: it
        // refers back to that group, which is being measured.
        yield 'a lookbehind referring back to the group it stands in, that group called from a lookbehind' => ['pattern' => '/(?<=(?1))((?<=\\1)d+)/', 'offset' => 10];
        yield 'a lookbehind calling the group it stands in, that group called from a lookbehind' => ['pattern' => '/(?<=(?1))((?<=(?1))d+)/', 'offset' => 10];
        yield 'an alphabetic lookbehind referring back to a group called through another' => ['pattern' => '/(?<=(?2))(x?(?2))((*plb:\\1)d+)/', 'offset' => 20];
        // The groups a kept measure calls are all kept with it: those called
        // before a measure taken again, those its own calls reach, and those
        // a lookbehind in it calls.
        yield 'a group kept, calling group 2 then a group measured before' => ['pattern' => '/(?<=(?3))(?<=(?1))(a(?2)(?3))(c(?(?<=(?1))x|y))(b)/', 'offset' => 33];
        yield 'a group kept, calling group 2 then holding a lookbehind measured before' => ['pattern' => '/(a(?2)(?<=b))(?<=(?1))(c(?(?<=(?1))x|y))/', 'offset' => 26];
        yield 'a group kept, calling group 3 through group 4' => ['pattern' => '/((?<!(?3)))()((?4))((?(?<=(?1))a|b))/', 'offset' => 1];
        yield 'a group kept, holding a lookbehind that calls group 3' => ['pattern' => '/(?<=(?1))((?3)(?<!a))()((?(?<!(?1))a))/', 'offset' => 26];
        yield 'a lookbehind kept inside another, calling group 3' => ['pattern' => '/()((?<!(?<!(?3))))((?(?<!(?2))a))/', 'offset' => 7];
    }

    /**
     * With a branch reset PCRE measures a lookbehind again each time it
     * meets it, and gives up past a budget ("lookbehind is too
     * complicated"): a lookbehind in a group called fifty times, calling a
     * group of a hundred branches, is far past it, and no measure kept from
     * an earlier meeting may hide that. Without the branch reset the
     * measures are reused, and the pattern compiles.
     */
    #[Test]
    public function test_validate_refuses_a_lookbehind_measured_again_past_the_budget_after_a_branch_reset(): void
    {
        $body = '(?<='.str_repeat('(?1)', 50).')((?<=(?2)))('.implode('|', array_fill(0, 100, 'a')).')';
        $reset = '/(?|x)'.$body.'/';
        $plain = '/'.$body.'/';

        $refused = PcreMessageCodes::warningOf($reset);
        if ('10.49' === self::runningRelease()) {
            $this->assertSame('lookbehind is too complicated', PcreMessageCodes::read($refused ?? 'compiles')['message'], 'Oracle: the branch reset.');
        }
        $this->assertNull(PcreMessageCodes::warningOf($plain), 'Oracle: no branch reset.');

        $regex = Regex::create(['cache' => null]);
        $result = $regex->validate($reset);
        if (null === $refused) {
            $this->assertTrue($result->isValid, (string) $result->error);
        } else {
            $this->assertContains(ErrorCode::LookbehindTooComplex->value, PcreMessageCodes::CODES[PcreMessageCodes::read($refused)['message']] ?? [], 'Oracle: '.$refused);
            $this->assertSame(ErrorCode::LookbehindTooComplex, $result->errorCode, (string) $result->error);
        }
        $this->assertTrue($regex->validate($plain)->isValid, (string) $regex->validate($plain)->error);
    }

    /**
     * Where no group it called is being measured, the measure kept holds.
     */
    #[Test]
    #[DataProvider('provideKeptMeasuresTakenAgain')]
    public function test_validate_takes_a_kept_measure_again_where_no_group_it_called_is_being_measured(string $pattern): void
    {
        $compiles = null === PcreMessageCodes::warningOf($pattern);
        if ('10.49' === self::runningRelease()) {
            $this->assertTrue($compiles, \sprintf('Oracle: %s compiles.', $pattern));
        }

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertSame($compiles, $result->isValid, \sprintf('%s: PCRE %s it, the library says "%s".', $pattern, $compiles ? 'compiles' : 'refuses', (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideKeptMeasuresTakenAgain(): iterable
    {
        yield 'a group kept, called from a condition outside the group it calls' => ['pattern' => '/(?<=(?1))(a(?2))(c)(?(?<=(?1))x|y)/'];
        yield 'a group kept, called from a condition in another group' => ['pattern' => '/(?<=(?1))(a(?2))(c)(c(?(?<=(?1))x|y))/'];
        yield 'a lookbehind kept, its group called from outside the group it calls' => ['pattern' => '/((?<=(?2))a)(b)(?(?<=((?1)))x)/'];
        yield 'a lookbehind kept after a letter, its group called from outside' => ['pattern' => '/(a(?<=(?2)))(b)(?(?<=(c(?1)))x|y)/'];
    }

    /**
     * PCRE counts every branch it measures for the lookbehinds of a pattern,
     * those of the lookbehinds and those of the groups they reach, in one
     * count, and gives up at the 2,002nd ("lookbehind is too complicated",
     * at the lookbehind it was measuring). Without a branch reset a
     * capturing group is measured once. PCRE2 10.49 gives each row below.
     */
    #[Test]
    #[DataProvider('provideMeasureCounts')]
    public function test_validate_counts_the_branches_measured_across_every_lookbehind(string $pattern, ?int $offset): void
    {
        if ('10.49' === self::runningRelease()) {
            $refused = PcreMessageCodes::warningOf($pattern);
            $this->assertSame(null === $offset ? null : 'lookbehind is too complicated', null === $refused ? null : PcreMessageCodes::read($refused)['message'], 'Oracle.');
        }

        $result = Regex::create(['cache' => null, 'pcre_version' => '10.49', 'php_version' => '8.4'])->validate($pattern);
        if (null === $offset) {
            $this->assertTrue($result->isValid, (string) $result->error);

            return;
        }

        $this->assertSame(ErrorCode::LookbehindTooComplex, $result->errorCode, (string) $result->error);
        $this->assertSame($offset, $result->offset);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int|null}>
     */
    public static function provideMeasureCounts(): iterable
    {
        yield '2,001 lookbehinds' => ['pattern' => '/'.str_repeat('(?<=a)', 2001).'/', 'offset' => null];
        yield '2,002 lookbehinds' => ['pattern' => '/'.str_repeat('(?<=a)', 2002).'/', 'offset' => 12006];
        yield 'two branches a lookbehind, then one' => ['pattern' => '/'.str_repeat('(?<=a|b)', 1000).'(?<=c)/', 'offset' => null];
        yield 'two branches a lookbehind, then two' => ['pattern' => '/'.str_repeat('(?<=a|b)', 1000).'(?<=c)(?<=d)/', 'offset' => 8006];
        yield 'a group called from 1,500 lookbehinds, measured once' => ['pattern' => '/(a)'.str_repeat('(?<=(?1))', 1500).'/', 'offset' => null];
        yield 'the same after a branch reset, measured each time' => ['pattern' => '/(a)(?|x)'.str_repeat('(?<=(?1))', 1500).'/', 'offset' => 9008];
        yield 'calls doubled ten levels down after a branch reset' => ['pattern' => self::doublingCalls(10), 'offset' => null];
        yield 'calls doubled eleven levels down after a branch reset' => ['pattern' => self::doublingCalls(11), 'offset' => 0];
    }

    /**
     * Deep doubling calls after a branch reset stop at the budget, in no
     * time: 2^30 branches are never measured.
     */
    #[Test]
    public function test_validate_stops_measuring_doubling_calls_at_the_budget(): void
    {
        $this->assertLessThan(self::BUDGET, self::bestValidationTime(self::doublingCalls(30)));
        $this->assertSame(ErrorCode::LookbehindTooComplex, Regex::create(['cache' => null])->validate(self::doublingCalls(30))->errorCode);
    }

    /**
     * "(?(DEFINE)" holding $levels groups: group i is "((?i+1)(?i+1))", the
     * last "(a)", so group 1 is 2^($levels - 1) letters long.
     */
    private static function deepCalls(int $levels, bool $named): string
    {
        $groups = '';
        for ($group = 1; $group < $levels; $group++) {
            $call = '(?'.($group + 1).')';
            $groups .= (1 === $group && $named ? '(?<g1>' : '(').$call.$call.')';
        }

        return '(?(DEFINE)'.$groups.'(a))';
    }

    /**
     * Validates the pattern built for $levels then $levels + 2, and asserts
     * each takes less than the budget and the larger less than MAX_GROWTH
     * times the smaller.
     *
     * @param \Closure(int): string $build
     */
    private function assertSlowGrowth(\Closure $build, int $levels, string $what): void
    {
        $small = self::bestValidationTime($build($levels));
        $this->assertLessThan(self::BUDGET, $small, \sprintf('%s, %d levels: %.3f s.', $what, $levels, $small));

        $large = self::bestValidationTime($build($levels + 2));
        $this->assertLessThan(self::BUDGET, $large, \sprintf('%s, %d levels: %.3f s, %.3f s for two fewer.', $what, $levels + 2, $large, $small));

        // Below a few milliseconds the clock says more than the reading.
        if ($large >= 0.02) {
            $this->assertLessThan(self::MAX_GROWTH, $large / $small, \sprintf('%s: %.3f s for %d levels, %.3f s for %d.', $what, $small, $levels, $large, $levels + 2));
        }
    }

    /**
     * A lookbehind calling group 1, each group calling the next twice, the
     * last one "a", after a branch reset: 2^$depth branches to measure.
     */
    private static function doublingCalls(int $depth): string
    {
        $groups = '';
        for ($group = 1; $group < $depth; $group++) {
            $groups .= '((?'.($group + 1).')(?'.($group + 1).'))';
        }

        return '/(?<=(?1))(?|x)'.$groups.'(a)/';
    }

    /**
     * The best of three validations. One ten times over the budget is not a
     * pause of the machine: the other two are not run.
     */
    private static function bestValidationTime(string $regex): float
    {
        $best = \INF;
        for ($run = 0; $run < 3; $run++) {
            $start = hrtime(true);
            Regex::create(['cache' => null])->validate($regex);
            $best = min($best, (hrtime(true) - $start) / 1e9);
            if ($best >= 10 * self::BUDGET) {
                break;
            }
        }

        return $best;
    }

    /**
     * The major.minor release of the PCRE2 the running PHP links.
     */
    private static function runningRelease(): string
    {
        return implode('.', \array_slice(explode('.', explode(' ', \PCRE_VERSION)[0]), 0, 2));
    }
}
