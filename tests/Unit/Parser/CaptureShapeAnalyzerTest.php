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
     * @param list<Participation> $expected
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

        $this->assertSame('year', $shape->groups[0]->name);
        $this->assertSame(1, $shape->groups[0]->number);
        $this->assertNull($shape->groups[1]->name);
        $this->assertSame(2, $shape->groups[1]->number);
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
     * @return iterable<string, array{pattern: string, expected: list<Participation>}>
     */
    public static function provideParticipation(): iterable
    {
        $always = Participation::Always;
        $maybe = Participation::MayBeUnset;
        $never = Participation::Never;

        yield 'plain group' => ['pattern' => '/(a)/', 'expected' => [$always]];
        yield 'optional group' => ['pattern' => '/(a)?/', 'expected' => [$maybe]];
        yield 'one branch' => ['pattern' => '/(a)|b/', 'expected' => [$maybe]];
        yield 'every branch' => ['pattern' => '/(?:(a)x|(a)y)/', 'expected' => [$maybe, $maybe]];
        yield 'repeated at least once' => ['pattern' => '/(a)+/', 'expected' => [$always]];
        yield 'nested optional' => ['pattern' => '/((a)?b)/', 'expected' => [$always, $maybe]];
        yield 'positive lookahead' => ['pattern' => '/(?=(a))a/', 'expected' => [$always]];
        yield 'negative lookahead' => ['pattern' => '/(?!(b))a/', 'expected' => [$never]];
        yield 'negative lookbehind' => ['pattern' => '/(?<!(b))a/', 'expected' => [$never]];
        yield 'define' => ['pattern' => '/(?(DEFINE)(x))a(?1)/', 'expected' => [$never]];
        yield 'zero repeat' => ['pattern' => '/(a){0}b/', 'expected' => [$never]];
        yield 'branch reset' => ['pattern' => '/(?|(a)|(b))/', 'expected' => [$always]];
        yield 'branch reset, one branch short' => ['pattern' => '/(?|(a)(b)|(c))/', 'expected' => [$always, $maybe]];
        yield 'conditional on both sides' => ['pattern' => '/(x)?(?(1)(a)|(b))/', 'expected' => [$maybe, $maybe, $maybe]];
        yield 'conditional assertion' => ['pattern' => '/(?(?=(a))a|b)/', 'expected' => [$maybe]];
        yield 'atomic' => ['pattern' => '/(?>(a))/', 'expected' => [$always]];
        yield 'recursion' => ['pattern' => '/(a(?1)?b)/', 'expected' => [$always]];
        yield 'accept cuts the match short' => ['pattern' => '/(a(*ACCEPT)b)c/', 'expected' => [$maybe]];
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
        yield 'trailing optional named group' => ['pattern' => '/(?<y>\d{4})(?:-(?<d>\d\d))?/', 'flags' => 0, 'expected' => 'array{0: non-empty-string, y: non-empty-string, 1: non-empty-string, d?: non-empty-string, 2?: non-empty-string}'];
        yield 'duplicate names' => ['pattern' => '/(?J)(?<n>a)|(?<n>b)/', 'flags' => 0, 'expected' => "array{0: 'a'|'b', n?: ''|'a'|'b', 1?: ''|'a', 2?: 'b'}"];
        yield 'unknown text' => ['pattern' => '/(\w*)/', 'flags' => 0, 'expected' => 'array{0: string, 1: string}'];
        yield 'quote in a value' => ['pattern' => "/(it's)/", 'flags' => 0, 'expected' => "array{0: 'it\\'s', 1: 'it\\'s'}"];
        yield 'unprintable value' => ['pattern' => '/(\x01)/', 'flags' => 0, 'expected' => 'array{0: non-empty-string, 1: non-empty-string}'];
        yield 'empty group of unknown text' => ['pattern' => '/(\b)a/i', 'flags' => 0, 'expected' => "array{0: non-empty-string, 1: ''}"];
        yield 'too many values to write' => ['pattern' => '/(a|b|c|d|e|f|g|h|i|j|k|l|m|n|o|p|q)/', 'flags' => 0, 'expected' => 'array{0: non-empty-string, 1: non-empty-string}'];
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
