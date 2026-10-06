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

use PHPRegex\Parser\Analysis\PatternInfo;
use PHPRegex\Parser\Analysis\PatternInfoAnalyzer;
use PHPRegex\Parser\BsrConvention;
use PHPRegex\Parser\NewlineConvention;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The facts PCRE2 computes on a compiled pattern, read from the tree. Every
 * expected value was read from pcre2test 10.49 ("/I") or, for what PCRE2
 * does not report (match lengths, the end anchor), from preg_match() on PHP
 * 8.4.26; the witness tests below replay the engine.
 *
 * The exact facts (capture count, names, back reference, \C, limits,
 * newline, BSR) equal PCRE2's. The others are sound bounds: a length range
 * holds every match, an anchor is true only when proven.
 */
final class PatternInfoAnalyzerTest extends TestCase
{
    /**
     * Every row is read for one target, so that no row depends on the PHP
     * running the suite: the variable-length lookbehinds need PCRE2 10.43.
     */
    private const TARGET = ['php_version' => '8.4', 'pcre_version' => '10.44'];

    #[Test]
    public function test_analysis_version_is_1(): void
    {
        $this->assertSame('1', PatternInfoAnalyzer::ANALYSIS_VERSION);
    }

    #[Test]
    public function test_pattern_info_is_a_final_readonly_value_with_the_documented_facts(): void
    {
        $class = new \ReflectionClass(PatternInfo::class);

        $this->assertTrue($class->isFinal());
        $this->assertTrue($class->isReadOnly());

        $properties = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            $class->getProperties(\ReflectionProperty::IS_PUBLIC),
        );
        sort($properties);

        // canMatchEmpty was cut: it is minMatchLength === 0.
        $this->assertSame([
            'anchoredEnd', 'anchoredStart', 'bsr', 'captureCount', 'depthLimit', 'heapLimit', 'matchLimit',
            'maxBackreference', 'maxLookbehind', 'maxMatchLength', 'minMatchLength', 'names', 'newline', 'usesBackslashC',
        ], $properties);

        $constructor = $class->getConstructor();
        $this->assertInstanceOf(\ReflectionMethod::class, $constructor);
        $this->assertStringContainsString('@internal', (string) $constructor->getDocComment());
    }

    #[Test]
    #[DataProvider('provideCaptureCounts')]
    public function test_capture_count_is_read_from_the_pattern(string $pattern, int $expected): void
    {
        $this->assertSame($expected, $this->info($pattern)->captureCount);
    }

    /**
     * pcre2test "Capture group count".
     *
     * @return iterable<string, array{pattern: string, expected: int}>
     */
    public static function provideCaptureCounts(): iterable
    {
        yield 'no group' => ['pattern' => '/abc/', 'expected' => 0];
        yield 'empty pattern' => ['pattern' => '//', 'expected' => 0];
        yield 'three groups' => ['pattern' => '/(a)(b)(c)/', 'expected' => 3];
        yield 'groups that do not capture' => ['pattern' => '/(?:a)(?>b)(?=c)(?<=d)/', 'expected' => 0];
        yield 'branch reset counts its widest branch' => ['pattern' => '/(?|(a)|(b)(c))\k<n>(?<n>d)/', 'expected' => 3];
        yield 'n modifier keeps named groups only' => ['pattern' => '/(a)(?<n>b)/n', 'expected' => 1];
        yield 'inline n keeps named groups only' => ['pattern' => '/(?n)(a)(?<n>b)/', 'expected' => 1];
        yield 'group inside DEFINE' => ['pattern' => '/(?(DEFINE)(?<d>\d))(?&d)/', 'expected' => 1];
        yield 'duplicate names under J' => ['pattern' => '/(?<n>a)|(?<n>b)/J', 'expected' => 2];
    }

    /**
     * @param array<string, list<int>> $expected
     */
    #[Test]
    #[DataProvider('provideNames')]
    public function test_names_map_each_name_to_its_group_numbers(string $pattern, array $expected): void
    {
        $this->assertSame($expected, $this->info($pattern)->names);
    }

    /**
     * pcre2test "Named capture groups": sorted by name, as PCRE2's name
     * table is, each name with its numbers ascending.
     *
     * @return iterable<string, array{pattern: string, expected: array<string, list<int>>}>
     */
    public static function provideNames(): iterable
    {
        yield 'no name' => ['pattern' => '/(a)(b)/', 'expected' => []];
        yield 'names sorted as PCRE2 sorts them' => ['pattern' => '/(?<b>x)(?<a>y)/', 'expected' => ['a' => [2], 'b' => [1]]];
        yield 'duplicate names under J' => ['pattern' => '/(?<n>a)|(?<n>b)/J', 'expected' => ['n' => [1, 2]]];
        yield 'duplicate names under inline J' => ['pattern' => '/(?J)(?<n>a)|(?<n>b)/', 'expected' => ['n' => [1, 2]]];
        yield 'one name in a branch reset' => ['pattern' => '/(?|(?<x>a)|(?<x>b))/', 'expected' => ['x' => [1]]];
        yield 'named group after a branch reset' => ['pattern' => '/(?|(a)|(b)(c))\k<n>(?<n>d)/', 'expected' => ['n' => [3]]];
        yield 'Python and quoted spellings' => ['pattern' => "/(?P<name>a)(?'q'b)/", 'expected' => ['name' => [1], 'q' => [2]]];
        yield 'n modifier numbers the named group 1' => ['pattern' => '/(a)(?<n>b)/n', 'expected' => ['n' => [1]]];
        // Met as group 2 first, then as group 1: listed ascending.
        yield 'duplicate name met out of order in a branch reset' => ['pattern' => '/(?J)(?|(x)(?<b>y)|(?<b>z))/', 'expected' => ['b' => [1, 2]]];
    }

    #[Test]
    #[DataProvider('provideMaxBackreferences')]
    public function test_max_backreference_is_the_highest_group_a_reference_can_resolve_to(string $pattern, int $expected): void
    {
        $this->assertSame($expected, $this->info($pattern)->maxBackreference);
    }

    /**
     * pcre2test "Max back reference", 0 when it prints none.
     *
     * @return iterable<string, array{pattern: string, expected: int}>
     */
    public static function provideMaxBackreferences(): iterable
    {
        yield 'no reference' => ['pattern' => '/(a)(b)/', 'expected' => 0];
        yield 'numbered' => ['pattern' => '/(a)\1/', 'expected' => 1];
        yield 'the highest referenced, not the group count' => ['pattern' => '/(a)(b)\g{1}/', 'expected' => 1];
        yield 'relative' => ['pattern' => '/(a)(b)(c)\g{-2}/', 'expected' => 2];
        yield 'relative without braces' => ['pattern' => '/(a)\g-1/', 'expected' => 1];
        yield 'relative forward' => ['pattern' => '/(a)\g{+1}(b)/', 'expected' => 2];
        yield 'numbered without braces' => ['pattern' => '/(a)(b)\g1/', 'expected' => 1];
        yield 'relative condition, forward' => ['pattern' => '/(?(+1)a)(b)/', 'expected' => 1];
        yield 'relative condition, backward' => ['pattern' => '/(a)(?(-1)b)/', 'expected' => 1];
        yield 'named condition in quotes' => ['pattern' => "/(?('n')a)(?<n>b)/", 'expected' => 1];
        yield 'named recursion condition' => ['pattern' => '/(?(R&n)a|b)(?<n>c)/', 'expected' => 1];
        yield 'named recursion condition to a duplicate name' => ['pattern' => '/(?J)(?<n>a)|(?<n>b)(?(R&n)c)/', 'expected' => 2];
        yield 'forward reference' => ['pattern' => '/(a)(?:\2)?(b)/', 'expected' => 2];
        yield 'named reference in a branch reset' => ['pattern' => '/(?|(a)|(b)(c))\k<n>(?<n>d)/', 'expected' => 3];
        yield 'named reference to a duplicate name' => ['pattern' => '/(?J)(?<n>a)|(?<n>b)\k<n>/', 'expected' => 2];
        yield 'Python spelling, the call is not a reference' => ['pattern' => '/(?P<n>a)(?P=n)(?P>n)/', 'expected' => 1];
        yield 'numbered condition' => ['pattern' => '/(?(2)a)()()/', 'expected' => 2];
        yield 'named condition' => ['pattern' => '/(?(<n>)a)(?<n>b)/', 'expected' => 1];
        yield 'subroutine call' => ['pattern' => '/(?1)(a)/', 'expected' => 0];
        yield 'whole-pattern recursion' => ['pattern' => '/(a)(?R)?/', 'expected' => 0];
        yield 'recursion condition by number' => ['pattern' => '/(?(R1)a|b)(c)/', 'expected' => 0];
        // A group with the exact name "R2" or "R" makes the condition a test
        // of that group, wherever it stands (pcre2test 10.49).
        yield 'condition R2 on a group named R2' => ['pattern' => '/(?<R2>a)(?(R2)b|c)/', 'expected' => 1];
        yield 'condition R2 on a group named R2 after it' => ['pattern' => '/(?(R2)b|c)(?<R2>a)/', 'expected' => 1];
        yield 'condition R on a group named R' => ['pattern' => '/(?<R>a)(?(R)b|c)/', 'expected' => 1];
        yield 'condition R1, the name wins over group 1' => ['pattern' => '/(a)(?<R1>x)?(?(R1)b|c)/', 'expected' => 2];
        yield 'condition R1 on a group named R1 that is group 1' => ['pattern' => '/(?<R1>x)(a)(?(R1)b|c)/', 'expected' => 1];
        yield 'condition R on a duplicate name' => ['pattern' => '/(?J)(?:(?<R>a)|(?<R>b))(?(R)c|d)/', 'expected' => 2];
        yield 'condition R2 on a group named R2 inside an assertion' => ['pattern' => '/(?(R2)a|c)(*pla:(?<R2>a))/', 'expected' => 1];
    }

    /**
     * The tree is read as written: a reference the validator refuses, as it
     * names no group, gets an answer, not an error.
     */
    #[Test]
    #[DataProvider('provideReferencesToNoGroup')]
    public function test_a_reference_to_no_group_counts_nothing(string $pattern, int $expected): void
    {
        $this->assertFalse(RegexParser::create(self::TARGET)->validate($pattern)->isValid, 'PCRE2 refuses it.');
        $this->assertSame($expected, $this->info($pattern)->maxBackreference);
    }

    /**
     * @return iterable<string, array{pattern: string, expected: int}>
     */
    public static function provideReferencesToNoGroup(): iterable
    {
        yield 'unknown name' => ['pattern' => '/\k<zz>/', 'expected' => 0];
        yield 'relative, before any group' => ['pattern' => '/\g{-1}(a)/', 'expected' => 0];
        yield 'relative zero' => ['pattern' => '/(a)\g{-0}(b)/', 'expected' => 0];
        yield 'named recursion condition, unknown name' => ['pattern' => '/(?(R&zz)a|b)/', 'expected' => 0];
        yield 'unknown name beside a known group' => ['pattern' => '/(a)(b)\2\k<zz>/', 'expected' => 2];
    }

    #[Test]
    #[DataProvider('provideMatchLengths')]
    public function test_match_length_bounds_what_matches_0_holds(string $pattern, int $min, ?int $max): void
    {
        $info = $this->info($pattern);

        $this->assertSame($min, $info->minMatchLength, 'minimum');
        $this->assertSame($max, $info->maxMatchLength, 'maximum');
    }

    /**
     * Lengths of $matches[0]: characters under "u" or "(*UTF)", bytes
     * otherwise; null is unbounded.
     *
     * @return iterable<string, array{pattern: string, min: int, max: int|null}>
     */
    public static function provideMatchLengths(): iterable
    {
        yield 'empty pattern' => ['pattern' => '//', 'min' => 0, 'max' => 0];
        yield 'zero repeat' => ['pattern' => '/a{0}/', 'min' => 0, 'max' => 0];
        yield 'bounded repeat' => ['pattern' => '/a{2,5}/', 'min' => 2, 'max' => 5];
        yield 'unbounded repeat' => ['pattern' => '/a+/', 'min' => 1, 'max' => null];
        yield 'star on a group' => ['pattern' => '/(?:ab)*/', 'min' => 0, 'max' => null];
        yield 'alternation' => ['pattern' => '/a|bcd/', 'min' => 1, 'max' => 3];
        yield 'empty branch' => ['pattern' => '/a|/', 'min' => 0, 'max' => 1];
        yield 'optional group' => ['pattern' => '/(a)?b/', 'min' => 1, 'max' => 2];
        yield 'lookahead consumes nothing' => ['pattern' => '/(?=abc)a/', 'min' => 1, 'max' => 1];
        yield 'assertions consume nothing' => ['pattern' => '/^\bx$/', 'min' => 1, 'max' => 1];
        yield 'newline sequence' => ['pattern' => '/\R/', 'min' => 1, 'max' => 2];
        yield 'two-byte character in bytes' => ['pattern' => '/é/', 'min' => 2, 'max' => 2];
        yield 'two-byte character under u' => ['pattern' => '/é/u', 'min' => 1, 'max' => 1];
        yield 'two-byte character under (*UTF)' => ['pattern' => '/(*UTF)é/', 'min' => 1, 'max' => 1];
        yield 'caseless k matches the three-byte Kelvin sign as one character' => ['pattern' => '/(?i)k/u', 'min' => 1, 'max' => 1];
        yield 'extended grapheme cluster' => ['pattern' => '/\X/u', 'min' => 1, 'max' => null];
        // \K moves where $matches[0] starts: it may hold nothing, and at most
        // what the match consumed.
        yield '\K drops the minimum to 0' => ['pattern' => '/a\Kb/', 'min' => 0, 'max' => 2];
        // A \K inside a lookbehind starts $matches[0] before the match: "bc"
        // for /(?<=a\Kb)c/ on "abc", longer than the one byte consumed.
        yield '\K inside a lookbehind' => ['pattern' => '/(?<=a\Kb)c/', 'min' => 0, 'max' => null];
        yield '\K reached from a call inside a lookbehind' => ['pattern' => '/(?<=(?1))c(a\Kb)?/', 'min' => 0, 'max' => null];
    }

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideMatchLengthSubjects')]
    public function test_match_length_holds_every_match_the_engine_finds(string $pattern, array $subjects): void
    {
        $info = $this->info($pattern);
        $flags = substr($pattern, (int) strrpos($pattern, '/') + 1);
        $characters = str_contains($flags, 'u') || str_starts_with($pattern, '/(*UTF)');

        $found = 0;
        foreach ($subjects as $subject) {
            $this->assertNotFalse(preg_match_all($pattern, $subject, $matches));
            foreach ($matches[0] as $match) {
                $length = $characters ? mb_strlen($match, 'UTF-8') : \strlen($match);
                $this->assertGreaterThanOrEqual($info->minMatchLength, $length, \sprintf('"%s" on "%s"', $match, $subject));
                if (null !== $info->maxMatchLength) {
                    $this->assertLessThanOrEqual($info->maxMatchLength, $length, \sprintf('"%s" on "%s"', $match, $subject));
                }
                $found++;
            }
        }

        $this->assertGreaterThan(0, $found, 'No subject matched: the row checks nothing.');
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideMatchLengthSubjects(): iterable
    {
        yield 'bounded repeat' => ['pattern' => '/a{2,5}/', 'subjects' => ['aa', 'aaaaaaa', 'xaaax']];
        yield 'alternation' => ['pattern' => '/a|bcd/', 'subjects' => ['a', 'bcd', 'abcda']];
        yield 'newline sequence' => ['pattern' => '/\R/', 'subjects' => ["\r\n", "\n", "\r"]];
        yield 'two-byte character in bytes' => ['pattern' => '/é/', 'subjects' => ['é', 'café']];
        yield 'two-byte character under u' => ['pattern' => '/é/u', 'subjects' => ['é', 'café']];
        yield 'Kelvin sign under u' => ['pattern' => '/(?i)k/u', 'subjects' => ["\u{212A}", 'k', 'K']];
        yield '\K' => ['pattern' => '/a\Kb/', 'subjects' => ['ab', 'xaby']];
        yield 'lookahead' => ['pattern' => '/(?=abc)a/', 'subjects' => ['abc', 'xabcx']];
        yield '\K inside a lookbehind' => ['pattern' => '/(?<=a\Kb)c/', 'subjects' => ['abc', 'xabcy']];
        yield '\K reached from a call inside a lookbehind' => ['pattern' => '/(?<=(?1))c(a\Kb)?/', 'subjects' => ['abc']];
    }

    #[Test]
    #[DataProvider('provideMaxLookbehinds')]
    public function test_max_lookbehind_is_the_longest_lookbehind_body(string $pattern, int $expected): void
    {
        $this->assertSame($expected, $this->info($pattern)->maxLookbehind);
    }

    /**
     * pcre2test "Max lookbehind", except where PCRE2 also counts the one
     * character "\b", "\B" or "\A" look back at: those rows say so.
     *
     * @return iterable<string, array{pattern: string, expected: int}>
     */
    public static function provideMaxLookbehinds(): iterable
    {
        yield 'no lookbehind' => ['pattern' => '/abc/', 'expected' => 0];
        // PCRE2 says 1: it counts the character \b reads. Documented.
        yield 'word boundary is not a lookbehind' => ['pattern' => '/\bx/', 'expected' => 0];
        yield 'longest branch' => ['pattern' => '/(?<=ab|c)\bx\1(a)/', 'expected' => 2];
        yield 'beside a word boundary' => ['pattern' => '/a\b(?<=xyz)/', 'expected' => 3];
        yield 'nested lookbehind' => ['pattern' => '/(?<=a(?<=bc))d/', 'expected' => 2];
        yield 'variable-length repeat' => ['pattern' => '/(?<=a{1,3})b/', 'expected' => 3];
        yield 'negative lookbehind under u, in characters' => ['pattern' => '/(?<!\x{100}ab)c/u', 'expected' => 3];
        yield 'two-byte character in bytes' => ['pattern' => '/(?<=é)a/', 'expected' => 2];
        yield 'two-byte character under u' => ['pattern' => '/(?<=é)a/u', 'expected' => 1];
        // A group repeated zero times is empty, as PCRE2 10.43 and later
        // measure it (pcre2test 10.49: 1).
        yield 'group repeated zero times' => ['pattern' => '/(?<=(?:a|bc){0}d)x/', 'expected' => 1];
    }

    #[Test]
    #[DataProvider('provideBackslashC')]
    public function test_uses_backslash_c_is_true_for_the_one_code_unit_escape_only(string $pattern, bool $expected): void
    {
        $this->assertSame($expected, $this->info($pattern)->usesBackslashC);
    }

    /**
     * pcre2test "Contains \C".
     *
     * @return iterable<string, array{pattern: string, expected: bool}>
     */
    public static function provideBackslashC(): iterable
    {
        yield 'none' => ['pattern' => '/abc/', 'expected' => false];
        yield 'in a sequence' => ['pattern' => '/a\Cb/', 'expected' => true];
        yield 'in a lookbehind, without u' => ['pattern' => '/(?<=\C)a/', 'expected' => true];
        yield 'under (*UTF), which PHP compiles' => ['pattern' => '/(*UTF)a\Cb/', 'expected' => true];
        yield 'quoted' => ['pattern' => '/\Q\C\E/', 'expected' => false];
        yield 'escaped backslash then C' => ['pattern' => '/a\\\\Cb/', 'expected' => false];
        yield 'in a comment' => ['pattern' => '/a(?#\C)b/', 'expected' => false];
        yield 'in an x-mode comment' => ['pattern' => '/a#\C/x', 'expected' => false];
    }

    #[Test]
    #[DataProvider('provideLimits')]
    public function test_limits_are_the_pattern_requests_last_setting_wins(string $pattern, ?int $match, ?int $depth, ?int $heap): void
    {
        $info = $this->info($pattern);

        $this->assertSame($match, $info->matchLimit, 'match limit');
        $this->assertSame($depth, $info->depthLimit, 'depth limit');
        $this->assertSame($heap, $info->heapLimit, 'heap limit');
    }

    /**
     * pcre2test "Match limit", "Depth limit", "Heap limit".
     *
     * @return iterable<string, array{pattern: string, match: int|null, depth: int|null, heap: int|null}>
     */
    public static function provideLimits(): iterable
    {
        yield 'none' => ['pattern' => '/a/', 'match' => null, 'depth' => null, 'heap' => null];
        yield 'last match limit wins, lower' => ['pattern' => '/(*LIMIT_MATCH=100)(*LIMIT_MATCH=50)a/', 'match' => 50, 'depth' => null, 'heap' => null];
        yield 'last match limit wins, higher' => ['pattern' => '/(*LIMIT_MATCH=50)(*LIMIT_MATCH=100)a/', 'match' => 100, 'depth' => null, 'heap' => null];
        yield 'depth and heap' => ['pattern' => '/(*LIMIT_DEPTH=10)(*LIMIT_HEAP=20)a/', 'match' => null, 'depth' => 10, 'heap' => 20];
        yield 'LIMIT_RECURSION is the depth limit' => ['pattern' => '/(*LIMIT_RECURSION=7)a/', 'match' => null, 'depth' => 7, 'heap' => null];
        yield 'last of the two depth spellings wins' => ['pattern' => '/(*LIMIT_DEPTH=10)(*LIMIT_RECURSION=7)a/', 'match' => null, 'depth' => 7, 'heap' => null];
        yield 'zero is a limit, not none' => ['pattern' => '/(*LIMIT_MATCH=0)a/', 'match' => 0, 'depth' => null, 'heap' => null];
        yield 'last heap limit wins, to zero' => ['pattern' => '/(*LIMIT_HEAP=5)(*LIMIT_HEAP=0)a/', 'match' => null, 'depth' => null, 'heap' => 0];
        yield 'largest value PCRE2 reads' => ['pattern' => '/(*LIMIT_MATCH=4294967289)a/', 'match' => 4294967289, 'depth' => null, 'heap' => null];
    }

    #[Test]
    #[DataProvider('provideNewlines')]
    public function test_newline_is_the_convention_the_pattern_sets(string $pattern, ?string $expected): void
    {
        $newline = $this->info($pattern)->newline;

        if (null === $expected) {
            $this->assertNull($newline);

            return;
        }

        $this->assertInstanceOf(NewlineConvention::class, $newline);
        $this->assertSame($expected, $newline->value);
    }

    /**
     * pcre2test "Forced newline is ...".
     *
     * @return iterable<string, array{pattern: string, expected: string|null}>
     */
    public static function provideNewlines(): iterable
    {
        yield 'not set' => ['pattern' => '/a$/', 'expected' => null];
        yield 'CR' => ['pattern' => '/(*CR)a/', 'expected' => 'CR'];
        yield 'LF, the default, still set' => ['pattern' => '/(*LF)a/', 'expected' => 'LF'];
        yield 'CRLF' => ['pattern' => '/(*CRLF)a$/', 'expected' => 'CRLF'];
        yield 'ANY' => ['pattern' => '/(*ANY)a/', 'expected' => 'ANY'];
        yield 'ANYCRLF' => ['pattern' => '/(*ANYCRLF)a/', 'expected' => 'ANYCRLF'];
        yield 'NUL' => ['pattern' => '/(*NUL)a/', 'expected' => 'NUL'];
        yield 'last setting wins' => ['pattern' => '/(*CR)(*LF)a/', 'expected' => 'LF'];
        yield 'after other start verbs' => ['pattern' => '/(*UTF)(*UCP)(*NO_JIT)(*CRLF)a/', 'expected' => 'CRLF'];
    }

    #[Test]
    #[DataProvider('provideBsrs')]
    public function test_bsr_is_what_the_pattern_makes_r_match(string $pattern, ?string $expected): void
    {
        $bsr = $this->info($pattern)->bsr;

        if (null === $expected) {
            $this->assertNull($bsr);

            return;
        }

        $this->assertInstanceOf(BsrConvention::class, $bsr);
        $this->assertSame($expected, $bsr->value);
    }

    /**
     * pcre2test "\R matches ...".
     *
     * @return iterable<string, array{pattern: string, expected: string|null}>
     */
    public static function provideBsrs(): iterable
    {
        yield 'not set' => ['pattern' => '/\R/', 'expected' => null];
        yield 'ANYCRLF' => ['pattern' => '/(*BSR_ANYCRLF)\R/', 'expected' => 'ANYCRLF'];
        yield 'UNICODE' => ['pattern' => '/(*BSR_UNICODE)\R/', 'expected' => 'UNICODE'];
        yield 'last setting wins' => ['pattern' => '/(*BSR_ANYCRLF)(*BSR_UNICODE)a/', 'expected' => 'UNICODE'];
        yield 'beside a newline setting' => ['pattern' => '/(*CRLF)(*BSR_UNICODE)a$/', 'expected' => 'UNICODE'];
    }

    #[Test]
    public function test_conventions_are_backed_by_pcre2_names(): void
    {
        $newlines = array_map(static fn (NewlineConvention $case): string => $case->value, NewlineConvention::cases());
        $bsrs = array_map(static fn (BsrConvention $case): string => $case->value, BsrConvention::cases());
        sort($newlines);
        sort($bsrs);

        $this->assertSame(['ANY', 'ANYCRLF', 'CR', 'CRLF', 'LF', 'NUL'], $newlines);
        $this->assertSame(['ANYCRLF', 'UNICODE'], $bsrs);
    }

    #[Test]
    #[DataProvider('provideAnchoredStarts')]
    public function test_anchored_start_is_proven_from_each_top_level_alternative(string $pattern, bool $expected): void
    {
        $this->assertSame($expected, $this->info($pattern)->anchoredStart);
    }

    /**
     * pcre2test "anchored" among the options for every true row.
     *
     * @return iterable<string, array{pattern: string, expected: bool}>
     */
    public static function provideAnchoredStarts(): iterable
    {
        yield 'A modifier' => ['pattern' => '/a/A', 'expected' => true];
        yield '\A' => ['pattern' => '/\Aa/', 'expected' => true];
        yield '\G' => ['pattern' => '/\Ga/', 'expected' => true];
        yield 'caret without m' => ['pattern' => '/^a/', 'expected' => true];
        yield 'caret then a min-0 repeat' => ['pattern' => '/^a*/', 'expected' => true];
        yield 'every alternative anchored' => ['pattern' => '/^a|\Ab/', 'expected' => true];
        // Every attempt is tied to the search start; \K only moves where
        // $matches[0] starts.
        yield '\K after \A' => ['pattern' => '/\Aa\Kb/', 'expected' => true];
        yield 'inline option before the caret' => ['pattern' => '/(?i)^a/', 'expected' => true];
        yield 'limit verb before the caret' => ['pattern' => '/(*LIMIT_MATCH=10)^a/', 'expected' => true];
        yield 'x-mode whitespace before the caret' => ['pattern' => '/ ^a/x', 'expected' => true];
        yield 'm cancelled inline' => ['pattern' => '/(?-m)^a/m', 'expected' => true];
        yield 'comment before the anchor' => ['pattern' => '/(?#c)\Aa/', 'expected' => true];
        yield 'DEFINE group before the anchor' => ['pattern' => '/(?(DEFINE)(a))\Ab/', 'expected' => true];
        yield 'empty group after the anchor' => ['pattern' => '/\A(?:)a/', 'expected' => true];
        yield 'empty group after the anchor on an earlier alternative' => ['pattern' => '/(?:\A(?:)|\Ab)c/', 'expected' => true];
        yield 'option setting before the anchor inside a group' => ['pattern' => '/(?:(?i)\Aa)/', 'expected' => true];
        yield 'inside a capturing group' => ['pattern' => '/(\Aa)b/', 'expected' => true];
        yield 'inside an atomic group' => ['pattern' => '/(?>\Aa)b/', 'expected' => true];
        yield 'inside a group repeated at least once' => ['pattern' => '/(?:\Aa)+b/', 'expected' => true];

        yield 'no anchor' => ['pattern' => '/a/', 'expected' => false];
        yield 'empty pattern' => ['pattern' => '//', 'expected' => false];
        yield 'caret under m' => ['pattern' => '/^a/m', 'expected' => false];
        yield 'caret under inline m' => ['pattern' => '/(?m)^a/', 'expected' => false];
        yield 'one alternative unanchored' => ['pattern' => '/^a|b/', 'expected' => false];
        yield 'one alternative a multiline caret' => ['pattern' => '/(?m)^a|\Ab/', 'expected' => false];
        yield 'anchor under a min-0 repeat' => ['pattern' => '/(?:\Aa)?b/', 'expected' => false];
        yield '\G on one alternative only' => ['pattern' => '/\Ga|b/', 'expected' => false];
        // PCRE2 anchors ".*" under "s" itself (it reports "anchored"); not
        // modelled, and false stays sound. Documented.
        yield 'dot-star under s' => ['pattern' => '/.*a/s', 'expected' => false];
        // PCRE2 counts an empty group as an item before the anchor, whatever
        // the group: capturing or not, scoped options, an option setting
        // alone inside it (pcre2test reports none of these "anchored").
        yield 'empty group before the anchor' => ['pattern' => '/(?:)\Aa/', 'expected' => false];
        yield 'empty group before the anchor inside a group' => ['pattern' => '/(?:(?:)\Aa)/', 'expected' => false];
        yield 'group holding an option setting only' => ['pattern' => '/(?:(?i))\Aa/', 'expected' => false];
        yield 'empty capturing group' => ['pattern' => '/()\Aa/', 'expected' => false];
        yield 'group of two empty alternatives' => ['pattern' => '/(?:|)\Aa/', 'expected' => false];
        yield 'empty group with scoped options' => ['pattern' => '/(?i:)\Aa/', 'expected' => false];
        yield 'empty branch-reset group' => ['pattern' => '/(?|)\Aa/', 'expected' => false];
        yield 'empty group before the anchor alone' => ['pattern' => '/(?:)\A/', 'expected' => false];
        yield 'empty group on a later alternative' => ['pattern' => '/\Aa|(?:)\Ab/', 'expected' => false];
        yield 'empty group after a start option' => ['pattern' => '/(*UTF)(?:)\Aa/', 'expected' => false];
        // Under JIT, PHP's default, a (*SKIP) moves the next attempt past an
        // "A" modifier: /aa(*SKIP)b|a/A matches "aaa" at 2 (at no offset with
        // pcre.jit=0). Any (*SKIP), named or not, wherever it stands.
        yield 'A modifier and (*SKIP)' => ['pattern' => '/aa(*SKIP)b|a/A', 'expected' => false];
        yield 'A modifier and (*SKIP:name)' => ['pattern' => '/aa(*MARK:x)(*SKIP:x)b|a/A', 'expected' => false];
        yield 'A modifier and (*SKIP:name) with no such mark' => ['pattern' => '/aa(*SKIP:x)b|a/A', 'expected' => false];
        yield 'A modifier and (*SKIP) in a lookahead' => ['pattern' => '/(?=aa(*SKIP)b)|a/A', 'expected' => false];
        yield 'A modifier and (*SKIP) in an assertion read apart' => ['pattern' => '/(*pla:aa(*SKIP)b)|a/A', 'expected' => false];
        yield 'A modifier, \A on one alternative only, and (*SKIP)' => ['pattern' => '/\Aaa(*SKIP)b|a/A', 'expected' => false];
        // An anchor the next attempt cannot pass stays proven.
        yield 'A modifier and (*PRUNE)' => ['pattern' => '/aa(*PRUNE)b|a/A', 'expected' => true];
        yield 'A modifier and (*COMMIT)' => ['pattern' => '/aa(*COMMIT)b|a/A', 'expected' => true];
        yield 'A modifier and (*THEN)' => ['pattern' => '/aa(*THEN)b|a/A', 'expected' => true];
        yield '\A on every alternative and (*SKIP)' => ['pattern' => '/\Aaa(*SKIP)b|\Aa/', 'expected' => true];
        yield '\A on every alternative, A modifier and (*SKIP)' => ['pattern' => '/\Aaa(*SKIP)b|\Aa/A', 'expected' => true];
        yield 'caret on every alternative, A modifier and (*SKIP)' => ['pattern' => '/^aa(*SKIP)b|^a/A', 'expected' => true];
        yield '\G on every alternative, A modifier and (*SKIP)' => ['pattern' => '/\Gaa(*SKIP)b|\Ga/A', 'expected' => true];
    }

    /**
     * The JIT, PHP's default, starts the next attempt where a (*SKIP) points
     * even under "A"; the interpreter (pcre.jit=0) does not. The verdict
     * follows what a caller may observe: not proven.
     */
    #[Test]
    #[DataProvider('provideSkipPastTheAModifier')]
    public function test_a_skip_lets_a_jit_match_start_past_the_a_modifier(string $pattern, string $subject, int $offset): void
    {
        $this->assertFalse($this->info($pattern)->anchoredStart);

        $jit = ini_set('pcre.jit', '1');

        try {
            $found = preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE);
        } finally {
            ini_set('pcre.jit', false === $jit ? '1' : $jit);
        }

        if (true === \constant('PCRE_JIT_SUPPORT')) {
            $this->assertSame(1, $found);
            $this->assertSame($offset, $matches[0][1]);
        } else {
            $this->assertSame(0, $found);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, offset: int}>
     */
    public static function provideSkipPastTheAModifier(): iterable
    {
        yield '(*SKIP)' => ['pattern' => '/aa(*SKIP)b|a/A', 'subject' => 'aaa', 'offset' => 2];
        yield '(*SKIP:name)' => ['pattern' => '/aa(*MARK:x)(*SKIP:x)b|a/A', 'subject' => 'aaa', 'offset' => 2];
        yield '(*SKIP) in a lookahead' => ['pattern' => '/(?=aa(*SKIP)b)|a/A', 'subject' => 'aaa', 'offset' => 2];
        yield '\A on one alternative only' => ['pattern' => '/\Aaa(*SKIP)b|a/A', 'subject' => 'aaa', 'offset' => 2];
    }

    /**
     * An anchor on every alternative holds a (*SKIP) back: whatever the
     * offset the search starts at, a match starts there.
     */
    #[Test]
    #[DataProvider('provideSkipHeldByAnAnchor')]
    public function test_a_skip_does_not_move_a_match_past_an_anchor(string $pattern): void
    {
        $this->assertTrue($this->info($pattern)->anchoredStart);

        foreach (['aaa', 'xaaa', 'aab', 'xaab', 'xa'] as $subject) {
            for ($start = 0; $start <= \strlen($subject); $start++) {
                if (1 === preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE, $start)) {
                    $this->assertSame($start, $matches[0][1], \sprintf('%s on "%s" from %d', $pattern, $subject, $start));
                }
            }
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideSkipHeldByAnAnchor(): iterable
    {
        yield '\A' => ['pattern' => '/\Aaa(*SKIP)b|\Aa/A'];
        yield 'caret' => ['pattern' => '/^aa(*SKIP)b|^a/A'];
        yield '\G' => ['pattern' => '/\Gaa(*SKIP)b|\Ga/A'];
        yield '\G without A' => ['pattern' => '/\Gaa(*SKIP)b|\Ga/'];
        yield 'A and (*PRUNE)' => ['pattern' => '/aa(*PRUNE)b|a/A'];
        yield 'A and (*COMMIT)' => ['pattern' => '/aa(*COMMIT)b|a/A'];
    }

    #[Test]
    #[DataProvider('provideUnanchoredStartWitnesses')]
    public function test_anchored_start_false_rows_match_past_the_search_start(string $pattern, string $subject): void
    {
        $this->assertFalse($this->info($pattern)->anchoredStart);
        $this->assertSame(1, preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE));
        $this->assertGreaterThan(0, $matches[0][1], 'The match starts at the search start: the row proves nothing.');
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideUnanchoredStartWitnesses(): iterable
    {
        yield 'no anchor' => ['pattern' => '/a/', 'subject' => 'xa'];
        yield 'caret under m' => ['pattern' => '/^a/m', 'subject' => "x\na"];
        yield 'caret under inline m' => ['pattern' => '/(?m)^a/', 'subject' => "x\na"];
        yield 'one alternative unanchored' => ['pattern' => '/^a|b/', 'subject' => 'xb'];
        yield 'anchor under a min-0 repeat' => ['pattern' => '/(?:\Aa)?b/', 'subject' => 'xb'];
        yield '\G on one alternative only' => ['pattern' => '/\Ga|b/', 'subject' => 'xb'];
    }

    #[Test]
    #[DataProvider('provideAnchoredEnds')]
    public function test_anchored_end_is_proven_from_the_last_item_of_each_alternative(string $pattern, bool $expected): void
    {
        $this->assertSame($expected, $this->info($pattern)->anchoredEnd);
    }

    /**
     * @return iterable<string, array{pattern: string, expected: bool}>
     */
    public static function provideAnchoredEnds(): iterable
    {
        yield '\z' => ['pattern' => '/a\z/', 'expected' => true];
        yield 'dollar under D' => ['pattern' => '/a$/D', 'expected' => true];
        yield 'every alternative anchored' => ['pattern' => '/a\z|b\z/', 'expected' => true];
        yield 'one alternative ending in a group' => ['pattern' => '/(?:a|b)\z/', 'expected' => true];
        yield 'm cancelled inline' => ['pattern' => '/(?-m)a$/Dm', 'expected' => true];
        yield 'x-mode whitespace after \z' => ['pattern' => '/a\z /x', 'expected' => true];
        yield 'inside a capturing group' => ['pattern' => '/a(b\z)/', 'expected' => true];
        yield 'inside an atomic group' => ['pattern' => '/a(?>b\z)/', 'expected' => true];
        yield 'inside a group repeated at least once' => ['pattern' => '/a(?:b\z)+/', 'expected' => true];

        yield 'no anchor' => ['pattern' => '/a/', 'expected' => false];
        yield 'empty pattern' => ['pattern' => '//', 'expected' => false];
        yield 'dollar without D' => ['pattern' => '/a$/', 'expected' => false];
        yield 'dollar under D and m' => ['pattern' => '/a$/Dm', 'expected' => false];
        yield 'dollar under D and inline m' => ['pattern' => '/a(?m)$/D', 'expected' => false];
        yield '\Z' => ['pattern' => '/a\Z/', 'expected' => false];
        yield 'under a min-0 repeat' => ['pattern' => '/a(?:\z)?/', 'expected' => false];
        yield 'inside an assertion' => ['pattern' => '/a(?=b\z)/', 'expected' => false];
        yield 'reachable ACCEPT' => ['pattern' => '/a(*ACCEPT)b\z/', 'expected' => false];
        yield 'one alternative unanchored' => ['pattern' => '/a\z|b/', 'expected' => false];
        yield 'a repeat without the anchor' => ['pattern' => '/ab+/', 'expected' => false];
    }

    #[Test]
    #[DataProvider('provideUnanchoredEndWitnesses')]
    public function test_anchored_end_false_rows_end_before_the_subject_end(string $pattern, string $subject): void
    {
        $this->assertFalse($this->info($pattern)->anchoredEnd);
        $this->assertSame(1, preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE));
        $this->assertLessThan(\strlen($subject), $matches[0][1] + \strlen($matches[0][0]), 'The match ends at the subject end: the row proves nothing.');
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideUnanchoredEndWitnesses(): iterable
    {
        yield 'dollar without D' => ['pattern' => '/a$/', 'subject' => "a\n"];
        yield 'dollar under D and m' => ['pattern' => '/a$/Dm', 'subject' => "a\nb"];
        yield 'dollar under D and inline m' => ['pattern' => '/a(?m)$/D', 'subject' => "a\nb"];
        yield '\Z' => ['pattern' => '/a\Z/', 'subject' => "a\n"];
        yield 'under a min-0 repeat' => ['pattern' => '/a(?:\z)?/', 'subject' => 'ab'];
        yield 'inside an assertion' => ['pattern' => '/a(?=b\z)/', 'subject' => 'ab'];
        yield 'reachable ACCEPT' => ['pattern' => '/a(*ACCEPT)b\z/', 'subject' => 'ac'];
        yield 'one alternative unanchored' => ['pattern' => '/a\z|b/', 'subject' => 'bc'];
    }

    #[Test]
    #[DataProvider('provideAnchoredEndSubjects')]
    public function test_anchored_end_true_rows_end_at_the_subject_end(string $pattern, string $subject): void
    {
        $this->assertTrue($this->info($pattern)->anchoredEnd);
        $this->assertSame(1, preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE));
        $this->assertSame(\strlen($subject), $matches[0][1] + \strlen($matches[0][0]));
    }

    /**
     * Each subject would let an unanchored version of the row end earlier.
     *
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideAnchoredEndSubjects(): iterable
    {
        yield '\z' => ['pattern' => '/a\z/', 'subject' => 'aa'];
        yield 'dollar under D, no match before the final newline' => ['pattern' => '/a$/D', 'subject' => "a\na"];
        yield 'm cancelled inline' => ['pattern' => '/(?-m)a$/Dm', 'subject' => "a\na"];
        yield 'every alternative anchored' => ['pattern' => '/a\z|b\z/', 'subject' => 'abb'];
    }

    #[Test]
    public function test_analyzer_reads_a_tree_it_did_not_parse_itself(): void
    {
        // One analyzer, two trees: no fact from the first leaks into the second.
        $analyzer = new PatternInfoAnalyzer();
        $parser = RegexParser::create(self::TARGET);

        $first = $analyzer->analyze($parser->parse('/(*LIMIT_MATCH=5)(*CR)(a)\1\C/A'));
        $second = $analyzer->analyze($parser->parse('/b/'));

        $this->assertSame(5, $first->matchLimit);
        $this->assertSame(0, $second->captureCount);
        $this->assertSame(0, $second->maxBackreference);
        $this->assertNull($second->matchLimit);
        $this->assertNull($second->newline);
        $this->assertFalse($second->usesBackslashC);
        $this->assertFalse($second->anchoredStart);
    }

    private function info(string $pattern): PatternInfo
    {
        return (new PatternInfoAnalyzer())->analyze(RegexParser::create(self::TARGET)->parse($pattern));
    }
}
