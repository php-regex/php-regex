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
use PHPRegex\Linter\Rule\LintRuleRegistry;
use PHPRegex\Linter\Rule\OverlappingAlternationRule;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A wide alternation whose branches all start alike, a list of prefixed
 * names, is judged without comparing every pair of its branches: the
 * comparisons grow with the branches, not with their square, and the
 * overlap of two branches far into the list is reported as before.
 */
final class OverlappingAlternationWideTest extends TestCase
{
    /**
     * @param \Closure(int): string $branch
     * @param array{int, int}       $expected the comparisons for 100 branches, then for 400
     */
    #[Test]
    #[DataProvider('provideWideAlternations')]
    public function test_comparisons_grow_with_the_number_of_branches(\Closure $branch, string $flags, array $expected): void
    {
        $compared = [];
        foreach ([100, 400] as $total) {
            $pattern = '/(?:'.implode('|', array_map($branch, range(1, $total))).')+/'.$flags;
            $this->assertSame(0, preg_match($pattern, ''), $pattern);

            $rule = new OverlappingAlternationRule();
            $this->assertSame([], self::findings($pattern, $rule), $pattern);
            $compared[] = $rule->comparisons();
        }

        // Every pair would be 4,950 comparisons, then 79,800: sixteen times
        // as many for four times the branches. Each branch is compared three
        // times at most, whatever their number.
        $this->assertSame($expected, $compared);
        $this->assertLessThanOrEqual(3 * 100, $compared[0]);
        $this->assertLessThanOrEqual(3 * 400, $compared[1]);
    }

    /**
     * @return iterable<string, array{branch: \Closure(int): string, flags: string, expected: array{int, int}}>
     */
    public static function provideWideAlternations(): iterable
    {
        // The texts as literal branches, then as branches whose first characters meet.
        yield 'texts' => ['branch' => static fn (int $index): string => \sprintf('shared_start_%04d', $index), 'flags' => '', 'expected' => [198, 798]];
        // The same, the second time once for each case of the first letter.
        yield 'texts under i' => ['branch' => static fn (int $index): string => \sprintf('Shared_Start_%04d', $index), 'flags' => 'i', 'expected' => [297, 1197]];
        yield 'texts before a class' => ['branch' => static fn (int $index): string => \sprintf('shared_start_%04d[xy]', $index), 'flags' => '', 'expected' => [99, 399]];
        yield 'texts above ASCII before a class' => ['branch' => static fn (int $index): string => \sprintf('é_shared_start_%04d[xy]', $index), 'flags' => '', 'expected' => [99, 399]];
        yield 'texts above ASCII before a class under u' => ['branch' => static fn (int $index): string => \sprintf('é_shared_start_%04d[\x{E8}\x{E9}]', $index), 'flags' => 'u', 'expected' => [99, 399]];
        // Classes that share no character rule every pair out.
        yield 'texts after a class above ASCII under u' => ['branch' => static fn (int $index): string => \sprintf('[\x{%X}]_shared_start', 0x400 + $index), 'flags' => 'u', 'expected' => [0, 0]];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideOverlapsFarIntoTheList')]
    public function test_an_overlap_far_into_a_wide_alternation_is_reported(string $suffix, string $flags, array $expected): void
    {
        $branches = array_map(static fn (int $index): string => \sprintf('é_name_%03d', $index).$suffix, range(1, 150));
        $branches[] = 'é_name_120'.$suffix;
        $branches[] = 'é_name_12';
        $pattern = '/(?:'.implode('|', $branches).')+/'.$flags;
        $this->assertSame(0, preg_match($pattern, ''), $pattern);

        $rule = new OverlappingAlternationRule();
        $this->assertSame($expected, self::findings($pattern, $rule));
        // Each branch read a few times, then one of them with the later ones.
        $this->assertLessThan(8 * 152, $rule->comparisons());
    }

    /**
     * @return iterable<string, array{suffix: string, flags: string, expected: list<string>}>
     */
    public static function provideOverlapsFarIntoTheList(): iterable
    {
        yield 'texts' => [
            'suffix' => '',
            'flags' => '',
            'expected' => ['regex.lint.alternation.overlap: Alternation branches "é_name_120" and "é_name_12" overlap.'],
        ];
        yield 'texts under u' => [
            'suffix' => '',
            'flags' => 'u',
            'expected' => ['regex.lint.alternation.overlap: Alternation branches "é_name_120" and "é_name_12" overlap.'],
        ];
        yield 'texts before a class' => [
            'suffix' => '[xy]',
            'flags' => '',
            'expected' => ['regex.lint.overlap.charset: Alternation branches "é_name_120[xy]" and "é_name_120[xy]" have overlapping character sets, which may cause unnecessary backtracking.'],
        ];
        yield 'texts before a class above ASCII under u' => [
            'suffix' => '[\x{E8}\x{E9}]',
            'flags' => 'u',
            'expected' => ['regex.lint.overlap.charset: Alternation branches "é_name_120[\\x{E8}\\x{E9}]" and "é_name_120[\\x{E8}\\x{E9}]" have overlapping character sets, which may cause unnecessary backtracking.'],
        ];
    }

    /**
     * The overlap findings of a pattern, read from the rule given.
     *
     * @return list<string>
     */
    private static function findings(string $pattern, OverlappingAlternationRule $rule): array
    {
        $registry = new LintRuleRegistry();
        $registry->register($rule);

        $linter = PatternLinter::withRegistry([], $registry);
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        // The registry's own rule reports each finding as well.
        $found = [];
        foreach ($linter->getIssues() as $issue) {
            if (\in_array($issue->id, [OverlappingAlternationRule::ID_OVERLAP, OverlappingAlternationRule::ID_CHARSET], true)) {
                $found[$issue->id.': '.$issue->message] = true;
            }
        }

        return array_keys($found);
    }
}
