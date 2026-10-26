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
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\CpuClock;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
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
    use PcreGates;

    use PhpUnitForwardCompat;

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

    /**
     * A group that reads either constants or digits keeps both: the join
     * of (a|\d) holds no value set and no digit fact, its arms hold 'a' and
     * a digit: preg_match('/(a|\d)/', 'a') -> ["a","a"] and
     * preg_match('/(a|\d)/', '5') -> ["5","5"] (PHP 8.4.27, PCRE2 10.49).
     *
     * @param list<array{values: list<string>|null, digitsOnly: bool}> $arms
     */
    #[Test]
    #[DataProvider('provideArms')]
    public function test_a_group_keeps_the_arms_its_join_loses(string $pattern, int $group, array $arms): void
    {
        $actual = array_map(static fn (CaptureGroupShape $arm): array => ['values' => $arm->values, 'digitsOnly' => $arm->digitsOnly], $this->group($pattern, $group)->arms);

        $this->assertSame($arms, $actual);
    }

    /**
     * Syntax of PCRE2 10.45 and 10.47 reads what the engine writes: a call
     * that returns groups sets them, preg_match('/(?(DEFINE)((Sat)urday))(?1(2)),\2/',
     * 'Saturday,Sat') -> ["Saturday,Sat","","Sat"], and a substring scan
     * reads no character of the subject, preg_match("/(?<AA>a)(*scan_substring:(1,'AA',1,<AA>)a)b/",
     * 'ab') -> {"0":"ab","AA":"a","1":"a"} (PHP 8.4.27, PCRE2 10.49).
     *
     * @param list<string>|null $values
     * @param list<string>      $subjects
     */
    #[Test]
    #[DataProvider('provideNewerSyntax')]
    public function test_newer_syntax_reads_what_the_engine_writes(string $pattern, int $group, ?array $values, Participation $participation, array $subjects): void
    {
        $shape = (new CaptureShapeAnalyzer())->analyze(RegexParser::create(['pcre_version' => '10.47'])->parse($pattern));
        $groups = [0 => $shape->whole] + $shape->groups;
        $this->assertArrayHasKey($group, $groups);
        $actual = $groups[$group];

        $this->assertSame($values, $actual->values);
        $this->assertSame($participation, $actual->participation);

        $this->requiresPcre2('10.47', $pattern);
        foreach ($subjects as $subject) {
            $matches = [];
            $this->assertSame(1, preg_match($pattern, $subject, $matches, \PREG_UNMATCHED_AS_NULL), \sprintf('%s must match "%s".', $pattern, $subject));
            $this->assertMatchesFollow($shape, $matches, \PREG_UNMATCHED_AS_NULL, false, \sprintf('%s on "%s"', $pattern, $subject));
        }
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
        $this->requiresPhpReading($pattern);

        $this->assertSame($expected, $this->analyze($pattern)->matchShape($flags));
    }

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideEngineRows')]
    public function test_every_fact_holds_for_what_the_engine_writes(string $pattern, array $subjects): void
    {
        $this->requiresPhpReading($pattern);

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
        $this->requiresPhpReading($pattern);

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

        foreach (self::topLevelKeys($written) as $keys) {
            $this->assertSame(['MARK'], array_values(array_filter($keys, static fn (string $key): bool => 'MARK' === $key)), \sprintf('Each shape of "%s" must have one MARK key.', $written));
        }
        $this->assertSame($expected, $written);
    }

    /**
     * A mark and a group named MARK share the key on every PHP, the name in
     * its place before the group number; the key reads the mark when one is
     * set. PHP 8.5 alone also writes the key a second time, last, where 8.4
     * and 8.6 overwrite the group's entry (8.4.26, 8.5.11, 8.6.0RC3, PCRE2
     * 10.44):
     *   preg_match('/(?<MARK>a)(*MARK:m)/', 'a', $m)  -> keys 0, MARK, 1 (8.5: 0, MARK, 1, MARK); $m['MARK'] = 'm'
     *   preg_match('/(?<n>a)(b)/', 'ab', $m)          -> keys 0, n, 1, 2 on all three.
     */
    #[Test]
    public function test_a_named_key_keeps_its_place_before_its_number_on_every_php(): void
    {
        $matches = [];
        preg_match('/(?<MARK>a)(*MARK:m)/', 'a', $matches);
        $this->assertSame('m', $matches['MARK']);
        $this->assertSame([0, 'MARK', 1], array_values(array_unique(array_keys($matches))));

        foreach (['8.4', '8.5', '8.6'] as $php) {
            $parser = RegexParser::create(['php_version' => $php]);
            $this->assertSame("array{0: 'a', MARK: 'a'|'m', 1: 'a'}", (new CaptureShapeAnalyzer())->analyze($parser->parse('/(?<MARK>a)(*MARK:m)/'))->matchShape(), $php);
            $this->assertSame("array{0: 'ab', n: 'a', 1: 'a', 2: 'b'}", (new CaptureShapeAnalyzer())->analyze($parser->parse('/(?<n>a)(b)/'))->matchShape(), $php);
        }
    }

    /**
     * PHP writes $matches in insertion order: 0, then per group its name
     * before its number. Each shape of the string lists its keys in that
     * order, once each, and one of them holds the keys each match writes.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideEngineRows')]
    public function test_the_match_shape_lists_keys_in_the_order_preg_match_writes_them(string $pattern, array $subjects): void
    {
        $this->requiresPhpReading($pattern);

        $shape = $this->analyze($pattern);

        foreach (self::FLAG_SETS as $flags) {
            $members = self::topLevelKeys($shape->matchShape($flags));
            foreach ($members as $keys) {
                $this->assertSame(array_values(array_unique($keys)), $keys, \sprintf('%s with flags %d writes a key twice.', $pattern, $flags));
            }

            foreach ($subjects as $subject) {
                $matches = [];
                preg_match($pattern, $subject, $matches, $flags);
                // The second "MARK" PHP 8.5 alone writes, see above.
                $written = array_values(array_unique(array_map(strval(...), array_keys($matches))));

                $ordered = array_filter($members, static fn (array $keys): bool => $written === array_values(array_intersect($keys, $written)));
                $this->assertNotSame([], $ordered, \sprintf('%s on "%s" with flags %d: keys out of order.', $pattern, $subject, $flags));
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
        $this->expectExceptionMessageIsOrContains(\sprintf('got flags %d.', $flags));

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
     * Under /J a name holds a set group's value whenever one of the groups
     * bearing it is always set: its offset is never -1.
     * preg_match('/(?J)(?<n>z)?(?<n>a)/', 'a', $m, PREG_OFFSET_CAPTURE)
     * gives n = ['a', 0].
     */
    /**
     * An inline "(?-n)" holds inside an alphabetic assertion's body, as in
     * "(?>...)": preg_match('/(?-n)(*atomic:(a))/n', 'a', $m) captures 1.
     */
    #[Test]
    public function test_an_inline_capture_option_reaches_an_alphabetic_assertion(): void
    {
        $this->requiresPhp('8.2', 'An inline capture option under the n modifier', 'does not read the n modifier');

        $this->assertSame("array{0: 'a', 1: 'a'}", $this->analyze('/(?-n)(*atomic:(a))/n')->matchShape(0));
        $this->assertSame("array{0: 'a', 1: 'a'}", $this->analyze('/(?-n)(*pla:(a))a/n')->matchShape(0));
    }

    #[Test]
    public function test_a_shared_name_with_an_always_set_group_has_a_set_offset(): void
    {
        $this->assertStringContainsString("n: array{'z'|'a', int<0, max>}", $this->analyze('/(?J)(?<n>z)?(?<n>a)/')->matchShape(\PREG_OFFSET_CAPTURE));
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

        $start = CpuClock::nanoseconds();
        $shape->matchShape($flags);
        $seconds = (CpuClock::nanoseconds() - $start) / 1e9;

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
     * nonFalsy: every value the group holds when it is set satisfies
     * (bool) $value, so neither '' nor '0'. false means "not proven".
     */
    #[Test]
    #[DataProvider('provideNonFalsy')]
    public function test_non_falsy_is_proven_from_the_pattern(string $pattern, int $group, bool $expected): void
    {
        $this->assertSame($expected, $this->group($pattern, $group)->nonFalsy);
    }

    /**
     * digitsOnly: every value the group holds when it is set satisfies
     * ctype_digit($value), so a non-empty run of ASCII 0-9. false means "not
     * proven".
     */
    #[Test]
    #[DataProvider('provideDigitsOnly')]
    public function test_digits_only_is_proven_from_the_pattern(string $pattern, int $group, bool $expected): void
    {
        $this->assertSame($expected, $this->group($pattern, $group)->digitsOnly);
    }

    /**
     * lowercase: every value the group holds when it is set satisfies
     * strtolower($value) === $value, PHPStan's lowercase-string: it holds no
     * ASCII letter A-Z. false means "not proven".
     */
    #[Test]
    #[DataProvider('provideLowercase')]
    public function test_lowercase_is_proven_from_the_pattern(string $pattern, int $group, bool $expected): void
    {
        $this->assertSame($expected, $this->group($pattern, $group)->lowercase);
    }

    /**
     * uppercase: every value the group holds when it is set satisfies
     * strtoupper($value) === $value, PHPStan's uppercase-string: it holds no
     * ASCII letter a-z. false means "not proven".
     */
    #[Test]
    #[DataProvider('provideUppercase')]
    public function test_uppercase_is_proven_from_the_pattern(string $pattern, int $group, bool $expected): void
    {
        $this->assertSame($expected, $this->group($pattern, $group)->uppercase);
    }

    /**
     * decimalInt: every value the group holds when it is set satisfies
     * (string) (int) $value === $value, PHPStan's decimal-int-string. false
     * means "not proven".
     */
    #[Test]
    #[DataProvider('provideDecimalInt')]
    public function test_decimal_int_is_proven_from_the_pattern(string $pattern, int $group, bool $expected): void
    {
        $this->assertSame($expected, $this->group($pattern, $group)->decimalInt);
    }

    /**
     * What a match proves of the subject when the pattern pins both ends:
     * its lengths, its values and its facts; null when it does not.
     *
     * @param array{min: int, max: int|null, values: list<string>|null, facts: list<string>}|null $expected
     */
    #[Test]
    #[DataProvider('provideSubject')]
    public function test_the_subject_is_read_when_the_pattern_pins_both_ends(string $pattern, ?array $expected): void
    {
        $subject = $this->analyze($pattern)->subject;
        if (null === $expected) {
            $this->assertNull($subject, $pattern);

            return;
        }

        $this->assertInstanceOf(CaptureGroupShape::class, $subject, $pattern);
        $this->assertSame($expected, ['min' => $subject->minLength, 'max' => $subject->maxLength, 'values' => $subject->values, 'facts' => self::provenFacts($subject)], $pattern);
    }

    /**
     * Each subject the engine matches follows what the shape proves of the
     * subject, the trailing newline a "$" without /D lets through included,
     * and one of its arms, when it has some.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideSubjectEngineRows')]
    public function test_the_subject_follows_what_the_engine_matches(string $pattern, array $subjects): void
    {
        $shape = $this->analyze($pattern);
        $this->assertInstanceOf(CaptureGroupShape::class, $shape->subject, $pattern);
        $unicode = RegexParser::create()->parse($pattern)->isUnicode();

        foreach ($subjects as $subject) {
            $this->assertSame(1, preg_match($pattern, $subject), \sprintf('%s must match %s.', $pattern, json_encode($subject)));
            $context = \sprintf('%s on %s', $pattern, json_encode($subject, \JSON_UNESCAPED_UNICODE));
            $this->assertSame([], self::refusals($shape->subject, $subject, $unicode), $context);

            $held = [] === $shape->subject->arms;
            foreach ($shape->subject->arms as $arm) {
                $held = $held || [] === self::refusals($arm, $subject, $unicode);
            }
            $this->assertTrue($held, $context.': no arm holds it.');
        }
    }

    /**
     * Each case reads the subject of its own matches: preg_match('/^(?:(\d+)|([a-z]+))$/D',
     * '0') -> ["0","0"], on 'ab' -> ["ab","","ab"] (PHP 8.4.27, PCRE2 10.49).
     */
    #[Test]
    public function test_each_case_reads_its_own_subject(): void
    {
        $shape = $this->analyze('/^(?:(\d+)|([a-z]+))$/D');

        $this->assertCount(2, $shape->cases);
        $this->assertSame(['lowercase'], self::provenFacts($shape->subject ?? $this->fail('The merged shape reads no subject.')));
        $this->assertSame(['digitsOnly', 'lowercase', 'uppercase'], self::provenFacts($shape->cases[0]->subject ?? $this->fail('The first case reads no subject.')));
        $this->assertSame(['nonFalsy', 'lowercase'], self::provenFacts($shape->cases[1]->subject ?? $this->fail('The second case reads no subject.')));
    }

    /**
     * A join that loses the case of its arms keeps them: in /([a-z]+|[A-Z]+)/
     * neither case holds for every value, each holds for one branch;
     * preg_match('/([a-z]+|[A-Z]+)/', 'AB') -> ["AB","AB"] (PHP 8.4.27, PCRE2 10.49).
     * A join that loses no fact keeps none.
     */
    #[Test]
    public function test_a_group_keeps_the_arms_whose_case_its_join_loses(): void
    {
        $group = $this->group('/([a-z]+|[A-Z]+)/', 1);

        $this->assertFalse($group->lowercase);
        $this->assertFalse($group->uppercase);
        $this->assertSame([['nonFalsy', 'lowercase'], ['nonFalsy', 'uppercase']], array_map(self::provenFacts(...), $group->arms));
        $this->assertSame([['nonFalsy', 'lowercase'], ['digitsOnly', 'lowercase', 'uppercase']], array_map(self::provenFacts(...), $this->group('/([a-z]+|\d+)/', 1)->arms));
        $this->assertSame([['nonFalsy', 'decimalInt', 'lowercase', 'uppercase'], ['nonFalsy', 'digitsOnly', 'lowercase', 'uppercase']], array_map(self::provenFacts(...), $this->group('/(-?[1-9]\d?|00)/', 1)->arms));
        $this->assertSame([], $this->group('/(a+|b+)/', 1)->arms);
    }

    /**
     * The analysis proves the facts the row lists, and a fact proven true
     * holds for every value the engine writes into a set group; the unset
     * slot ('' or null) is the participation's business.
     *
     * @param list<string>                                                                   $subjects
     * @param array<int, list<'nonFalsy'|'digitsOnly'|'decimalInt'|'lowercase'|'uppercase'>> $facts
     */
    #[Test]
    #[DataProvider('provideFactEngineRows')]
    public function test_proven_facts_hold_for_what_the_engine_writes(string $pattern, array $subjects, array $facts): void
    {
        $shape = $this->analyze($pattern);

        $proven = [];
        foreach ([$shape->whole, ...$shape->groups] as $group) {
            $proven[$group->number] = self::provenFacts($group);
        }
        $this->assertSame($facts, $proven, $pattern);

        $this->assertProvenFactsHold($shape, $pattern, $subjects);
    }

    #[Test]
    #[DataProvider('provideFactShapes')]
    #[DataProvider('provideCaseShapes')]
    public function test_the_match_shape_writes_the_facts_and_the_cases(string $pattern, int $flags, string $expected): void
    {
        $this->assertSame($expected, $this->analyze($pattern)->matchShape($flags));
    }

    #[Test]
    #[DataProvider('provideSplits')]
    public function test_cases_split_on_the_one_alternation_reachable_from_the_root(string $pattern, int $count): void
    {
        $shape = $this->analyze($pattern);

        $this->assertCount($count, $shape->cases);
        foreach ($shape->cases as $case) {
            $this->assertInstanceOf(CaptureShape::class, $case);
            // Each case numbers every group of the pattern, as $groups does.
            $this->assertSame(array_keys($shape->groups), array_keys($case->groups));
        }
    }

    #[Test]
    #[DataProvider('provideNoSplits')]
    public function test_cases_stay_empty_when_the_pattern_does_not_split(string $pattern): void
    {
        $shape = $this->analyze($pattern);

        $this->assertSame([], $shape->cases);
        $this->assertStringNotContainsString('}|array{', $shape->matchShape());
    }

    /**
     * Each case is computed as if the other branches' groups took no part:
     * preg_match('/(a)|(b)/', 'b') -> ["b","","b"]; on 'a' -> ["a","a"];
     * preg_match('/(?:(a)|(b))?/', '') -> [""] (PHP 8.4.26, PCRE2 10.49).
     * The merged view stays in $groups.
     *
     * @param list<array<int<1, max>, Participation>> $expected in any order: cases carry no index correspondence with the branches
     * @param array<int<1, max>, Participation>       $merged   the merged view, unchanged
     */
    #[Test]
    #[DataProvider('provideCaseParticipation')]
    public function test_a_case_holds_only_the_groups_of_its_branch(string $pattern, array $expected, array $merged): void
    {
        $shape = $this->analyze($pattern);

        $actual = array_map(static fn (CaptureShape $case): array => array_map(static fn (CaptureGroupShape $group): Participation => $group->participation, $case->groups), $shape->cases);

        $this->assertEqualsCanonicalizing($expected, $actual);
        $this->assertSame($merged, array_map(static fn (CaptureGroupShape $group): Participation => $group->participation, $shape->groups));
    }

    /**
     * An inline option set in one alternative stays in force in the
     * following ones, as in PCRE: preg_match('/a(?i)b|(c)/', 'C') ->
     * ["C","C"], and preg_match('/^(?:a(?i)b|c)$/', 'C') -> 1 (PHP 8.4.26,
     * PCRE2 10.49). The case where group 1 is set must hold 'C'.
     */
    #[Test]
    public function test_inline_options_flow_into_the_cases_of_the_following_branches(): void
    {
        $shape = $this->analyze('/a(?i)b|(c)/');
        $this->assertNotSame([], $shape->cases);

        $holding = array_values(array_filter($shape->cases, static fn (CaptureShape $case): bool => Participation::Never !== $case->groups[1]->participation));
        $this->assertNotSame([], $holding, 'One case must set group 1.');
        foreach ($holding as $case) {
            $values = $case->groups[1]->values;
            $this->assertTrue(null === $values || \in_array('C', $values, true), \sprintf('Group 1 holds %s, not "C".', json_encode($values)));
            $this->assertFalse($case->groups[1]->digitsOnly);
        }
    }

    /**
     * A name several groups share under /J holds, per case, only the groups
     * of that case: preg_match('/(?<n>a)|(?<n>b)/J', 'b') ->
     * {"0":"b","n":"b","1":"","2":"b"} (PHP 8.4.26, PCRE2 10.49), so the
     * case of the second branch writes n as 'b', never ''.
     */
    #[Test]
    public function test_a_shared_name_holds_only_the_groups_of_its_case(): void
    {
        $shape = $this->analyze('/(?<n>a)|(?<n>b)/J');

        $this->assertCount(2, $shape->cases);
        $this->assertSame("array{0: 'a', n: 'a', 1: 'a'}|array{0: 'b', n: 'b', 1: '', 2: 'b'}", $shape->matchShape());
    }

    /**
     * PHPStan generalises a union of array shapes holding more than 256
     * value types, nested arrays included, into a list that loses the keys:
     * past that budget the merged shape is written instead. Sixteen
     * one-group branches hold 152 value types without flags, 456 with
     * PREG_OFFSET_CAPTURE (each value a pair); the cases stay the same.
     */
    #[Test]
    public function test_a_union_past_phpstans_budget_falls_back_to_the_merged_shape(): void
    {
        $shape = $this->analyze(self::branches(16, ''));
        $merged = new CaptureShape($shape->whole, $shape->groups, $shape->marks);

        $this->assertCount(16, $shape->cases);
        $this->assertSame(implode('|', array_map(static fn (CaptureShape $case): string => $case->matchShape(), $shape->cases)), $shape->matchShape());
        $this->assertSame($merged->matchShape(\PREG_OFFSET_CAPTURE), $shape->matchShape(\PREG_OFFSET_CAPTURE));
        $this->assertStringNotContainsString('}|array{', $shape->matchShape(\PREG_OFFSET_CAPTURE));
    }

    /**
     * The union of the cases covers every match: each $matches the engine
     * writes is held by at least one case, under every flag set.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideCaseEngineRows')]
    public function test_every_engine_result_is_held_by_one_case(string $pattern, array $subjects): void
    {
        $shape = $this->analyze($pattern);
        $this->assertNotSame([], $shape->cases, \sprintf('%s must split.', $pattern));
        $unicode = RegexParser::create()->parse($pattern)->isUnicode();

        foreach ($subjects as $subject) {
            foreach (self::FLAG_SETS as $flags) {
                $matches = [];
                $this->assertSame(1, preg_match($pattern, $subject, $matches, $flags));

                $held = array_filter($shape->cases, static fn (CaptureShape $case): bool => self::caseHolds($case, $matches, $flags, $unicode));
                $this->assertNotSame([], $held, \sprintf('%s on %s with flags %d writes %s, which no case holds.', $pattern, json_encode($subject), $flags, json_encode($matches)));
            }
        }

        // The merged view proves its own facts, which hold for every match too.
        $this->assertProvenFactsHold($shape, $pattern, $subjects);
    }

    /**
     * A capturing group's body never holds the split alternation, so what it
     * reads is the same in every case: its facts are computed once, not once
     * per case and again for the merged shape. Sixteen cases over forty
     * nested groups then cost little more than the merged shape alone, where
     * recomputing them made the split pattern about fifteen times slower.
     * Each time is the fastest of three runs, so a busy machine slows both.
     */
    #[Test]
    public function test_cases_reuse_the_facts_of_each_group(): void
    {
        $nested = str_repeat('([a]x?', 40).str_repeat(')', 40);
        $split = RegexParser::create()->parse('/(?:'.$nested.str_repeat('|(b)', 15).')/');
        $merged = RegexParser::create()->parse('/(?:'.$nested.')/');

        $analyzer = new CaptureShapeAnalyzer();
        $this->assertCount(16, $analyzer->analyze($split)->cases);

        $splitSeconds = self::fastestAnalysis($analyzer, $split);
        $mergedSeconds = self::fastestAnalysis($analyzer, $merged);

        $this->assertLessThan(3 * $mergedSeconds, $splitSeconds, \sprintf('The split pattern took %.3f s, the merged one %.3f s.', $splitSeconds, $mergedSeconds));
    }

    /**
     * PHPStan keeps a union of array shapes holding at most 256 value types,
     * and generalises past them (TypeCombinator::optimizeConstantArrays(),
     * "<= ConstantArrayTypeBuilder::ARRAY_COUNT_LIMIT"): up to the budget the
     * union of the cases is written, each distinct shape once; one value type
     * past it, the merged shape.
     */
    #[Test]
    #[DataProvider('provideBudgetEdges')]
    public function test_the_union_is_written_up_to_phpstans_budget_and_no_further(string $pattern, int $flags, int $cases, int $distinct, bool $union): void
    {
        $shape = $this->analyze($pattern);
        $written = array_values(array_unique(array_map(static fn (CaptureShape $case): string => $case->matchShape($flags), $shape->cases)));
        $merged = new CaptureShape($shape->whole, $shape->groups, $shape->marks);

        $this->assertCount($cases, $shape->cases);
        $this->assertCount($distinct, $written);
        $this->assertSame($union ? implode('|', $written) : $merged->matchShape($flags), $shape->matchShape($flags));
    }

    /**
     * A subroutine call runs a branch the case leaves out, and the verbs and
     * the \K inside it with that branch (PHP 8.4.26, PCRE2 10.49):
     *   preg_match('/(?:(a)|(b(*MARK:m)))(?2)/', 'ab') -> {"0":"ab","1":"a","MARK":"m"}
     *   preg_match('/(?:(a)|(b\K))(?2)/', 'ab')         -> ["","a"]
     */
    #[Test]
    public function test_a_call_runs_the_verbs_and_the_keep_of_a_branch_the_case_leaves_out(): void
    {
        $matches = [];
        $this->assertSame(1, preg_match('/(?:(a)|(b(*MARK:m)))(?2)/', 'ab', $matches));
        $this->assertSame('m', $matches['MARK']);
        $this->assertSame(1, preg_match('/(?:(a)|(b\K))(?2)/', 'ab', $matches));
        $this->assertSame('', $matches[0]);

        $marked = $this->analyze('/(?:(a)|(b(*MARK:m)))(?2)/');
        $this->assertCount(2, $marked->cases);
        foreach ($marked->cases as $case) {
            $this->assertSame(['m'], $case->marks);
            $this->assertStringContainsString("MARK?: 'm'", $case->matchShape());
        }

        $kept = $this->analyze('/(?:(a)|(b\K))(?2)/');
        $this->assertCount(2, $kept->cases);
        foreach ($kept->cases as $case) {
            $this->assertSame(0, $case->whole->minLength);
            $this->assertNull($case->whole->maxLength);
            $this->assertNull($case->whole->values);
        }
    }

    /**
     * A group a case never sets proves no fact there, though its pattern
     * proves both where it is set: preg_match('/(?:(\d\d)|(a))/', '00') ->
     * ["00","00"], on 'a' -> ["a","","a"] (PHP 8.4.26, PCRE2 10.49).
     */
    #[Test]
    public function test_a_group_a_case_never_sets_proves_no_fact(): void
    {
        $shape = $this->analyze('/(?:(\d\d)|(a))/');
        $this->assertCount(2, $shape->cases);

        $participations = [];
        foreach ($shape->cases as $case) {
            $group = $case->groups[1];
            $participations[] = $group->participation;
            $proven = Participation::Always === $group->participation;
            $this->assertSame($proven, $group->nonFalsy);
            $this->assertSame($proven, $group->digitsOnly);
            $this->assertSame($proven, $group->lowercase);
            $this->assertSame($proven, $group->uppercase);
        }
        $this->assertEqualsCanonicalizing([Participation::Always, Participation::Never], $participations);
    }

    /**
     * What preg_match_all() writes into $matches, for any return value, 0
     * included: under PREG_PATTERN_ORDER one list per key, every key always
     * written; under PREG_SET_ORDER a list of what preg_match() writes.
     * Each row is read from the engine in the comment above its provider.
     */
    #[Test]
    #[DataProvider('provideMatchAllShapes')]
    public function test_match_all_shape_is_written_as_a_phpstan_type(string $pattern, int $flags, string $expected): void
    {
        $this->requiresPhpReading($pattern);

        $this->assertSame($expected, $this->analyze($pattern)->matchAllShape($flags));
    }

    /**
     * preg_match_all() without flags orders by pattern:
     * preg_match_all('/(a)(b)?(c)?/', 'a ab', $m) and the same call with
     * PREG_PATTERN_ORDER both write [["a","ab"],["a","a"],["","b"],["",""]]
     * (PHP 8.4.26, PCRE2 10.49).
     */
    #[Test]
    public function test_match_all_shape_defaults_to_pattern_order(): void
    {
        $shape = $this->analyze('/(a)(b)?(c)?/');

        $this->assertSame("array{0: list<'a'|'ac'|'ab'|'abc'>, 1: list<'a'>, 2: list<''|'b'>, 3: list<''|'c'>}", $shape->matchAllShape());
        $this->assertSame($shape->matchAllShape(), $shape->matchAllShape(\PREG_PATTERN_ORDER));
        $this->assertSame($shape->matchAllShape(), $shape->matchAllShape(0));
    }

    /**
     * Each set preg_match_all() writes under PREG_SET_ORDER is what
     * preg_match() writes for that match, trailing unset groups left out:
     * preg_match_all('/(a)(b)?(c)?/', 'a ab', $m, PREG_SET_ORDER) ->
     * [["a","a"],["ab","a","b"]], with PREG_UNMATCHED_AS_NULL
     * [["a","a",null,null],["ab","a","b",null]] (PHP 8.4.26, PCRE2 10.49).
     * The element is matchShape() under the same offset and null flags,
     * the union of the cases included. Replayed on the subjects, one by one
     * and concatenated, each set the engine writes follows the merged shape,
     * and one case when the pattern splits.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideEngineRows')]
    #[DataProvider('provideCaseEngineRows')]
    public function test_match_all_shape_in_set_order_is_a_list_of_the_match_shape(string $pattern, array $subjects): void
    {
        $this->requiresPhpReading($pattern);

        $shape = $this->analyze($pattern);
        $unicode = RegexParser::create()->parse($pattern)->isUnicode();
        $replayed = array_values(array_unique([implode('', $subjects), ...$subjects]));

        foreach (self::FLAG_SETS as $flags) {
            $this->assertSame('list<'.$shape->matchShape($flags).'>', $shape->matchAllShape(\PREG_SET_ORDER | $flags), \sprintf('%s with flags %d', $pattern, $flags));

            foreach ($replayed as $subject) {
                $sets = [];
                $this->assertNotFalse(preg_match_all($pattern, $subject, $sets, \PREG_SET_ORDER | $flags));
                // Each subject matches; their concatenation may not, under an anchor.
                if (\in_array($subject, $subjects, true)) {
                    $this->assertNotSame([], $sets, \sprintf('%s must match %s.', $pattern, json_encode($subject)));
                }

                foreach ($sets as $index => $set) {
                    $context = \sprintf('%s on %s with PREG_SET_ORDER | %d, set %d', $pattern, json_encode($subject, \JSON_UNESCAPED_UNICODE), $flags, $index);
                    $this->assertMatchesFollow($shape, $set, $flags, $unicode, $context);
                    if ([] !== $shape->cases) {
                        $held = array_filter($shape->cases, static fn (CaptureShape $case): bool => self::caseHolds($case, $set, $flags, $unicode));
                        $this->assertNotSame([], $held, $context.': '.json_encode($set).', which no case holds.');
                    }
                }
            }
        }
    }

    /**
     * PHPStan counts its budget of value types on the element of the list:
     * up to 256 the element is the union of the cases, past it the merged
     * shape, as matchShape() writes it.
     */
    #[Test]
    #[DataProvider('provideBudgetEdges')]
    public function test_match_all_shape_in_set_order_counts_the_budget_on_the_element(string $pattern, int $flags, int $cases, int $distinct, bool $union): void
    {
        $shape = $this->analyze($pattern);
        $written = $shape->matchAllShape(\PREG_SET_ORDER | $flags);

        $this->assertCount($cases, $shape->cases);
        $this->assertSame('list<'.$shape->matchShape($flags).'>', $written);
        $this->assertSame($union, str_contains($written, '}|array{'), $written);
        // The element holds each distinct shape of the cases once, or the merged shape alone.
        $this->assertStringStartsWith('list<', $written);
        $this->assertCount($union ? $distinct : 1, self::topLevelMembers(substr($written, \strlen('list<'), -1)), $written);
    }

    /**
     * Under PREG_PATTERN_ORDER PHP writes every key on every call, a match
     * or none, in the order preg_match() would (0, then per group its name
     * before its number), and MARK last, only when a match set a mark
     * (PHP 8.4.26, PCRE2 10.49):
     *   preg_match_all('/(?<x>a)(b)?/', 'a', $m)        -> {"0":["a"],"x":["a"],"1":["a"],"2":[""]}
     *   preg_match_all('/(?<x>a)(b)?/', 'x', $m)        -> {"0":[],"x":[],"1":[],"2":[]}
     *   preg_match_all('/(*MARK:m)(a)|(b)/', 'ab', $m)  -> {"0":["a","b"],"1":["a",""],"2":["","b"],"MARK":["m"]}
     *   preg_match_all('/(*MARK:m)(a)|(b)/', 'b', $m)   -> [["b"],[""],["b"]]
     *   preg_match_all('/(?<MARK>a)|(*MARK:x)b/', 'ab', $m) -> {"0":["a","b"],"MARK":{"1":"x"},"1":["a",""]}
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideEngineRows')]
    #[DataProvider('provideCaseEngineRows')]
    public function test_match_all_shape_in_pattern_order_lists_every_key_in_the_order_php_writes_them(string $pattern, array $subjects): void
    {
        $this->requiresPhpReading($pattern);

        $shape = $this->analyze($pattern);
        $replayed = [implode('', $subjects), ...$subjects];
        if (0 === preg_match($pattern, "\x00")) {
            $replayed[] = "\x00";
        }

        foreach (self::FLAG_SETS as $flags) {
            $members = self::topLevelKeys($shape->matchAllShape(\PREG_PATTERN_ORDER | $flags));
            $this->assertCount(1, $members, \sprintf('%s with flags %d: one array shape.', $pattern, $flags));
            $keys = $members[0];
            $this->assertSame(array_values(array_unique($keys)), $keys, \sprintf('%s with flags %d writes a key twice.', $pattern, $flags));

            foreach ($replayed as $subject) {
                $matches = [];
                $this->assertNotFalse(preg_match_all($pattern, $subject, $matches, \PREG_PATTERN_ORDER | $flags));
                $written = array_map(strval(...), array_keys($matches));
                $context = \sprintf('%s on %s with flags %d writes keys %s', $pattern, json_encode($subject, \JSON_UNESCAPED_UNICODE), $flags, json_encode($written));

                $this->assertSame($written, array_values(array_intersect($keys, $written)), $context.': a key the shape lacks, or out of order.');
                $this->assertSame([], array_values(array_diff($keys, $written, ['MARK'])), $context.': a key the shape lists is missing.');
            }
        }
    }

    /**
     * preg_match_all() accepts exactly 0, PREG_PATTERN_ORDER (1) and
     * PREG_SET_ORDER (2) in the low byte, and refuses the 253 other values
     * with ValueError "Argument #4 ($flags) must be a PREG_* constant" (PHP
     * 8.4.26). PREG_SPLIT_NO_EMPTY and PREG_SPLIT_DELIM_CAPTURE share the
     * values 1 and 2, so the engine takes them as the orders.
     */
    #[Test]
    public function test_match_all_shape_refuses_exactly_the_low_bytes_preg_match_all_refuses(): void
    {
        $shape = $this->analyze('/(a)/');

        $engine = [];
        $library = [];
        for ($flags = 0; $flags < 256; $flags++) {
            try {
                preg_match_all('/(a)/', 'a', $matches, $flags);
                $engine[$flags] = 'accepted';
            } catch (\ValueError) {
                $engine[$flags] = 'refused';
            }

            try {
                $shape->matchAllShape($flags);
                $library[$flags] = 'accepted';
            } catch (InvalidRegexOptionException) {
                $library[$flags] = 'refused';
            }
        }

        $this->assertSame([0, 1, 2], array_keys($engine, 'accepted', true));
        $this->assertSame($engine, $library);
    }

    #[Test]
    #[DataProvider('provideMatchAllRefusedFlags')]
    public function test_match_all_shape_refuses_a_flag_preg_match_all_refuses(int $flags): void
    {
        try {
            preg_match_all('/(a)/', 'a', $matches, $flags);
            $this->fail(\sprintf('preg_match_all() was expected to refuse flags %d.', $flags));
        } catch (\ValueError) {
        }

        $shape = $this->analyze('/(a)/');

        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('got flags %d.', $flags));

        $shape->matchAllShape($flags);
    }

    /**
     * preg_match_all() ignores a bit above the low byte (PHP 8.4.26):
     * '/(a)/' on 'a' with 1024 writes [["a"],["a"]], as with no flag; with
     * 2|256|512|4096 [[["a",0],["a",0]]], as with 2|256|512.
     */
    #[Test]
    #[DataProvider('provideMatchAllIgnoredFlags')]
    public function test_match_all_shape_ignores_a_flag_preg_match_all_ignores(int $flags, int $meaningful): void
    {
        $with = [];
        $without = [];
        $this->assertSame(2, preg_match_all('/(z)?(a)/', 'a a', $with, $flags));
        $this->assertSame(2, preg_match_all('/(z)?(a)/', 'a a', $without, $meaningful));
        $this->assertSame($without, $with);

        $shape = $this->analyze('/(z)?(a)/');

        $this->assertSame($shape->matchAllShape($meaningful), $shape->matchAllShape($flags));
    }

    /**
     * The shape of each key is built once per call, as matchShape() builds
     * it: its time grows with the group count, not with its square.
     */
    #[Test]
    #[DataProvider('provideManyGroups')]
    public function test_match_all_shape_stays_fast_with_many_groups(string $pattern, int $flags): void
    {
        $shape = $this->analyze($pattern);

        foreach ([\PREG_PATTERN_ORDER, \PREG_SET_ORDER] as $order) {
            $start = CpuClock::nanoseconds();
            $shape->matchAllShape($order | $flags);
            $seconds = (CpuClock::nanoseconds() - $start) / 1e9;

            $this->assertLessThan(2.0, $seconds, \sprintf('matchAllShape(%d) took %.2f s.', $order | $flags, $seconds));
        }
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
        // preg_match('/(?(?!(a))x|a)/', 'a') -> ["a","a"] (PHP 8.4.27, PCRE2 10.49): a negative condition that fails keeps what it captured
        yield 'conditional on a negative assertion' => ['pattern' => '/(?(?!(a))x|a)/', 'expected' => [1 => $maybe]];
        // preg_match('/(?(?<!(a))x|b)/', 'ab') -> ["b","a"] (PHP 8.4.27, PCRE2 10.49)
        yield 'conditional on a negative lookbehind' => ['pattern' => '/(?(?<!(a))x|b)/', 'expected' => [1 => $maybe]];
        yield 'atomic' => ['pattern' => '/(?>(a))/', 'expected' => [1 => $always]];
        yield 'recursion' => ['pattern' => '/(a(?1)?b)/', 'expected' => [1 => $always]];
        yield 'accept cuts the match short' => ['pattern' => '/(a(*ACCEPT)b)c/', 'expected' => [1 => $always]];
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
        // Under /i the engine folds ASCII letters only — preg_match('/(é)/i', 'É') is 0 —
        // so a literal free of them keeps its values — preg_match('/(£|€)/i', '£') and
        // preg_match('/(£|€)/i', '€') match as spelled — while one ASCII letter folds:
        // preg_match('/(ab£)/i', 'AB£') matches (PHP 8.4.27, PCRE2 10.49).
        yield 'caseless, letters past ASCII' => ['pattern' => '/(£|€)/i', 'group' => 1, 'values' => ['£', '€']];
        yield 'caseless, one character past ASCII' => ['pattern' => '/(é)/i', 'group' => 1, 'values' => ['é']];
        yield 'caseless, one ASCII letter' => ['pattern' => '/(ab£)/i', 'group' => 1, 'values' => null];
        yield 'unbounded' => ['pattern' => '/(a+)/', 'group' => 1, 'values' => null];
        // A class the members of which are a small finite set reads as that set:
        // preg_match('/([a-c])/', 'b') sets group 1 to 'b' (PHP 8.4.26, PCRE2 10.49).
        yield 'class of members' => ['pattern' => '/([abc])/', 'group' => 1, 'values' => ['a', 'b', 'c']];
        yield 'class with escapes' => ['pattern' => '/([\~,\.?])/', 'group' => 1, 'values' => ['~', ',', '.', '?']];
        yield 'class of a range' => ['pattern' => '/([a-c])/', 'group' => 1, 'values' => ['a', 'b', 'c']];
        yield 'class of a long range' => ['pattern' => '/([a-z])/', 'group' => 1, 'values' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z']];
        yield 'class past the limit' => ['pattern' => '/([a-zA-Z])/', 'group' => 1, 'values' => null];
        yield 'class of a range past the limit' => ['pattern' => '/([A-a])/', 'group' => 1, 'values' => null];
        yield 'negative class' => ['pattern' => '/([^a])/', 'group' => 1, 'values' => null];
        yield 'class with a type' => ['pattern' => '/([a\d])/', 'group' => 1, 'values' => null];
        yield 'class of an escape' => ['pattern' => '/([\x41])/', 'group' => 1, 'values' => ['A']];
        yield 'class of a control escape' => ['pattern' => '/([\cA])/', 'group' => 1, 'values' => ["\x01"]];
        // A surrogate is no character of the encoding: mb_chr leaves it
        // unnamed and the class unread, rather than reading false as one.
        yield 'class of a surrogate' => ['pattern' => '/([\x{D800}])/u', 'group' => 1, 'values' => null];
        yield 'class of a range over a surrogate' => ['pattern' => '/([\x{D7FE}-\x{D801}])/u', 'group' => 1, 'values' => null];
        // A bounded repeat holds each count of its body: preg_match('/(a{1,3})/', 'aa') -> ['aa', 'aa'] (PHP 8.4.26).
        yield 'bounded repeat' => ['pattern' => '/(a{1,3})/', 'group' => 1, 'values' => ['a', 'aa', 'aaa']];
        yield 'bounded repeat from zero' => ['pattern' => '/(a{0,2})/', 'group' => 1, 'values' => ['', 'a', 'aa']];
        yield 'bounded repeat of an alternation' => ['pattern' => '/((?:a|b){1,2})/', 'group' => 1, 'values' => ['a', 'b', 'aa', 'ab', 'ba', 'bb']];
        // Laziness and possession pick a count, never a string the counts do not hold:
        // preg_match('/(a{2,3}?)/', 'aaa') -> ['aa', 'aa'] (PHP 8.4.26).
        yield 'lazy bounded repeat' => ['pattern' => '/(a{2,3}?)/', 'group' => 1, 'values' => ['aa', 'aaa']];
        yield 'possessive bounded repeat' => ['pattern' => '/((?:ab){0,2}+)/', 'group' => 1, 'values' => ['', 'ab', 'abab']];
        yield 'exact repeat past four' => ['pattern' => '/(a{5})/', 'group' => 1, 'values' => ['aaaaa']];
        yield 'bounded repeat of an empty body' => ['pattern' => '/((?:){2,1000})/', 'group' => 1, 'values' => ['']];
        yield 'bounded repeat past the limit' => ['pattern' => '/(a{1,33})/', 'group' => 1, 'values' => null];
        yield 'bounded repeat past the limit by its product' => ['pattern' => '/((?:a|b|c){1,3})/', 'group' => 1, 'values' => null];
        yield 'extended mode' => ['pattern' => '/(a b)/x', 'group' => 1, 'values' => ['ab']];
        yield 'branch reset joins' => ['pattern' => '/(?|(a)|(b))/', 'group' => 1, 'values' => ['a', 'b']];
        // A branch reset number joins the values of its branches, up to 32: preg_match() on each of
        // 'a', 'p', 'q', '5' sets group 1 to that character (PHP 8.4.26, PCRE2 10.49).
        yield 'branch reset joins 32 values: the limit' => ['pattern' => '/(?|(a|b|c|d|e|f|g|h|i|j|k|l|m|n|o|p)|(q|r|s|t|u|v|w|x|y|z|0|1|2|3|4|5))/', 'group' => 1, 'values' => str_split('abcdefghijklmnopqrstuvwxyz012345')];
        yield 'branch reset joins 33 values: past the limit' => ['pattern' => '/(?|(a|b|c|d|e|f|g|h|i|j|k|l|m|n|o|p)|(q|r|s|t|u|v|w|x|y|z|0|1|2|3|4|5|6))/', 'group' => 1, 'values' => null];
        yield 'whole match' => ['pattern' => '/a(b|c)/', 'group' => 0, 'values' => ['ab', 'ac']];
        yield 'keep resets the whole match' => ['pattern' => '/a\K(b)/', 'group' => 0, 'values' => null];
        yield 'too many' => ['pattern' => '/((?:a|b|c|d)(?:a|b|c|d)(?:a|b|c|d))/', 'group' => 1, 'values' => null];
        // The engine tries the branches left to right at each position and
        // takes the leftmost position: a zero-width branch read first wins
        // before any later branch is ever reached, when nothing after it can
        // fail. preg_match('/^|foo/', 'xfoo') sets matches[0] to ''.
        yield 'a caret branch wins the whole match' => ['pattern' => '/^|foo/', 'group' => 0, 'values' => ['']];
        yield 'a word boundary branch wins the whole match' => ['pattern' => '/\b|foo/', 'group' => 0, 'values' => ['']];
        // A group keeps every branch: a scan resumes after the empty match,
        // where the caret no longer matches. preg_match_all('/(^|bar)/', 'bar')
        // sets group 1 to '' then 'bar'.
        yield 'a caret branch inside a group keeps the later branches' => ['pattern' => '/(^|foo)/', 'group' => 1, 'values' => ['', 'foo']];
        yield 'a word boundary branch inside a group keeps the later branches' => ['pattern' => '/(\b|foo)/', 'group' => 1, 'values' => ['', 'foo']];
        // The engine backtracks into the later branches when what follows
        // fails: preg_match('/(^|a)b/', 'ab') sets matches[0] to 'ab'.
        yield 'a caret branch loses when what follows it fails' => ['pattern' => '/(?:^|a)b/', 'group' => 0, 'values' => ['b', 'ab']];
        yield 'a caret branch loses inside a group when what follows it fails' => ['pattern' => '/(^|a)b/', 'group' => 1, 'values' => ['', 'a']];
        yield 'a caret branch loses after a consuming prefix' => ['pattern' => '/x(^|foo)/', 'group' => 0, 'values' => ['x', 'xfoo']];
        // '\b' still wins at the first boundary, but a branch that may start
        // where no boundary sits keeps its value: preg_match('/\b|-x/', '!x')
        // reads '' while '-x' reads '-x'.
        yield 'a boundary branch beside a non-word start keeps both' => ['pattern' => '/\b|-x/', 'group' => 0, 'values' => ['', '-x']];
        yield 'a zero-width branch read second stays' => ['pattern' => '/(foo|$)/', 'group' => 1, 'values' => ['foo', '']];
        yield 'a boundary branch before an escaped word character' => ['pattern' => '/\b|\x41/', 'group' => 0, 'values' => ['']];
        yield 'a boundary branch before a zero-width prefix and a word' => ['pattern' => '/\b|^foo/', 'group' => 0, 'values' => ['']];
        yield 'a boundary branch before a grouped word' => ['pattern' => '/\b|(?:foo)/', 'group' => 0, 'values' => ['']];
        yield 'a boundary branch before a class keeps both' => ['pattern' => '/\b|\d+x/', 'group' => 0, 'values' => null];
        yield 'a boundary branch before an all-zero-width branch' => ['pattern' => '/\b|(?=x)$/', 'group' => 0, 'values' => ['']];
        // Without /u a class matches one byte: preg_match('/[é]/', "\xc3")
        // and preg_match('/[é]/', "\xa9") both match.
        yield 'a class reads bytes without u' => ['pattern' => '/[é]/', 'group' => 0, 'values' => ["\xc3", "\xa9"]];
        yield 'a class escape past seven bits is a byte without u' => ['pattern' => '/([\xad])/', 'group' => 1, 'values' => ["\xad"]];
        yield 'a class range past seven bits is bytes without u' => ['pattern' => '/[\xe0-\xe2]/', 'group' => 0, 'values' => ["\xe0", "\xe1", "\xe2"]];
        yield 'a class reads characters under u' => ['pattern' => '/[é]/u', 'group' => 0, 'values' => ['é']];
        // An inline option holds where PCRE says it does: inside its group when
        // scoped, to the end of the enclosing group when bare, and into the
        // following branches: preg_match('/(?i:a)(b)/', 'aB') is 0,
        // preg_match('/((?i)a)(b)/', 'aB') is 0, preg_match('/(a(?i)b|c)/', 'C')
        // -> ["C","C"], preg_match('/(?i)(?-i:(a))/', 'A') is 0 and
        // preg_match('/(?i:x(?^)(a))/', 'xA') is 0 (PHP 8.4.27, PCRE2 10.49).
        yield 'a scoped caseless option ends with its group' => ['pattern' => '/(?i:a)(b)/', 'group' => 1, 'values' => ['b']];
        yield 'a bare caseless option ends with its group' => ['pattern' => '/((?i)a)(b)/', 'group' => 2, 'values' => ['b']];
        yield 'a bare caseless option reads its own group caselessly' => ['pattern' => '/((?i)a)(b)/', 'group' => 1, 'values' => null];
        yield 'a bare caseless option reaches the following branches' => ['pattern' => '/(a(?i)b|c)/', 'group' => 1, 'values' => null];
        yield 'a scoped option turns caseless off' => ['pattern' => '/(?i)(?-i:(a))/', 'group' => 1, 'values' => ['a']];
        yield 'a bare option turns caseless off to the end of its group' => ['pattern' => '/(?i:(?-i)(a))(b)/i', 'group' => 1, 'values' => ['a']];
        yield 'a bare option turns caseless off inside its group only' => ['pattern' => '/(?i:(?-i)(a))(b)/i', 'group' => 2, 'values' => null];
        yield 'a reset option turns caseless off' => ['pattern' => '/(?i:x(?^)(a))/', 'group' => 1, 'values' => ['a']];
        // A call reads what the group it calls reads, with the options of
        // that group: preg_match('/(?<t>a)(?:&(?<t2>(?&t)))?/', 'a&a') sets
        // t2 to 'a', preg_match('/(?i:(a))((?1))/', 'aA') sets group 2 to 'A'
        // and preg_match('/(a)(?i:((?1)))/', 'aA') is 0 (PHP 8.4.27, PCRE2 10.49).
        yield 'a call by name' => ['pattern' => '/(?<t>a)(?:&(?<t2>(?&t)))?/', 'group' => 2, 'values' => ['a']];
        yield 'a call by number' => ['pattern' => '/(a)((?1))/', 'group' => 2, 'values' => ['a']];
        yield 'a relative call back' => ['pattern' => '/(a)((?-2))/', 'group' => 2, 'values' => ['a']];
        // "(?-1)" in group 2 calls group 2 itself: preg_match('/(a)((?-1))/', 'aa') is 0.
        yield 'a relative call of the group that holds it' => ['pattern' => '/(a)((?-1))/', 'group' => 2, 'values' => null];
        yield 'a relative call forward' => ['pattern' => '/((?+1))(a)/', 'group' => 1, 'values' => ['a']];
        yield 'a call reads with the options of the group it calls' => ['pattern' => '/(?i:(a))((?1))/', 'group' => 2, 'values' => null];
        yield 'a call ignores the options around it' => ['pattern' => '/(a)(?i:((?1)))/', 'group' => 2, 'values' => ['a']];
        yield 'a call into a group that holds it' => ['pattern' => '/(a(?1)?b)/', 'group' => 1, 'values' => null];
        yield 'a call of the whole pattern' => ['pattern' => '/(x|(?R))/', 'group' => 1, 'values' => null];
        yield 'a call of a group a call holds' => ['pattern' => '/(a(?2)?)(b(?1)?)/', 'group' => 1, 'values' => null];
        // preg_match('/(x)((?1)(?1))((?2))/', 'xxxxx') sets group 3 to 'xx'.
        yield 'a call of a group that calls another twice' => ['pattern' => '/(x)((?1)(?1))((?2))/', 'group' => 3, 'values' => ['xx']];
    }

    /**
     * @return iterable<string, array{pattern: string, group: int, arms: list<array{values: list<string>|null, digitsOnly: bool}>}>
     */
    public static function provideArms(): iterable
    {
        yield 'a constant beside digits' => ['pattern' => '/(a|\d)/', 'group' => 1, 'arms' => [['values' => ['a'], 'digitsOnly' => false], ['values' => null, 'digitsOnly' => true]]];
        yield 'through a group that does not capture' => ['pattern' => '/((?:a|\d+))/', 'group' => 1, 'arms' => [['values' => ['a'], 'digitsOnly' => false], ['values' => null, 'digitsOnly' => true]]];
        yield 'the whole match' => ['pattern' => '/a|\d/', 'group' => 0, 'arms' => [['values' => ['a'], 'digitsOnly' => false], ['values' => null, 'digitsOnly' => true]]];
        yield 'each branch of a branch reset' => ['pattern' => '/(?|(a)|(\d))/', 'group' => 1, 'arms' => [['values' => ['a'], 'digitsOnly' => false], ['values' => null, 'digitsOnly' => true]]];
        yield 'arms that read the same once' => ['pattern' => '/(a|\d|\d)/', 'group' => 1, 'arms' => [['values' => ['a'], 'digitsOnly' => false], ['values' => null, 'digitsOnly' => true]]];
        // A scan from offset 1 reads past the "^" a first match would stop at:
        // preg_match('/\d|(?:^|x)/', '1x', $m, 0, 1) -> ["x"].
        yield 'the whole match of a scan' => ['pattern' => '/\d|(?:^|x)/', 'group' => 0, 'arms' => [['values' => null, 'digitsOnly' => true], ['values' => ['', 'x'], 'digitsOnly' => false]]];
        yield 'values the join already holds' => ['pattern' => '/(a|b)/', 'group' => 1, 'arms' => []];
        yield 'digits the join already holds' => ['pattern' => '/(\d|\d\d)/', 'group' => 1, 'arms' => []];
        yield 'arms that say nothing more' => ['pattern' => '/(a+|b+)/', 'group' => 1, 'arms' => []];
        yield 'a repeated alternation' => ['pattern' => '/((?:a|\d)+)/', 'group' => 1, 'arms' => []];
        yield 'a group that may hold what a cut left' => ['pattern' => '/(a|\d(*ACCEPT))/', 'group' => 1, 'arms' => []];
        yield 'a group no match sets' => ['pattern' => '/(?!(a|\d))b/', 'group' => 1, 'arms' => []];
    }

    /**
     * @return iterable<string, array{pattern: string, group: int, values: list<string>|null, participation: Participation, subjects: list<string>}>
     */
    public static function provideNewerSyntax(): iterable
    {
        yield 'a call returns a group by number' => ['pattern' => '/(?(DEFINE)((Sat)urday))(?1(2)),\2/', 'group' => 2, 'values' => ['Sat'], 'participation' => Participation::MayBeUnset, 'subjects' => ['Saturday,Sat']];
        yield 'a call returns a group by relative number' => ['pattern' => '/(?(DEFINE)((Sat)urday))(?-2(-1)),\2/', 'group' => 2, 'values' => ['Sat'], 'participation' => Participation::MayBeUnset, 'subjects' => ['Saturday,Sat']];
        yield 'a call returns a group by name' => ['pattern' => '/(?(DEFINE)(?<fn>(?<ret>Sat)urday))(?&fn(<ret>)),\k<ret>/', 'group' => 2, 'values' => ['Sat'], 'participation' => Participation::MayBeUnset, 'subjects' => ['Saturday,Sat']];
        // A name returns every group bearing it: on 'abcd!' the engine sets
        // group 2 to 'ab' and group 3 to 'cd', both named n.
        yield 'a call returns every group bearing a name' => ['pattern' => '/^(?>(?1(<n>)))!(?(DEFINE)((?<n>..)(?<n>..)))|abcde/J', 'group' => 3, 'values' => null, 'participation' => Participation::MayBeUnset, 'subjects' => ['abcd!', '1221!']];
        // A returned number is the capture of whichever branch of a branch
        // reset ran: preg_match('/(?(DEFINE)(?<w>(?|(Sat)urday|(Sun)day)))(?&w(2)),\2/', 'Saturday,Sat')
        // sets group 2 to 'Sat', by name as by number.
        yield 'a call returns a branch reset group by number' => ['pattern' => '/(?(DEFINE)(?<w>(?|(Sat)urday|(Sun)day)))(?&w(2)),\2/', 'group' => 2, 'values' => ['Sat', 'Sun'], 'participation' => Participation::MayBeUnset, 'subjects' => ['Saturday,Sat', 'Sunday,Sun']];
        yield 'a call returns a branch reset group by name' => ['pattern' => '/(?(DEFINE)(?<w>(?|(?<s>Sat)urday|(?<s>Sun)day)))(?&w(<s>)),\k<s>/', 'group' => 2, 'values' => ['Sat', 'Sun'], 'participation' => Participation::MayBeUnset, 'subjects' => ['Saturday,Sat', 'Sunday,Sun']];
        yield 'a call leaves the group it runs unset' => ['pattern' => '/(?(DEFINE)((Sat)urday))(?1(2)),\2/', 'group' => 1, 'values' => null, 'participation' => Participation::Never, 'subjects' => ['Saturday,Sat']];
        yield 'a substring scan reads no character' => ['pattern' => "/(?<AA>a)(*scan_substring:(1,'AA',1,<AA>)a)b/", 'group' => 0, 'values' => ['ab'], 'participation' => Participation::Always, 'subjects' => ['ab']];
        yield 'a substring scan after an alternation' => ['pattern' => "/(?:(?'A'a)|(?<A>b))(*scs:('A')b)c/J", 'group' => 0, 'values' => ['ac', 'bc'], 'participation' => Participation::Always, 'subjects' => ['bc']];
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
        // preg_match('/a\Kb?/', 'a') -> [""]: \K leaves the whole match empty, so its minimum is exactly 0
        yield 'keep cuts the whole match short' => ['pattern' => '/a\Kb?/', 'group' => 0, 'min' => 0, 'max' => null];
        // A call reads as long as the body of the group it calls, wherever it stands
        // (PHP 8.4.27, PCRE2 10.49): preg_match('/(?<t>ab)x((?&t)c)/', 'abxabc') sets
        // group 2 to 'abc'; preg_match('/(?<t>a{1,3})x((?&t)c)/', 'axaaac') to 'aaac';
        // preg_match('/(x)((?1)(?1))((?2)z)/', 'xxxxxz') group 3 to 'xxz';
        // preg_match('/(?(DEFINE)(?<d>\d\d))((?&d)-(?&d))/', '12-34') group 2 to '12-34';
        // preg_match('/(?<t>éé)x((?&t)c)/u', 'ééxééc') group 2 to 'ééc', three code points.
        yield 'a call in a sequence reads the body it calls' => ['pattern' => '/(?<t>ab)x((?&t)c)/', 'group' => 2, 'min' => 3, 'max' => 3];
        yield 'a call of a bounded repeat' => ['pattern' => '/(?<t>a{1,3})x((?&t)c)/', 'group' => 2, 'min' => 2, 'max' => 4];
        yield 'a call of an unbounded repeat' => ['pattern' => '/(?<t>a+)x((?&t)c)/', 'group' => 2, 'min' => 2, 'max' => null];
        yield 'a call of a group that calls another' => ['pattern' => '/(x)((?1)(?1))((?2)z)/', 'group' => 3, 'min' => 3, 'max' => 3];
        yield 'calls of a group in DEFINE' => ['pattern' => '/(?(DEFINE)(?<d>\d\d))((?&d)-(?&d))/', 'group' => 2, 'min' => 5, 'max' => 5];
        yield 'a call counts code points under /u' => ['pattern' => '/(?<t>éé)x((?&t)c)/u', 'group' => 2, 'min' => 3, 'max' => 3];
        yield 'a call in the whole match' => ['pattern' => '/(?<t>\w\w)x(?&t)/', 'group' => 0, 'min' => 5, 'max' => 5];
        // A recursion stays unbounded from zero, followed or not:
        // preg_match('/(?<t>a(?<u>b(?&u)?))x((?&t)c)/', 'abxabbbc') sets group 3 to
        // 'abbbc', preg_match('/(a(?1)?b)/', 'aabb') group 1 to 'aabb'.
        yield 'a call of a group that holds a recursion' => ['pattern' => '/(?<t>a(?<u>b(?&u)?))x((?&t)c)/', 'group' => 3, 'min' => 3, 'max' => null];
        yield 'a recursion reads unbounded' => ['pattern' => '/(a(?1)?b)/', 'group' => 1, 'min' => 2, 'max' => null];
        yield 'a call of a recursive group reads unbounded from zero' => ['pattern' => '/(?<t>\d(?&t)?)x((?:(?&t)))/', 'group' => 2, 'min' => 0, 'max' => null];
    }

    /**
     * @return iterable<string, array{pattern: string, flags: int, expected: string}>
     */
    public static function provideShapes(): iterable
    {
        yield 'trailing optional group is left out' => ['pattern' => '/(a)(z)?/', 'flags' => 0, 'expected' => "array{0: 'a'|'az', 1: 'a', 2?: 'z'}"];
        yield 'middle optional group is empty' => ['pattern' => '/(z)?(a)/', 'flags' => 0, 'expected' => "array{0: 'a'|'za', 1: ''|'z', 2: 'a'}"];
        yield 'unmatched as null' => ['pattern' => '/(a)(x)?(b)/', 'flags' => \PREG_UNMATCHED_AS_NULL, 'expected' => "array{0: 'ab'|'axb', 1: 'a', 2: 'x'|null, 3: 'b'}"];
        yield 'named group' => ['pattern' => '/(?<n>a+)(b)/', 'flags' => 0, 'expected' => 'array{0: lowercase-string&non-falsy-string, n: lowercase-string&non-falsy-string, 1: lowercase-string&non-falsy-string, 2: \'b\'}'];
        yield 'offset capture' => ['pattern' => '/(z)?(a)/', 'flags' => \PREG_OFFSET_CAPTURE, 'expected' => "array{0: array{'a'|'za', int<0, max>}, 1: array{''|'z', int<-1, max>}, 2: array{'a', int<0, max>}}"];
        yield 'never set, then set' => ['pattern' => '/(?!(b))(a)/', 'flags' => 0, 'expected' => "array{0: 'a', 1: '', 2: 'a'}"];
        yield 'never set, last' => ['pattern' => '/(?!(b))a/', 'flags' => 0, 'expected' => "array{0: 'a'}"];
        yield 'never set, last, as null' => ['pattern' => '/(?!(b))a/', 'flags' => \PREG_UNMATCHED_AS_NULL, 'expected' => "array{0: 'a', 1: null}"];
        yield 'mark' => ['pattern' => '/(*MARK:m)a/', 'flags' => 0, 'expected' => "array{0: 'a', MARK?: 'm'}"];
        yield 'a constant beside digits' => ['pattern' => '/(a|\d)/', 'flags' => 0, 'expected' => "array{0: 'a'|numeric-string, 1: 'a'|numeric-string}"];
        yield 'a constant beside digits, with offsets' => ['pattern' => '/(a|\d+)x/', 'flags' => \PREG_OFFSET_CAPTURE, 'expected' => "array{0: array{lowercase-string&non-falsy-string, int<0, max>}, 1: array{'a'|numeric-string, int<0, max>}}"];
        // preg_match('/(?<x>a)(*MARK:m)b/', 'ab') -> {"0":"ab","x":"a","1":"a","MARK":"m"}: x keeps its own value, MARK comes last (PHP 8.4.26)
        yield 'named group next to a mark verb' => ['pattern' => '/(?<x>a)(*MARK:m)b/', 'flags' => 0, 'expected' => "array{0: 'ab', x: 'a', 1: 'a', MARK?: 'm'}"];
        // preg_match('/(?<MARK>a)/', 'a') -> {"0":"a","MARK":"a","1":"a"}: without a verb the key holds the group alone
        yield 'group named MARK, no mark verb' => ['pattern' => '/(?<MARK>a)/', 'flags' => 0, 'expected' => "array{0: 'a', MARK: 'a', 1: 'a'}"];
        yield 'trailing optional named group' => ['pattern' => '/(?<y>\d{4})(?:-(?<d>\d\d))?/', 'flags' => 0, 'expected' => 'array{0: non-falsy-string, y: non-falsy-string&numeric-string, 1: non-falsy-string&numeric-string, d?: non-falsy-string&numeric-string, 2?: non-falsy-string&numeric-string}'];
        // A root alternation holding groups splits into one shape per branch:
        // preg_match('/(?J)(?<n>a)|(?<n>b)/', 'a') -> {"0":"a","n":"a","1":"a"}, on 'b' -> {"0":"b","n":"b","1":"","2":"b"}
        yield 'duplicate names' => ['pattern' => '/(?J)(?<n>a)|(?<n>b)/', 'flags' => 0, 'expected' => "array{0: 'a', n: 'a', 1: 'a'}|array{0: 'b', n: 'b', 1: '', 2: 'b'}"];
        yield 'unknown text' => ['pattern' => '/(\w*)/', 'flags' => 0, 'expected' => 'array{0: string, 1: string}'];
        yield 'quote in a value' => ['pattern' => "/(it's)/", 'flags' => 0, 'expected' => "array{0: 'it\\'s', 1: 'it\\'s'}"];
        yield 'unprintable value' => ['pattern' => '/(\x01)/', 'flags' => 0, 'expected' => 'array{0: non-falsy-string, 1: non-falsy-string}'];
        yield 'empty group of unknown text' => ['pattern' => '/(\b)a/i', 'flags' => 0, 'expected' => "array{0: non-falsy-string, 1: ''}"];
        yield 'too many values to write' => ['pattern' => '/(a|b|c|d|e|f|g|h|i|j|k|l|m|n|o|p|q)/', 'flags' => 0, 'expected' => 'array{0: lowercase-string&non-falsy-string, 1: lowercase-string&non-falsy-string}'];
        // preg_match('/(a)(?<x>b)/n', 'ab') -> {"0":"ab","x":"b","1":"b"}, the same under (?n) (PHP 8.4.26)
        yield 'no auto capture' => ['pattern' => '/(a)(?<x>b)/n', 'flags' => 0, 'expected' => "array{0: 'ab', x: 'b', 1: 'b'}"];
        yield 'inline no auto capture' => ['pattern' => '/(?n)(a)(?<x>b)/', 'flags' => 0, 'expected' => "array{0: 'ab', x: 'b', 1: 'b'}"];
        yield 'no auto capture, offsets' => ['pattern' => '/(a)(?<x>b)/n', 'flags' => \PREG_OFFSET_CAPTURE, 'expected' => "array{0: array{'ab', int<0, max>}, x: array{'b', int<0, max>}, 1: array{'b', int<0, max>}}"];
        // preg_match('/(?J)(?:(?<n>a)|(?<n>b))(x)/', 'ax') -> n => 'a', 2 => ''; on 'bx' -> n => 'b', 1 => ''
        yield 'shared name, one group set' => ['pattern' => '/(?J)(?:(?<n>a)|(?<n>b))(x)/', 'flags' => 0, 'expected' => "array{0: 'ax', n: 'a', 1: 'a', 2: '', 3: 'x'}|array{0: 'bx', n: 'b', 1: '', 2: 'b', 3: 'x'}"];
        yield 'shared name, one group set, as null' => ['pattern' => '/(?J)(?:(?<n>a)|(?<n>b))(x)/', 'flags' => \PREG_UNMATCHED_AS_NULL, 'expected' => "array{0: 'ax', n: 'a', 1: 'a', 2: null, 3: 'x'}|array{0: 'bx', n: 'b', 1: null, 2: 'b', 3: 'x'}"];
        // A name one group always sets never reads unset: it holds a set group's value.
        // preg_match('/(?J)(?<n>a)(?<n>z)?(c)/', 'ac') -> {"0":"ac","n":"a","1":"a","2":"","3":"c"}, as null n => 'a' too (PHP 8.4.26, PCRE2 10.49)
        yield 'shared name, one group always set' => ['pattern' => '/(?J)(?<n>a)(?<n>z)?(c)/', 'flags' => 0, 'expected' => "array{0: 'ac'|'azc', n: 'a'|'z', 1: 'a', 2: ''|'z', 3: 'c'}"];
        yield 'shared name, one group always set, as null' => ['pattern' => '/(?J)(?<n>a)(?<n>z)?(c)/', 'flags' => \PREG_UNMATCHED_AS_NULL, 'expected' => "array{0: 'ac'|'azc', n: 'a'|'z', 1: 'a', 2: 'z'|null, 3: 'c'}"];
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
        yield '/(^|a)b/' => ['pattern' => '/(^|a)b/', 'subjects' => ['b', 'ab']];
        yield '/[é]/' => ['pattern' => '/[é]/', 'subjects' => ["\xc3", "\xa9"]];
        yield '/([\xad])/' => ['pattern' => '/([\xad])/', 'subjects' => ["\xad"]];
        yield '/(?i:a)(b)/' => ['pattern' => '/(?i:a)(b)/', 'subjects' => ['ab', 'Ab']];
        yield '/(a|\d)/' => ['pattern' => '/(a|\d)/', 'subjects' => ['a', '5', 'x7']];
        yield '/(?|(a)|(\d))/' => ['pattern' => '/(?|(a)|(\d))/', 'subjects' => ['a', '5']];
        yield '/(a|\d+)x/' => ['pattern' => '/(a|\d+)x/', 'subjects' => ['ax', '12x']];
        yield '/((?i)a)(b)/' => ['pattern' => '/((?i)a)(b)/', 'subjects' => ['ab', 'Ab']];
        yield '/(a(?i)b|c)/' => ['pattern' => '/(a(?i)b|c)/', 'subjects' => ['ab', 'aB', 'c', 'C']];
        yield '/(?i:(?-i)(a))(b)/i' => ['pattern' => '/(?i:(?-i)(a))(b)/i', 'subjects' => ['ab', 'aB']];
        yield '/(?<t>a)(?:&(?<t2>(?&t)))?/' => ['pattern' => '/(?<t>a)(?:&(?<t2>(?&t)))?/', 'subjects' => ['a', 'a&a']];
        yield '/(?i:(a))((?1))/' => ['pattern' => '/(?i:(a))((?1))/', 'subjects' => ['aa', 'AA', 'aA']];
        yield '/(a(?1)?b)/' => ['pattern' => '/(a(?1)?b)/', 'subjects' => ['ab', 'aabb']];
        yield '/(?<t>ab)x((?&t)c)/' => ['pattern' => '/(?<t>ab)x((?&t)c)/', 'subjects' => ['abxabc']];
        yield '/(?<t>a{1,3})x((?&t)c)/' => ['pattern' => '/(?<t>a{1,3})x((?&t)c)/', 'subjects' => ['axac', 'aaaxaaac']];
        yield '/(x)((?1)(?1))((?2)z)/' => ['pattern' => '/(x)((?1)(?1))((?2)z)/', 'subjects' => ['xxxxxz']];
        yield '/(?(DEFINE)(?<d>\d\d))((?&d)-(?&d))/' => ['pattern' => '/(?(DEFINE)(?<d>\d\d))((?&d)-(?&d))/', 'subjects' => ['12-34']];
        yield '/(?<t>éé)x((?&t)c)/u' => ['pattern' => '/(?<t>éé)x((?&t)c)/u', 'subjects' => ['ééxééc']];
        yield '/(?(DEFINE)(?<t>a(*ACCEPT)bb))x((?&t)c)/' => ['pattern' => '/(?(DEFINE)(?<t>a(*ACCEPT)bb))x((?&t)c)/', 'subjects' => ['xac']];
        yield '/(?<t>\w\w)x((?:(?&t)|ab))/' => ['pattern' => '/(?<t>\w\w)x((?:(?&t)|ab))/', 'subjects' => ['abx00', 'abxab']];
        yield '/(?<t>a(?<u>b(?&u)?))x((?&t)c)/' => ['pattern' => '/(?<t>a(?<u>b(?&u)?))x((?&t)c)/', 'subjects' => ['abxabc', 'abxabbbc']];
        yield '/(?<t>\d(?&t)?)x((?:(?&t)))/' => ['pattern' => '/(?<t>\d(?&t)?)x((?:(?&t)))/', 'subjects' => ['1x0', '12x345']];
        yield '/(?<n>a)(b)/' => ['pattern' => '/(?<n>a)(b)/', 'subjects' => ['ab']];
        yield '/(?J)(?<n>a)|(?<n>b)/' => ['pattern' => '/(?J)(?<n>a)|(?<n>b)/', 'subjects' => ['a', 'b']];
        yield '/(a){0}b/' => ['pattern' => '/(a){0}b/', 'subjects' => ['b']];
        yield '/(a{1,3})(b{0,2}?)c/' => ['pattern' => '/(a{1,3})(b{0,2}?)c/', 'subjects' => ['ac', 'aaabbc', 'abc']];
        yield '/^(\d{3})-(\d{4})$/' => ['pattern' => '/^(\d{3})-(\d{4})$/', 'subjects' => ['555-1234']];
        yield '/(foo|bar)(baz)?/i' => ['pattern' => '/(foo|bar)(baz)?/i', 'subjects' => ['FOObaz', 'bar']];
        yield '/(a b)/x' => ['pattern' => '/(a b)/x', 'subjects' => ['ab']];
        yield '/(?<y>\d{4})-(?<m>\d\d)?/' => ['pattern' => '/(?<y>\d{4})-(?<m>\d\d)?/', 'subjects' => ['2026-10', '2026-']];
        yield '/(x)?(?(1)(a)|(b))/' => ['pattern' => '/(x)?(?(1)(a)|(b))/', 'subjects' => ['xa', 'b']];
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
        yield '/(?J)(?<n>a)(?<n>z)?(c)/' => ['pattern' => '/(?J)(?<n>a)(?<n>z)?(c)/', 'subjects' => ['ac', 'azc']];
        yield '/(?J)(?:(?<n>a)|(?<n>b))?(x)/' => ['pattern' => '/(?J)(?:(?<n>a)|(?<n>b))?(x)/', 'subjects' => ['x', 'ax']];
        yield '/(a)(?<x>b)/n' => ['pattern' => '/(a)(?<x>b)/n', 'subjects' => ['ab']];
        yield '/(?n)(a)(?<x>b)/' => ['pattern' => '/(?n)(a)(?<x>b)/', 'subjects' => ['ab']];
        yield '/(?<p>a)(b)(?<q>c)/n' => ['pattern' => '/(?<p>a)(b)(?<q>c)/n', 'subjects' => ['abc']];
        yield '/(?(DEFINE)(?<d>x))(a)(?&d)/' => ['pattern' => '/(?(DEFINE)(?<d>x))(a)(?&d)/', 'subjects' => ['ax']];
        yield '/(?|(a)|(b)(c))(d)/' => ['pattern' => '/(?|(a)|(b)(c))(d)/', 'subjects' => ['ad', 'bcd']];
        // preg_match('/((?(?!(a))x|a))/', 'a') -> ["a","a","a"] (PHP 8.4.27, PCRE2 10.49): the failed negative condition keeps group 2
        yield '/((?(?!(a))x|a))/' => ['pattern' => '/((?(?!(a))x|a))/', 'subjects' => ['a', 'x']];
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
        // The root alternation splits; a verb in the branch not taken leaves no mark:
        // preg_match('/(*MARK:x)c|b/', 'b') -> ["b"] (PHP 8.4.26, PCRE2 10.49).
        yield 'group and verb in different branches' => ['pattern' => '/(?<MARK>a)|(*MARK:x)b/', 'flags' => 0, 'expected' => "array{0: 'a', MARK: 'a', 1: 'a'}|array{0: 'b', MARK?: 'x'}"];
        yield 'group and verb in different branches, offsets' => ['pattern' => '/(?<MARK>a)|(*MARK:x)b/', 'flags' => \PREG_OFFSET_CAPTURE, 'expected' => "array{0: array{'a', int<0, max>}, MARK: array{'a', int<0, max>}, 1: array{'a', int<0, max>}}|array{0: array{'b', int<0, max>}, MARK?: 'x'}"];
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
     * Oracle (PHP 8.4.26, PCRE2 10.49), and (bool) '00' === true:
     *   preg_match('/(\d)/', '0')          -> ["0","0"]
     *   preg_match('/(a|0)/', '0')         -> ["0","0"]
     *   preg_match('/(a?)/', '')           -> ["",""]
     *   preg_match('/(a?)(\1)/', '')       -> ["","",""]
     *   preg_match('/(0(*ACCEPT)1)/', '01') -> ["0","0"]
     *   preg_match('/(.)/', '0')           -> ["0","0"]
     *   preg_match('/^([^a])$/', '0')      -> 1
     *   preg_match('/^([^0])$/', '0'), and the same for [^\d], [^a0],
     *   (?:a|)[1-9] and, under /u, (?:\Qab\E)?[1-9]  -> 0
     *
     * @return iterable<string, array{pattern: string, group: int, expected: bool}>
     */
    public static function provideNonFalsy(): iterable
    {
        yield 'two characters' => ['pattern' => '/(ab)/', 'group' => 1, 'expected' => true];
        yield 'may be empty' => ['pattern' => '/(a?)/', 'group' => 1, 'expected' => false];
        yield 'one character from a class without zero' => ['pattern' => '/([1-9])/', 'group' => 1, 'expected' => true];
        yield 'one digit may be zero' => ['pattern' => '/(\d)/', 'group' => 1, 'expected' => false];
        yield 'the zero literal' => ['pattern' => '/(0)/', 'group' => 1, 'expected' => false];
        yield 'two digits, "00" included, are truthy' => ['pattern' => '/(\d\d)/', 'group' => 1, 'expected' => true];
        yield 'the "00" literal is truthy' => ['pattern' => '/(00)/', 'group' => 1, 'expected' => true];
        yield 'never set: negative lookahead' => ['pattern' => '/(?!(ab))c/', 'group' => 1, 'expected' => false];
        yield 'never set: zero repeat' => ['pattern' => '/(ab){0}c/', 'group' => 1, 'expected' => false];
        yield 'every branch truthy, one starts with zero' => ['pattern' => '/(a|0b)/', 'group' => 1, 'expected' => true];
        yield 'a branch reads zero alone' => ['pattern' => '/(a|0)/', 'group' => 1, 'expected' => false];
        yield 'optional group: the fact describes the set value' => ['pattern' => '/(ab)?/', 'group' => 1, 'expected' => true];
        yield 'whole match of two characters' => ['pattern' => '/ab/', 'group' => 0, 'expected' => true];
        yield 'whole match of one digit' => ['pattern' => '/\d/', 'group' => 0, 'expected' => false];
        yield 'backreference to a group that may be empty' => ['pattern' => '/(a?)(\1)/', 'group' => 2, 'expected' => false];
        yield 'accept leaves the group at "0"' => ['pattern' => '/(0(*ACCEPT)1)/', 'group' => 1, 'expected' => false];
        yield 'any character may be zero' => ['pattern' => '/(.)/', 'group' => 1, 'expected' => false];
        yield 'any code point may be zero' => ['pattern' => '/(.)/u', 'group' => 1, 'expected' => false];
        yield 'one code point that is not zero' => ['pattern' => '/(é)/u', 'group' => 1, 'expected' => true];
        yield 'caseless, two characters' => ['pattern' => '/(ab)/i', 'group' => 1, 'expected' => true];
        yield 'a negated class that holds zero' => ['pattern' => '/([^0])/', 'group' => 1, 'expected' => true];
        yield 'a negated class that holds every digit' => ['pattern' => '/([^\d])/', 'group' => 1, 'expected' => true];
        yield 'a negated class with zero among its members' => ['pattern' => '/([^a0])/', 'group' => 1, 'expected' => true];
        yield 'a negated class without zero' => ['pattern' => '/([^a])/', 'group' => 1, 'expected' => false];
        yield 'an empty alternative reads no zero' => ['pattern' => '/((?:a|)[1-9])/', 'group' => 1, 'expected' => true];
        yield 'an empty alternative, then a character that may be zero' => ['pattern' => '/((?:a|)\w)/', 'group' => 1, 'expected' => false];
        yield 'a quoted run of two code points is not zero alone' => ['pattern' => '/((?:\Qab\E)?[1-9])/u', 'group' => 1, 'expected' => true];
        yield 'a quoted run of two code points, then a character that may be zero' => ['pattern' => '/((?:\Qab\E)?\w)/u', 'group' => 1, 'expected' => false];
        yield 'a mandatory literal pins the value away from zero' => ['pattern' => '/(3(?:\/.*)?)?/', 'group' => 1, 'expected' => true];
        yield 'an optional letter prefix keeps the set value truthy' => ['pattern' => '/(c(\d+)?)?/', 'group' => 1, 'expected' => true];
        yield 'a mandatory suffix keeps the set value truthy' => ['pattern' => '/([0-9]*\$)?/', 'group' => 1, 'expected' => true];
        yield 'whole match pinned by a mandatory literal' => ['pattern' => '/3(?:\/.*)?$/', 'group' => 0, 'expected' => true];
        yield 'a lone zero beside optionals still reads falsy' => ['pattern' => '/((a)?0)?/', 'group' => 1, 'expected' => false];
        // preg_match('/(?<t>\w\w)x((?:(?&t)|ab))/', 'abx00') sets group 2 to '00':
        // two characters either way, so never '' or '0' (PHP 8.4.27, PCRE2 10.49).
        yield 'a call nested in a group reads the length of its body' => ['pattern' => '/(?<t>\w\w)x((?:(?&t)|ab))/', 'group' => 2, 'expected' => true];
        // preg_match('/(?<t>\d(?&t)?)x((?:(?&t)))/', '1x0') sets group 2 to '0'.
        yield 'a call of a recursive group may read zero' => ['pattern' => '/(?<t>\d(?&t)?)x((?:(?&t)))/', 'group' => 2, 'expected' => false];
    }

    /**
     * Oracle (PHP 8.4.26, PCRE2 10.49), U+0663 ARABIC-INDIC DIGIT THREE, for
     * which ctype_digit() is false:
     *   preg_match('/(\d+)/u', "\u{663}")             -> 1: /u turns UCP on
     *   preg_match('/(*UTF)(*UCP)(\d+)/', "\u{663}")  -> 1
     *   preg_match('/([[:digit:]]+)/u', "\u{663}")    -> 1: UCP widens the POSIX class too
     *   preg_match('/(\p{Nd}+)/u', "\u{663}")         -> 1
     *   preg_match('/(*UTF)(\d+)/', "\u{663}")        -> 0: UTF without UCP keeps \d ASCII
     *   preg_match('/(\d+)/', "\u{663}")              -> 0
     *   preg_match('/([0-9]+)/u', "\u{663}")          -> 0, and no code point folds into [0-9] under /iu
     *   \d, \d under /i and [0-9] under /i match the bytes 0x30-0x39 only, in byte mode
     *
     * @return iterable<string, array{pattern: string, group: int, expected: bool}>
     */
    public static function provideDigitsOnly(): iterable
    {
        yield 'a class of ASCII digits' => ['pattern' => '/([0-9]+)/', 'group' => 1, 'expected' => true];
        yield '\d without UCP' => ['pattern' => '/(\d+)/', 'group' => 1, 'expected' => true];
        yield '\d under /u matches non-ASCII digits' => ['pattern' => '/(\d+)/u', 'group' => 1, 'expected' => false];
        yield '\d under (*UTF)(*UCP) matches non-ASCII digits' => ['pattern' => '/(*UTF)(*UCP)(\d+)/', 'group' => 1, 'expected' => false];
        // A deliberate over-approximation: \d proves digitsOnly only without
        // UCP, so the answer reads the pattern alone, not the engine's
        // tables. In byte mode the engine itself keeps \d to 0x30-0x39 under
        // (*UCP) (no Latin-1 byte is Nd), so true would be sound here.
        yield '\d under (*UCP) is not proven' => ['pattern' => '/(*UCP)(\d+)/', 'group' => 1, 'expected' => false];
        yield '\d under (*UTF) without UCP' => ['pattern' => '/(*UTF)(\d+)/', 'group' => 1, 'expected' => true];
        yield '\d under /i' => ['pattern' => '/(\d+)/i', 'group' => 1, 'expected' => true];
        yield 'a class of ASCII digits under /u' => ['pattern' => '/([0-9]+)/u', 'group' => 1, 'expected' => true];
        yield 'a class of ASCII digits under /iu' => ['pattern' => '/([0-9]+)/iu', 'group' => 1, 'expected' => true];
        yield 'POSIX digit under /u matches non-ASCII digits' => ['pattern' => '/([[:digit:]]+)/u', 'group' => 1, 'expected' => false];
        yield '\p{Nd} under /u' => ['pattern' => '/(\p{Nd}+)/u', 'group' => 1, 'expected' => false];
        yield 'a range with escaped endpoints' => ['pattern' => '/([\x30-\x39]+)/', 'group' => 1, 'expected' => true];
        yield 'a digit then a letter' => ['pattern' => '/(1a)/', 'group' => 1, 'expected' => false];
        // a digit run of any length is digits or the empty string: the fact
        // describes every non-empty value, the readers compose the '' arm
        yield 'may be empty' => ['pattern' => '/(\d*)/', 'group' => 1, 'expected' => true];
        yield 'never set' => ['pattern' => '/(?!(1))a/', 'group' => 1, 'expected' => false];
        yield 'optional group: the fact describes the set value' => ['pattern' => '/(\d+)?/', 'group' => 1, 'expected' => true];
        yield 'the zero literal is digits' => ['pattern' => '/(0)/', 'group' => 1, 'expected' => true];
        yield 'two digits' => ['pattern' => '/(\d\d)/', 'group' => 1, 'expected' => true];
        yield 'whole match of digits' => ['pattern' => '/(\d+)/', 'group' => 0, 'expected' => true];
        yield 'whole match with a dot' => ['pattern' => '/(\d+)\.(\d+)/', 'group' => 0, 'expected' => false];
        yield 'a digit or a dash' => ['pattern' => '/(\d|-)/', 'group' => 1, 'expected' => false];
    }

    /**
     * PHPStan reads a constant string as lowercase-string when
     * strtolower($v) === $v, which since PHP 8.2 folds the ASCII letters
     * only: strtolower('É') === 'É'. A value is lowercase when it holds no
     * byte A-Z. Oracle (PHP 8.4.27, PCRE2 10.49):
     *   preg_match('/([a-z]+)/i', 'AB')                  -> ["AB","AB"]
     *   preg_match('/(?i:x)([a-z]+)/', 'XAB')            -> 0: the option ends with its group
     *   preg_match('/(\d+)/u', "\u{663}")                -> 1: a digit of another script, no letter
     *   preg_match('/(\w+)/', 'A')                       -> ["A","A"]
     *   preg_match('/^\W$/i', 'A')                       -> 0: \W reads no letter, caseless or not
     *   preg_match('/(\xE9)/i', "\xC9")                  -> 0: without /u nothing past ASCII folds
     *   preg_match('/(é)/iu', 'É')                       -> ["É","É"]: lowercase by strtolower(), not proven
     *   preg_match('/(\x{212A})/iu', 'K')                -> ["K","K"]: KELVIN SIGN folds into ASCII
     *   preg_match('/(\x{17F})/iu', 'S')                 -> ["S","S"]: LATIN SMALL LETTER LONG S too
     *   preg_match('/(\p{Ll}+)/iu', 'A')                 -> ["A","A"]: caseless, Ll reads any cased letter
     *   preg_match('/([[:lower:]]+)/i', 'A')             -> ["A","A"]
     *   preg_match('/(a(?i)b)/', 'aB')                   -> ["aB","aB"]
     *   preg_match('/(?i)(?<t>[a-z])(?-i)x((?&t))/', 'axB') -> {"0":"axB","t":"a","1":"a","2":"B"}: a call reads with the options of its group
     *   preg_match('/([Z-a]+)/', 'Z')                    -> ["Z","Z"]
     *   preg_match('/([0-9_.-]+)/i', '0_.-')             -> 1: no digit or punctuation folds
     *
     * @return iterable<string, array{pattern: string, group: int, expected: bool}>
     */
    public static function provideLowercase(): iterable
    {
        yield 'a class of lowercase letters' => ['pattern' => '/([a-z]+)/', 'group' => 1, 'expected' => true];
        yield 'a class of uppercase letters' => ['pattern' => '/([A-Z]+)/', 'group' => 1, 'expected' => false];
        yield 'caseless, a letter reads in either case' => ['pattern' => '/([a-z]+)/i', 'group' => 1, 'expected' => false];
        yield 'a scoped caseless option ends with its group' => ['pattern' => '/(?i:x)([a-z]+)/', 'group' => 1, 'expected' => true];
        yield 'a caseless option turned off again' => ['pattern' => '/(?i)x(?-i)([a-z]+)/', 'group' => 1, 'expected' => true];
        yield 'digits hold no letter' => ['pattern' => '/(\d+)/', 'group' => 1, 'expected' => true];
        yield 'digits of any script hold no ASCII letter' => ['pattern' => '/(\d+)/u', 'group' => 1, 'expected' => true];
        yield 'word characters' => ['pattern' => '/(\w+)/', 'group' => 1, 'expected' => false];
        yield 'non-word characters, caseless' => ['pattern' => '/(\W+)/i', 'group' => 1, 'expected' => true];
        yield 'white space or a line break' => ['pattern' => '/(\s+|\R)/', 'group' => 1, 'expected' => true];
        // A deliberate over-approximation: a negated class is not read.
        yield 'a negated class' => ['pattern' => '/([^A-Z]+)/', 'group' => 1, 'expected' => false];
        yield 'a code point past ASCII' => ['pattern' => '/(é)/u', 'group' => 1, 'expected' => true];
        // A deliberate over-approximation: 'É' is lowercase for strtolower(),
        // but under /iu the analysis reads no fold past ASCII, as \x{212A} shows.
        yield 'a code point past ASCII, caseless' => ['pattern' => '/(é)/iu', 'group' => 1, 'expected' => false];
        yield 'the Kelvin sign folds into K' => ['pattern' => '/(\x{212A})/iu', 'group' => 1, 'expected' => false];
        yield 'the long s folds into S' => ['pattern' => '/(\x{17F})/iu', 'group' => 1, 'expected' => false];
        yield 'a byte past ASCII that strtolower() keeps' => ['pattern' => '/(\xC9)/', 'group' => 1, 'expected' => true];
        yield 'a byte past ASCII, caseless, without /u' => ['pattern' => '/(\xE9)/i', 'group' => 1, 'expected' => true];
        yield 'lowercase letters of any script' => ['pattern' => '/(\p{Ll}+)/u', 'group' => 1, 'expected' => true];
        yield 'lowercase letters of any script, caseless' => ['pattern' => '/(\p{Ll}+)/iu', 'group' => 1, 'expected' => false];
        yield 'lowercase letters of any script, in a class' => ['pattern' => '/([\p{Ll}\d]+)/u', 'group' => 1, 'expected' => true];
        yield 'the POSIX lower class' => ['pattern' => '/([[:lower:]]+)/', 'group' => 1, 'expected' => true];
        yield 'the POSIX lower class, caseless' => ['pattern' => '/([[:lower:]]+)/i', 'group' => 1, 'expected' => false];
        yield 'the POSIX upper class' => ['pattern' => '/([[:upper:]]+)/', 'group' => 1, 'expected' => false];
        yield 'any character' => ['pattern' => '/(.)/', 'group' => 1, 'expected' => false];
        yield 'constants beside digits' => ['pattern' => '/(abc|\d+)/', 'group' => 1, 'expected' => true];
        yield 'an inline caseless option inside the group' => ['pattern' => '/(a(?i)b)/', 'group' => 1, 'expected' => false];
        yield 'a call of a group of lowercase letters' => ['pattern' => '/(?<t>[a-z]+)-((?&t))/', 'group' => 2, 'expected' => true];
        yield 'a call reads with the options of its group' => ['pattern' => '/(?i)(?<t>[a-z])(?-i)x((?&t))/', 'group' => 2, 'expected' => false];
        // A deliberate over-approximation: a backreference is not read.
        yield 'a backreference' => ['pattern' => '/([a-z])(\1)/', 'group' => 2, 'expected' => false];
        yield 'optional group: the fact describes the set value' => ['pattern' => '/([a-z]+)?/', 'group' => 1, 'expected' => true];
        yield 'never set' => ['pattern' => '/(?!([a-z]))A/', 'group' => 1, 'expected' => false];
        yield 'an uppercase letter spelled as an escape' => ['pattern' => '/(\x41)/', 'group' => 1, 'expected' => false];
        yield 'a range with escaped endpoints' => ['pattern' => '/([\x61-\x7a]+)/', 'group' => 1, 'expected' => true];
        yield 'a range across both cases' => ['pattern' => '/([Z-a]+)/', 'group' => 1, 'expected' => false];
        yield 'digits and punctuation, caseless' => ['pattern' => '/([0-9_.-]+)/i', 'group' => 1, 'expected' => true];
        yield 'whole match' => ['pattern' => '/[a-z]+\d/', 'group' => 0, 'expected' => true];
    }

    /**
     * PHPStan reads a constant string as uppercase-string when
     * strtoupper($v) === $v: it holds no byte a-z, so strtoupper('é') === 'é'
     * is uppercase too. Oracle (PHP 8.4.27, PCRE2 10.49):
     *   preg_match('/([A-Z]+)/i', 'ab')        -> ["ab","ab"]
     *   preg_match('/(\p{Lu}+)/iu', 'a')       -> ["a","a"]
     *   preg_match('/([[:upper:]]+)/i', 'a')   -> ["a","a"]
     *   preg_match('/(\x{212A})/iu', 'k')      -> ["k","k"]
     *   preg_match('/(\p{Lt})/u', "\u{1C5}")   -> 1: a titlecase letter is no ASCII letter
     *
     * @return iterable<string, array{pattern: string, group: int, expected: bool}>
     */
    public static function provideUppercase(): iterable
    {
        yield 'a class of uppercase letters' => ['pattern' => '/([A-Z]+)/', 'group' => 1, 'expected' => true];
        yield 'a class of lowercase letters' => ['pattern' => '/([a-z]+)/', 'group' => 1, 'expected' => false];
        yield 'caseless, a letter reads in either case' => ['pattern' => '/([A-Z]+)/i', 'group' => 1, 'expected' => false];
        yield 'digits hold no letter' => ['pattern' => '/(\d+)/', 'group' => 1, 'expected' => true];
        yield 'uppercase letters of any script' => ['pattern' => '/(\p{Lu}+)/u', 'group' => 1, 'expected' => true];
        yield 'uppercase letters of any script, caseless' => ['pattern' => '/(\p{Lu}+)/iu', 'group' => 1, 'expected' => false];
        yield 'lowercase letters of any script' => ['pattern' => '/(\p{Ll}+)/u', 'group' => 1, 'expected' => false];
        yield 'titlecase letters' => ['pattern' => '/(\p{Lt})/u', 'group' => 1, 'expected' => true];
        yield 'the POSIX upper class' => ['pattern' => '/([[:upper:]]+)/', 'group' => 1, 'expected' => true];
        yield 'the POSIX upper class, caseless' => ['pattern' => '/([[:upper:]]+)/i', 'group' => 1, 'expected' => false];
        yield 'an uppercase code point past ASCII' => ['pattern' => '/(É)/u', 'group' => 1, 'expected' => true];
        yield 'a lowercase code point past ASCII, which strtoupper() keeps' => ['pattern' => '/(é)/u', 'group' => 1, 'expected' => true];
        yield 'the Kelvin sign folds into k' => ['pattern' => '/(\x{212A})/iu', 'group' => 1, 'expected' => false];
        yield 'constants and digits' => ['pattern' => '/(ABC|\d+)/', 'group' => 1, 'expected' => true];
        yield 'whole match' => ['pattern' => '/[A-Z]+-\d/', 'group' => 0, 'expected' => true];
    }

    /**
     * PHPStan reads a constant string as decimal-int-string when
     * (string) (int) $v === $v: no leading zero, no '+', no '-0', and no
     * value past PHP_INT_MAX, which (int) caps. Up to 18 digits are always
     * below it on a 64-bit build. Oracle (PHP 8.4.27, PCRE2 10.49):
     *   preg_match('/(\d+)/', '007')                               -> ["007","007"]
     *   preg_match('/^(0|[1-9]\d{0,18})$/', '9999999999999999999') -> 1, (int) gives PHP_INT_MAX
     *   preg_match('/^(-?(?:0|[1-9]\d*))$/', '-0')                 -> ["-0","-0"]
     *   preg_match('/(-?\d)/', '-0')                               -> ["-0","-0"]
     *   preg_match('/([1-9]\d*)/', '99999999999999999999')         -> 1
     *   preg_match('/(\+?[1-9])/', '+1')                           -> ["+1","+1"]
     *   preg_match('/([1-9]\d{0,17})/u', "1\u{663}")               -> 1: \d under /u
     *   preg_match('/((?:0|[1-9])\d)/', '05')                      -> ["05","05"]
     *
     * @return iterable<string, array{pattern: string, group: int, expected: bool}>
     */
    public static function provideDecimalInt(): iterable
    {
        yield 'digits may lead with zero' => ['pattern' => '/(\d+)/', 'group' => 1, 'expected' => false];
        yield 'zero, or up to eighteen digits without a leading zero' => ['pattern' => '/^(0|[1-9]\d{0,17})$/', 'group' => 1, 'expected' => true];
        yield 'nineteen digits may pass PHP_INT_MAX' => ['pattern' => '/^(0|[1-9]\d{0,18})$/', 'group' => 1, 'expected' => false];
        yield 'a sign before up to nine digits' => ['pattern' => '/^(-?[1-9][0-9]{0,8})$/', 'group' => 1, 'expected' => true];
        yield 'a sign before zero' => ['pattern' => '/^(-?(?:0|[1-9]\d*))$/', 'group' => 1, 'expected' => false];
        yield 'a sign before any digit' => ['pattern' => '/(-?\d)/', 'group' => 1, 'expected' => false];
        yield 'unbounded digits' => ['pattern' => '/([1-9]\d*)/', 'group' => 1, 'expected' => false];
        yield 'a plus sign' => ['pattern' => '/(\+?[1-9])/', 'group' => 1, 'expected' => false];
        yield 'one digit that is not zero' => ['pattern' => '/([1-9])/', 'group' => 1, 'expected' => true];
        yield 'zero alone' => ['pattern' => '/(0)/', 'group' => 1, 'expected' => true];
        yield 'two zeros' => ['pattern' => '/(00)/', 'group' => 1, 'expected' => false];
        yield 'minus zero' => ['pattern' => '/(-0)/', 'group' => 1, 'expected' => false];
        yield 'a negative number of up to eighteen digits' => ['pattern' => '/(-[1-9]\d{0,17})/', 'group' => 1, 'expected' => true];
        yield 'an optional sign before up to eighteen digits' => ['pattern' => '/(-?[1-9]\d{0,17})/', 'group' => 1, 'expected' => true];
        yield 'an optional sign in a group' => ['pattern' => '/((?:-|)[1-9])/', 'group' => 1, 'expected' => true];
        yield '\d under /u reads digits of other scripts' => ['pattern' => '/([1-9]\d{0,17})/u', 'group' => 1, 'expected' => false];
        yield 'ASCII digits under /u' => ['pattern' => '/([1-9][0-9]{0,17})/u', 'group' => 1, 'expected' => true];
        yield 'a leading digit that may be zero' => ['pattern' => '/((?:0|[1-9])\d)/', 'group' => 1, 'expected' => false];
        // A deliberate over-approximation: a branch that reads nothing is not
        // read as no first digit, though every value here is one digit.
        yield 'a branch that reads nothing' => ['pattern' => '/((?:[1-9]|\b(?(?=a)))\d)/', 'group' => 1, 'expected' => false];
        yield 'zero as the last branch' => ['pattern' => '/([1-9]\d?|0)/', 'group' => 1, 'expected' => true];
        yield 'an assertion reads nothing' => ['pattern' => '/(\b[1-9]\d{0,2})/', 'group' => 1, 'expected' => true];
        yield 'a repeat that starts without zero' => ['pattern' => '/((?:[1-9]\d{0,8}){2})/', 'group' => 1, 'expected' => true];
        yield 'a repeat that may pass eighteen digits' => ['pattern' => '/((?:[1-9]\d{0,8}){3})/', 'group' => 1, 'expected' => false];
        yield 'a call of a decimal group' => ['pattern' => '/(?<n>[1-9]\d?)x((?&n))/', 'group' => 2, 'expected' => true];
        yield 'digits beside a letter' => ['pattern' => '/([1-9]\d{0,17}|[a-z])/', 'group' => 1, 'expected' => false];
        yield 'optional group: the fact describes the set value' => ['pattern' => '/([1-9]\d{0,17})?/', 'group' => 1, 'expected' => true];
        yield 'never set' => ['pattern' => '/(?!([1-9]))a/', 'group' => 1, 'expected' => false];
        yield 'whole match' => ['pattern' => '/^(?:0|[1-9]\d{0,17})$/D', 'group' => 0, 'expected' => true];
        yield 'whole match with a sign' => ['pattern' => '/^-?[1-9]\d{0,17}$/', 'group' => 0, 'expected' => true];
    }

    /**
     * The subject is known when every match starts at its start, "\A" or
     * "^" without multiline, and ends at its end: "\z", or "$" under /D, both
     * without multiline. "$" without /D and "\Z" also match before a final
     * newline, the one of the newline convention, "\n" by default. Oracle
     * (PHP 8.4.27, PCRE2 10.49):
     *   preg_match('/^[a-z]+$/', "abc\n")        -> ["abc"]: the subject ends in a newline
     *   preg_match('/^[a-z]+$/D', "abc\n")       -> 0
     *   preg_match('/^\d+$/', "0\n")             -> ["0"]
     *   preg_match('/(*CRLF)^a$/', "a\r\n")      -> 1, and on "a\n" -> 0
     *   preg_match('/(*ANYCRLF)^a$/', "a\r")     -> 1
     *   preg_match('/(*CR)^a$/', "a\r")          -> 1
     *   preg_match('/(*CR)(*LF)^a$/', "a\r")     -> 0: the last convention holds
     *   preg_match('/(*ANY)^a$/', "a\x85")       -> 1, and under /u on "a\u{2028}"
     *   preg_match('/^a\Kb$/D', 'ab')            -> ["b"]: the subject is still 'ab'
     *   preg_match('/[a-z]+$/', 'ABc')           -> ["c"]
     *   preg_match('/^[a-z]+/', 'abC')           -> ["ab"]
     *   preg_match('/^[a-z]+$/m', "ab\nCD")      -> ["ab"], and so under (?m), or (?m) after "^"
     *   preg_match('/^a$|b/', 'xb')              -> ["b"]
     *   preg_match('/^a/', 'ba', $m, 0, 1)       -> 0: an offset never lets "^" or "\A" match
     *   preg_match('/\Ga$/', 'ba', $m, 0, 1)     -> 1: "\G" stands at the offset
     *
     * @return iterable<string, array{pattern: string, expected: array{min: int, max: int|null, values: list<string>|null, facts: list<string>}|null}>
     */
    public static function provideSubject(): iterable
    {
        yield 'lowercase letters, then "$"' => ['pattern' => '/^[a-z]+$/', 'expected' => ['min' => 1, 'max' => null, 'values' => null, 'facts' => ['nonFalsy', 'lowercase']]];
        yield 'lowercase letters, then "$" under /D' => ['pattern' => '/^[a-z]+$/D', 'expected' => ['min' => 1, 'max' => null, 'values' => null, 'facts' => ['nonFalsy', 'lowercase']]];
        yield 'lowercase letters in a group' => ['pattern' => '/^([a-z]+)$/', 'expected' => ['min' => 1, 'max' => null, 'values' => null, 'facts' => ['nonFalsy', 'lowercase']]];
        yield 'a decimal integer under /D' => ['pattern' => '/^(0|[1-9]\d{0,17})$/D', 'expected' => ['min' => 1, 'max' => 18, 'values' => null, 'facts' => ['digitsOnly', 'decimalInt', 'lowercase', 'uppercase']]];
        yield 'a decimal integer, then a newline "$" lets through' => ['pattern' => '/^(0|[1-9]\d{0,17})$/', 'expected' => ['min' => 1, 'max' => 19, 'values' => null, 'facts' => ['lowercase', 'uppercase']]];
        yield 'digits, then "$"' => ['pattern' => '/^\d+$/', 'expected' => ['min' => 1, 'max' => null, 'values' => null, 'facts' => ['lowercase', 'uppercase']]];
        yield 'constants, then "$"' => ['pattern' => '/^(a|b)$/', 'expected' => ['min' => 1, 'max' => 2, 'values' => ['a', 'b', "a\n", "b\n"], 'facts' => ['nonFalsy', 'lowercase']]];
        yield 'constants, then "\z"' => ['pattern' => '/^(a|b)\z/', 'expected' => ['min' => 1, 'max' => 1, 'values' => ['a', 'b'], 'facts' => ['nonFalsy', 'lowercase']]];
        yield '"\Z" ignores /D' => ['pattern' => '/\A(a|b)\Z/D', 'expected' => ['min' => 1, 'max' => 2, 'values' => ['a', 'b', "a\n", "b\n"], 'facts' => ['nonFalsy', 'lowercase']]];
        yield 'each branch anchored' => ['pattern' => '/^a$|^b$/', 'expected' => ['min' => 1, 'max' => 2, 'values' => ['a', 'b', "a\n", "b\n"], 'facts' => ['nonFalsy', 'lowercase']]];
        yield 'each branch anchored at the start' => ['pattern' => '/(?:^a|^b)\z/', 'expected' => ['min' => 1, 'max' => 1, 'values' => ['a', 'b'], 'facts' => ['nonFalsy', 'lowercase']]];
        yield 'a branch that may leave a newline' => ['pattern' => '/^(?:a\z|b$)/', 'expected' => ['min' => 1, 'max' => 2, 'values' => ['a', 'b', "a\n", "b\n"], 'facts' => ['nonFalsy', 'lowercase']]];
        yield 'an option before "^"' => ['pattern' => '/(?i)^a$/', 'expected' => ['min' => 1, 'max' => 2, 'values' => null, 'facts' => ['nonFalsy']]];
        yield 'caseless letters' => ['pattern' => '/^(?i)[a-z]+$/', 'expected' => ['min' => 1, 'max' => null, 'values' => null, 'facts' => ['nonFalsy']]];
        yield 'the CRLF convention' => ['pattern' => '/(*CRLF)^a$/', 'expected' => ['min' => 1, 'max' => 3, 'values' => ['a', "a\r\n"], 'facts' => ['nonFalsy', 'lowercase']]];
        yield 'the ANYCRLF convention' => ['pattern' => '/(*ANYCRLF)^a$/', 'expected' => ['min' => 1, 'max' => 3, 'values' => ['a', "a\r", "a\n", "a\r\n"], 'facts' => ['nonFalsy', 'lowercase']]];
        yield 'the ANY convention' => ['pattern' => '/(*ANY)^a$/', 'expected' => ['min' => 1, 'max' => 3, 'values' => ['a', "a\r", "a\n", "a\r\n", "a\x0b", "a\x0c", "a\x85"], 'facts' => ['nonFalsy', 'lowercase']]];
        yield 'the ANY convention under /u' => ['pattern' => '/(*ANY)^a$/u', 'expected' => ['min' => 1, 'max' => 3, 'values' => ['a', "a\r", "a\n", "a\r\n", "a\x0b", "a\x0c", "a\u{85}", "a\u{2028}", "a\u{2029}"], 'facts' => ['nonFalsy', 'lowercase']]];
        yield 'the NUL convention' => ['pattern' => '/(*NUL)^a$/', 'expected' => ['min' => 1, 'max' => 2, 'values' => ['a', "a\0"], 'facts' => ['nonFalsy', 'lowercase']]];
        yield 'the CR convention' => ['pattern' => '/(*CR)^a$/', 'expected' => ['min' => 1, 'max' => 2, 'values' => ['a', "a\r"], 'facts' => ['nonFalsy', 'lowercase']]];
        yield 'the last convention holds' => ['pattern' => '/(*CR)(*LF)^a$/', 'expected' => ['min' => 1, 'max' => 2, 'values' => ['a', "a\n"], 'facts' => ['nonFalsy', 'lowercase']]];
        yield 'a keep moves the match, not the subject' => ['pattern' => '/^a\Kb$/D', 'expected' => ['min' => 2, 'max' => 2, 'values' => ['ab'], 'facts' => ['nonFalsy', 'lowercase']]];
        yield 'a lookahead after "$"' => ['pattern' => '/^a$(?=\n)/', 'expected' => ['min' => 1, 'max' => 2, 'values' => ['a', "a\n"], 'facts' => ['nonFalsy', 'lowercase']]];
        yield 'no start anchor' => ['pattern' => '/[a-z]+$/', 'expected' => null];
        yield 'no end anchor' => ['pattern' => '/^[a-z]+/', 'expected' => null];
        yield 'multiline' => ['pattern' => '/^[a-z]+$/m', 'expected' => null];
        yield 'multiline, inline' => ['pattern' => '/(?m)^[a-z]+$/', 'expected' => null];
        yield 'multiline from after the start anchor' => ['pattern' => '/^(?m)[a-z]+$/', 'expected' => null];
        yield 'a branch without anchors' => ['pattern' => '/^a$|b/', 'expected' => null];
        yield 'a branch without a start anchor' => ['pattern' => '/^a$|b$/', 'expected' => null];
        yield 'nothing read before the end' => ['pattern' => '/\b\z/', 'expected' => null];
        yield 'an optional start anchor' => ['pattern' => '/(?:^)?a$/', 'expected' => null];
        yield '"\G" stands at the offset' => ['pattern' => '/\Ga$/', 'expected' => null];
        yield 'an accept ends the match early' => ['pattern' => '/^a(*ACCEPT)b$/', 'expected' => null];
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideSubjectEngineRows(): iterable
    {
        yield 'subject: /^[a-z]+$/' => ['pattern' => '/^[a-z]+$/', 'subjects' => ['abc', "abc\n"]];
        yield 'subject: /^[a-z]+$/D' => ['pattern' => '/^[a-z]+$/D', 'subjects' => ['abc']];
        yield 'subject: /^(0|[1-9]\d{0,17})$/D' => ['pattern' => '/^(0|[1-9]\d{0,17})$/D', 'subjects' => ['0', '7', '999999999999999999']];
        yield 'subject: /^(0|[1-9]\d{0,17})$/' => ['pattern' => '/^(0|[1-9]\d{0,17})$/', 'subjects' => ['0', "0\n", '999999999999999999']];
        yield 'subject: /^\d+$/' => ['pattern' => '/^\d+$/', 'subjects' => ['0', "0\n"]];
        yield 'subject: /^(a|b)$/' => ['pattern' => '/^(a|b)$/', 'subjects' => ['a', "b\n"]];
        yield 'subject: /\A(a|b)\Z/D' => ['pattern' => '/\A(a|b)\Z/D', 'subjects' => ['a', "b\n"]];
        yield 'subject: /^(?:a\z|b$)/' => ['pattern' => '/^(?:a\z|b$)/', 'subjects' => ['a', "b\n"]];
        yield 'subject: /(?i)^a$/' => ['pattern' => '/(?i)^a$/', 'subjects' => ['a', "A\n"]];
        yield 'subject: /^(?i)[a-z]+$/' => ['pattern' => '/^(?i)[a-z]+$/', 'subjects' => ['AB', "ab\n"]];
        yield 'subject: /(*CRLF)^a$/' => ['pattern' => '/(*CRLF)^a$/', 'subjects' => ['a', "a\r\n"]];
        yield 'subject: /(*ANYCRLF)^a$/' => ['pattern' => '/(*ANYCRLF)^a$/', 'subjects' => ['a', "a\r", "a\n", "a\r\n"]];
        yield 'subject: /(*ANY)^a$/' => ['pattern' => '/(*ANY)^a$/', 'subjects' => ["a\x0b", "a\x0c", "a\x85", "a\r\n"]];
        yield 'subject: /(*ANY)^a$/u' => ['pattern' => '/(*ANY)^a$/u', 'subjects' => ["a\u{85}", "a\u{2028}", "a\u{2029}"]];
        yield 'subject: /(*NUL)^a$/' => ['pattern' => '/(*NUL)^a$/', 'subjects' => ['a', "a\0"]];
        yield 'subject: /(*CR)^a$/' => ['pattern' => '/(*CR)^a$/', 'subjects' => ['a', "a\r"]];
        yield 'subject: /^a\Kb$/D' => ['pattern' => '/^a\Kb$/D', 'subjects' => ['ab']];
        yield 'subject: /^a$(?=\n)/' => ['pattern' => '/^a$(?=\n)/', 'subjects' => ["a\n"]];
        yield 'subject: /^(?:(\d+)|([a-z]+))$/D' => ['pattern' => '/^(?:(\d+)|([a-z]+))$/D', 'subjects' => ['0', 'ab']];
        yield 'subject: /^(?:[a-z]+|\d+)$/' => ['pattern' => '/^(?:[a-z]+|\d+)$/', 'subjects' => ['ab', "12\n"]];
    }

    /**
     * Each subject reaches the value that makes a fact false, or proves a
     * true one: the replay fails on a wrong true. The facts list, for each
     * group number, 0 included, the facts the analysis proves.
     *
     * @return iterable<string, array{pattern: string, subjects: list<string>, facts: array<int, list<'nonFalsy'|'digitsOnly'|'decimalInt'|'lowercase'|'uppercase'>>}>
     */
    public static function provideFactEngineRows(): iterable
    {
        yield 'facts: /(\d+)/' => ['pattern' => '/(\d+)/', 'subjects' => ['0', '123'], 'facts' => [0 => ['digitsOnly', 'lowercase', 'uppercase'], 1 => ['digitsOnly', 'lowercase', 'uppercase']]];
        yield 'facts: /(\d+)/u' => ['pattern' => '/(\d+)/u', 'subjects' => ['0', "\u{663}"], 'facts' => [0 => ['lowercase', 'uppercase'], 1 => ['lowercase', 'uppercase']]];
        yield 'facts: /(*UTF)(*UCP)(\d+)/' => ['pattern' => '/(*UTF)(*UCP)(\d+)/', 'subjects' => ["\u{663}"], 'facts' => [0 => ['lowercase', 'uppercase'], 1 => ['lowercase', 'uppercase']]];
        yield 'facts: /(*UCP)(\d+)/' => ['pattern' => '/(*UCP)(\d+)/', 'subjects' => ['0', '9'], 'facts' => [0 => ['lowercase', 'uppercase'], 1 => ['lowercase', 'uppercase']]];
        yield 'facts: /(*UTF)(\d+)/' => ['pattern' => '/(*UTF)(\d+)/', 'subjects' => ['12'], 'facts' => [0 => ['digitsOnly', 'lowercase', 'uppercase'], 1 => ['digitsOnly', 'lowercase', 'uppercase']]];
        yield 'facts: /(\d+)/i' => ['pattern' => '/(\d+)/i', 'subjects' => ['12'], 'facts' => [0 => ['digitsOnly', 'lowercase', 'uppercase'], 1 => ['digitsOnly', 'lowercase', 'uppercase']]];
        yield 'facts: /([0-9]+)/u' => ['pattern' => '/([0-9]+)/u', 'subjects' => ['09'], 'facts' => [0 => ['digitsOnly', 'lowercase', 'uppercase'], 1 => ['digitsOnly', 'lowercase', 'uppercase']]];
        yield 'facts: /([[:digit:]]+)/u' => ['pattern' => '/([[:digit:]]+)/u', 'subjects' => ["\u{663}"], 'facts' => [0 => ['lowercase', 'uppercase'], 1 => ['lowercase', 'uppercase']]];
        yield 'facts: /(\p{Nd}+)/u' => ['pattern' => '/(\p{Nd}+)/u', 'subjects' => ["\u{663}"], 'facts' => [0 => ['lowercase', 'uppercase'], 1 => ['lowercase', 'uppercase']]];
        yield 'facts: /([\x30-\x39]+)/' => ['pattern' => '/([\x30-\x39]+)/', 'subjects' => ['09'], 'facts' => [0 => ['digitsOnly', 'lowercase', 'uppercase'], 1 => ['digitsOnly', 'lowercase', 'uppercase']]];
        yield 'facts: /(\d\d)/' => ['pattern' => '/(\d\d)/', 'subjects' => ['00'], 'facts' => [0 => ['nonFalsy', 'digitsOnly', 'lowercase', 'uppercase'], 1 => ['nonFalsy', 'digitsOnly', 'lowercase', 'uppercase']]];
        yield 'facts: /(\d)/' => ['pattern' => '/(\d)/', 'subjects' => ['0'], 'facts' => [0 => ['digitsOnly', 'lowercase', 'uppercase'], 1 => ['digitsOnly', 'lowercase', 'uppercase']]];
        yield 'facts: /(0)/' => ['pattern' => '/(0)/', 'subjects' => ['0'], 'facts' => [0 => ['digitsOnly', 'decimalInt', 'lowercase', 'uppercase'], 1 => ['digitsOnly', 'decimalInt', 'lowercase', 'uppercase']]];
        yield 'facts: /(a|0b)/' => ['pattern' => '/(a|0b)/', 'subjects' => ['a', '0b'], 'facts' => [0 => ['nonFalsy', 'lowercase'], 1 => ['nonFalsy', 'lowercase']]];
        yield 'facts: /(a|0)/' => ['pattern' => '/(a|0)/', 'subjects' => ['a', '0'], 'facts' => [0 => ['lowercase'], 1 => ['lowercase']]];
        yield 'facts: /(a?)/' => ['pattern' => '/(a?)/', 'subjects' => ['', 'a'], 'facts' => [0 => ['lowercase'], 1 => ['lowercase']]];
        yield 'facts: /(a?)(\1)/' => ['pattern' => '/(a?)(\1)/', 'subjects' => ['', 'aa'], 'facts' => [0 => [], 1 => ['lowercase'], 2 => []]];
        yield 'facts: /(0(*ACCEPT)1)/' => ['pattern' => '/(0(*ACCEPT)1)/', 'subjects' => ['01'], 'facts' => [0 => [], 1 => []]];
        yield 'facts: /([1-9])/' => ['pattern' => '/([1-9])/', 'subjects' => ['1'], 'facts' => [0 => ['nonFalsy', 'digitsOnly', 'decimalInt', 'lowercase', 'uppercase'], 1 => ['nonFalsy', 'digitsOnly', 'decimalInt', 'lowercase', 'uppercase']]];
        yield 'facts: /(\d+)?/' => ['pattern' => '/(\d+)?/', 'subjects' => ['', '0'], 'facts' => [0 => ['digitsOnly', 'lowercase', 'uppercase'], 1 => ['digitsOnly', 'lowercase', 'uppercase']]];
        yield 'facts: /(ab)?/' => ['pattern' => '/(ab)?/', 'subjects' => ['', 'ab'], 'facts' => [0 => ['lowercase'], 1 => ['nonFalsy', 'lowercase']]];
        yield 'facts: /(1a)/' => ['pattern' => '/(1a)/', 'subjects' => ['1a'], 'facts' => [0 => ['nonFalsy', 'lowercase'], 1 => ['nonFalsy', 'lowercase']]];
        yield 'facts: /(\d*)/' => ['pattern' => '/(\d*)/', 'subjects' => ['', '7'], 'facts' => [0 => ['digitsOnly', 'lowercase', 'uppercase'], 1 => ['digitsOnly', 'lowercase', 'uppercase']]];
        yield 'facts: /(.)/' => ['pattern' => '/(.)/', 'subjects' => ['0'], 'facts' => [0 => [], 1 => []]];
        yield 'facts: /(é)/u' => ['pattern' => '/(é)/u', 'subjects' => ['é'], 'facts' => [0 => ['nonFalsy', 'lowercase', 'uppercase'], 1 => ['nonFalsy', 'lowercase', 'uppercase']]];
        yield 'facts: /(ab)/i' => ['pattern' => '/(ab)/i', 'subjects' => ['AB'], 'facts' => [0 => ['nonFalsy'], 1 => ['nonFalsy']]];
        yield 'facts: /(\d+)\.(\d+)/' => ['pattern' => '/(\d+)\.(\d+)/', 'subjects' => ['1.5'], 'facts' => [0 => ['nonFalsy', 'lowercase', 'uppercase'], 1 => ['digitsOnly', 'lowercase', 'uppercase'], 2 => ['digitsOnly', 'lowercase', 'uppercase']]];
        yield 'facts: /(\d\d+)/' => ['pattern' => '/(\d\d+)/', 'subjects' => ['00'], 'facts' => [0 => ['nonFalsy', 'digitsOnly', 'lowercase', 'uppercase'], 1 => ['nonFalsy', 'digitsOnly', 'lowercase', 'uppercase']]];
        yield 'facts: /([a-z]+)/' => ['pattern' => '/([a-z]+)/', 'subjects' => ['abc'], 'facts' => [0 => ['nonFalsy', 'lowercase'], 1 => ['nonFalsy', 'lowercase']]];
        yield 'facts: /([A-Z]+)/' => ['pattern' => '/([A-Z]+)/', 'subjects' => ['ABC'], 'facts' => [0 => ['nonFalsy', 'uppercase'], 1 => ['nonFalsy', 'uppercase']]];
        yield 'facts: /([a-z]+)/i' => ['pattern' => '/([a-z]+)/i', 'subjects' => ['AB', 'ab'], 'facts' => [0 => ['nonFalsy'], 1 => ['nonFalsy']]];
        yield 'facts: /(\W+)/i' => ['pattern' => '/(\W+)/i', 'subjects' => ['-', '.,'], 'facts' => [0 => ['nonFalsy', 'lowercase', 'uppercase'], 1 => ['nonFalsy', 'lowercase', 'uppercase']]];
        yield 'facts: /(\x{212A})/iu' => ['pattern' => '/(\x{212A})/iu', 'subjects' => ['K', 'k', "\u{212A}"], 'facts' => [0 => ['nonFalsy'], 1 => ['nonFalsy']]];
        yield 'facts: /(é)/iu' => ['pattern' => '/(é)/iu', 'subjects' => ['É'], 'facts' => [0 => ['nonFalsy'], 1 => ['nonFalsy']]];
        yield 'facts: /(\xE9)/i' => ['pattern' => '/(\xE9)/i', 'subjects' => ["\xE9"], 'facts' => [0 => ['nonFalsy', 'lowercase', 'uppercase'], 1 => ['nonFalsy', 'lowercase', 'uppercase']]];
        yield 'facts: /(\p{Ll}+)/u' => ['pattern' => '/(\p{Ll}+)/u', 'subjects' => ['a', 'é'], 'facts' => [0 => ['lowercase'], 1 => ['lowercase']]];
        yield 'facts: /([[:lower:]]+)/i' => ['pattern' => '/([[:lower:]]+)/i', 'subjects' => ['A'], 'facts' => [0 => ['nonFalsy'], 1 => ['nonFalsy']]];
        yield 'facts: /(?i)(?<t>[a-z])(?-i)x((?&t))/' => ['pattern' => '/(?i)(?<t>[a-z])(?-i)x((?&t))/', 'subjects' => ['axB'], 'facts' => [0 => ['nonFalsy'], 1 => ['nonFalsy'], 2 => ['nonFalsy']]];
        yield 'facts: /^(0|[1-9]\d{0,17})$/' => ['pattern' => '/^(0|[1-9]\d{0,17})$/', 'subjects' => ['0', '7', '999999999999999999'], 'facts' => [0 => ['digitsOnly', 'decimalInt', 'lowercase', 'uppercase'], 1 => ['digitsOnly', 'decimalInt', 'lowercase', 'uppercase']]];
        yield 'facts: /^(-?[1-9][0-9]{0,8})$/' => ['pattern' => '/^(-?[1-9][0-9]{0,8})$/', 'subjects' => ['-1', '999999999'], 'facts' => [0 => ['nonFalsy', 'decimalInt', 'lowercase', 'uppercase'], 1 => ['nonFalsy', 'decimalInt', 'lowercase', 'uppercase']]];
        yield 'facts: /(-?[1-9]\d?|00)/' => ['pattern' => '/(-?[1-9]\d?|00)/', 'subjects' => ['-5', '00', '42'], 'facts' => [0 => ['nonFalsy', 'lowercase', 'uppercase'], 1 => ['nonFalsy', 'lowercase', 'uppercase']]];
        yield 'facts: /((?:[1-9]\d{0,8}){2})/' => ['pattern' => '/((?:[1-9]\d{0,8}){2})/', 'subjects' => ['1010', '999999999999999999'], 'facts' => [0 => ['nonFalsy', 'digitsOnly', 'decimalInt', 'lowercase', 'uppercase'], 1 => ['nonFalsy', 'digitsOnly', 'decimalInt', 'lowercase', 'uppercase']]];
    }

    /**
     * Written in PHPRegex's own form: every key named, a group's facts as
     * accessory types. A digit run is a numeric-string, which PHPStan already
     * reads as non-empty. CaptureShapePhpStanTypeTest checks that each one is
     * the type PHPStan prints for the pattern, up to type equivalence.
     *
     * @return iterable<string, array{pattern: string, flags: int, expected: string}>
     */
    public static function provideFactShapes(): iterable
    {
        yield 'digits, may be "0"' => ['pattern' => '/(\d+)/', 'flags' => 0, 'expected' => 'array{0: numeric-string, 1: numeric-string}'];
        yield 'digits, may be "0", offsets' => ['pattern' => '/(\d+)/', 'flags' => \PREG_OFFSET_CAPTURE, 'expected' => 'array{0: array{numeric-string, int<0, max>}, 1: array{numeric-string, int<0, max>}}'];
        yield 'digits, at least two' => ['pattern' => '/(\d\d+)/', 'flags' => 0, 'expected' => 'array{0: non-falsy-string&numeric-string, 1: non-falsy-string&numeric-string}'];
        yield 'letters, never "0"' => ['pattern' => '/([a-z]+)/', 'flags' => 0, 'expected' => 'array{0: lowercase-string&non-falsy-string, 1: lowercase-string&non-falsy-string}'];
        yield 'digits, may be empty' => ['pattern' => '/(\d*)/', 'flags' => 0, 'expected' => "array{0: ''|numeric-string, 1: ''|numeric-string}"];
        yield 'optional digits' => ['pattern' => '/(\d+)?/', 'flags' => 0, 'expected' => "array{0: ''|numeric-string, 1?: numeric-string}"];
        yield 'optional digits, as null' => ['pattern' => '/(\d+)?/', 'flags' => \PREG_UNMATCHED_AS_NULL, 'expected' => "array{0: ''|numeric-string, 1: numeric-string|null}"];
        yield 'digits under /u are not proven' => ['pattern' => '/(\d+)/u', 'flags' => 0, 'expected' => 'array{0: non-empty-string, 1: non-empty-string}'];
        // PHPStan's type parser reads an intersection inside a union only in parentheses.
        yield 'optional digits, at least two, as null' => ['pattern' => '/(\d\d+)?/', 'flags' => \PREG_UNMATCHED_AS_NULL, 'expected' => "array{0: ''|numeric-string, 1: (non-falsy-string&numeric-string)|null}"];
        // phpstan/phpstan#15018: lowercase letters are a lowercase-string, the
        // whole match and the group alike; caseless, they are not.
        yield 'lowercase letters, anchored' => ['pattern' => '/^[a-z]+$/', 'flags' => 0, 'expected' => 'array{0: lowercase-string&non-falsy-string}'];
        yield 'lowercase letters in a group' => ['pattern' => '/^([a-z]+)$/', 'flags' => 0, 'expected' => 'array{0: lowercase-string&non-falsy-string, 1: lowercase-string&non-falsy-string}'];
        yield 'letters read caselessly' => ['pattern' => '/([a-z]+)/i', 'flags' => 0, 'expected' => 'array{0: non-falsy-string, 1: non-falsy-string}'];
        yield 'uppercase letters' => ['pattern' => '/([A-Z]+)/', 'flags' => 0, 'expected' => 'array{0: non-falsy-string&uppercase-string, 1: non-falsy-string&uppercase-string}'];
        yield 'lowercase letters, may be empty' => ['pattern' => '/([a-z]*)/', 'flags' => 0, 'expected' => 'array{0: lowercase-string, 1: lowercase-string}'];
        // phpstan/phpstan#14909: zero, or digits without a leading zero, up to
        // eighteen of them, are a decimal-int-string; \d+ may read '007'.
        yield 'a decimal integer' => ['pattern' => '/^(0|[1-9]\d{0,17})$/D', 'flags' => 0, 'expected' => 'array{0: decimal-int-string, 1: decimal-int-string}'];
        yield 'a decimal integer that is never zero, as null' => ['pattern' => '/(-?[1-9]\d{0,17})?/', 'flags' => \PREG_UNMATCHED_AS_NULL, 'expected' => 'array{0: string, 1: (decimal-int-string&non-falsy-string)|null}'];
        yield 'digits hold no letter of either case' => ['pattern' => '/(\d+)/', 'flags' => 0, 'expected' => 'array{0: numeric-string, 1: numeric-string}'];
    }

    /**
     * The union of the cases, one shape per branch in the order of the
     * branches, then the case where no branch group is set. Oracle (PHP
     * 8.4.26, PCRE2 10.49):
     *   '/(a)|(b)/' on 'a' -> ["a","a"], on 'b' -> ["b","","b"]; with UNMATCHED_AS_NULL ["a","a",null], ["b",null,"b"];
     *     with OFFSET_CAPTURE on 'b' -> [["b",0],["",-1],["b",0]]
     *   '/^(?:(\d+)|([a-z]+))$/' on '0' -> ["0","0"], on 'ab' -> ["ab","","ab"]
     *   '/(?:(a)|(b))?/' on '' -> [""], as null ["",null,null]
     *   '/(?:(a)|(b))?c/' on 'c' -> ["c"], on 'bc' -> ["bc","","b"]
     *   '/(a)|b/' on 'b' -> ["b"]; '/(a)|/' on '' -> [""]
     *   '/(?<n>a)|(?<n>b)/J' with UNMATCHED_AS_NULL on 'a' -> {"0":"a","n":"a","1":"a","2":null}
     *
     * @return iterable<string, array{pattern: string, flags: int, expected: string}>
     */
    public static function provideCaseShapes(): iterable
    {
        yield 'two branches' => ['pattern' => '/(a)|(b)/', 'flags' => 0, 'expected' => "array{0: 'a', 1: 'a'}|array{0: 'b', 1: '', 2: 'b'}"];
        yield 'through a capturing group' => ['pattern' => '/Price: (£|(\d)|x)/i', 'flags' => 0, 'expected' => "array{0: non-falsy-string, 1: '£'|numeric-string|non-falsy-string}|array{0: non-falsy-string, 1: '£'|numeric-string|non-falsy-string, 2: numeric-string}"];
        yield 'two branches, as null' => ['pattern' => '/(a)|(b)/', 'flags' => \PREG_UNMATCHED_AS_NULL, 'expected' => "array{0: 'a', 1: 'a', 2: null}|array{0: 'b', 1: null, 2: 'b'}"];
        yield 'two branches, offsets' => ['pattern' => '/(a)|(b)/', 'flags' => \PREG_OFFSET_CAPTURE, 'expected' => "array{0: array{'a', int<0, max>}, 1: array{'a', int<0, max>}}|array{0: array{'b', int<0, max>}, 1: array{'', int<-1, max>}, 2: array{'b', int<0, max>}}"];
        yield 'anchored, in a non-capturing group' => ['pattern' => '/^(?:(\d+)|([a-z]+))$/', 'flags' => 0, 'expected' => "array{0: numeric-string, 1: numeric-string}|array{0: lowercase-string&non-falsy-string, 1: '', 2: lowercase-string&non-falsy-string}"];
        yield 'anchored, in a non-capturing group, as null' => ['pattern' => '/^(?:(\d+)|([a-z]+))$/', 'flags' => \PREG_UNMATCHED_AS_NULL, 'expected' => 'array{0: numeric-string, 1: numeric-string, 2: null}|array{0: lowercase-string&non-falsy-string, 1: null, 2: lowercase-string&non-falsy-string}'];
        yield 'after a literal' => ['pattern' => '/x(?:(a)|(b))/', 'flags' => 0, 'expected' => "array{0: 'xa', 1: 'a'}|array{0: 'xb', 1: '', 2: 'b'}"];
        yield 'optional: a case where no branch group is set' => ['pattern' => '/(?:(a)|(b))?/', 'flags' => 0, 'expected' => "array{0: 'a', 1: 'a'}|array{0: 'b', 1: '', 2: 'b'}|array{0: ''}"];
        yield 'optional: a case where no branch group is set, as null' => ['pattern' => '/(?:(a)|(b))?/', 'flags' => \PREG_UNMATCHED_AS_NULL, 'expected' => "array{0: 'a', 1: 'a', 2: null}|array{0: 'b', 1: null, 2: 'b'}|array{0: '', 1: null, 2: null}"];
        yield 'optional, then a literal' => ['pattern' => '/(?:(a)|(b))?c/', 'flags' => 0, 'expected' => "array{0: 'ac', 1: 'a'}|array{0: 'bc', 1: '', 2: 'b'}|array{0: 'c'}"];
        yield 'optional, inside a non-capturing group' => ['pattern' => '/(?:(?:(a)|(b))?)/', 'flags' => 0, 'expected' => "array{0: 'a', 1: 'a'}|array{0: 'b', 1: '', 2: 'b'}|array{0: ''}"];
        yield 'a branch with no group' => ['pattern' => '/(a)|b/', 'flags' => 0, 'expected' => "array{0: 'a', 1: 'a'}|array{0: 'b'}"];
        yield 'an empty branch' => ['pattern' => '/(a)|/', 'flags' => 0, 'expected' => "array{0: 'a', 1: 'a'}|array{0: ''}"];
        yield 'a name shared under /J' => ['pattern' => '/(?<n>a)|(?<n>b)/J', 'flags' => 0, 'expected' => "array{0: 'a', n: 'a', 1: 'a'}|array{0: 'b', n: 'b', 1: '', 2: 'b'}"];
        yield 'a name shared under /J, as null' => ['pattern' => '/(?<n>a)|(?<n>b)/J', 'flags' => \PREG_UNMATCHED_AS_NULL, 'expected' => "array{0: 'a', n: 'a', 1: 'a', 2: null}|array{0: 'b', n: 'b', 1: null, 2: 'b'}"];
        // preg_match('/^([A-Z]{1,3})([0-9]{1,7})(:([A-Z]{1,3})([0-9]{1,7}))?$/', 'A1') -> ["A1","A","1"]; on 'A1:B2' -> ["A1:B2","A","1",":B2","B","2"];
        // with UNMATCHED_AS_NULL on 'A1' -> ["A1","A","1",null,null,null] (PHP 8.4.27, PCRE2 10.49)
        yield 'an optional group holding groups: they run together' => ['pattern' => '/^([A-Z]{1,3})([0-9]{1,7})(:([A-Z]{1,3})([0-9]{1,7}))?$/', 'flags' => 0, 'expected' => 'array{0: non-falsy-string&uppercase-string, 1: non-falsy-string&uppercase-string, 2: numeric-string, 3: non-falsy-string&uppercase-string, 4: non-falsy-string&uppercase-string, 5: numeric-string}|array{0: non-falsy-string&uppercase-string, 1: non-falsy-string&uppercase-string, 2: numeric-string}'];
        yield 'an optional group holding groups, as null' => ['pattern' => '/^([A-Z]{1,3})([0-9]{1,7})(:([A-Z]{1,3})([0-9]{1,7}))?$/', 'flags' => \PREG_UNMATCHED_AS_NULL, 'expected' => 'array{0: non-falsy-string&uppercase-string, 1: non-falsy-string&uppercase-string, 2: numeric-string, 3: non-falsy-string&uppercase-string, 4: non-falsy-string&uppercase-string, 5: numeric-string}|array{0: non-falsy-string&uppercase-string, 1: non-falsy-string&uppercase-string, 2: numeric-string, 3: null, 4: null, 5: null}'];
        // preg_match('/(?:(a)(b))?c/', 'c') -> ["c"]; on 'abc' -> ["abc","a","b"]
        yield 'an optional non-capturing group holding groups' => ['pattern' => '/(?:(a)(b))?c/', 'flags' => 0, 'expected' => "array{0: 'abc', 1: 'a', 2: 'b'}|array{0: 'c'}"];
        // preg_match('/(?:(a)(b))?(c)/', 'c') -> ["c","","","c"]; on 'abc' -> ["abc","a","b","c"]
        yield 'an optional group holding groups, then a group' => ['pattern' => '/(?:(a)(b))?(c)/', 'flags' => 0, 'expected' => "array{0: 'abc', 1: 'a', 2: 'b', 3: 'c'}|array{0: 'c', 1: '', 2: '', 3: 'c'}"];
        // preg_match('/(?(?=(a))a|(c))/', 'a') -> ["a","a"]; on 'c' -> ["c","","c"]: group 1 is written whenever group 2 is set
        yield 'a condition: one case where it holds, one where it fails' => ['pattern' => '/(?(?=(a))a|(c))/', 'flags' => 0, 'expected' => "array{0: 'a', 1: 'a'}|array{0: 'c', 1: '', 2: 'c'}"];
        // preg_match('/(?(?=(a))b|(c))/', 'c') -> ["c","","c"]
        yield 'a condition: its group is written when the later group is set' => ['pattern' => '/(?(?=(a))b|(c))/', 'flags' => 0, 'expected' => "array{0: 'b', 1: 'a'}|array{0: 'c', 1: '', 2: 'c'}"];
        // preg_match('/(?(?!(a))x|a)(b)/', 'ab') -> ["ab","a","b"]; on 'xb' -> ["xb","","b"]: a negative condition that fails keeps its group
        yield 'a negative condition: its group is set when it fails' => ['pattern' => '/(?(?!(a))x|a)(b)/', 'flags' => 0, 'expected' => "array{0: 'xb', 1: '', 2: 'b'}|array{0: 'ab', 1: 'a', 2: 'b'}"];
        // preg_match('/(?(?C1)(?=(a))a|(c))/', 'a') -> ["a","a"]; on 'c' -> ["c","","c"] (a callout leaves the JIT out)
        yield 'a condition after a callout' => ['pattern' => '/(?(?C1)(?=(a))a|(c))/', 'flags' => 0, 'expected' => "array{0: 'a', 1: 'a'}|array{0: 'c', 1: '', 2: 'c'}"];
    }

    /**
     * @return iterable<string, array{pattern: string, count: int}>
     */
    public static function provideSplits(): iterable
    {
        yield 'root alternation' => ['pattern' => '/(a)|(b)/', 'count' => 2];
        yield 'three branches' => ['pattern' => '/(a)|(b)|(c)/', 'count' => 3];
        yield 'anchored, in a non-capturing group' => ['pattern' => '/^(?:(\d+)|([a-z]+))$/', 'count' => 2];
        yield 'after a literal' => ['pattern' => '/x(?:(a)|(b))/', 'count' => 2];
        yield 'optional: one more case' => ['pattern' => '/(?:(a)|(b))?/', 'count' => 3];
        // A capturing group on the way is set whatever the branch: preg_match('/Price: (£|(\d)|x)/i',
        // 'Price: 5') -> ["Price: 5","5","5"] and on 'Price: x' -> ["Price: x","x"] (PHP 8.4.27, PCRE2 10.49).
        yield 'through a capturing group' => ['pattern' => '/Price: (£|(\d)|x)/i', 'count' => 3];
        yield 'through a named group' => ['pattern' => '/(?<v>a|(b))c/', 'count' => 2];
        yield 'inside a capturing group' => ['pattern' => '/((a)|(b))/', 'count' => 2];
        yield 'an optional group holding a branch reset with groups' => ['pattern' => '/(?:(?|(a)|(b))(c))?/', 'count' => 2];
        yield 'an optional group holding groups, inside a capturing group' => ['pattern' => '/(x(?:(a)(b))?)/', 'count' => 2];
        yield 'a branch with no group' => ['pattern' => '/(a)|b/', 'count' => 2];
        yield 'an empty branch' => ['pattern' => '/(a)|/', 'count' => 2];
        yield 'a name shared under /J' => ['pattern' => '/(?<n>a)|(?<n>b)/J', 'count' => 2];
        yield 'an option set in the first branch' => ['pattern' => '/a(?i)b|(c)/', 'count' => 2];
        yield 'a second alternation without groups' => ['pattern' => '/(?:(a)|(b))(?:c|d)/', 'count' => 2];
        yield 'sixteen branches: the limit' => ['pattern' => self::branches(16, ''), 'count' => 16];
        yield 'fifteen optional branches: sixteen cases' => ['pattern' => self::branches(15, '?'), 'count' => 16];
        yield 'optional, inside a non-capturing group' => ['pattern' => '/(?:(?:(a)|(b))?)/', 'count' => 3];
        // preg_match('/(?|a|b)(?:(c)|(d))/', 'bd') -> ["bd","","d"]: a branch reset that captures nothing numbers nothing
        yield 'a branch reset without groups beside the alternation' => ['pattern' => '/(?|a|b)(?:(c)|(d))/', 'count' => 2];
        yield 'an optional capturing group holding groups' => ['pattern' => '/^([A-Z]{1,3})([0-9]{1,7})(:([A-Z]{1,3})([0-9]{1,7}))?$/', 'count' => 2];
        yield 'an optional non-capturing group holding groups' => ['pattern' => '/(?:(a)(b))?c/', 'count' => 2];
        yield 'an optional group holding groups, lazy' => ['pattern' => '/(?:(a)(b))??c/', 'count' => 2];
        yield 'an optional group holding groups, {0,1}' => ['pattern' => '/(?:(a)(b)){0,1}c/', 'count' => 2];
        yield 'an optional group holding groups, inside a non-capturing group' => ['pattern' => '/(?:x(?:(a)(b))?)/', 'count' => 2];
        yield 'an optional group holding a branch reset in a capturing group' => ['pattern' => '/((?|(a)|(b))(c))?/', 'count' => 2];
        yield 'an alternation wins over an optional group' => ['pattern' => '/(?:(a)|(b))(?:(c)(d))?/', 'count' => 2];
        yield 'a condition holding groups' => ['pattern' => '/(?(?=(a))a|(c))/', 'count' => 2];
        yield 'a condition on a group number' => ['pattern' => '/(x)?(?(1)(a)|(b))/', 'count' => 2];
        yield 'a condition whose only group is in the assertion' => ['pattern' => '/(?(?=(a))a|b)/', 'count' => 2];
        yield 'an optional condition: one more case' => ['pattern' => '/(?:(?(?=(a))a|(c)))?/', 'count' => 3];
        yield 'an alternation wins over a condition' => ['pattern' => '/(?(?=(a))a|(c))(?:(d)|(e)|(f))/', 'count' => 3];
        yield 'two alternations leave the split to an optional group' => ['pattern' => '/(?:(a)|(b))(?:(c)|(d))(?:(e)(f))?/', 'count' => 2];
        yield 'an alternation past the limit leaves the split to an optional group' => ['pattern' => '/(?:'.substr(self::branches(17, ''), 1, -1).')(?:(x)(y))?/', 'count' => 2];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideNoSplits(): iterable
    {
        yield 'seventeen branches: past the limit' => ['pattern' => self::branches(17, '')];
        yield 'sixteen optional branches: seventeen cases' => ['pattern' => self::branches(16, '?')];
        yield 'a second alternation with groups' => ['pattern' => '/(?:(a)|(b))(?:(c)|(d))/'];
        yield 'branch reset at the root' => ['pattern' => '/(?|(a)|(b))/'];
        // preg_match('/(?|(a)|(b))(?:(c)|(d))/', 'bd') -> ["bd","b","","d"] (PHP 8.4.26, PCRE2 10.49)
        yield 'a branch reset with groups before the alternation' => ['pattern' => '/(?|(a)|(b))(?:(c)|(d))/'];
        // preg_match('/(?:(c)|(d))(?|(a)|(b))/', 'db') -> ["db","","d","b"]
        yield 'a branch reset with groups after the alternation' => ['pattern' => '/(?:(c)|(d))(?|(a)|(b))/'];
        yield 'repeated exactly once' => ['pattern' => '/(?:(a)|(b)){1}/'];
        // preg_match('/(x)(?:(?:a)|b)/', 'xb') -> ["xb","x"]: no branch captures
        yield 'an alternation whose only group does not capture' => ['pattern' => '/(x)(?:(?:a)|b)/'];
        yield 'repeated at least once' => ['pattern' => '/(?:(a)|(b))+/'];
        yield 'repeated any number of times' => ['pattern' => '/(?:(a)|(b))*/'];
        yield 'repeated twice' => ['pattern' => '/(?:(a)|(b)){2}/'];
        yield 'inside a lookahead' => ['pattern' => '/(?=(a)|(b))\w/'];
        yield 'an alternation without groups' => ['pattern' => '/(x)(?:a|b)/'];
        yield 'a root alternation without groups' => ['pattern' => '/a|b/'];
        yield 'no alternation' => ['pattern' => '/(a)/'];
        yield 'an optional group holding one group' => ['pattern' => '/(?:x(a))?/'];
        yield 'an optional capturing group alone' => ['pattern' => '/(a)?(b)/'];
        yield 'two optional groups holding groups' => ['pattern' => '/(?:(a)(b))?(?:(c)(d))?/'];
        yield 'an optional group holding groups, repeated' => ['pattern' => '/(?:(a)(b)){0,2}/'];
        yield 'a condition without groups' => ['pattern' => '/(a)(?(1)b|c)/'];
        yield 'a condition under a repeat' => ['pattern' => '/(?:(?(?=(a))a|(c)))+/'];
    }

    /**
     * @return iterable<string, array{pattern: string, expected: list<array<int<1, max>, Participation>>, merged: array<int<1, max>, Participation>}>
     */
    public static function provideCaseParticipation(): iterable
    {
        $always = Participation::Always;
        $maybe = Participation::MayBeUnset;
        $never = Participation::Never;

        yield 'two branches' => ['pattern' => '/(a)|(b)/', 'expected' => [[1 => $always, 2 => $never], [1 => $never, 2 => $always]], 'merged' => [1 => $maybe, 2 => $maybe]];
        yield 'optional: no branch group set' => ['pattern' => '/(?:(a)|(b))?/', 'expected' => [[1 => $always, 2 => $never], [1 => $never, 2 => $always], [1 => $never, 2 => $never]], 'merged' => [1 => $maybe, 2 => $maybe]];
        yield 'a group outside the alternation' => ['pattern' => '/(x)(?:(a)|(b))/', 'expected' => [[1 => $always, 2 => $always, 3 => $never], [1 => $always, 2 => $never, 3 => $always]], 'merged' => [1 => $always, 2 => $maybe, 3 => $maybe]];
        yield 'an optional group inside a branch' => ['pattern' => '/(a)(b)?|(c)/', 'expected' => [[1 => $always, 2 => $maybe, 3 => $never], [1 => $never, 2 => $never, 3 => $always]], 'merged' => [1 => $maybe, 2 => $maybe, 3 => $maybe]];
        // preg_match('/(a)(b)|(c)/', 'ab') -> ["ab","a","b"]; on 'c' -> ["c","","","c"]
        yield 'two groups in one branch' => ['pattern' => '/(a)(b)|(c)/', 'expected' => [[1 => $always, 2 => $always, 3 => $never], [1 => $never, 2 => $never, 3 => $always]], 'merged' => [1 => $maybe, 2 => $maybe, 3 => $maybe]];
        // preg_match('/^([A-Z]{1,3})([0-9]{1,7})(:([A-Z]{1,3})([0-9]{1,7}))?$/', 'A1:B2') -> ["A1:B2","A","1",":B2","B","2"]; on 'A1' -> ["A1","A","1"]
        yield 'an optional group: its groups run together' => ['pattern' => '/^([A-Z]{1,3})([0-9]{1,7})(:([A-Z]{1,3})([0-9]{1,7}))?$/', 'expected' => [[1 => $always, 2 => $always, 3 => $always, 4 => $always, 5 => $always], [1 => $always, 2 => $always, 3 => $never, 4 => $never, 5 => $never]], 'merged' => [1 => $always, 2 => $always, 3 => $maybe, 4 => $maybe, 5 => $maybe]];
        // preg_match('/(?(?=(a))a|(c))/', 'a') -> ["a","a"]; on 'c' -> ["c","","c"]
        yield 'a condition: its assertion group is set where it holds' => ['pattern' => '/(?(?=(a))a|(c))/', 'expected' => [[1 => $always, 2 => $never], [1 => $never, 2 => $always]], 'merged' => [1 => $maybe, 2 => $maybe]];
        // preg_match('/(?(?!(a))x|a)(b)/', 'ab') -> ["ab","a","b"]; on 'xb' -> ["xb","","b"]
        yield 'a negative condition: its assertion group is set where it fails' => ['pattern' => '/(?(?!(a))x|a)(b)/', 'expected' => [[1 => $never, 2 => $always], [1 => $always, 2 => $always]], 'merged' => [1 => $maybe, 2 => $always]];
    }

    /**
     * Each subject takes a different branch.
     *
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideCaseEngineRows(): iterable
    {
        yield '/Price: (£|(\d)|x)/i' => ['pattern' => '/Price: (£|(\d)|x)/i', 'subjects' => ['Price: £', 'Price: 5', 'Price: x', 'PRICE: X']];
        yield '/(?<v>a|(b))c/' => ['pattern' => '/(?<v>a|(b))c/', 'subjects' => ['ac', 'bc']];
        yield '/((a)|(b))/' => ['pattern' => '/((a)|(b))/', 'subjects' => ['a', 'b']];
        yield '/(?:(?|(a)|(b))(c))?/' => ['pattern' => '/(?:(?|(a)|(b))(c))?/', 'subjects' => ['', 'ac', 'bc']];
        yield '/(x(?:(a)(b))?)/' => ['pattern' => '/(x(?:(a)(b))?)/', 'subjects' => ['x', 'xab']];
        yield 'cases: /(a)|(b)/' => ['pattern' => '/(a)|(b)/', 'subjects' => ['a', 'b']];
        yield 'cases: /(a)|(b)|(c)/' => ['pattern' => '/(a)|(b)|(c)/', 'subjects' => ['a', 'b', 'c']];
        yield 'cases: /^(?:(\d+)|([a-z]+))$/' => ['pattern' => '/^(?:(\d+)|([a-z]+))$/', 'subjects' => ['0', '42', 'ab']];
        yield 'cases: /x(?:(a)|(b))/' => ['pattern' => '/x(?:(a)|(b))/', 'subjects' => ['xa', 'xb']];
        yield 'cases: /(?:(a)|(b))?/' => ['pattern' => '/(?:(a)|(b))?/', 'subjects' => ['', 'a', 'b']];
        yield 'cases: /(?:(a)|(b))?c/' => ['pattern' => '/(?:(a)|(b))?c/', 'subjects' => ['c', 'ac', 'bc']];
        yield 'cases: /(a)|b/' => ['pattern' => '/(a)|b/', 'subjects' => ['a', 'b']];
        yield 'cases: /(a)|/' => ['pattern' => '/(a)|/', 'subjects' => ['', 'a']];
        yield 'cases: /(?<n>a)|(?<n>b)/J' => ['pattern' => '/(?<n>a)|(?<n>b)/J', 'subjects' => ['a', 'b']];
        yield 'cases: /a(?i)b|(c)/' => ['pattern' => '/a(?i)b|(c)/', 'subjects' => ['ab', 'aB', 'c', 'C']];
        yield 'cases: /(?:(a)|(b))(?:c|d)/' => ['pattern' => '/(?:(a)|(b))(?:c|d)/', 'subjects' => ['ac', 'bd']];
        yield 'cases: /(x)(?:(a)|(b))/' => ['pattern' => '/(x)(?:(a)|(b))/', 'subjects' => ['xa', 'xb']];
        yield 'cases: /(a)(b)?|(c)/' => ['pattern' => '/(a)(b)?|(c)/', 'subjects' => ['a', 'ab', 'c']];
        yield 'cases: sixteen branches' => ['pattern' => self::branches(16, ''), 'subjects' => range('a', 'p')];
        yield 'cases: fifteen optional branches' => ['pattern' => self::branches(15, '?'), 'subjects' => ['', 'a', 'o']];
        yield 'cases: /(a)(b)|(c)/' => ['pattern' => '/(a)(b)|(c)/', 'subjects' => ['ab', 'c']];
        yield 'cases: /(?|a|b)(?:(c)|(d))/' => ['pattern' => '/(?|a|b)(?:(c)|(d))/', 'subjects' => ['ac', 'bd']];
        yield 'cases: /(?:(\d\d)|(a))/' => ['pattern' => '/(?:(\d\d)|(a))/', 'subjects' => ['00', 'a']];
        // The call runs the \K of the branch the first case leaves out: on 'ab' -> ["","a"].
        yield 'cases: /(?:(a)|(b\K))(?2)/' => ['pattern' => '/(?:(a)|(b\K))(?2)/', 'subjects' => ['ab', 'bb']];
        yield 'cases: /(?:(a)|(b(*MARK:m)))(?2)/' => ['pattern' => '/(?:(a)|(b(*MARK:m)))(?2)/', 'subjects' => ['ab', 'bb']];
        yield 'cases: /^([A-Z]{1,3})([0-9]{1,7})(:([A-Z]{1,3})([0-9]{1,7}))?$/' => ['pattern' => '/^([A-Z]{1,3})([0-9]{1,7})(:([A-Z]{1,3})([0-9]{1,7}))?$/', 'subjects' => ['A1', 'A1:B2', 'ZZZ9999999:A1']];
        yield 'cases: /(?:(a)(b))?c/' => ['pattern' => '/(?:(a)(b))?c/', 'subjects' => ['c', 'abc']];
        yield 'cases: /(?:(a)(b))?(c)/' => ['pattern' => '/(?:(a)(b))?(c)/', 'subjects' => ['c', 'abc']];
        yield 'cases: /(?:x(?:(a)(b))?)/' => ['pattern' => '/(?:x(?:(a)(b))?)/', 'subjects' => ['x', 'xab']];
        yield 'cases: /((?|(a)|(b))(c))?/' => ['pattern' => '/((?|(a)|(b))(c))?/', 'subjects' => ['', 'ac', 'bc']];
        yield 'cases: /(?(?=(a))a|(c))/' => ['pattern' => '/(?(?=(a))a|(c))/', 'subjects' => ['a', 'c']];
        yield 'cases: /(?(?=(a))b|(c))/' => ['pattern' => '/(?(?=(a))b|(c))/', 'subjects' => ['c']];
        yield 'cases: /(?(?!(a))x|a)(b)/' => ['pattern' => '/(?(?!(a))x|a)(b)/', 'subjects' => ['ab', 'xb']];
        yield 'cases: /(?(?<!(a))x|b)/' => ['pattern' => '/(?(?<!(a))x|b)/', 'subjects' => ['ab', 'x']];
        yield 'cases: /(x)?(?(1)(a)|(b))/' => ['pattern' => '/(x)?(?(1)(a)|(b))/', 'subjects' => ['xa', 'b']];
        yield 'cases: /(?:(?(?=(a))a|(c)))?/' => ['pattern' => '/(?:(?(?=(a))a|(c)))?/', 'subjects' => ['', 'a', 'c']];
        yield 'cases: /(?(?=(a))a|(c))(?:(d)|(e)|(f))/' => ['pattern' => '/(?(?=(a))a|(c))(?:(d)|(e)|(f))/', 'subjects' => ['ad', 'ce', 'cf']];
        yield 'cases: /(?:(a)|(b))(?:(c)|(d))(?:(e)(f))?/' => ['pattern' => '/(?:(a)|(b))(?:(c)|(d))(?:(e)(f))?/', 'subjects' => ['ac', 'bdef']];
        // A skipped group's mark and \K are backtracked: preg_match('/(?:(a)(*MARK:m)(b))?./', 'ac') -> ["a"], and so with \K (PHP 8.4.27, PCRE2 10.49).
        yield 'cases: /(?J)(?<n>a)?(?:(?<n>b)(c))?/' => ['pattern' => '/(?J)(?<n>a)?(?:(?<n>b)(c))?/', 'subjects' => ['', 'a', 'bc', 'abc']];
        yield 'cases: /(?:(a)(*MARK:m)(b))?./' => ['pattern' => '/(?:(a)(*MARK:m)(b))?./', 'subjects' => ['ac', 'abc']];
        yield 'cases: /(?:(a)\\K(b))?./' => ['pattern' => '/(?:(a)\\K(b))?./', 'subjects' => ['ac', 'abc']];
    }

    /**
     * Value types: one per key, three per key written as an offset pair (the
     * pair and its two members), one for the MARK key, the same count
     * TypeCombinator::countConstantArrayValueTypes() gives for each shape.
     * Each row keeps under 63 distinct keys: past them PHPStan generalises
     * any union of array shapes, whatever its count.
     *
     * @return iterable<string, array{pattern: string, flags: int, cases: int, distinct: int, union: bool}>
     */
    public static function provideBudgetEdges(): iterable
    {
        // 18 + 14 x 17: the last branch writes the shape of the second again.
        yield 'a shape two cases share counts once: 256 value types' => ['pattern' => '/'.str_repeat('(x)', 16).'(?:(a)|b|c|d|e|f|g|h|i|j|k|l|m|n|o|b)/', 'flags' => 0, 'cases' => 16, 'distinct' => 15, 'union' => true];
        // 33 + 7 x 32.
        yield 'a shape two cases share counts once: 257 value types' => ['pattern' => '/'.str_repeat('(x)', 31).'(?:(a)|b|c|d|e|f|g|h|b)/', 'flags' => 0, 'cases' => 9, 'distinct' => 8, 'union' => false];
        // 18 + 14 x 17, the mark key one in each.
        yield 'a mark counts one value type: 256' => ['pattern' => '/(*MARK:m)'.str_repeat('(x)', 15).'(?:(a)|b|c|d|e|f|g|h|i|j|k|l|m|n|o)/', 'flags' => 0, 'cases' => 15, 'distinct' => 15, 'union' => true];
        // 17 + 15 x 16.
        yield 'a mark counts one value type: 257' => ['pattern' => '/(*MARK:m)'.str_repeat('(x)', 14).'(?:(a)|b|c|d|e|f|g|h|i|j|k|l|m|n|o|p)/', 'flags' => 0, 'cases' => 16, 'distinct' => 16, 'union' => false];
        // 3 x (42 + 43).
        yield 'an offset pair counts three value types: 255' => ['pattern' => '/'.str_repeat('(x)', 40).'(?:(a)|(b))/', 'flags' => \PREG_OFFSET_CAPTURE, 'cases' => 2, 'distinct' => 2, 'union' => true];
        // 3 x (43 + 44).
        yield 'an offset pair counts three value types: 261' => ['pattern' => '/'.str_repeat('(x)', 41).'(?:(a)|(b))/', 'flags' => \PREG_OFFSET_CAPTURE, 'cases' => 2, 'distinct' => 2, 'union' => false];
    }

    /**
     * Read from the engine (PHP 8.4.26, PCRE2 10.49):
     *   preg_match_all('/(a)(b)?(c)?/', 'a ab', $m)                           -> [["a","ab"],["a","a"],["","b"],["",""]]
     *   … PREG_PATTERN_ORDER | PREG_UNMATCHED_AS_NULL                         -> [["a","ab"],["a","a"],[null,"b"],[null,null]]
     *   preg_match_all('/(a)(b)?(c)?/', 'a', $m, PREG_OFFSET_CAPTURE)          -> [[["a",0]],[["a",0]],[["",-1]],[["",-1]]]
     *   … PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL                        -> [[["a",0]],[["a",0]],[[null,-1]],[[null,-1]]]
     *   preg_match_all('/(a)(b)?(c)?/', 'a ab', $m, PREG_SET_ORDER)           -> [["a","a"],["ab","a","b"]]
     *   preg_match_all('/(?<x>a)(b)?/', 'a', $m)                              -> {"0":["a"],"x":["a"],"1":["a"],"2":[""]}
     *   preg_match_all('/(?<x>a)(b)?/', 'a', $m, PREG_SET_ORDER)              -> [{"0":"a","x":"a","1":"a"}]
     *   preg_match_all('/(?!(b))a/', 'aa', $m)                                -> [["a","a"],["",""]]; PREG_SET_ORDER -> [["a"],["a"]]
     *   preg_match_all('/(?(DEFINE)(?<d>x))(a)(?&d)/', 'ax', $m)              -> {"0":["ax"],"d":[""],"1":[""],"2":["a"]}
     *   preg_match_all('/(?J)(?<n>a)(?<n>z)?(c)/', 'ac azc', $m)              -> {"0":["ac","azc"],"n":["","z"],"1":["a","a"],"2":["","z"],"3":["c","c"]}
     *     (the list of group 2, the last group named n, where preg_match() on 'ac' gives n => 'a');
     *     PREG_SET_ORDER -> [{"0":"ac","n":"a","1":"a","2":"","3":"c"},{"0":"azc","n":"z","1":"a","2":"z","3":"c"}]
     *   preg_match_all('/(?J)(?<n>a)(?<n>z){0}/', 'aa', $m)                   -> {"0":["a","a"],"n":["",""],"1":["a","a"],"2":["",""]}
     *   preg_match_all('/(?J)(?<n>a)|(?<n>b)/', 'ab', $m)                     -> {"0":["a","b"],"n":["","b"],"1":["a",""],"2":["","b"]}
     *   preg_match_all('/(?|(a)|(b)(c))(d)/', 'ad bcd', $m)                   -> [["ad","bcd"],["a","b"],["","c"],["d","d"]]
     *   preg_match_all('/(?|(x)|(?<a>y))/', 'xy', $m)                         -> {"0":["x","y"],"a":["x","y"],"1":["x","y"]}
     *   preg_match_all('/(*MARK:m)(a)|(b)/', 'ab', $m)                        -> {"0":["a","b"],"1":["a",""],"2":["","b"],"MARK":["m"]}
     *   preg_match_all('/(*MARK:m)(a)|(b)/', 'ba', $m)                        -> {…,"MARK":{"1":"m"}}: keyed by match index
     *   preg_match_all('/(*MARK:m)(a)|(b)/', 'ab', $m, PREG_OFFSET_CAPTURE)   -> {…,"MARK":["m"]}: a mark stays a string
     *   preg_match_all('/(*MARK:m)(a)|(b)/', 'ab', $m, PREG_SET_ORDER)        -> [{"0":"a","1":"a","MARK":"m"},["b","","b"]]
     *   preg_match_all('/(*MARK:m)a|(*MARK:n)b/', 'ab', $m)                   -> {"0":["a","b"],"MARK":["m","n"]}
     *   preg_match_all('/(?<MARK>a)/', 'aa', $m)                              -> {"0":["a","a"],"MARK":["a","a"],"1":["a","a"]}
     *   preg_match_all('/(a)|(b)/', 'ab', $m)                                 -> [["a","b"],["a",""],["","b"]]; PREG_SET_ORDER -> [["a","a"],["b","","b"]]
     *   preg_match_all('/(?:(a)|(b))?c/', 'c bc', $m)                         -> [["c","bc"],["",""],["","b"]]; PREG_SET_ORDER -> [["c"],["bc","","b"]]
     *   preg_match_all('/(\d+)/', '1 22', $m)                                 -> [["1","22"],["1","22"]]
     *   preg_match_all('/(a)(?<x>b)/n', 'abab', $m)                           -> {"0":["ab","ab"],"x":["b","b"],"1":["b","b"]}
     *
     * @return iterable<string, array{pattern: string, flags: int, expected: string}>
     */
    public static function provideMatchAllShapes(): iterable
    {
        // Orders and flags: every key, an unset group read as '' (null, or a pair at -1), no trailing trimming in pattern order.
        // A scan resumes after an empty match, where the caret no longer
        // matches: preg_match_all('/(^|bar)/', 'bar') sets group 1 to '' then 'bar'.
        yield 'pattern order: a caret branch does not win across the scan' => ['pattern' => '/(^|bar)/', 'flags' => \PREG_PATTERN_ORDER, 'expected' => "array{0: list<''|'bar'>, 1: list<''|'bar'>}"];
        yield 'pattern order: every group key' => ['pattern' => '/(a)(b)?(c)?/', 'flags' => \PREG_PATTERN_ORDER, 'expected' => "array{0: list<'a'|'ac'|'ab'|'abc'>, 1: list<'a'>, 2: list<''|'b'>, 3: list<''|'c'>}"];
        yield 'pattern order: every group key, as null' => ['pattern' => '/(a)(b)?(c)?/', 'flags' => \PREG_PATTERN_ORDER | \PREG_UNMATCHED_AS_NULL, 'expected' => "array{0: list<'a'|'ac'|'ab'|'abc'>, 1: list<'a'>, 2: list<'b'|null>, 3: list<'c'|null>}"];
        yield 'pattern order: every group key, offsets' => ['pattern' => '/(a)(b)?(c)?/', 'flags' => \PREG_PATTERN_ORDER | \PREG_OFFSET_CAPTURE, 'expected' => "array{0: list<array{'a'|'ac'|'ab'|'abc', int<0, max>}>, 1: list<array{'a', int<0, max>}>, 2: list<array{''|'b', int<-1, max>}>, 3: list<array{''|'c', int<-1, max>}>}"];
        yield 'pattern order: every group key, offsets, as null' => ['pattern' => '/(a)(b)?(c)?/', 'flags' => \PREG_PATTERN_ORDER | \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL, 'expected' => "array{0: list<array{'a'|'ac'|'ab'|'abc', int<0, max>}>, 1: list<array{'a', int<0, max>}>, 2: list<array{'b'|null, int<-1, max>}>, 3: list<array{'c'|null, int<-1, max>}>}"];
        yield 'set order: the match shape of each set' => ['pattern' => '/(a)(b)?(c)?/', 'flags' => \PREG_SET_ORDER, 'expected' => "list<array{0: 'a'|'ac'|'ab'|'abc', 1: 'a', 2?: ''|'b', 3?: 'c'}>"];
        yield 'set order: the match shape of each set, as null' => ['pattern' => '/(a)(b)?(c)?/', 'flags' => \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL, 'expected' => "list<array{0: 'a'|'ac'|'ab'|'abc', 1: 'a', 2: 'b'|null, 3: 'c'|null}>"];
        yield 'set order: the match shape of each set, offsets' => ['pattern' => '/(a)(b)?(c)?/', 'flags' => \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE, 'expected' => "list<array{0: array{'a'|'ac'|'ab'|'abc', int<0, max>}, 1: array{'a', int<0, max>}, 2?: array{''|'b', int<-1, max>}, 3?: array{'c', int<-1, max>}}>"];
        yield 'set order: the match shape of each set, offsets, as null' => ['pattern' => '/(a)(b)?(c)?/', 'flags' => \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL, 'expected' => "list<array{0: array{'a'|'ac'|'ab'|'abc', int<0, max>}, 1: array{'a', int<0, max>}, 2: array{'b'|null, int<-1, max>}, 3: array{'c'|null, int<-1, max>}}>"];
        yield 'pattern order: no group' => ['pattern' => '/a/', 'flags' => \PREG_PATTERN_ORDER, 'expected' => "array{0: list<'a'>}"];
        yield 'set order: no group' => ['pattern' => '/a/', 'flags' => \PREG_SET_ORDER, 'expected' => "list<array{0: 'a'}>"];

        // Names: the name before its number.
        yield 'pattern order: a name before its number' => ['pattern' => '/(?<x>a)(b)?/', 'flags' => \PREG_PATTERN_ORDER, 'expected' => "array{0: list<'a'|'ab'>, x: list<'a'>, 1: list<'a'>, 2: list<''|'b'>}"];
        yield 'pattern order: a name before its number, offsets' => ['pattern' => '/(?<x>a)(b)?/', 'flags' => \PREG_PATTERN_ORDER | \PREG_OFFSET_CAPTURE, 'expected' => "array{0: list<array{'a'|'ab', int<0, max>}>, x: list<array{'a', int<0, max>}>, 1: list<array{'a', int<0, max>}>, 2: list<array{''|'b', int<-1, max>}>}"];
        yield 'set order: a name before its number' => ['pattern' => '/(?<x>a)(b)?/', 'flags' => \PREG_SET_ORDER, 'expected' => "list<array{0: 'a'|'ab', x: 'a', 1: 'a', 2?: 'b'}>"];
        yield 'pattern order: no auto capture' => ['pattern' => '/(a)(?<x>b)/n', 'flags' => \PREG_PATTERN_ORDER, 'expected' => "array{0: list<'ab'>, x: list<'b'>, 1: list<'b'>}"];

        // Groups no match sets.
        yield 'pattern order: a group never set' => ['pattern' => '/(?!(b))a/', 'flags' => \PREG_PATTERN_ORDER, 'expected' => "array{0: list<'a'>, 1: list<''>}"];
        yield 'pattern order: a group never set, as null' => ['pattern' => '/(?!(b))a/', 'flags' => \PREG_PATTERN_ORDER | \PREG_UNMATCHED_AS_NULL, 'expected' => "array{0: list<'a'>, 1: list<null>}"];
        yield 'pattern order: a group never set, offsets' => ['pattern' => '/(?!(b))a/', 'flags' => \PREG_PATTERN_ORDER | \PREG_OFFSET_CAPTURE, 'expected' => "array{0: list<array{'a', int<0, max>}>, 1: list<array{'', int<-1, max>}>}"];
        yield 'set order: a group never set is left out' => ['pattern' => '/(?!(b))a/', 'flags' => \PREG_SET_ORDER, 'expected' => "list<array{0: 'a'}>"];
        yield 'set order: a group never set, as null' => ['pattern' => '/(?!(b))a/', 'flags' => \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL, 'expected' => "list<array{0: 'a', 1: null}>"];
        yield 'pattern order: groups inside DEFINE' => ['pattern' => '/(?(DEFINE)(?<d>x))(a)(?&d)/', 'flags' => \PREG_PATTERN_ORDER, 'expected' => "array{0: list<'ax'>, d: list<''>, 1: list<''>, 2: list<'a'>}"];

        // Names shared under (?J): in pattern order the list of the last group bearing the name, set or not.
        yield 'pattern order: a shared name holds the list of its last group' => ['pattern' => '/(?J)(?<n>a)(?<n>z)?(c)/', 'flags' => \PREG_PATTERN_ORDER, 'expected' => "array{0: list<'ac'|'azc'>, n: list<''|'z'>, 1: list<'a'>, 2: list<''|'z'>, 3: list<'c'>}"];
        yield 'pattern order: a shared name holds the list of its last group, as null' => ['pattern' => '/(?J)(?<n>a)(?<n>z)?(c)/', 'flags' => \PREG_PATTERN_ORDER | \PREG_UNMATCHED_AS_NULL, 'expected' => "array{0: list<'ac'|'azc'>, n: list<'z'|null>, 1: list<'a'>, 2: list<'z'|null>, 3: list<'c'>}"];
        yield 'pattern order: a shared name holds the list of its last group, offsets' => ['pattern' => '/(?J)(?<n>a)(?<n>z)?(c)/', 'flags' => \PREG_PATTERN_ORDER | \PREG_OFFSET_CAPTURE, 'expected' => "array{0: list<array{'ac'|'azc', int<0, max>}>, n: list<array{''|'z', int<-1, max>}>, 1: list<array{'a', int<0, max>}>, 2: list<array{''|'z', int<-1, max>}>, 3: list<array{'c', int<0, max>}>}"];
        yield 'set order: a shared name holds the highest group set' => ['pattern' => '/(?J)(?<n>a)(?<n>z)?(c)/', 'flags' => \PREG_SET_ORDER, 'expected' => "list<array{0: 'ac'|'azc', n: 'a'|'z', 1: 'a', 2: ''|'z', 3: 'c'}>"];
        yield 'pattern order: a shared name whose last group is never set' => ['pattern' => '/(?J)(?<n>a)(?<n>z){0}/', 'flags' => \PREG_PATTERN_ORDER, 'expected' => "array{0: list<'a'>, n: list<''>, 1: list<'a'>, 2: list<''>}"];
        yield 'pattern order: a shared name whose last group is never set, as null' => ['pattern' => '/(?J)(?<n>a)(?<n>z){0}/', 'flags' => \PREG_PATTERN_ORDER | \PREG_UNMATCHED_AS_NULL, 'expected' => "array{0: list<'a'>, n: list<null>, 1: list<'a'>, 2: list<null>}"];
        yield 'set order: a shared name whose last group is never set' => ['pattern' => '/(?J)(?<n>a)(?<n>z){0}/', 'flags' => \PREG_SET_ORDER, 'expected' => "list<array{0: 'a', n: 'a', 1: 'a'}>"];
        yield 'pattern order: a shared name across a split' => ['pattern' => '/(?J)(?<n>a)|(?<n>b)/', 'flags' => \PREG_PATTERN_ORDER, 'expected' => "array{0: list<'a'|'b'>, n: list<''|'b'>, 1: list<''|'a'>, 2: list<''|'b'>}"];
        yield 'set order: a shared name across a split' => ['pattern' => '/(?J)(?<n>a)|(?<n>b)/', 'flags' => \PREG_SET_ORDER, 'expected' => "list<array{0: 'a', n: 'a', 1: 'a'}|array{0: 'b', n: 'b', 1: '', 2: 'b'}>"];

        // Branch reset.
        yield 'pattern order: branch reset' => ['pattern' => '/(?|(a)|(b)(c))(d)/', 'flags' => \PREG_PATTERN_ORDER, 'expected' => "array{0: list<'ad'|'bcd'>, 1: list<'a'|'b'>, 2: list<''|'c'>, 3: list<'d'>}"];
        yield 'set order: branch reset' => ['pattern' => '/(?|(a)|(b)(c))(d)/', 'flags' => \PREG_SET_ORDER, 'expected' => "list<array{0: 'ad'|'bcd', 1: 'a'|'b', 2: ''|'c', 3: 'd'}>"];
        yield 'pattern order: a name in one branch of a branch reset' => ['pattern' => '/(?|(x)|(?<a>y))/', 'flags' => \PREG_PATTERN_ORDER, 'expected' => "array{0: list<'x'|'y'>, a: list<'x'|'y'>, 1: list<'x'|'y'>}"];

        // Marks: in pattern order keyed by match index, only for the matches that set one; in set order as preg_match() writes them.
        yield 'pattern order: marks keyed by match index' => ['pattern' => '/(*MARK:m)(a)|(b)/', 'flags' => \PREG_PATTERN_ORDER, 'expected' => "array{0: list<'a'|'b'>, 1: list<''|'a'>, 2: list<''|'b'>, MARK?: array<int, 'm'>}"];
        yield 'pattern order: marks keyed by match index, as null' => ['pattern' => '/(*MARK:m)(a)|(b)/', 'flags' => \PREG_PATTERN_ORDER | \PREG_UNMATCHED_AS_NULL, 'expected' => "array{0: list<'a'|'b'>, 1: list<'a'|null>, 2: list<'b'|null>, MARK?: array<int, 'm'>}"];
        yield 'pattern order: marks stay strings under offsets' => ['pattern' => '/(*MARK:m)(a)|(b)/', 'flags' => \PREG_PATTERN_ORDER | \PREG_OFFSET_CAPTURE, 'expected' => "array{0: list<array{'a'|'b', int<0, max>}>, 1: list<array{''|'a', int<-1, max>}>, 2: list<array{''|'b', int<-1, max>}>, MARK?: array<int, 'm'>}"];
        yield 'set order: a mark as preg_match() writes it' => ['pattern' => '/(*MARK:m)(a)|(b)/', 'flags' => \PREG_SET_ORDER, 'expected' => "list<array{0: 'a', 1: 'a', MARK?: 'm'}|array{0: 'b', 1: '', 2: 'b'}>"];
        yield 'set order: a mark as preg_match() writes it, offsets' => ['pattern' => '/(*MARK:m)(a)|(b)/', 'flags' => \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE, 'expected' => "list<array{0: array{'a', int<0, max>}, 1: array{'a', int<0, max>}, MARK?: 'm'}|array{0: array{'b', int<0, max>}, 1: array{'', int<-1, max>}, 2: array{'b', int<0, max>}}>"];
        yield 'pattern order: several mark names' => ['pattern' => '/(*MARK:m)a|(*MARK:n)b/', 'flags' => \PREG_PATTERN_ORDER, 'expected' => "array{0: list<'a'|'b'>, MARK?: array<int, 'm'|'n'>}"];
        yield 'pattern order: a group named MARK, no verb' => ['pattern' => '/(?<MARK>a)/', 'flags' => \PREG_PATTERN_ORDER, 'expected' => "array{0: list<'a'>, MARK: list<'a'>, 1: list<'a'>}"];

        // A split pattern: the merged view per key in pattern order, the union of the cases in set order.
        yield 'pattern order: a split pattern, merged per key' => ['pattern' => '/(a)|(b)/', 'flags' => \PREG_PATTERN_ORDER, 'expected' => "array{0: list<'a'|'b'>, 1: list<''|'a'>, 2: list<''|'b'>}"];
        yield 'pattern order: a split pattern, merged per key, as null' => ['pattern' => '/(a)|(b)/', 'flags' => \PREG_PATTERN_ORDER | \PREG_UNMATCHED_AS_NULL, 'expected' => "array{0: list<'a'|'b'>, 1: list<'a'|null>, 2: list<'b'|null>}"];
        yield 'set order: a split pattern, the union of the cases' => ['pattern' => '/(a)|(b)/', 'flags' => \PREG_SET_ORDER, 'expected' => "list<array{0: 'a', 1: 'a'}|array{0: 'b', 1: '', 2: 'b'}>"];
        yield 'set order: a split pattern, the union of the cases, as null' => ['pattern' => '/(a)|(b)/', 'flags' => \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL, 'expected' => "list<array{0: 'a', 1: 'a', 2: null}|array{0: 'b', 1: null, 2: 'b'}>"];
        yield 'pattern order: an optional split' => ['pattern' => '/(?:(a)|(b))?c/', 'flags' => \PREG_PATTERN_ORDER, 'expected' => "array{0: list<'c'|'ac'|'bc'>, 1: list<''|'a'>, 2: list<''|'b'>}"];
        yield 'set order: an optional split' => ['pattern' => '/(?:(a)|(b))?c/', 'flags' => \PREG_SET_ORDER, 'expected' => "list<array{0: 'ac', 1: 'a'}|array{0: 'bc', 1: '', 2: 'b'}|array{0: 'c'}>"];

        // Facts in the list values.
        yield 'pattern order: facts' => ['pattern' => '/(\d+)/', 'flags' => \PREG_PATTERN_ORDER, 'expected' => 'array{0: list<numeric-string>, 1: list<numeric-string>}'];
        yield 'pattern order: facts of a group that may be unset' => ['pattern' => '/(\d\d+)?/', 'flags' => \PREG_PATTERN_ORDER, 'expected' => "array{0: list<''|numeric-string>, 1: list<''|(non-falsy-string&numeric-string)>}"];
        yield 'pattern order: facts of a group that may be unset, as null' => ['pattern' => '/(\d\d+)?/', 'flags' => \PREG_PATTERN_ORDER | \PREG_UNMATCHED_AS_NULL, 'expected' => "array{0: list<''|numeric-string>, 1: list<(non-falsy-string&numeric-string)|null>}"];
        yield 'pattern order: facts of a group that may be unset, offsets' => ['pattern' => '/(\d\d+)?/', 'flags' => \PREG_PATTERN_ORDER | \PREG_OFFSET_CAPTURE, 'expected' => "array{0: list<array{''|numeric-string, int<0, max>}>, 1: list<array{''|(non-falsy-string&numeric-string), int<-1, max>}>}"];
        yield 'pattern order: facts of a split pattern, merged' => ['pattern' => '/^(?:(\d+)|([a-z]+))$/', 'flags' => \PREG_PATTERN_ORDER, 'expected' => "array{0: list<lowercase-string&non-empty-string>, 1: list<''|numeric-string>, 2: list<''|(lowercase-string&non-falsy-string)>}"];
        yield 'set order: facts of a split pattern, per case' => ['pattern' => '/^(?:(\d+)|([a-z]+))$/', 'flags' => \PREG_SET_ORDER, 'expected' => "list<array{0: numeric-string, 1: numeric-string}|array{0: lowercase-string&non-falsy-string, 1: '', 2: lowercase-string&non-falsy-string}>"];
    }

    /**
     * Each refused by preg_match_all() with ValueError (PHP 8.4.26), the
     * test checks it again.
     *
     * @return iterable<string, array{flags: int}>
     */
    public static function provideMatchAllRefusedFlags(): iterable
    {
        yield 'both orders' => ['flags' => \PREG_PATTERN_ORDER | \PREG_SET_ORDER];
        yield 'both orders with both flags' => ['flags' => \PREG_PATTERN_ORDER | \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL];
        yield 'PREG_SPLIT_OFFSET_CAPTURE' => ['flags' => \PREG_SPLIT_OFFSET_CAPTURE];
        yield 'PREG_SET_ORDER | PREG_SPLIT_OFFSET_CAPTURE' => ['flags' => \PREG_SET_ORDER | \PREG_SPLIT_OFFSET_CAPTURE];
        yield 'bit 3' => ['flags' => 8];
        yield 'highest bit of the low byte' => ['flags' => 128];
        yield 'the whole low byte' => ['flags' => 255];
        yield '-1' => ['flags' => -1];
        yield '999' => ['flags' => 999];
    }

    /**
     * @return iterable<string, array{flags: int, meaningful: int}>
     */
    public static function provideMatchAllIgnoredFlags(): iterable
    {
        yield '0 is pattern order' => ['flags' => 0, 'meaningful' => \PREG_PATTERN_ORDER];
        yield '1024 alone' => ['flags' => 1024, 'meaningful' => \PREG_PATTERN_ORDER];
        yield '1024 with set order' => ['flags' => \PREG_SET_ORDER | 1024, 'meaningful' => \PREG_SET_ORDER];
        yield 'a high bit with set order and both flags' => ['flags' => \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL | 4096, 'meaningful' => \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL];
        yield 'PHP_INT_MIN, a low byte of 0' => ['flags' => \PHP_INT_MIN, 'meaningful' => \PREG_PATTERN_ORDER];
    }

    /**
     * '/(a)|(b)|…/' with $count branches of one letter each, or, with a
     * quantifier, '/(?:(a)|(b)|…)?/'.
     */
    private static function branches(int $count, string $quantifier): string
    {
        $alternation = implode('|', array_map(static fn (string $letter): string => '('.$letter.')', \array_slice(range('a', 'z'), 0, $count)));

        return '' === $quantifier ? '/'.$alternation.'/' : '/(?:'.$alternation.')'.$quantifier.'/';
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

            $this->assertSame([], self::factRefusals($group, $value), \sprintf('%s: group %d', $context, $group->number));

            // Lengths count code points in UTF mode, bytes otherwise.
            $length = $unicode ? mb_strlen($value, 'UTF-8') : \strlen($value);
            $this->assertGreaterThanOrEqual($group->minLength, $length, \sprintf('%s: group %d is shorter than its minimum.', $context, $group->number));
            if (null !== $group->maxLength) {
                $this->assertLessThanOrEqual($group->maxLength, $length, \sprintf('%s: group %d is longer than its maximum.', $context, $group->number));
            }
        }
    }

    /**
     * Whether one case holds a $matches the engine wrote: every key it
     * writes is a group of the case, each group the case always sets is
     * written, each group it never sets reads unset, and each value set
     * fits the group's values and lengths.
     *
     * @param array<int|string, mixed> $matches
     */
    private static function caseHolds(CaptureShape $case, array $matches, int $flags, bool $unicode): bool
    {
        $asNull = 0 !== ($flags & \PREG_UNMATCHED_AS_NULL);
        $offsets = 0 !== ($flags & \PREG_OFFSET_CAPTURE);
        $unset = $asNull ? null : '';

        foreach ([$case->whole, ...$case->groups] as $group) {
            $present = \array_key_exists($group->number, $matches);
            $entry = $present ? $matches[$group->number] : null;
            $value = $offsets && \is_array($entry) ? $entry[0] : $entry;
            $located = !$offsets || !\is_array($entry) || -1 !== $entry[1];

            if (Participation::Always === $group->participation && (!$present || null === $value || !$located)) {
                return false;
            }

            if (Participation::Never === $group->participation) {
                if ($present && ($unset !== $value || $located && $offsets)) {
                    return false;
                }

                continue;
            }

            if (!\is_string($value) || !$located || ('' === $value && Participation::Always !== $group->participation && !$asNull)) {
                continue;
            }

            if (null !== $group->values && !\in_array($value, $group->values, true)) {
                return false;
            }

            if ([] !== self::factRefusals($group, $value)) {
                return false;
            }

            $length = $unicode ? mb_strlen($value, 'UTF-8') : \strlen($value);
            if ($length < $group->minLength || (null !== $group->maxLength && $length > $group->maxLength)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A fact proven true holds for every value the engine writes into a set
     * group of the merged shape.
     *
     * @param list<string> $subjects
     */
    private function assertProvenFactsHold(CaptureShape $shape, string $pattern, array $subjects): void
    {
        foreach ($subjects as $subject) {
            $located = [];
            $this->assertSame(1, preg_match($pattern, $subject, $located, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL), \sprintf('%s must match %s.', $pattern, json_encode($subject)));

            foreach ([$shape->whole, ...$shape->groups] as $group) {
                [$value, $offset] = $located[$group->number];
                if (-1 === $offset || !\is_string($value)) {
                    continue;
                }

                $context = \sprintf('%s on %s: group %d holds %s', $pattern, json_encode($subject, \JSON_UNESCAPED_UNICODE), $group->number, json_encode($value, \JSON_UNESCAPED_UNICODE));
                $this->assertSame([], self::factRefusals($group, $value), $context);
            }
        }
    }

    /**
     * The fastest of three analyses of one tree, in seconds.
     */
    private static function fastestAnalysis(CaptureShapeAnalyzer $analyzer, RegexNode $regex): float
    {
        $fastest = \INF;
        for ($run = 0; $run < 3; $run++) {
            $start = CpuClock::nanoseconds();
            $analyzer->analyze($regex);
            $fastest = min($fastest, (CpuClock::nanoseconds() - $start) / 1e9);
        }

        return $fastest;
    }

    /**
     * The keys of each array shape of a written type, a shape or a union of
     * shapes, outermost level only, in order.
     *
     * @return list<list<string>>
     */
    private static function topLevelKeys(string $type): array
    {
        $members = [];
        foreach (self::topLevelMembers($type) as $shape) {
            self::assertStringStartsWith('array{', $shape);
            self::assertStringEndsWith('}', $shape);
            $members[] = self::shapeKeys($shape);
        }

        return $members;
    }

    /**
     * The members of a written union, split on each '|' outside a shape and
     * outside a quoted string.
     *
     * @return list<string>
     */
    private static function topLevelMembers(string $type): array
    {
        $members = [];
        $depth = 0;
        $member = '';
        $quoted = false;
        $escaped = false;
        foreach (str_split($type.'|') as $char) {
            if ($quoted) {
                $member .= $char;
                [$escaped, $quoted] = [!$escaped && '\\' === $char, $escaped || "'" !== $char];

                continue;
            }

            if ('|' === $char && 0 === $depth) {
                $members[] = $member;
                $member = '';

                continue;
            }

            $member .= $char;
            $quoted = "'" === $char;
            $depth += match ($char) {
                '{', '<' => 1,
                '}', '>' => -1,
                default => 0,
            };
        }

        return $members;
    }

    /**
     * The keys of one written array shape, outermost level only, in order.
     *
     * @return list<string>
     */
    private static function shapeKeys(string $shape): array
    {
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

    /**
     * The facts a record proves, in a fixed order.
     *
     * @return list<'nonFalsy'|'digitsOnly'|'decimalInt'|'lowercase'|'uppercase'>
     */
    private static function provenFacts(CaptureGroupShape $group): array
    {
        return array_keys(array_filter([
            'nonFalsy' => $group->nonFalsy,
            'digitsOnly' => $group->digitsOnly,
            'decimalInt' => $group->decimalInt,
            'lowercase' => $group->lowercase,
            'uppercase' => $group->uppercase,
        ]));
    }

    /**
     * The facts a record proves that the value breaks, as PHP and PHPStan
     * read them: a digit run of any length is digits or ''.
     *
     * @return list<string>
     */
    private static function factRefusals(CaptureGroupShape $group, string $value): array
    {
        return array_keys(array_filter([
            'nonFalsy' => $group->nonFalsy && !(bool) $value,
            'digitsOnly' => $group->digitsOnly && '' !== $value && !ctype_digit($value),
            'decimalInt' => $group->decimalInt && (string) (int) $value !== $value,
            'lowercase' => $group->lowercase && strtolower($value) !== $value,
            'uppercase' => $group->uppercase && strtoupper($value) !== $value,
        ]));
    }

    /**
     * What a record says that the value breaks: its values, its lengths and
     * its facts.
     *
     * @return list<string>
     */
    private static function refusals(CaptureGroupShape $group, string $value, bool $unicode): array
    {
        $length = $unicode ? mb_strlen($value, 'UTF-8') : \strlen($value);

        return [
            ...(null === $group->values || \in_array($value, $group->values, true) ? [] : ['values']),
            ...($length < $group->minLength ? ['minLength'] : []),
            ...(null !== $group->maxLength && $length > $group->maxLength ? ['maxLength'] : []),
            ...self::factRefusals($group, $value),
        ];
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
