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

namespace PHPRegex\Tests\Unit\Automata;

use PHPRegex\Automata\Language;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Tests\Support\CpuClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Counting multiplies, at each length, what each state leads to by the
 * characters that lead there: once per state it leads to, however many
 * ranges spell those characters, and not at all for a state no string is
 * accepted from. The counts stay exact.
 */
final class LanguageCountingTest extends TestCase
{
    #[Test]
    #[DataProvider('provideExactCounts')]
    public function test_a_count_is_exact(string $pattern, ?int $length, string $count, string $member): void
    {
        $this->assertSame(1, preg_match($pattern, $member));
        $language = (new LanguageSolver())->language($pattern);

        $this->assertSame($count, null === $length ? $language->size() : $language->countOfLength($length));
    }

    /**
     * @return iterable<string, array{pattern: string, length: int|null, count: string, member: string}>
     */
    public static function provideExactCounts(): iterable
    {
        // 2^101 - 1: the strings of each length from 0 to 100.
        yield 'every length up to a hundred' => ['pattern' => '/^[ab]{0,100}$/', 'length' => null, 'count' => '2535301200456458802993406410751', 'member' => str_repeat('ab', 50)];
        // (26^41 - 1) / 25.
        yield 'every length up to forty' => ['pattern' => '/^[a-z]{0,40}$/', 'length' => null, 'count' => '413016363125221434343092883566249096593443596740982776791', 'member' => 'regex'];
        // 2^200.
        yield 'two letters, two hundred times' => ['pattern' => '/^[ab]*$/', 'length' => 200, 'count' => '1606938044258990275541962092341162602522202993782792835301376', 'member' => 'abba'];
        yield 'the same letters in two branches' => ['pattern' => '/^(?:[a-m]|[n-z]|\d)+$/', 'length' => 3, 'count' => '46656', 'member' => 'a1z'];
        yield 'the empty string, where it is a member' => ['pattern' => '/^a*$/', 'length' => 0, 'count' => '1', 'member' => ''];
        yield 'the empty string, where it is not' => ['pattern' => '/^a+$/', 'length' => 0, 'count' => '0', 'member' => 'a'];
        yield 'a length past the longest string' => ['pattern' => '/^[ab]{2}$/', 'length' => 5, 'count' => '0', 'member' => 'ab'];
        yield 'a length before the shortest string' => ['pattern' => '/^[ab]{3,4}$/', 'length' => 2, 'count' => '0', 'member' => 'aba'];
        yield 'a total over lengths with none' => ['pattern' => '/^(?:a|bbb)$/', 'length' => null, 'count' => '2', 'member' => 'bbb'];
        // Surrogates are no characters under u: 0x110000 - 0x800 of them.
        yield 'every character under u' => ['pattern' => '/^.$/su', 'length' => 1, 'count' => '1112064', 'member' => 'é'];
        yield 'every character under u, twice' => ['pattern' => '/^..$/su', 'length' => 2, 'count' => '1236686340096', 'member' => 'éa'];
    }

    #[Test]
    public function test_no_string_has_a_negative_length(): void
    {
        $language = (new LanguageSolver())->language('/^a*$/');

        $this->assertSame('0', $language->countOfLength(-1));
        $this->assertSame('1', $language->countOfLength(0));
    }

    #[Test]
    public function test_a_language_with_no_string_counts_none(): void
    {
        $this->assertSame(0, preg_match('/^a\bb$/', 'ab'));
        $language = (new LanguageSolver())->language('/^a\bb$/');

        $this->assertSame('0', $language->countOfLength(2));
        $this->assertSame('0', $language->size());
    }

    /**
     * 512 characters written as one range, then as 512 ranges of one
     * character with a gap after each: the same count at every length, the
     * same numbers to multiply, so about the same time. Multiplying once for
     * each range took some hundreds of times as long.
     */
    #[Test]
    public function test_counting_costs_the_same_however_many_ranges_spell_the_characters(): void
    {
        $one = '/^[\x{100}-\x{2FF}]+$/u';
        $many = '/^['.implode('', array_map(static fn (int $codePoint): string => \sprintf('\x{%X}', $codePoint), range(0x100, 0x4FE, 2))).']+$/u';
        $this->assertSame(1, preg_match($one, "\u{100}\u{2FF}"));
        $this->assertSame(1, preg_match($many, "\u{100}\u{4FE}"));
        $this->assertSame(0, preg_match($many, "\u{101}"));

        $solver = new LanguageSolver();
        $languages = [$solver->language($one), $solver->language($many)];
        foreach ($languages as $language) {
            // 512^3; the automaton's moves are read here, before the clock.
            $this->assertSame('134217728', $language->countOfLength(3));
        }
        $this->assertSame($languages[0]->countOfLength(120), $languages[1]->countOfLength(120));

        [$oneRange, $manyRanges] = array_map(self::bestCountingTime(...), $languages);

        $this->assertLessThan(8.0, $manyRanges / max($oneRange, 1e-4), \sprintf('One range: %.4f s; 512 ranges: %.4f s.', $oneRange, $manyRanges));
    }

    /**
     * The least CPU time of three counts at length 120.
     */
    private static function bestCountingTime(Language $language): float
    {
        $best = \INF;
        for ($turn = 0; $turn < 3; $turn++) {
            $start = CpuClock::seconds();
            $language->countOfLength(120);
            $best = min($best, CpuClock::seconds() - $start);
        }

        return $best;
    }
}
