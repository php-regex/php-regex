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

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the automaton of a pattern knows once built: whether its language is
 * finite, how many strings of each length it holds, every one of them in
 * order, and the strings it proves it rejects. Each string handed out is
 * checked against the engine.
 */
final class LanguageTest extends TestCase
{
    #[Test]
    #[DataProvider('provideFiniteness')]
    public function test_a_language_knows_whether_it_is_finite(string $pattern, bool $finite, ?int $min, ?int $max, ?string $size): void
    {
        $language = (new LanguageSolver())->language($pattern);

        $this->assertSame($finite, $language->isFinite());
        $this->assertSame($min, $language->minLength());
        $this->assertSame($max, $language->maxLength());
        $this->assertSame($size, $language->size());
    }

    #[Test]
    #[DataProvider('provideCounts')]
    public function test_a_language_counts_its_strings_of_a_length(string $pattern, int $length, string $count): void
    {
        $this->assertSame($count, (new LanguageSolver())->language($pattern)->countOfLength($length));
    }

    #[Test]
    public function test_a_finite_language_lists_every_string_in_order(): void
    {
        $strings = iterator_to_array((new LanguageSolver())->language('/^[ab]{2}$/')->strings(), false);

        $this->assertSame(['aa', 'ab', 'ba', 'bb'], $strings);
    }

    #[Test]
    #[DataProvider('provideEnumerations')]
    public function test_every_string_listed_matches_and_none_is_missed(string $pattern, int $take): void
    {
        $language = (new LanguageSolver())->language($pattern);
        $strings = [];
        foreach ($language->strings() as $string) {
            $strings[] = $string;
            if (\count($strings) >= $take) {
                break;
            }
        }

        $longest = 0;
        foreach ($strings as $index => $string) {
            $this->assertSame(1, preg_match($pattern, $string), \sprintf('%s lists %s.', $pattern, json_encode($string)));
            if ($index > 0) {
                $this->assertTrue(self::before($strings[$index - 1], $string), 'Strings come shortest first, then in order.');
            }
            $longest = max($longest, \strlen($string));
        }

        // Every string up to the longest listed but one, over the letters
        // the pattern reads, that matches is listed.
        foreach (self::subjects('ab01', min($longest - 1, 4)) as $subject) {
            if (1 === preg_match($pattern, $subject)) {
                $this->assertContains($subject, $strings, \sprintf('%s misses %s.', $pattern, json_encode($subject)));
            }
        }
    }

    #[Test]
    #[DataProvider('provideEnumerations')]
    public function test_every_string_proven_out_does_not_match(string $pattern, int $take): void
    {
        // A search reads the subject as preg_match() does, final newline included.
        $count = 0;
        foreach ((new LanguageSolver())->language($pattern, new SolverOptions(matchMode: MatchMode::Partial))->nonMembers() as $string) {
            $this->assertSame(0, preg_match($pattern, $string), \sprintf('%s does match %s.', $pattern, json_encode($string)));
            if (++$count >= $take) {
                break;
            }
        }

        $this->assertSame($take, $count);
    }

    #[Test]
    public function test_a_search_counts_the_subjects_preg_match_accepts(): void
    {
        $language = (new LanguageSolver())->language('/ab/', new SolverOptions(matchMode: MatchMode::Partial));

        // Over every byte: "ab" first then any byte, or any byte then "ab",
        // which cannot both hold: 256 + 256.
        $this->assertSame('512', $language->countOfLength(3));
        $this->assertFalse($language->isFinite());
    }

    #[Test]
    public function test_the_empty_language(): void
    {
        $language = (new LanguageSolver())->language('/[^\s\S]/');

        $this->assertTrue($language->isEmpty());
        $this->assertTrue($language->isFinite());
        $this->assertSame('0', $language->size());
        $this->assertNull($language->minLength());
        $this->assertSame([], iterator_to_array($language->strings(), false));
    }

    #[Test]
    public function test_strings_in_utf_mode_are_whole_characters(): void
    {
        $strings = iterator_to_array((new LanguageSolver())->language('/^[éè]$/u')->strings(), false);

        $this->assertSame(['è', 'é'], $strings);
        $this->assertSame('2', (new LanguageSolver())->language('/^[éè]$/u')->size());
    }

    /**
     * @return iterable<string, array{pattern: string, finite: bool, min: int|null, max: int|null, size: string|null}>
     */
    public static function provideFiniteness(): iterable
    {
        yield 'plate number' => ['pattern' => '/^[A-Z]{2}\d{4}$/', 'finite' => true, 'min' => 6, 'max' => 6, 'size' => '6760000'];
        yield 'one or more' => ['pattern' => '/^a+$/', 'finite' => false, 'min' => 1, 'max' => null, 'size' => null];
        yield 'any of two letters' => ['pattern' => '/^(a|b)*$/', 'finite' => false, 'min' => 0, 'max' => null, 'size' => null];
        yield 'the empty string' => ['pattern' => '/^$/', 'finite' => true, 'min' => 0, 'max' => 0, 'size' => '1'];
        yield 'a range of lengths' => ['pattern' => '/^[ab]{2,3}$/', 'finite' => true, 'min' => 2, 'max' => 3, 'size' => '12'];
        yield 'optional words' => ['pattern' => '/^(?:GET|POST)(?:\?)?$/', 'finite' => true, 'min' => 3, 'max' => 5, 'size' => '4'];
    }

    /**
     * @return iterable<string, array{pattern: string, length: int, count: string}>
     */
    public static function provideCounts(): iterable
    {
        yield 'two letters, length 2' => ['pattern' => '/^[ab]{2,3}$/', 'length' => 2, 'count' => '4'];
        yield 'two letters, length 3' => ['pattern' => '/^[ab]{2,3}$/', 'length' => 3, 'count' => '8'];
        yield 'two letters, length 4' => ['pattern' => '/^[ab]{2,3}$/', 'length' => 4, 'count' => '0'];
        yield 'past a 64-bit integer' => ['pattern' => '/^[a-z]+$/', 'length' => 20, 'count' => '19928148895209409152340197376'];
        yield 'digits with a separator' => ['pattern' => '/^\d+-\d+$/', 'length' => 3, 'count' => '100'];
        yield 'every byte, past a limb' => ['pattern' => '/^[\x00-\xff]+$/', 'length' => 4, 'count' => '4294967296'];
        yield 'the basic plane without surrogates, past a limb' => ['pattern' => '/^[\x{0}-\x{FFFF}]+$/u', 'length' => 2, 'count' => '4030726144'];
    }

    /**
     * @return iterable<string, array{pattern: string, take: int}>
     */
    public static function provideEnumerations(): iterable
    {
        yield 'two letters, any length' => ['pattern' => '/^[ab]*$/', 'take' => 40];
        yield 'digits then a letter' => ['pattern' => '/^\d{1,2}[ab]$/', 'take' => 60];
        yield 'alternation' => ['pattern' => '/^(?:a|ab|b0)+$/', 'take' => 30];
    }

    private static function before(string $first, string $second): bool
    {
        return \strlen($first) < \strlen($second) || (\strlen($first) === \strlen($second) && strcmp($first, $second) < 0);
    }

    /**
     * @return list<string>
     */
    private static function subjects(string $letters, int $length): array
    {
        $all = [''];
        $layer = [''];
        for ($size = 1; $size <= $length; $size++) {
            $next = [];
            foreach ($layer as $prefix) {
                foreach (str_split($letters) as $letter) {
                    $next[] = $prefix.$letter;
                }
            }
            $all = [...$all, ...$next];
            $layer = $next;
        }

        return $all;
    }
}
