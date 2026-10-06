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

use PHPRegex\Parser\Analysis\CaptureGroupShape;
use PHPRegex\Parser\Analysis\CaptureShape;
use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\Analysis\Participation;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a successful preg_match() writes into $matches, read from the pattern
 * alone. Every fact is checked against the engine: the shape must hold for
 * each subject below, under each combination of PREG_UNMATCHED_AS_NULL and
 * PREG_OFFSET_CAPTURE.
 */
final class CaptureShapeAnalyzerTest extends TestCase
{
    private const FLAG_SETS = [0, \PREG_UNMATCHED_AS_NULL, \PREG_OFFSET_CAPTURE, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL];

    /**
     * @param array<int<1, max>, Participation> $expected keyed by group number
     */
    #[Test]
    #[DataProvider('provideParticipation')]
    public function test_participation_is_read_from_the_pattern(string $pattern, array $expected): void
    {
        $shape = $this->analyze($pattern);

        $this->assertSame($expected, array_map(static fn (CaptureGroupShape $group): Participation => $group->participation, $shape->groups));
    }

    #[Test]
    public function test_the_whole_match_always_participates(): void
    {
        $this->assertSame(Participation::Always, $this->analyze('/(a)?/')->whole->participation);
        $this->assertSame(0, $this->analyze('/(a)?/')->whole->number);
    }

    /**
     * @param list<string>|null $values
     */
    #[Test]
    #[DataProvider('provideValues')]
    public function test_a_group_knows_its_values_when_they_are_finite(string $pattern, int $group, ?array $values): void
    {
        $this->assertSame($values, $this->group($pattern, $group)->values);
    }

    #[Test]
    #[DataProvider('provideLengths')]
    public function test_a_group_knows_its_length(string $pattern, int $group, int $min, ?int $max): void
    {
        $shape = $this->group($pattern, $group);

        $this->assertSame($min, $shape->minLength);
        $this->assertSame($max, $shape->maxLength);
    }

    #[Test]
    public function test_names_and_numbers_are_both_known(): void
    {
        $shape = $this->analyze('/(?<year>\d{4})-(\d\d)/');

        $this->assertSame('year', $shape->groups[1]->name);
        $this->assertSame(1, $shape->groups[1]->number);
        $this->assertNull($shape->groups[2]->name);
        $this->assertSame(2, $shape->groups[2]->number);
    }

    #[Test]
    public function test_marks_are_collected(): void
    {
        $this->assertSame(['m', 'n'], $this->analyze('/(*MARK:m)a|(*:n)b/')->marks);
        $this->assertSame([], $this->analyze('/a/')->marks);
    }

    #[Test]
    #[DataProvider('provideShapes')]
    public function test_the_match_shape_is_written_as_a_phpstan_type(string $pattern, int $flags, string $expected): void
    {
        $this->assertSame($expected, $this->analyze($pattern)->matchShape($flags));
    }

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideEngineRows')]
    public function test_every_fact_holds_for_what_the_engine_writes(string $pattern, array $subjects): void
    {
        $shape = $this->analyze($pattern);

        foreach ($subjects as $subject) {
            foreach (self::FLAG_SETS as $flags) {
                $matches = [];
                $this->assertSame(1, preg_match($pattern, $subject, $matches, $flags), \sprintf('%s must match "%s".', $pattern, $subject));
                $this->assertMatchesFollow($shape, $matches, $flags, RegexParser::create()->parse($pattern)->isUnicode(), \sprintf('%s on "%s" with flags %d', $pattern, $subject, $flags));
            }
        }
    }

    /**
     * @param list<int> $numbers
     */
    #[Test]
    #[DataProvider('provideGroupNumbers')]
    public function test_groups_are_keyed_by_number(string $pattern, string $subject, array $numbers): void
    {
        $shape = $this->analyze($pattern);

        $this->assertSame($numbers, array_keys($shape->groups));
        foreach ($shape->groups as $number => $group) {
            $this->assertSame($number, $group->number);
        }

        // Exactly 1..N, N the capture count: under PREG_UNMATCHED_AS_NULL
        // the engine writes every group, set or not.
        $matches = [];
        $this->assertSame(1, preg_match($pattern, $subject, $matches, \PREG_UNMATCHED_AS_NULL));
        $this->assertSame(array_values(array_filter(array_keys($matches), static fn (int|string $key): bool => \is_int($key) && $key > 0)), array_keys($shape->groups));
        $this->assertCount(\count($numbers), $shape->groups);
    }

    #[Test]
    public function test_branch_reset_groups_share_one_record(): void
    {
        // preg_match('/(?|(a)|(b)(c))(d)/', 'bcd') -> ["bcd","b","c","d"]; on 'ad' -> ["ad","a","","d"]
        $shape = $this->analyze('/(?|(a)|(b)(c))(d)/');

        $this->assertSame(['a', 'b'], $shape->groups[1]->values);
        $this->assertSame(Participation::Always, $shape->groups[1]->participation);
        $this->assertSame(['c'], $shape->groups[2]->values);
        $this->assertSame(Participation::MayBeUnset, $shape->groups[2]->participation);
        $this->assertSame(['d'], $shape->groups[3]->values);
    }

    #[Test]
    #[DataProvider('provideBranchResetNames')]
    public function test_a_branch_reset_group_takes_the_name_any_branch_gives(string $pattern, string $expected): void
    {
        $shape = $this->analyze($pattern);

        $this->assertSame($expected, $shape->matchShape());
        $this->assertCount(1, $shape->groups);
        $this->assertArrayHasKey(1, $shape->groups);
        $this->assertSame('a', $shape->groups[1]->name);
    }

    #[Test]
    #[DataProvider('provideMarkGroups')]
    public function test_a_group_named_mark_shares_the_key_with_the_verb(string $pattern, int $flags, string $expected): void
    {
        $written = $this->analyze($pattern)->matchShape($flags);

        $this->assertSame(['MARK'], array_values(array_filter(self::topLevelKeys($written), static fn (string $key): bool => 'MARK' === $key)), \sprintf('"%s" must have one MARK key.', $written));
        $this->assertSame($expected, $written);
    }

    /**
     * PHP writes $matches in insertion order: 0, then per group its name
     * before its number. The string lists its keys in that order, once each.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideEngineRows')]
    public function test_the_match_shape_lists_keys_in_the_order_preg_match_writes_them(string $pattern, array $subjects): void
    {
        $shape = $this->analyze($pattern);

        foreach (self::FLAG_SETS as $flags) {
            $keys = self::topLevelKeys($shape->matchShape($flags));
            $this->assertSame(array_values(array_unique($keys)), $keys, \sprintf('%s with flags %d writes a key twice.', $pattern, $flags));

            foreach ($subjects as $subject) {
                $matches = [];
                preg_match($pattern, $subject, $matches, $flags);
                $written = array_map(strval(...), array_keys($matches));

                $this->assertSame($written, array_values(array_intersect($keys, $written)), \sprintf('%s on "%s" with flags %d: keys out of order.', $pattern, $subject, $flags));
            }
        }
    }

    /**
     * A name several groups share under (?J) holds the value of the
     * highest-numbered of those groups that is set; when none is set, what an
     * unset group holds under the flags. Read from the engine:
     *   preg_match('/(?J)(?<n>a)(?<n>b)/', 'ab')            -> n => 'b' (groups 1 and 2 set)
     *   preg_match('/(?J)(?:(?<n>a)|(?<n>b))(x)/', 'ax')    -> n => 'a' (group 2 unset, '' / null)
     *   preg_match('/(?J)(?<n>a)(?<n>z)?(?<n>c)/', 'ac')    -> n => 'c'
     *   preg_match('/(?J)(?<n>a)(?<n>)/', 'a', OFFSET)      -> n => ['', 1] (a group set to '' is set)
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideSharedNames')]
    public function test_a_shared_name_holds_the_highest_numbered_group_set(string $pattern, array $subjects): void
    {
        $shape = $this->analyze($pattern);
        $sharing = array_values(array_map(static fn (CaptureGroupShape $group): int => $group->number, array_filter($shape->groups, static fn (CaptureGroupShape $group): bool => 'n' === $group->name)));
        $this->assertGreaterThan(1, \count($sharing), \sprintf('%s: several groups must be named n.', $pattern));

        foreach ($subjects as $subject) {
            $located = [];
            preg_match($pattern, $subject, $located, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL);
            $set = array_values(array_filter($sharing, static fn (int $number): bool => -1 !== $located[$number][1]));

            foreach (self::FLAG_SETS as $flags) {
                $matches = [];
                $this->assertSame(1, preg_match($pattern, $subject, $matches, $flags));
                $context = \sprintf('%s on "%s" with flags %d', $pattern, $subject, $flags);

                if ([] !== $set) {
                    $this->assertSame($matches[max($set)], $matches['n'], $context);

                    continue;
                }

                $unset = 0 !== ($flags & \PREG_UNMATCHED_AS_NULL) ? null : '';
                if (\array_key_exists('n', $matches)) {
                    $this->assertSame(0 !== ($flags & \PREG_OFFSET_CAPTURE) ? [$unset, -1] : $unset, $matches['n'], $context);
                }
            }
        }
    }

    /**
     * preg_match() refuses any bit of the low byte that is not one of its
     * flags (ValueError "Argument #4 ($flags) must be a PREG_* constant",
     * PHP 8.4.26): matchShape() refuses the same, with the library's own
     * exception.
     */
    #[Test]
    #[DataProvider('provideRefusedFlags')]
    public function test_match_shape_refuses_a_flag_preg_match_refuses(int $flags): void
    {
        try {
            preg_match('/(a)/', 'a', $matches, $flags);
            $this->fail(\sprintf('preg_match() was expected to refuse flags %d.', $flags));
        } catch (\ValueError) {
        }

        $shape = $this->analyze('/(a)/');

        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage(\sprintf('got flags %d.', $flags));

        $shape->matchShape($flags);
    }

    #[Test]
    public function test_match_shape_refusal_names_the_accepted_flags(): void
    {
        try {
            $this->analyze('/(a)/')->matchShape(\PREG_SET_ORDER);
            $this->fail('PREG_SET_ORDER was expected to be refused.');
        } catch (InvalidRegexOptionException $e) {
            $this->assertStringContainsString('PREG_OFFSET_CAPTURE', $e->getMessage());
            $this->assertStringContainsString('PREG_UNMATCHED_AS_NULL', $e->getMessage());
        }
    }

    #[Test]
    public function test_match_shape_accepts_both_flags_preg_match_accepts(): void
    {
        $this->assertSame("array{0: array{'a', int<0, max>}, 1: array{'a', int<0, max>}}", $this->analyze('/(a)/')->matchShape(\PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL));
    }

    /**
     * preg_match() ignores a bit above the low byte: '/(a)/' with 1024 writes
     * ["a","a"], with 256|1024 [["a",0],["a",0]] (PHP 8.4.26). matchShape()
     * ignores it too, so a later flag that leaves $matches alone needs no
     * change here.
     *
     * @param 0|256|512|768 $meaningful
     */
    #[Test]
    #[DataProvider('provideIgnoredFlags')]
    public function test_match_shape_ignores_a_flag_preg_match_ignores(int $flags, int $meaningful): void
    {
        $with = [];
        $without = [];
        $this->assertSame(1, preg_match('/(z)?(a)/', 'a', $with, $flags));
        $this->assertSame(1, preg_match('/(z)?(a)/', 'a', $without, $meaningful));
        $this->assertSame($without, $with);

        $shape = $this->analyze('/(z)?(a)/');

        $this->assertSame($shape->matchShape($meaningful), $shape->matchShape($flags));
    }

    /**
     * Each group's key reads what the groups after it may do, and each name
     * what every group sharing it holds: matchShape() looks both up once per
     * call, so its time grows with the group count, not with its square.
     * The analysis runs before the clock starts.
     */
    #[Test]
    #[DataProvider('provideManyGroups')]
    public function test_match_shape_stays_fast_with_many_groups(string $pattern, int $flags): void
    {
        $shape = $this->analyze($pattern);

        $start = hrtime(true);
        $shape->matchShape($flags);
        $seconds = (hrtime(true) - $start) / 1e9;

        $this->assertLessThan(2.0, $seconds, \sprintf('matchShape(%d) took %.2f s.', $flags, $seconds));
    }

    #[Test]
    public function test_the_analysis_version_is_a_public_string_constant(): void
    {
        $constant = new \ReflectionClassConstant(CaptureShapeAnalyzer::class, 'ANALYSIS_VERSION');
        $version = $constant->getValue();

        $this->assertTrue($constant->isPublic());
        // A string, as RedosAnalyzer::ANALYSIS_VERSION: both key caches.
        $this->assertIsString($version);
        $this->assertMatchesRegularExpression('/^[1-9]\d*$/', $version);
    }

    /**
     * @return iterable<string, array{pattern: string, expected: array<int<1, max>, Participation>}>
     */
    public static function provideParticipation(): iterable
    {
        $always = Participation::Always;
        $maybe = Participation::MayBeUnset;
        $never = Participation::Never;

        yield 'plain group' => ['pattern' => '/(a)/', 'expected' => [1 => $always]];
        yield 'optional group' => ['pattern' => '/(a)?/', 'expected' => [1 => $maybe]];
        yield 'one branch' => ['pattern' => '/(a)|b/', 'expected' => [1 => $maybe]];
        yield 'every branch' => ['pattern' => '/(?:(a)x|(a)y)/', 'expected' => [1 => $maybe, 2 => $maybe]];
        yield 'repeated at least once' => ['pattern' => '/(a)+/', 'expected' => [1 => $always]];
        yield 'nested optional' => ['pattern' => '/((a)?b)/', 'expected' => [1 => $always, 2 => $maybe]];
        yield 'positive lookahead' => ['pattern' => '/(?=(a))a/', 'expected' => [1 => $always]];
        yield 'negative lookahead' => ['pattern' => '/(?!(b))a/', 'expected' => [1 => $never]];
        yield 'negative lookbehind' => ['pattern' => '/(?<!(b))a/', 'expected' => [1 => $never]];
        yield 'define' => ['pattern' => '/(?(DEFINE)(x))a(?1)/', 'expected' => [1 => $never]];
        yield 'zero repeat' => ['pattern' => '/(a){0}b/', 'expected' => [1 => $never]];
        yield 'branch reset' => ['pattern' => '/(?|(a)|(b))/', 'expected' => [1 => $always]];
        yield 'branch reset, one branch short' => ['pattern' => '/(?|(a)(b)|(c))/', 'expected' => [1 => $always, 2 => $maybe]];
        yield 'conditional on both sides' => ['pattern' => '/(x)?(?(1)(a)|(b))/', 'expected' => [1 => $maybe, 2 => $maybe, 3 => $maybe]];
        yield 'conditional assertion' => ['pattern' => '/(?(?=(a))a|b)/', 'expected' => [1 => $maybe]];
        yield 'atomic' => ['pattern' => '/(?>(a))/', 'expected' => [1 => $always]];
        yield 'recursion' => ['pattern' => '/(a(?1)?b)/', 'expected' => [1 => $always]];
        yield 'accept cuts the match short' => ['pattern' => '/(a(*ACCEPT)b)c/', 'expected' => [1 => $maybe]];
    }

    /**
     * @return iterable<string, array{pattern: string, group: int, values: list<string>|null}>
     */
    public static function provideValues(): iterable
    {
        yield 'literal' => ['pattern' => '/(foo)/', 'group' => 1, 'values' => ['foo']];
        yield 'alternation' => ['pattern' => '/(foo|bar)/', 'group' => 1, 'values' => ['foo', 'bar']];
        yield 'product' => ['pattern' => '/((?:a|b)(?:c|d))/', 'group' => 1, 'values' => ['ac', 'ad', 'bc', 'bd']];
        yield 'optional piece' => ['pattern' => '/(ab?)/', 'group' => 1, 'values' => ['a', 'ab']];
        yield 'escape' => ['pattern' => '/(\x41|\n)/', 'group' => 1, 'values' => ['A', "\n"]];
        yield 'escape in utf mode' => ['pattern' => '/(\x{e9})/u', 'group' => 1, 'values' => ['é']];
        yield 'alternation with a class' => ['pattern' => '/(a|\d)/', 'group' => 1, 'values' => null];
        yield 'accept' => ['pattern' => '/(a(*ACCEPT)b)c/', 'group' => 1, 'values' => null];
        yield 'accept before any text' => ['pattern' => '/((*ACCEPT)a)/', 'group' => 1, 'values' => null];
        yield 'class' => ['pattern' => '/(\d)/', 'group' => 1, 'values' => null];
        yield 'caseless' => ['pattern' => '/(foo)/i', 'group' => 1, 'values' => null];
        yield 'inline caseless' => ['pattern' => '/(?i)(foo)/', 'group' => 1, 'values' => null];
        yield 'unbounded' => ['pattern' => '/(a+)/', 'group' => 1, 'values' => null];
        yield 'extended mode' => ['pattern' => '/(a b)/x', 'group' => 1, 'values' => ['ab']];
        yield 'branch reset joins' => ['pattern' => '/(?|(a)|(b))/', 'group' => 1, 'values' => ['a', 'b']];
        yield 'whole match' => ['pattern' => '/a(b|c)/', 'group' => 0, 'values' => ['ab', 'ac']];
        yield 'keep resets the whole match' => ['pattern' => '/a\K(b)/', 'group' => 0, 'values' => null];
        yield 'too many' => ['pattern' => '/((?:a|b|c|d)(?:a|b|c|d)(?:a|b|c|d))/', 'group' => 1, 'values' => null];
    }

    /**
     * @return iterable<string, array{pattern: string, group: int, min: int, max: int|null}>
     */
    public static function provideLengths(): iterable
    {
        yield 'fixed' => ['pattern' => '/(\d{4})/', 'group' => 1, 'min' => 4, 'max' => 4];
        yield 'range' => ['pattern' => '/(a{2,5})/', 'group' => 1, 'min' => 2, 'max' => 5];
        yield 'unbounded' => ['pattern' => '/(a*)/', 'group' => 1, 'min' => 0, 'max' => null];
        yield 'branch reset widens' => ['pattern' => '/(?|(a)|(bcd))/', 'group' => 1, 'min' => 1, 'max' => 3];
        // A group (*ACCEPT) leaves open holds what it read so far: its length is not measured.
        // preg_match('/((*ACCEPT)a)/', 'x') -> ["",""]: the group reads nothing, so the minimum is exactly 0 (PHP 8.4.26)
        yield 'accept leaves the group open before any text' => ['pattern' => '/((*ACCEPT)a)/', 'group' => 1, 'min' => 0, 'max' => null];
        // preg_match('/(a(*ACCEPT)b)c/', 'abc') -> ["a","a"]
        yield 'accept leaves the group open after some text' => ['pattern' => '/(a(*ACCEPT)b)c/', 'group' => 1, 'min' => 0, 'max' => null];
    }

    /**
     * @return iterable<string, array{pattern: string, flags: int, expected: string}>
     */
    public static function provideShapes(): iterable
    {
        yield 'trailing optional group is left out' => ['pattern' => '/(a)(z)?/', 'flags' => 0, 'expected' => "array{0: 'a'|'az', 1: 'a', 2?: 'z'}"];
        yield 'middle optional group is empty' => ['pattern' => '/(z)?(a)/', 'flags' => 0, 'expected' => "array{0: 'a'|'za', 1: ''|'z', 2: 'a'}"];
        yield 'unmatched as null' => ['pattern' => '/(a)(x)?(b)/', 'flags' => \PREG_UNMATCHED_AS_NULL, 'expected' => "array{0: 'ab'|'axb', 1: 'a', 2: 'x'|null, 3: 'b'}"];
        yield 'named group' => ['pattern' => '/(?<n>a+)(b)/', 'flags' => 0, 'expected' => 'array{0: non-empty-string, n: non-empty-string, 1: non-empty-string, 2: \'b\'}'];
        yield 'offset capture' => ['pattern' => '/(z)?(a)/', 'flags' => \PREG_OFFSET_CAPTURE, 'expected' => "array{0: array{'a'|'za', int<0, max>}, 1: array{''|'z', int<-1, max>}, 2: array{'a', int<0, max>}}"];
        yield 'never set, then set' => ['pattern' => '/(?!(b))(a)/', 'flags' => 0, 'expected' => "array{0: 'a', 1: '', 2: 'a'}"];
        yield 'never set, last' => ['pattern' => '/(?!(b))a/', 'flags' => 0, 'expected' => "array{0: 'a'}"];
        yield 'never set, last, as null' => ['pattern' => '/(?!(b))a/', 'flags' => \PREG_UNMATCHED_AS_NULL, 'expected' => "array{0: 'a', 1: null}"];
        yield 'mark' => ['pattern' => '/(*MARK:m)a/', 'flags' => 0, 'expected' => "array{0: 'a', MARK?: 'm'}"];
        // preg_match('/(?<x>a)(*MARK:m)b/', 'ab') -> {"0":"ab","x":"a","1":"a","MARK":"m"}: x keeps its own value, MARK comes last (PHP 8.4.26)
        yield 'named group next to a mark verb' => ['pattern' => '/(?<x>a)(*MARK:m)b/', 'flags' => 0, 'expected' => "array{0: 'ab', x: 'a', 1: 'a', MARK?: 'm'}"];
        // preg_match('/(?<MARK>a)/', 'a') -> {"0":"a","MARK":"a","1":"a"}: without a verb the key holds the group alone
        yield 'group named MARK, no mark verb' => ['pattern' => '/(?<MARK>a)/', 'flags' => 0, 'expected' => "array{0: 'a', MARK: 'a', 1: 'a'}"];
        yield 'trailing optional named group' => ['pattern' => '/(?<y>\d{4})(?:-(?<d>\d\d))?/', 'flags' => 0, 'expected' => 'array{0: non-empty-string, y: non-empty-string, 1: non-empty-string, d?: non-empty-string, 2?: non-empty-string}'];
        yield 'duplicate names' => ['pattern' => '/(?J)(?<n>a)|(?<n>b)/', 'flags' => 0, 'expected' => "array{0: 'a'|'b', n?: ''|'a'|'b', 1?: ''|'a', 2?: 'b'}"];
        yield 'unknown text' => ['pattern' => '/(\w*)/', 'flags' => 0, 'expected' => 'array{0: string, 1: string}'];
        yield 'quote in a value' => ['pattern' => "/(it's)/", 'flags' => 0, 'expected' => "array{0: 'it\\'s', 1: 'it\\'s'}"];
        yield 'unprintable value' => ['pattern' => '/(\x01)/', 'flags' => 0, 'expected' => 'array{0: non-empty-string, 1: non-empty-string}'];
        yield 'empty group of unknown text' => ['pattern' => '/(\b)a/i', 'flags' => 0, 'expected' => "array{0: non-empty-string, 1: ''}"];
        yield 'too many values to write' => ['pattern' => '/(a|b|c|d|e|f|g|h|i|j|k|l|m|n|o|p|q)/', 'flags' => 0, 'expected' => 'array{0: non-empty-string, 1: non-empty-string}'];
        // preg_match('/(a)(?<x>b)/n', 'ab') -> {"0":"ab","x":"b","1":"b"}, the same under (?n) (PHP 8.4.26)
        yield 'no auto capture' => ['pattern' => '/(a)(?<x>b)/n', 'flags' => 0, 'expected' => "array{0: 'ab', x: 'b', 1: 'b'}"];
        yield 'inline no auto capture' => ['pattern' => '/(?n)(a)(?<x>b)/', 'flags' => 0, 'expected' => "array{0: 'ab', x: 'b', 1: 'b'}"];
        yield 'no auto capture, offsets' => ['pattern' => '/(a)(?<x>b)/n', 'flags' => \PREG_OFFSET_CAPTURE, 'expected' => "array{0: array{'ab', int<0, max>}, x: array{'b', int<0, max>}, 1: array{'b', int<0, max>}}"];
        // preg_match('/(?J)(?:(?<n>a)|(?<n>b))(x)/', 'ax') -> n => 'a', 2 => ''; on 'bx' -> n => 'b', 1 => ''
        yield 'shared name, one group set' => ['pattern' => '/(?J)(?:(?<n>a)|(?<n>b))(x)/', 'flags' => 0, 'expected' => "array{0: 'ax'|'bx', n: ''|'a'|'b', 1: ''|'a', 2: ''|'b', 3: 'x'}"];
        yield 'shared name, one group set, as null' => ['pattern' => '/(?J)(?:(?<n>a)|(?<n>b))(x)/', 'flags' => \PREG_UNMATCHED_AS_NULL, 'expected' => "array{0: 'ax'|'bx', n: 'a'|'b'|null, 1: 'a'|null, 2: 'b'|null, 3: 'x'}"];
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideEngineRows(): iterable
    {
        yield '/(a)(z)?/' => ['pattern' => '/(a)(z)?/', 'subjects' => ['a', 'az']];
        yield '/(z)?(a)/' => ['pattern' => '/(z)?(a)/', 'subjects' => ['a', 'za']];
        yield '/(a)(x)?(b)/' => ['pattern' => '/(a)(x)?(b)/', 'subjects' => ['ab', 'axb']];
        yield '/(a)|b/' => ['pattern' => '/(a)|b/', 'subjects' => ['a', 'b']];
        yield '/(?=(a))a/' => ['pattern' => '/(?=(a))a/', 'subjects' => ['a']];
        yield '/(?!(b))a/' => ['pattern' => '/(?!(b))a/', 'subjects' => ['a']];
        yield '/(?!(b))(a)/' => ['pattern' => '/(?!(b))(a)/', 'subjects' => ['a']];
        yield '/(?(DEFINE)(x))a(?1)/' => ['pattern' => '/(?(DEFINE)(x))a(?1)/', 'subjects' => ['ax']];
        yield '/(?|(a)|(b))/' => ['pattern' => '/(?|(a)|(b))/', 'subjects' => ['a', 'b']];
        yield '/(?|(a)(b)|(c))/' => ['pattern' => '/(?|(a)(b)|(c))/', 'subjects' => ['ab', 'c']];
        yield '/((a)|b)+/' => ['pattern' => '/((a)|b)+/', 'subjects' => ['ab', 'ba', 'bb']];
        yield '/(?<n>a)(b)/' => ['pattern' => '/(?<n>a)(b)/', 'subjects' => ['ab']];
        yield '/(?J)(?<n>a)|(?<n>b)/' => ['pattern' => '/(?J)(?<n>a)|(?<n>b)/', 'subjects' => ['a', 'b']];
        yield '/(a){0}b/' => ['pattern' => '/(a){0}b/', 'subjects' => ['b']];
        yield '/^(\d{3})-(\d{4})$/' => ['pattern' => '/^(\d{3})-(\d{4})$/', 'subjects' => ['555-1234']];
        yield '/(foo|bar)(baz)?/i' => ['pattern' => '/(foo|bar)(baz)?/i', 'subjects' => ['FOObaz', 'bar']];
        yield '/(a b)/x' => ['pattern' => '/(a b)/x', 'subjects' => ['ab']];
        yield '/(?<y>\d{4})-(?<m>\d\d)?/' => ['pattern' => '/(?<y>\d{4})-(?<m>\d\d)?/', 'subjects' => ['2026-10', '2026-']];
        yield '/(x)?(?(1)(a)|(b))/' => ['pattern' => '/(x)?(?(1)(a)|(b))/', 'subjects' => ['xa', 'b']];
        yield '/(a(?1)?b)/' => ['pattern' => '/(a(?1)?b)/', 'subjects' => ['ab', 'aabb']];
        yield '/a\K(b)/' => ['pattern' => '/a\K(b)/', 'subjects' => ['ab']];
        yield '/(*MARK:m)(a)|(b)/' => ['pattern' => '/(*MARK:m)(a)|(b)/', 'subjects' => ['a', 'b']];
        yield '/(é)+/' => ['pattern' => '/(é)+/', 'subjects' => ['éé']];
        yield '/(\x41|\n)/' => ['pattern' => '/(\x41|\n)/', 'subjects' => ['A', "\n"]];
        yield '/()/' => ['pattern' => '/()/', 'subjects' => ['']];
        yield '/(a*)(b)?/' => ['pattern' => '/(a*)(b)?/', 'subjects' => ['', 'aab']];
        yield '/(?<n>a)?(?<m>z)?/' => ['pattern' => '/(?<n>a)?(?<m>z)?/', 'subjects' => ['', 'z']];
        yield "/(it's)/" => ['pattern' => "/(it's)/", 'subjects' => ["it's"]];
        yield '/(a(*ACCEPT)b)c/' => ['pattern' => '/(a(*ACCEPT)b)c/', 'subjects' => ['a', 'abc']];
        yield '/(\x{e9})/u' => ['pattern' => '/(\x{e9})/u', 'subjects' => ['é']];
        yield '/(\b)a/i' => ['pattern' => '/(\b)a/i', 'subjects' => ['A']];
        yield '/(?<MARK>a)(*MARK:x)b/' => ['pattern' => '/(?<MARK>a)(*MARK:x)b/', 'subjects' => ['ab']];
        yield '/(?<MARK>a)|(*MARK:x)b/' => ['pattern' => '/(?<MARK>a)|(*MARK:x)b/', 'subjects' => ['a', 'b']];
        yield '/(?<MARK>a)(?<y>b)(*MARK:x)c/' => ['pattern' => '/(?<MARK>a)(?<y>b)(*MARK:x)c/', 'subjects' => ['abc']];
        yield '/(?<MARK>a)?(b)(?:(*MARK:x)c|d|(*MARK:y)e)/' => ['pattern' => '/(?<MARK>a)?(b)(?:(*MARK:x)c|d|(*MARK:y)e)/', 'subjects' => ['bc', 'bd', 'be', 'abc', 'abd', 'abe']];
        yield '/(?<MARK>a)/' => ['pattern' => '/(?<MARK>a)/', 'subjects' => ['a']];
        yield '/(?<x>a)(*MARK:m)b/' => ['pattern' => '/(?<x>a)(*MARK:m)b/', 'subjects' => ['ab']];
        yield '/((*ACCEPT)a)/' => ['pattern' => '/((*ACCEPT)a)/', 'subjects' => ['x', 'a']];
        yield '/(?|(x)|(?<a>y))/' => ['pattern' => '/(?|(x)|(?<a>y))/', 'subjects' => ['x', 'y']];
        yield '/(?|(?<a>x)|(y))/' => ['pattern' => '/(?|(?<a>x)|(y))/', 'subjects' => ['x', 'y']];
        yield '/(?J)(?:(?<n>a)|(?<n>b))(x)/' => ['pattern' => '/(?J)(?:(?<n>a)|(?<n>b))(x)/', 'subjects' => ['ax', 'bx']];
        yield '/(?J)(?<n>a)(?<n>b)/' => ['pattern' => '/(?J)(?<n>a)(?<n>b)/', 'subjects' => ['ab']];
        yield '/(?J)(?:(?<n>a)|(?<n>b))?(x)/' => ['pattern' => '/(?J)(?:(?<n>a)|(?<n>b))?(x)/', 'subjects' => ['x', 'ax']];
        yield '/(a)(?<x>b)/n' => ['pattern' => '/(a)(?<x>b)/n', 'subjects' => ['ab']];
        yield '/(?n)(a)(?<x>b)/' => ['pattern' => '/(?n)(a)(?<x>b)/', 'subjects' => ['ab']];
        yield '/(?<p>a)(b)(?<q>c)/n' => ['pattern' => '/(?<p>a)(b)(?<q>c)/n', 'subjects' => ['abc']];
        yield '/(?(DEFINE)(?<d>x))(a)(?&d)/' => ['pattern' => '/(?(DEFINE)(?<d>x))(a)(?&d)/', 'subjects' => ['ax']];
        yield '/(?|(a)|(b)(c))(d)/' => ['pattern' => '/(?|(a)|(b)(c))(d)/', 'subjects' => ['ad', 'bcd']];
    }

    /**
     * Subjects read with PREG_UNMATCHED_AS_NULL (PHP 8.4.26):
     *   '/(?(DEFINE)(?<d>x))(a)(?&d)/' on 'ax' -> {"0":"ax","d":null,"1":null,"2":"a"}
     *   '/(?<p>a)(b)(?<q>c)/n' on 'abc'        -> {"0":"abc","p":"a","1":"a","q":"c","2":"c"}
     *   '/(?|(a)(b)|(c))(d)/' on 'cd'          -> ["cd","c",null,"d"]
     *
     * @return iterable<string, array{pattern: string, subject: string, numbers: list<int>}>
     */
    public static function provideGroupNumbers(): iterable
    {
        yield 'no group' => ['pattern' => '/a/', 'subject' => 'a', 'numbers' => []];
        yield 'two groups' => ['pattern' => '/(a)(b)/', 'subject' => 'ab', 'numbers' => [1, 2]];
        yield 'branch reset, unequal branches' => ['pattern' => '/(?|(a)|(b)(c))(d)/', 'subject' => 'bcd', 'numbers' => [1, 2, 3]];
        yield 'branch reset, short branch taken' => ['pattern' => '/(?|(a)(b)|(c))(d)/', 'subject' => 'cd', 'numbers' => [1, 2, 3]];
        yield 'never set: negative lookahead' => ['pattern' => '/(?!(b))a/', 'subject' => 'a', 'numbers' => [1]];
        yield 'never set: zero repeat' => ['pattern' => '/(a){0}b/', 'subject' => 'b', 'numbers' => [1]];
        yield 'group inside DEFINE' => ['pattern' => '/(?(DEFINE)(x))a(?1)/', 'subject' => 'ax', 'numbers' => [1]];
        yield 'named group inside DEFINE' => ['pattern' => '/(?(DEFINE)(?<d>x))(a)(?&d)/', 'subject' => 'ax', 'numbers' => [1, 2]];
        yield 'named groups under /n' => ['pattern' => '/(?<p>a)(b)(?<q>c)/n', 'subject' => 'abc', 'numbers' => [1, 2]];
        yield 'named group under (?n)' => ['pattern' => '/(?n)(a)(?<x>b)/', 'subject' => 'ab', 'numbers' => [1]];
    }

    /**
     * preg_match('/(?|(x)|(?<a>y))/', 'x') -> {"0":"x","a":"x","1":"x"}
     * preg_match('/(?|(?<a>x)|(y))/', 'y') -> {"0":"y","a":"y","1":"y"}
     * (PHP 8.4.26; two different names for one number do not compile).
     *
     * @return iterable<string, array{pattern: string, expected: string}>
     */
    public static function provideBranchResetNames(): iterable
    {
        yield 'name in the second branch' => ['pattern' => '/(?|(x)|(?<a>y))/', 'expected' => "array{0: 'x'|'y', a: 'x'|'y', 1: 'x'|'y'}"];
        yield 'name in the first branch' => ['pattern' => '/(?|(?<a>x)|(y))/', 'expected' => "array{0: 'x'|'y', a: 'x'|'y', 1: 'x'|'y'}"];
    }

    /**
     * One MARK key: the group's values and the mark names, required when the
     * group always participates. Under PREG_OFFSET_CAPTURE the group writes a
     * pair and the verb a plain string. Read from the engine (PHP 8.4.26):
     *   '/(?<MARK>a)(*MARK:x)b/' on 'ab'              -> [0 => 'ab', 'MARK' => 'x', 1 => 'a']
     *   '/(?<MARK>a)(*MARK:x)b/' on 'ab', OFFSET      -> [0 => ['ab', 0], 'MARK' => 'x', 1 => ['a', 0]]
     *   '/(?<MARK>a)|(*MARK:x)b/' on 'a'              -> [0 => 'a', 'MARK' => 'a', 1 => 'a']
     *   '/(?<MARK>a)|(*MARK:x)b/' on 'b'              -> [0 => 'b', 'MARK' => 'x']
     *   '/(?<MARK>a)|(*MARK:x)b/' on 'a', OFFSET      -> [0 => ['a', 0], 'MARK' => ['a', 0], 1 => ['a', 0]]
     *   '/(?<MARK>a)|(*MARK:x)b/' on 'b', OFFSET      -> [0 => ['b', 0], 'MARK' => 'x']
     *   '/(?<MARK>a)(?<y>b)(*MARK:x)c/' on 'abc'      -> [0 => 'abc', 'MARK' => 'x', 1 => 'a', 'y' => 'b', 2 => 'b']
     *   '/(?<MARK>a)?(b)(?:(*MARK:x)c|d|(*MARK:y)e)/':
     *     on 'abd' -> 'MARK' => 'a'; on 'bc' -> 'x'; on 'be' -> 'y';
     *     on 'bd'  -> 'MARK' => '' (null with UNMATCHED_AS_NULL): no verb on the matching path, group 1 unset
     *
     * @return iterable<string, array{pattern: string, flags: int, expected: string}>
     */
    public static function provideMarkGroups(): iterable
    {
        // A named group after the one named MARK must not write the key a second time.
        yield 'another named group after it' => ['pattern' => '/(?<MARK>a)(?<y>b)(*MARK:x)c/', 'flags' => 0, 'expected' => "array{0: 'abc', MARK: 'a'|'x', 1: 'a', y: 'b', 2: 'b'}"];
        // Every member of the MARK union is reachable: the group's value, its unset value, each mark name.
        yield 'group may be unset, several mark names' => ['pattern' => '/(?<MARK>a)?(b)(?:(*MARK:x)c|d|(*MARK:y)e)/', 'flags' => 0, 'expected' => "array{0: 'bc'|'bd'|'be'|'abc'|'abd'|'abe', MARK: ''|'a'|'x'|'y', 1: ''|'a', 2: 'b'}"];
        yield 'group may be unset, several mark names, as null' => ['pattern' => '/(?<MARK>a)?(b)(?:(*MARK:x)c|d|(*MARK:y)e)/', 'flags' => \PREG_UNMATCHED_AS_NULL, 'expected' => "array{0: 'bc'|'bd'|'be'|'abc'|'abd'|'abe', MARK: 'a'|'x'|'y'|null, 1: 'a'|null, 2: 'b'}"];
        yield 'group always set, verb after it' => ['pattern' => '/(?<MARK>a)(*MARK:x)b/', 'flags' => 0, 'expected' => "array{0: 'ab', MARK: 'a'|'x', 1: 'a'}"];
        yield 'group always set, verb after it, offsets' => ['pattern' => '/(?<MARK>a)(*MARK:x)b/', 'flags' => \PREG_OFFSET_CAPTURE, 'expected' => "array{0: array{'ab', int<0, max>}, MARK: array{'a', int<0, max>}|'x', 1: array{'a', int<0, max>}}"];
        yield 'group and verb in different branches' => ['pattern' => '/(?<MARK>a)|(*MARK:x)b/', 'flags' => 0, 'expected' => "array{0: 'a'|'b', MARK?: 'a'|'x', 1?: 'a'}"];
        yield 'group and verb in different branches, offsets' => ['pattern' => '/(?<MARK>a)|(*MARK:x)b/', 'flags' => \PREG_OFFSET_CAPTURE, 'expected' => "array{0: array{'a'|'b', int<0, max>}, MARK?: array{'a', int<-1, max>}|'x', 1?: array{'a', int<-1, max>}}"];
        // A mark name holding a control byte cannot be written as a constant: preg_match('/(?<MARK>a)(*MARK:x\x01)b/', 'ab') writes "x\x01".
        yield 'mark name not writable as a constant' => ['pattern' => "/(?<MARK>a)(*MARK:x\x01)b/", 'flags' => 0, 'expected' => "array{0: 'ab', MARK: 'a'|non-empty-string, 1: 'a'}"];
        yield 'mark name not writable as a constant, offsets' => ['pattern' => "/(?<MARK>a)(*MARK:x\x01)b/", 'flags' => \PREG_OFFSET_CAPTURE, 'expected' => "array{0: array{'ab', int<0, max>}, MARK: array{'a', int<0, max>}|non-empty-string, 1: array{'a', int<0, max>}}"];
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideSharedNames(): iterable
    {
        yield 'one of two set' => ['pattern' => '/(?J)(?:(?<n>a)|(?<n>b))(x)/', 'subjects' => ['ax', 'bx']];
        yield 'one of two set, nothing after' => ['pattern' => '/(?J)(?<n>a)|(?<n>b)/', 'subjects' => ['a', 'b']];
        yield 'both set' => ['pattern' => '/(?J)(?<n>a)(?<n>b)/', 'subjects' => ['ab']];
        yield 'both set, reversed text' => ['pattern' => '/(?J)(?<n>b)(?<n>a)/', 'subjects' => ['ba']];
        yield 'first and third set' => ['pattern' => '/(?J)(?<n>a)(?<n>z)?(?<n>c)/', 'subjects' => ['ac', 'azc']];
        yield 'second of three set, third unset' => ['pattern' => '/(?J)(?<n>a)?(?<n>b)(?<n>z)?(x)/', 'subjects' => ['abx', 'bx']];
        yield 'later group set to empty' => ['pattern' => '/(?J)(?<n>a)(?<n>)/', 'subjects' => ['a']];
        yield 'none set' => ['pattern' => '/(?J)(?:(?<n>a)|(?<n>b))?(x)/', 'subjects' => ['x', 'ax']];
        yield 'none set, nothing after' => ['pattern' => '/(?J)(?:(?<n>a)|(?<n>b))?x/', 'subjects' => ['x']];
    }

    /**
     * @return iterable<string, array{flags: int}>
     */
    public static function provideRefusedFlags(): iterable
    {
        yield 'PREG_SET_ORDER' => ['flags' => \PREG_SET_ORDER];
        yield 'PREG_PATTERN_ORDER' => ['flags' => \PREG_PATTERN_ORDER];
        yield 'PREG_SPLIT_NO_EMPTY' => ['flags' => \PREG_SPLIT_NO_EMPTY];
        yield 'PREG_SPLIT_OFFSET_CAPTURE' => ['flags' => \PREG_SPLIT_OFFSET_CAPTURE];
        yield '999' => ['flags' => 999];
        yield 'highest bit of the low byte' => ['flags' => 128];
        yield 'PREG_OFFSET_CAPTURE | PREG_SET_ORDER' => ['flags' => \PREG_OFFSET_CAPTURE | \PREG_SET_ORDER];
        yield 'both accepted flags | PREG_SET_ORDER' => ['flags' => \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL | \PREG_SET_ORDER];
    }

    /**
     * @return iterable<string, array{flags: int, meaningful: 0|256|512|768}>
     */
    public static function provideIgnoredFlags(): iterable
    {
        yield '1024 alone' => ['flags' => 1024, 'meaningful' => 0];
        yield '1024 with offsets' => ['flags' => \PREG_OFFSET_CAPTURE | 1024, 'meaningful' => \PREG_OFFSET_CAPTURE];
        yield '1024 with null' => ['flags' => \PREG_UNMATCHED_AS_NULL | 1024, 'meaningful' => \PREG_UNMATCHED_AS_NULL];
        yield 'a high bit with both flags' => ['flags' => \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL | (1 << 20), 'meaningful' => \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL];
    }

    /**
     * @return iterable<string, array{pattern: string, flags: int}>
     */
    public static function provideManyGroups(): iterable
    {
        $optional = '/'.str_repeat('(a)?', 24999).'/';
        $named = '/';
        for ($i = 1; $i <= 4000; $i++) {
            $named .= '(?<g'.$i.'>a)?';
        }
        $named .= '/';

        yield 'optional groups, no flag' => ['pattern' => $optional, 'flags' => 0];
        yield 'optional groups, PREG_OFFSET_CAPTURE' => ['pattern' => $optional, 'flags' => \PREG_OFFSET_CAPTURE];
        yield 'optional groups, PREG_UNMATCHED_AS_NULL' => ['pattern' => $optional, 'flags' => \PREG_UNMATCHED_AS_NULL];
        yield 'optional groups, both flags' => ['pattern' => $optional, 'flags' => \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL];
        yield 'distinct names, no flag' => ['pattern' => $named, 'flags' => 0];
    }

    /**
     * @param array<int|string, mixed> $matches
     */
    private function assertMatchesFollow(CaptureShape $shape, array $matches, int $flags, bool $unicode, string $context): void
    {
        $asNull = 0 !== ($flags & \PREG_UNMATCHED_AS_NULL);
        $offsets = 0 !== ($flags & \PREG_OFFSET_CAPTURE);

        $known = [0 => true, 'MARK' => [] !== $shape->marks];
        foreach ($shape->groups as $group) {
            $known[$group->number] = true;
            if (null !== $group->name) {
                $known[$group->name] = true;
            }
        }

        foreach (array_keys($matches) as $key) {
            $this->assertTrue($known[$key] ?? false, \sprintf('%s: key "%s" is not in the shape.', $context, $key));
        }

        // MARK holds a mark name, or what a group named MARK holds.
        if (\array_key_exists('MARK', $matches)) {
            $mark = \is_array($matches['MARK']) ? $matches['MARK'][0] : $matches['MARK'];
            $allowed = $shape->marks;
            foreach ($shape->groups as $group) {
                if ('MARK' === $group->name) {
                    $allowed = null === $group->values || null === $allowed ? null : [...$allowed, ...$group->values, ...(Participation::Always === $group->participation ? [] : [$asNull ? null : ''])];
                }
            }

            if (null !== $allowed) {
                $this->assertContains($mark, $allowed, \sprintf('%s: MARK took a value outside its set.', $context));
            }
        }

        foreach ([$shape->whole, ...$shape->groups] as $group) {
            $present = \array_key_exists($group->number, $matches);
            $entry = $present ? $matches[$group->number] : null;
            $value = $offsets && \is_array($entry) ? $entry[0] : $entry;

            if (Participation::Always === $group->participation) {
                $this->assertTrue($present, \sprintf('%s: group %d always participates but is missing.', $context, $group->number));
                $this->assertNotNull($value, \sprintf('%s: group %d always participates but is null.', $context, $group->number));
            }

            if (Participation::Never === $group->participation && $present) {
                $this->assertSame($asNull ? null : '', $value, \sprintf('%s: group %d never participates.', $context, $group->number));
            }

            if ($asNull && Participation::Never !== $group->participation) {
                $this->assertTrue($present, \sprintf('%s: group %d must be present under PREG_UNMATCHED_AS_NULL.', $context, $group->number));
            }

            if (!\is_string($value) || ('' === $value && Participation::Always !== $group->participation && !$asNull)) {
                continue;
            }

            if (null !== $group->values) {
                $this->assertContains($value, $group->values, \sprintf('%s: group %d took a value outside its set.', $context, $group->number));
            }

            // Lengths count code points in UTF mode, bytes otherwise.
            $length = $unicode ? mb_strlen($value, 'UTF-8') : \strlen($value);
            $this->assertGreaterThanOrEqual($group->minLength, $length, \sprintf('%s: group %d is shorter than its minimum.', $context, $group->number));
            if (null !== $group->maxLength) {
                $this->assertLessThanOrEqual($group->maxLength, $length, \sprintf('%s: group %d is longer than its maximum.', $context, $group->number));
            }
        }
    }

    /**
     * The keys of a written array shape, outermost level only, in order.
     *
     * @return list<string>
     */
    private static function topLevelKeys(string $shape): array
    {
        self::assertStringStartsWith('array{', $shape);
        self::assertStringEndsWith('}', $shape);

        $keys = [];
        $depth = 0;
        $item = '';
        $quoted = false;
        $escaped = false;
        foreach (str_split(substr($shape, 6, -1).',') as $char) {
            $item .= $char;
            if ($quoted) {
                [$escaped, $quoted] = [!$escaped && '\\' === $char, $escaped || "'" !== $char];

                continue;
            }

            if ("'" === $char) {
                $quoted = true;

                continue;
            }

            if (',' === $char && 0 === $depth) {
                $item = substr($item, 0, -1);
                $keys[] = rtrim(explode(':', trim($item), 2)[0], '?');
                $item = '';

                continue;
            }

            $depth += match ($char) {
                '{', '<' => 1,
                '}', '>' => -1,
                default => 0,
            };
        }

        return $keys;
    }

    private function analyze(string $pattern): CaptureShape
    {
        return (new CaptureShapeAnalyzer())->analyze(RegexParser::create()->parse($pattern));
    }

    private function group(string $pattern, int $number): CaptureGroupShape
    {
        foreach ([$this->analyze($pattern)->whole, ...$this->analyze($pattern)->groups] as $group) {
            if ($number === $group->number) {
                return $group;
            }
        }

        $this->fail(\sprintf('%s has no group %d.', $pattern, $number));
    }
}
