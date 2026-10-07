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

namespace PHPRegex\Tests\Integration\Bridge\Psalm;

use PHPRegex\Parser\Analysis\CaptureShape;
use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Psalm\Internal\MatchesType;
use PHPRegex\Tests\Integration\Bridge\PHPStan\EngineMatches;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Type\Union;

/**
 * The Psalm type of $matches, built from the capture shape as Psalm atomics:
 * the same facts CaptureShape::matchShape() and matchAllShape() write for
 * PHPStan, read as Psalm types, but numeric-string: Psalm 6.19's type
 * combiner reads numeric-string|'a' as numeric-string, so where PHPStan
 * writes numeric-string Psalm gets non-falsy-string when every value is
 * truthy, else non-empty-string. The cases of a split pattern are merged key
 * by key into one array shape. The array $matches is a sealed shape, not a
 * list; an offset pair is a list.
 *
 * Each row's type is exact (each contains the other) and holds what the
 * engine writes for its subjects; the parity corpus is replayed on it too.
 */
final class MatchesTypeTest extends TestCase
{
    /**
     * @param 0|256|512|768 $flags
     * @param list<string>  $subjects
     */
    #[Test]
    #[DataProvider('provideMatchTypes')]
    public function test_match_type_reads_the_shape_as_psalm_atomics(string $pattern, int $flags, string $expected, array $subjects): void
    {
        $built = MatchesType::ofMatch(self::shape($pattern), $flags);

        $this->assertExactly($expected, $built);
        foreach ($subjects as $subject) {
            $this->assertSame(1, preg_match($pattern, $subject, $matches, $flags), \sprintf('%s does not match %s: the row is wrong.', $pattern, json_encode($subject)));
            $this->assertHolds($built, $matches, \sprintf('%s on %s with flags %d', $pattern, json_encode($subject), $flags));
        }
    }

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideMatchAllTypes')]
    public function test_match_all_type_reads_the_shape_as_psalm_atomics(string $pattern, int $flags, string $expected, array $subjects): void
    {
        $built = MatchesType::ofMatchAll(self::shape($pattern), $flags);

        $this->assertExactly($expected, $built);
        foreach ($subjects as $subject) {
            $this->assertNotFalse(preg_match_all($pattern, $subject, $matches, $flags));
            $this->assertHolds($built, $matches, \sprintf('preg_match_all %s on %s with flags %d', $pattern, json_encode($subject), $flags));
        }
    }

    #[Test]
    public function test_match_type_of_a_split_pattern_merges_its_cases_key_by_key(): void
    {
        $shape = self::shape('/(a)|(b)/');
        $this->assertNotSame([], $shape->cases, 'The row needs a pattern the analyzer splits.');
        $merged = new CaptureShape($shape->whole, $shape->groups, $shape->marks);

        foreach (array_keys(EngineMatches::FLAG_SETS) as $flags) {
            $built = MatchesType::ofMatch($shape, $flags);
            foreach ($shape->cases as $index => $case) {
                $this->assertTrue(PsalmTypes::isContainedBy(MatchesType::ofMatch($case, $flags), $built), \sprintf('Flags %d: case %d, outside %s.', $flags, $index, $built->getId()));
            }
            // As precise as the merged shape at least: merging the cases key by key says more.
            $this->assertTrue(PsalmTypes::isContainedBy($built, MatchesType::ofMatch($merged, $flags)), \sprintf('Flags %d: %s, wider than the merged shape.', $flags, $built->getId()));
        }

        // preg_match('/(a)|(b)/', 'a', $m) -> ['a', 'a']; on 'b' -> ['b', '', 'b']: key 1 is written on every match.
        $this->assertExactly("array{0: 'a'|'b', 1: ''|'a', 2?: 'b'}", MatchesType::ofMatch($shape, 0));
    }

    /**
     * @param non-empty-list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideCorpus')]
    public function test_match_type_holds_every_engine_result_of_the_parity_corpus(string $source, string $pattern, array $subjects): void
    {
        self::skipWhenTheEngineCannotRun($pattern, $source);

        $shape = self::shape($pattern);

        $failures = [];
        foreach (EngineMatches::FLAG_SETS as $flags => $flagNames) {
            $built = MatchesType::ofMatch($shape, $flags);
            foreach ($subjects as $subject) {
                preg_match($pattern, $subject, $matches, $flags);
                if (!PsalmTypes::isContainedBy(PsalmTypes::ofValue($matches), $built)) {
                    $failures[] = \sprintf('%s on %s wrote %s, outside %s', $flagNames, json_encode($subject), PsalmTypes::ofValue($matches)->getId(), $built->getId());
                }
            }
        }

        $this->assertSame([], $failures, $pattern.' ('.$source.')');
    }

    /**
     * @param non-empty-list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideCorpus')]
    public function test_match_all_type_holds_every_engine_result_of_the_parity_corpus(string $source, string $pattern, array $subjects): void
    {
        self::skipWhenTheEngineCannotRun($pattern, $source);

        $shape = self::shape($pattern);

        $failures = [];
        foreach (EngineMatches::MATCH_ALL_FLAG_SETS as $flags => $flagNames) {
            $built = MatchesType::ofMatchAll($shape, $flags);
            foreach (EngineMatches::matchAllSubjects($pattern, $subjects) as $subject) {
                preg_match_all($pattern, $subject, $matches, $flags);
                if (!PsalmTypes::isContainedBy(PsalmTypes::ofValue($matches), $built)) {
                    $failures[] = \sprintf('%s on %s wrote %s, outside %s', $flagNames, json_encode($subject), PsalmTypes::ofValue($matches)->getId(), $built->getId());
                }
            }
        }

        $this->assertSame([], $failures, $pattern.' ('.$source.')');
    }

    /**
     * Psalm 6.19's type combiner reads numeric-string|'a' as numeric-string,
     * so a numeric-string in $matches would make Psalm reject a fallback
     * value next to it: the plugin never writes one.
     *
     * @param non-empty-list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideCorpus')]
    public function test_matches_type_never_holds_numeric_string(string $source, string $pattern, array $subjects): void
    {
        self::skipWhenTheEngineCannotRun($pattern, $source);

        $shape = self::shape($pattern);

        $found = [];
        foreach (EngineMatches::FLAG_SETS as $flags => $flagNames) {
            $found[] = MatchesType::ofMatch($shape, $flags)->getId();
        }
        foreach (EngineMatches::MATCH_ALL_FLAG_SETS as $flags => $flagNames) {
            $found[] = MatchesType::ofMatchAll($shape, $flags)->getId();
        }

        $this->assertSame([], array_values(array_filter($found, static fn (string $type): bool => str_contains($type, 'numeric'))), $pattern.' ('.$source.')');
    }

    /**
     * preg_match('/a/', 'a', $m, PREG_SET_ORDER) and preg_match_all() with
     * both orders throw ValueError: "Argument #4 ($flags) must be a PREG_*
     * constant" (PHP 8.4.26). The plugin leaves such a call to Psalm.
     */
    #[Test]
    #[DataProvider('provideRefusedFlags')]
    public function test_matches_type_refuses_the_flags_php_refuses(bool $matchAll, int $flags): void
    {
        $shape = self::shape('/(a)/');

        $this->expectException(InvalidRegexOptionException::class);

        $matchAll ? MatchesType::ofMatchAll($shape, $flags) : MatchesType::ofMatch($shape, $flags);
    }

    /**
     * The keys of the largest array shape the type holds, which Psalm's
     * maxShapedArraySize bounds: what preg_match() writes on its fullest
     * match. The engine writes that many keys on one of the row's subjects,
     * and never more.
     *
     * @param 0|256|512|768 $flags
     * @param list<string>  $subjects
     */
    #[Test]
    #[DataProvider('provideMatchKeyCounts')]
    public function test_matches_type_counts_the_keys_preg_match_writes(string $pattern, int $flags, int $expected, array $subjects): void
    {
        $this->assertSame($expected, MatchesType::keyCount(self::shape($pattern), $flags, false));

        $most = 0;
        foreach ($subjects as $subject) {
            $this->assertSame(1, preg_match($pattern, $subject, $matches, $flags), \sprintf('%s does not match %s: the row is wrong.', $pattern, json_encode($subject)));
            $most = max($most, \count($matches));
        }
        $this->assertSame($expected, $most, 'The most keys the engine writes on the row\'s subjects.');
    }

    /**
     * Every key preg_match_all() writes under PREG_PATTERN_ORDER, the keys
     * of one match under PREG_SET_ORDER.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideMatchAllKeyCounts')]
    public function test_matches_type_counts_the_keys_preg_match_all_writes(string $pattern, int $flags, int $expected, array $subjects): void
    {
        $this->assertSame($expected, MatchesType::keyCount(self::shape($pattern), $flags, true));

        $most = 0;
        foreach ($subjects as $subject) {
            $this->assertNotFalse(preg_match_all($pattern, $subject, $matches, $flags));
            if (\PREG_SET_ORDER !== ($flags & 0xFF)) {
                $most = max($most, \count($matches));

                continue;
            }
            foreach ($matches as $set) {
                $this->assertIsArray($set);
                $most = max($most, \count($set));
            }
        }
        $this->assertSame($expected, $most, 'The most keys the engine writes on the row\'s subjects.');
    }

    /**
     * @return iterable<string, array{pattern: string, flags: 0|256|512|768, expected: int, subjects: list<string>}>
     */
    public static function provideMatchKeyCounts(): iterable
    {
        // preg_match('/(?!(b))a/', 'a', $m) -> ['a']; with PREG_UNMATCHED_AS_NULL -> ['a', null] (PHP 8.4.26)
        yield 'a trailing group no match sets is left out' => ['pattern' => '/(?!(b))a/', 'flags' => 0, 'expected' => 1, 'subjects' => ['a']];
        yield 'a trailing group no match sets, as null' => ['pattern' => '/(?!(b))a/', 'flags' => \PREG_UNMATCHED_AS_NULL, 'expected' => 2, 'subjects' => ['a']];
        // preg_match('/(a)|(b)/', 'a', $m) -> ['a', 'a']; on 'b' -> ['b', '', 'b']
        yield 'split pattern counts its largest case' => ['pattern' => '/(a)|(b)/', 'flags' => 0, 'expected' => 3, 'subjects' => ['a', 'b']];
    }

    /**
     * @return iterable<string, array{pattern: string, flags: int, expected: int, subjects: list<string>}>
     */
    public static function provideMatchAllKeyCounts(): iterable
    {
        // preg_match_all('/(?!(b))a/', 'a', $m) -> [['a'], ['']]; with PREG_SET_ORDER -> [['a']] (PHP 8.4.26)
        yield 'every group in pattern order' => ['pattern' => '/(?!(b))a/', 'flags' => \PREG_PATTERN_ORDER, 'expected' => 2, 'subjects' => ['a', 'x']];
        yield 'flags 0 read as pattern order' => ['pattern' => '/(?!(b))a/', 'flags' => 0, 'expected' => 2, 'subjects' => ['a', 'x']];
        yield 'one match in set order' => ['pattern' => '/(?!(b))a/', 'flags' => \PREG_SET_ORDER, 'expected' => 1, 'subjects' => ['a', 'aa']];
        yield 'one match in set order, as null' => ['pattern' => '/(?!(b))a/', 'flags' => \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL, 'expected' => 2, 'subjects' => ['a']];
    }

    /**
     * @return iterable<string, array{matchAll: bool, flags: int}>
     */
    public static function provideRefusedFlags(): iterable
    {
        yield 'preg_match with an order' => ['matchAll' => false, 'flags' => \PREG_SET_ORDER];
        // preg_match('/a/', 'a', $m, PREG_PATTERN_ORDER) throws the same ValueError (PHP 8.4.26).
        yield 'preg_match with the pattern order' => ['matchAll' => false, 'flags' => \PREG_PATTERN_ORDER];
        yield 'preg_match_all with both orders' => ['matchAll' => true, 'flags' => \PREG_PATTERN_ORDER | \PREG_SET_ORDER];
    }

    /**
     * @return iterable<string, array{pattern: string, flags: 0|256|512|768, expected: string, subjects: list<string>}>
     */
    public static function provideMatchTypes(): iterable
    {
        // preg_match('/(a)(b)?/', 'a', $m) -> ['a', 'a']; on 'ab' -> ['ab', 'a', 'b'] (PHP 8.4.26)
        yield 'literals, a trailing optional group left out' => ['pattern' => '/(a)(b)?/', 'flags' => 0, 'expected' => "array{0: 'a'|'ab', 1: 'a', 2?: 'b'}", 'subjects' => ['a', 'ab']];
        // with PREG_UNMATCHED_AS_NULL on 'a' -> ['a', 'a', null]
        yield 'null under PREG_UNMATCHED_AS_NULL' => ['pattern' => '/(a)(b)?/', 'flags' => \PREG_UNMATCHED_AS_NULL, 'expected' => "array{0: 'a'|'ab', 1: 'a', 2: 'b'|null}", 'subjects' => ['a', 'ab']];
        // with PREG_OFFSET_CAPTURE on 'ab' -> [['ab', 0], ['a', 0], ['b', 1]]
        yield 'offset pairs' => ['pattern' => '/(a)(b)?/', 'flags' => \PREG_OFFSET_CAPTURE, 'expected' => "array{0: list{'a'|'ab', int<0, max>}, 1: list{'a', int<0, max>}, 2?: list{'b', int<-1, max>}}", 'subjects' => ['a', 'ab']];
        // with both on 'a' -> [['a', 0], ['a', 0], [null, -1]]
        yield 'offset pairs holding null' => ['pattern' => '/(a)(b)?/', 'flags' => \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL, 'expected' => "array{0: list{'a'|'ab', int<0, max>}, 1: list{'a', int<0, max>}, 2: list{'b'|null, int<-1, max>}}", 'subjects' => ['a', 'ab']];
        // preg_match('/(z)?(a)/', 'a', $m) -> ['a', '', 'a']
        yield 'unset group before a set one reads empty' => ['pattern' => '/(z)?(a)/', 'flags' => 0, 'expected' => "array{0: 'a'|'za', 1: ''|'z', 2: 'a'}", 'subjects' => ['a', 'za']];
        yield 'digits never falsy become non-falsy-string' => ['pattern' => '/(?<y>\d{4})-(?<m>\d\d)?/', 'flags' => 0, 'expected' => 'array{0: non-falsy-string, y: non-falsy-string, 1: non-falsy-string, m?: non-falsy-string, 2?: non-falsy-string}', 'subjects' => ['2026-10', '2026-']];
        yield 'digits that may be 0' => ['pattern' => '/(\d+)/', 'flags' => 0, 'expected' => 'array{0: non-empty-string, 1: non-empty-string}', 'subjects' => ['0', '42']];
        yield 'non-falsy text' => ['pattern' => '/(?<n>a+)(b)/', 'flags' => 0, 'expected' => "array{0: non-falsy-string, n: non-falsy-string, 1: non-falsy-string, 2: 'b'}", 'subjects' => ['ab', 'aab']];
        yield 'non-empty text' => ['pattern' => '/(.+)/', 'flags' => 0, 'expected' => 'array{0: non-empty-string, 1: non-empty-string}', 'subjects' => ['0', 'xy']];
        yield 'unknown text' => ['pattern' => '/(\w*)/', 'flags' => 0, 'expected' => 'array{0: string, 1: string}', 'subjects' => ['', 'ab']];
        yield 'empty group' => ['pattern' => '/()/', 'flags' => 0, 'expected' => "array{0: '', 1: ''}", 'subjects' => ['']];
        yield 'no group' => ['pattern' => '/abc/', 'flags' => 0, 'expected' => "array{0: 'abc'}", 'subjects' => ['abc']];
        yield 'utf-8 literal' => ['pattern' => '/(é)/u', 'flags' => 0, 'expected' => "array{0: 'é', 1: 'é'}", 'subjects' => ['é']];
        yield 'quote in a literal' => ['pattern' => "/(it's)/", 'flags' => 0, 'expected' => "array{0: 'it\\'s', 1: 'it\\'s'}", 'subjects' => ["it's"]];
        // preg_match('/a(*MARK:x)|b(*MARK:y)/', 'b', $m) -> ['b', 'MARK' => 'y']
        yield 'marks a verb leaves' => ['pattern' => '/a(*MARK:x)|b(*MARK:y)/', 'flags' => 0, 'expected' => "array{0: 'a'|'b', MARK?: 'x'|'y'}", 'subjects' => ['a', 'b']];
        yield 'marks a verb leaves, plain beside offset pairs' => ['pattern' => '/a(*MARK:x)|b(*MARK:y)/', 'flags' => \PREG_OFFSET_CAPTURE, 'expected' => "array{0: list{'a'|'b', int<0, max>}, MARK?: 'x'|'y'}", 'subjects' => ['a', 'b']];
        // preg_match('/(?<MARK>a)(*MARK:m)/', 'a', $m, PREG_OFFSET_CAPTURE) -> [['a', 0], 'MARK' => 'm', ['a', 0]]
        yield 'group named MARK beside a verb' => ['pattern' => '/(?<MARK>a)(*MARK:m)/', 'flags' => 0, 'expected' => "array{0: 'a', MARK: 'a'|'m', 1: 'a'}", 'subjects' => ['a']];
        yield 'group named MARK beside a verb, offsets' => ['pattern' => '/(?<MARK>a)(*MARK:m)/', 'flags' => \PREG_OFFSET_CAPTURE, 'expected' => "array{0: list{'a', int<0, max>}, MARK: list{'a', int<0, max>}|'m', 1: list{'a', int<0, max>}}", 'subjects' => ['a']];
        // preg_match('/(?J)(?<n>a)(?<n>z)?(c)/', 'ac', $m) -> ['ac', 'n' => 'a', 'a', '', 'c']
        yield 'shared name, one group always set' => ['pattern' => '/(?J)(?<n>a)(?<n>z)?(c)/', 'flags' => 0, 'expected' => "array{0: 'ac'|'azc', n: 'a'|'z', 1: 'a', 2: ''|'z', 3: 'c'}", 'subjects' => ['ac', 'azc']];
        // preg_match('/(?J)(?<n>a)|(?<n>b)/', 'a', $m) -> ['a', 'n' => 'a', 'a']; on 'b' -> ['b', 'n' => 'b', '', 'b']
        yield 'shared name, split pattern, cases merged key by key' => ['pattern' => '/(?J)(?<n>a)|(?<n>b)/', 'flags' => 0, 'expected' => "array{0: 'a'|'b', n: 'a'|'b', 1: ''|'a', 2?: 'b'}", 'subjects' => ['a', 'b']];
        yield 'branch reset' => ['pattern' => '/(?|(a)|(b))/', 'flags' => 0, 'expected' => "array{0: 'a'|'b', 1: 'a'|'b'}", 'subjects' => ['a', 'b']];
        yield 'no auto capture' => ['pattern' => '/(a)(?<x>b)/n', 'flags' => 0, 'expected' => "array{0: 'ab', x: 'b', 1: 'b'}", 'subjects' => ['ab']];
        // preg_match('/(?!(b))a/', 'a', $m, PREG_UNMATCHED_AS_NULL) -> ['a', null]; without the flag -> ['a']
        yield 'group no match sets, last' => ['pattern' => '/(?!(b))a/', 'flags' => 0, 'expected' => "array{0: 'a'}", 'subjects' => ['a']];
        yield 'group no match sets, last, as null' => ['pattern' => '/(?!(b))a/', 'flags' => \PREG_UNMATCHED_AS_NULL, 'expected' => "array{0: 'a', 1: null}", 'subjects' => ['a']];
        // preg_match('/(a)|(b)/', 'b', $m, PREG_OFFSET_CAPTURE) -> [['b', 0], ['', -1], ['b', 0]]: the pairs of the cases merge element by element
        yield 'offset pairs of a split pattern, cases merged key by key' => ['pattern' => '/(a)|(b)/', 'flags' => \PREG_OFFSET_CAPTURE, 'expected' => "array{0: list{'a'|'b', int<0, max>}, 1: list{''|'a', int<-1, max>}, 2?: list{'b', int<0, max>}}", 'subjects' => ['a', 'b']];
        // preg_match('/(\p{L}{0})/u', 'x', $m) -> ['', '']: a group that matches nothing but the empty string reads ''
        yield 'empty group whose values are not listed' => ['pattern' => '/(\p{L}{0})/u', 'flags' => 0, 'expected' => "array{0: '', 1: ''}", 'subjects' => ['', 'x']];
        // Past 16 values, a group's facts: (a|b|...|q) reads non-falsy-string
        yield 'seventeen values' => ['pattern' => '/(a|b|c|d|e|f|g|h|i|j|k|l|m|n|o|p|q)/', 'flags' => 0, 'expected' => 'array{0: non-falsy-string, 1: non-falsy-string}', 'subjects' => ['a', 'q']];
        // preg_match("/a(*MARK:x\ty)/", 'a', $m) -> ['a', 'MARK' => "x\ty"]: a name no issue can print legibly reads non-empty-string
        yield 'mark name with a control character' => ['pattern' => "/a(*MARK:x\ty)/", 'flags' => 0, 'expected' => "array{0: 'a', MARK?: non-empty-string}", 'subjects' => ['a']];
    }

    /**
     * @return iterable<string, array{pattern: string, flags: int, expected: string, subjects: list<string>}>
     */
    public static function provideMatchAllTypes(): iterable
    {
        // preg_match_all('/(a)(b)?/', 'a ab', $m) -> [['a', 'ab'], ['a', 'a'], ['', 'b']]; on 'x' -> [[], [], []] (PHP 8.4.26)
        yield 'pattern order' => ['pattern' => '/(a)(b)?/', 'flags' => \PREG_PATTERN_ORDER, 'expected' => "array{0: list<'a'|'ab'>, 1: list<'a'>, 2: list<''|'b'>}", 'subjects' => ['a ab', 'x']];
        yield 'pattern order when flags are 0' => ['pattern' => '/(a)(b)?/', 'flags' => 0, 'expected' => "array{0: list<'a'|'ab'>, 1: list<'a'>, 2: list<''|'b'>}", 'subjects' => ['a ab', 'x']];
        yield 'pattern order, null' => ['pattern' => '/(a)(b)?/', 'flags' => \PREG_PATTERN_ORDER | \PREG_UNMATCHED_AS_NULL, 'expected' => "array{0: list<'a'|'ab'>, 1: list<'a'>, 2: list<'b'|null>}", 'subjects' => ['a ab', 'x']];
        yield 'pattern order, offsets' => ['pattern' => '/(a)(b)?/', 'flags' => \PREG_PATTERN_ORDER | \PREG_OFFSET_CAPTURE, 'expected' => "array{0: list<list{'a'|'ab', int<0, max>}>, 1: list<list{'a', int<0, max>}>, 2: list<list{''|'b', int<-1, max>}>}", 'subjects' => ['a ab', 'x']];
        // preg_match_all('/a(*MARK:x)|b(*MARK:y)/', 'ab', $m) -> [['a', 'b'], 'MARK' => ['x', 'y']]
        yield 'pattern order, marks keyed by match' => ['pattern' => '/a(*MARK:x)|b(*MARK:y)/', 'flags' => \PREG_PATTERN_ORDER, 'expected' => "array{0: list<'a'|'b'>, MARK?: array<int, 'x'|'y'>}", 'subjects' => ['ab', 'z']];
        yield 'pattern order, group named MARK beside a verb' => ['pattern' => '/(?<MARK>a)(*MARK:m)/', 'flags' => \PREG_PATTERN_ORDER, 'expected' => "array{0: list<'a'>, MARK: list<'a'>|array<int, 'm'>, 1: list<'a'>}", 'subjects' => ['aa', 'z']];
        // preg_match_all('/(?J)(?<n>a)|(?<n>b)/', 'ab', $m) -> n => ['', 'b']: the list of the highest-numbered group
        yield 'pattern order, shared name' => ['pattern' => '/(?J)(?<n>a)|(?<n>b)/', 'flags' => \PREG_PATTERN_ORDER, 'expected' => "array{0: list<'a'|'b'>, n: list<''|'b'>, 1: list<''|'a'>, 2: list<''|'b'>}", 'subjects' => ['ab', 'z']];
        yield 'set order' => ['pattern' => '/(a)(b)?/', 'flags' => \PREG_SET_ORDER, 'expected' => "list<array{0: 'a'|'ab', 1: 'a', 2?: 'b'}>", 'subjects' => ['a ab', 'x']];
        yield 'set order, offsets' => ['pattern' => '/(a)(b)?/', 'flags' => \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE, 'expected' => "list<array{0: list{'a'|'ab', int<0, max>}, 1: list{'a', int<0, max>}, 2?: list{'b', int<-1, max>}}>", 'subjects' => ['a ab', 'x']];
        // preg_match_all('/(a)|(b)/', 'ab', $m, PREG_SET_ORDER) -> [['a', 'a'], ['b', '', 'b']]
        yield 'set order of a split pattern, cases merged key by key' => ['pattern' => '/(a)|(b)/', 'flags' => \PREG_SET_ORDER, 'expected' => "list<array{0: 'a'|'b', 1: ''|'a', 2?: 'b'}>", 'subjects' => ['ab', 'x']];
    }

    /**
     * @return iterable<string, array{source: string, pattern: string, subjects: non-empty-list<string>}>
     */
    public static function provideCorpus(): iterable
    {
        foreach (EngineMatches::corpus() as $index => $row) {
            yield \sprintf('#%d %s', $index, $row['pattern']) => $row;
        }
    }

    /**
     * The corpus holds rows only a newer engine can run — the "r" flag needs
     * PHP 8.4 and PCRE2 10.43 — and a pattern the running engine refuses has
     * no engine result to hold: the row is replayed where it compiles.
     */
    private static function skipWhenTheEngineCannotRun(string $pattern, string $source): void
    {
        if (false === @preg_match($pattern, '')) {
            self::markTestSkipped(sprintf('%s (%s) does not run on PCRE2 %s.', $pattern, $source, \PCRE_VERSION));
        }
    }

    private static function shape(string $pattern): CaptureShape
    {
        // Psalm builds a literal string type only under a configuration, which
        // a Psalm run sets up: the builder runs inside one, these rows do not.
        PsalmTypes::codebase();

        // A fixed target, as the digests are read with one: the types this
        // parity holds must not change with the PCRE2 running the tests.
        return (new CaptureShapeAnalyzer())->analyze(RegexParser::create(['php_version' => '8.4', 'pcre_version' => '10.44'])->parse($pattern));
    }

    private function assertExactly(string $expected, Union $built): void
    {
        $type = PsalmTypes::parse($expected);

        $this->assertTrue(PsalmTypes::isContainedBy($built, $type), \sprintf('Built %s, wider than %s.', $built->getId(), $type->getId()));
        $this->assertTrue(PsalmTypes::isContainedBy($type, $built), \sprintf('Built %s, narrower than %s.', $built->getId(), $type->getId()));
    }

    /**
     * @param array<array-key, mixed> $matches
     */
    private function assertHolds(Union $built, array $matches, string $call): void
    {
        $value = PsalmTypes::ofValue($matches);

        $this->assertTrue(PsalmTypes::isContainedBy($value, $built), \sprintf('%s wrote %s, outside %s.', $call, $value->getId(), $built->getId()));
    }
}
