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
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where PCRE2 ends the measure of a lookbehind, each row as pcre2test 10.32
 * to 10.49 compile it: null where the release compiles the pattern, else the
 * offset of its error, "lookbehind assertion is not fixed length" up to
 * 10.42 and "length of lookbehind assertion is not limited" from 10.43.
 *
 * Up to 10.42 a repeat of variable count has no fixed length whatever it
 * repeats, an empty group or an assertion included, and the measure ends at
 * the first item of no fixed length: a lookbehind after it is never
 * measured. On every release "(*ACCEPT)" and "(*FAIL)" end the branch they
 * stand in: what follows them there is never measured. A condition with no
 * "|" is measured as its one branch.
 */
final class LookbehindMeasureEndTest extends TestCase
{
    /**
     * @param array<string, int|null> $outcomes
     */
    #[Test]
    #[DataProvider('provideMeasures')]
    public function test_a_lookbehind_is_measured_up_to_where_pcre2_ends_the_measure(string $pattern, array $outcomes): void
    {
        foreach ($outcomes as $release => $offset) {
            $validation = self::parser($release)->validate($pattern);
            if (null === $offset) {
                $this->assertTrue($validation->isValid, \sprintf('%s on PCRE2 %s: %s', $pattern, $release, $validation->error ?? ''));

                continue;
            }

            $this->assertFalse($validation->isValid, \sprintf('%s on PCRE2 %s', $pattern, $release));
            $this->assertSame($offset, $validation->offset, \sprintf('%s on PCRE2 %s: %s', $pattern, $release, $validation->error ?? ''));
            $this->assertContains($validation->errorCode, [ErrorCode::LookbehindUnbounded, ErrorCode::LookbehindVariableLengthNotSupported], $pattern.' on PCRE2 '.$release);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, outcomes: array<string, int|null>}>
     */
    public static function provideMeasures(): iterable
    {
        yield 'optional empty group' => ['pattern' => '/(?<=(?:)?)b/', 'outcomes' => ['10.32' => 0, '10.35' => 0, '10.42' => 0, '10.43' => null, '10.49' => null]];
        yield 'optional empty capture' => ['pattern' => '/(?<=()?)b/', 'outcomes' => ['10.32' => 0, '10.42' => 0, '10.43' => null]];
        yield 'empty group counted once or twice' => ['pattern' => '/(?<=(?:){1,2})b/', 'outcomes' => ['10.32' => 0, '10.42' => 0, '10.43' => null]];
        yield 'optional lookbehind' => ['pattern' => '/(?<=(?<=a)?)b/', 'outcomes' => ['10.32' => 0, '10.42' => 0, '10.43' => null]];
        yield 'optional group holding a lookahead' => ['pattern' => '/(?<=(?:(?=a))?)b/', 'outcomes' => ['10.32' => 0, '10.42' => 0, '10.43' => null]];
        yield 'optional DEFINE group' => ['pattern' => '/(?<=(?(DEFINE)a)?)b/', 'outcomes' => ['10.32' => 0, '10.35' => 0, '10.42' => 0, '10.43' => null]];
        yield 'lazy optional group holding an anchor' => ['pattern' => '/(?<=(?:^)??)b/', 'outcomes' => ['10.33' => 0, '10.42' => 0, '10.43' => null]];
        yield 'optional reference to an empty group' => ['pattern' => '/(?<=()\1?)b/', 'outcomes' => ['10.32' => 0, '10.42' => 0, '10.43' => null]];
        yield 'call to an empty group counted once or twice' => ['pattern' => '/()(?<=(?1){1,2})b/', 'outcomes' => ['10.32' => 2, '10.42' => 2, '10.43' => null]];
        yield 'optional accept' => ['pattern' => '/(?<=(*ACCEPT)?)b/', 'outcomes' => ['10.34' => 0, '10.42' => 0, '10.43' => null]];
        yield 'lazy optional accept with a name' => ['pattern' => '/(?<=a(*ACCEPT:x)??)b/', 'outcomes' => ['10.34' => 0, '10.42' => 0, '10.43' => null]];
        // Up to 10.34 the end of a DEFINE group that opens with a group ends
        // the lookbehind: what repeats it is not measured.
        yield 'optional DEFINE group opened by an empty group' => ['pattern' => '/(?<=(?(DEFINE)())??)/', 'outcomes' => ['10.32' => null, '10.34' => null, '10.35' => 0]];
        yield 'optional DEFINE group opened by an option setting' => ['pattern' => '/(?<=(?(DEFINE)(?i))??)/', 'outcomes' => ['10.32' => 0, '10.34' => 0, '10.35' => 0]];
        // A lookahead repeated directly is measured as if not repeated.
        yield 'optional lookahead' => ['pattern' => '/(?<=(?=a)?)b/', 'outcomes' => ['10.32' => null, '10.42' => null, '10.43' => null]];
        yield 'alphabetic lookahead counted once or twice' => ['pattern' => '/(?<=(*pla:a){1,2})b/', 'outcomes' => ['10.33' => null, '10.42' => null, '10.49' => null]];
        // Up to 10.42 the measure ends on the outer lookbehind at the first
        // item of no fixed length; from 10.43 it goes on to the inner one.
        yield 'optional character before an unbounded lookbehind' => ['pattern' => '/(?<=xa?(?<=b+))/', 'outcomes' => ['10.32' => 0, '10.42' => 0, '10.43' => 7, '10.49' => 7]];
        yield 'group of unequal branches before an unbounded lookbehind' => ['pattern' => '/(?<=x(?:a|bc)(?<=b+))/', 'outcomes' => ['10.32' => 0, '10.42' => 0, '10.43' => 13]];
        yield 'optional empty group before an unbounded lookbehind' => ['pattern' => '/(?<=x(?:)?(?<=b+))/', 'outcomes' => ['10.32' => 0, '10.42' => 0, '10.43' => 10]];
        yield 'branch of no fixed length before a branch holding an unbounded lookbehind' => ['pattern' => '/(?<=a?|(?<=b+))/', 'outcomes' => ['10.32' => 0, '10.42' => 0, '10.43' => 7]];
        // "(*ACCEPT)" and "(*FAIL)" end the branch they stand in.
        yield 'repeat after an accept' => ['pattern' => '/(?<=a(*ACCEPT)b+)/', 'outcomes' => ['10.32' => null, '10.42' => null, '10.43' => null, '10.49' => null]];
        yield 'repeat after a fail' => ['pattern' => '/(?<=a(*FAIL)b+)/', 'outcomes' => ['10.32' => null, '10.42' => null, '10.49' => null]];
        yield 'repeat after a fail with a name' => ['pattern' => '/(?<=a(*F:x)b+)/', 'outcomes' => ['10.32' => null, '10.49' => null]];
        yield 'repeat after an accept in a group of equal branches' => ['pattern' => '/(?<=(?:a(*ACCEPT)b+|c))/', 'outcomes' => ['10.32' => null, '10.42' => null, '10.49' => null]];
        yield 'repeat after an accept in a group of unequal branches' => ['pattern' => '/(?<=(?:a(*ACCEPT)b+|cd))/', 'outcomes' => ['10.32' => 0, '10.42' => 0, '10.43' => null, '10.49' => null]];
        yield 'repeat after a commit' => ['pattern' => '/(?<=a(*COMMIT)b+)/', 'outcomes' => ['10.32' => 0, '10.42' => 0, '10.43' => 0]];
        // A condition with no "|" is a group of one branch.
        yield 'conditions of one branch within the budget' => ['pattern' => '/(a)(?<='.str_repeat('(?(1)a)', 2000).')/', 'outcomes' => ['10.32' => null, '10.42' => null, '10.49' => null]];
        yield 'conditions of two branches within the budget' => ['pattern' => '/(a)(?<='.str_repeat('(?(1)a|b)', 999).')/', 'outcomes' => ['10.32' => null, '10.43' => null, '10.49' => null]];
        yield 'condition of one branch' => ['pattern' => '/(a)(?<=(?(1)b))/', 'outcomes' => ['10.32' => null, '10.42' => null, '10.43' => null, '10.49' => null]];
        yield 'condition of one long branch' => ['pattern' => '/(a)(?<=(?(1)b{256}))/', 'outcomes' => ['10.42' => null, '10.43' => null, '10.49' => null]];
        yield 'assertion condition of one long branch' => ['pattern' => '/(?<=(?(?=a)b{300}))/', 'outcomes' => ['10.32' => null, '10.49' => null]];
        yield 'condition of one branch and a space under x' => ['pattern' => '/(a)(?<=(?(1)b )c)/x', 'outcomes' => ['10.42' => null, '10.49' => null]];
        yield 'condition of one branch before an unbounded lookbehind' => ['pattern' => '/(a)(?<=(?(1)b)(?<=c+))/', 'outcomes' => ['10.32' => 14, '10.42' => 14, '10.43' => 14]];
        yield 'condition of an empty second branch' => ['pattern' => '/(a)(?<=(?(1)b|))/', 'outcomes' => ['10.32' => 3, '10.42' => 3, '10.43' => null]];
        yield 'condition of an empty second branch and a space under x' => ['pattern' => '/(a)(?<=(?(1)b| )c)/x', 'outcomes' => ['10.42' => 3, '10.43' => null]];
        yield 'condition of an empty first branch and a space under x' => ['pattern' => '/(a)(?<=(?(1) |b)c)/x', 'outcomes' => ['10.42' => 3, '10.43' => null]];
        // A lookbehind inside another is measured only where the measure of
        // the outer one reaches it.
        yield 'unbounded lookbehind after an accept' => ['pattern' => '/(?<=a(*ACCEPT)(?<=b+))/', 'outcomes' => ['10.32' => null, '10.42' => null, '10.49' => null]];
        yield 'unbounded lookbehind after an accept in the second branch' => ['pattern' => '/(?<=a|(*ACCEPT)(?<=b+))/', 'outcomes' => ['10.32' => null, '10.49' => null]];
        yield 'lookahead holding an unbounded lookbehind after an accept' => ['pattern' => '/(?<=a(*ACCEPT)(?=(?<=b+)))/', 'outcomes' => ['10.33' => null, '10.34' => null, '10.49' => null]];
        yield 'unbounded lookbehind in a DEFINE group' => ['pattern' => '/(?<=(?(DEFINE)(?<=b+))c)/', 'outcomes' => ['10.32' => 0, '10.34' => 0, '10.35' => null, '10.49' => null]];
        yield 'unbounded lookbehind in a script run' => ['pattern' => '/(?<=(*sr:(?<=b+)))/', 'outcomes' => ['10.33' => 9, '10.42' => 9, '10.49' => 9]];
        yield 'unbounded lookbehind in a script run in a lookahead' => ['pattern' => '/(?<=(?=(*sr:(?<=b+))))/', 'outcomes' => ['10.33' => null, '10.34' => 12, '10.49' => 12]];
        yield 'unbounded lookbehind after an accept in a lookahead' => ['pattern' => '/(?<=(?=a(*ACCEPT)(?<=b+)))/', 'outcomes' => ['10.34' => 17, '10.49' => 17]];
        yield 'unbounded lookbehind in a group a lookbehind calls' => ['pattern' => '/(?<=(?1))(a(*ACCEPT)(?<=b+))/', 'outcomes' => ['10.32' => 20, '10.42' => 20, '10.49' => 20]];
        // A group of no fixed length ends the measure up to 10.42 before
        // its length is added to the branch.
        yield 'group of no fixed length after an item at the limit' => ['pattern' => '/(?<=a{65535}(?:b?))/', 'outcomes' => ['10.32' => 0, '10.34' => 0, '10.42' => 0]];
        // Up to 10.33 the length of a branch wraps around past 2^31 instead
        // of passing the limit: a branch that ends below zero has no fixed
        // length, and the measure goes on past one that wrapped.
        yield 'branch that wraps below zero' => ['pattern' => '/(?<=(?:a{65535}){65535})/', 'outcomes' => ['10.32' => 0, '10.33' => 0]];
        yield 'branch that wraps below zero before an empty repeat' => ['pattern' => '/(?<=(?:a{65535}){65535}b{0})/', 'outcomes' => ['10.32' => 0, '10.33' => 0]];
        yield 'group branch that wraps below zero' => ['pattern' => '/(?<=(?:(?:a{65535}){65535})x)/', 'outcomes' => ['10.32' => 0, '10.33' => 0]];
        yield 'unbounded lookbehind after a branch that wrapped' => ['pattern' => '/(?<=(?:a{40000}){65534}(?<=c+))/', 'outcomes' => ['10.32' => 23, '10.33' => 23]];
        // Up to 10.34 the first byte of a character of several bytes that
        // opens a DEFINE group is skipped, and the repeat of the last one
        // has no fixed length.
        yield 'DEFINE group opened by an optional two-byte character' => ['pattern' => '/(?<=(?(DEFINE)é?a{65535}b)c?)/', 'outcomes' => ['10.32' => 0, '10.34' => 0]];
        yield 'counted lookbehind after a condition that wrapped' => ['pattern' => '/(?<=(?(?=x)a{40000}bc){65534}((?<=a{0}.?){1}bc{0}\\d{0}){1,2})(?<=c+)/', 'outcomes' => ['10.32' => 30, '10.33' => 30]];
    }

    /**
     * PCRE refuses a branch too long, or one past the budget of branches it
     * measures, as it measures it: before a later branch of no fixed length
     * or no bound.
     *
     * @param array<string, int> $offsets
     */
    #[Test]
    #[DataProvider('provideRefusalsMetFirst')]
    public function test_a_refusal_met_in_an_earlier_branch_comes_first(string $pattern, array $offsets, ErrorCode $code): void
    {
        foreach ($offsets as $release => $offset) {
            $validation = self::parser($release)->validate($pattern);

            $this->assertSame($offset, $validation->offset, \sprintf('%s on PCRE2 %s: %s', $pattern, $release, $validation->error ?? ''));
            $this->assertSame($code, $validation->errorCode, \sprintf('%s on PCRE2 %s: %s', $pattern, $release, $validation->error ?? ''));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, offsets: array<string, int>, code: ErrorCode}>
     */
    public static function provideRefusalsMetFirst(): iterable
    {
        yield 'branch too long before an optional one' => ['pattern' => '/(?<=a{65535}a{2}|b?)/', 'offsets' => ['10.32' => 0, '10.42' => 0, '10.43' => 0], 'code' => ErrorCode::LookbehindTooLong];
        yield 'branch too long before a counted one' => ['pattern' => '/(?<=a{40000}a{40000}|b{1,2})/', 'offsets' => ['10.42' => 0, '10.49' => 0], 'code' => ErrorCode::LookbehindTooLong];
        yield 'branch too long before an unbounded one' => ['pattern' => '/(?<=a{65535}a{2}|b+)/', 'offsets' => ['10.32' => 0, '10.42' => 0, '10.43' => 0, '10.49' => 0], 'code' => ErrorCode::LookbehindTooLong];
        // Each item is added to the length of its branch before the repeat
        // after it is read, and the branch is refused past 65535 there: a
        // repeat of variable count, or none at all, comes too late.
        yield 'item past the limit before an optional repeat' => ['pattern' => '/(?<=a{65535}b?)/', 'offsets' => ['10.32' => 0, '10.34' => 0, '10.42' => 0, '10.43' => 0, '10.49' => 0], 'code' => ErrorCode::LookbehindTooLong];
        yield 'item past the limit before a lazy optional repeat' => ['pattern' => '/(?<=a{65535}b??)/', 'offsets' => ['10.32' => 0, '10.42' => 0, '10.49' => 0], 'code' => ErrorCode::LookbehindTooLong];
        yield 'item past the limit before a counted range' => ['pattern' => '/(?<=a{65535}b{1,2})/', 'offsets' => ['10.32' => 0, '10.42' => 0, '10.43' => 0], 'code' => ErrorCode::LookbehindTooLong];
        yield 'item past the limit before an unbounded repeat' => ['pattern' => '/(?<=a{65535}b*)/', 'offsets' => ['10.32' => 0, '10.42' => 0, '10.43' => 0, '10.49' => 0], 'code' => ErrorCode::LookbehindTooLong];
        yield 'item past the limit repeated zero times' => ['pattern' => '/(?<=a{65535}b{0})/', 'offsets' => ['10.32' => 0, '10.42' => 0, '10.43' => 0, '10.49' => 0], 'code' => ErrorCode::LookbehindTooLong];
        yield 'group of two branches past the limit repeated zero times' => ['pattern' => '/(?<=a{65535}(?:b|c){0})/', 'offsets' => ['10.32' => 0, '10.49' => 0], 'code' => ErrorCode::LookbehindTooLong];
        yield 'group past the limit repeated zero times' => ['pattern' => '/(?<=x{32768}(?:a{32768}){0})/', 'offsets' => ['10.32' => 0, '10.42' => 0, '10.43' => 0, '10.49' => 0], 'code' => ErrorCode::LookbehindTooLong];
        yield 'branch of a group past the limit, the group repeated zero times' => ['pattern' => '/(?<=(?:a{65535}b){0})/', 'offsets' => ['10.32' => 0, '10.42' => 0, '10.43' => 0, '10.49' => 0], 'code' => ErrorCode::LookbehindTooLong];
        yield 'reference past the limit repeated zero times' => ['pattern' => '/(?<=(a{65535})\\1{0})/', 'offsets' => ['10.32' => 0, '10.42' => 0, '10.43' => 0, '10.49' => 0], 'code' => ErrorCode::LookbehindTooLong];
        yield 'item past the limit in a later lookbehind' => ['pattern' => '/x(?<=a{65535}b{0})/', 'offsets' => ['10.32' => 1, '10.49' => 1], 'code' => ErrorCode::LookbehindTooLong];
        yield 'item past the limit before an unbounded lookbehind' => ['pattern' => '/(?<=a{65535}b(?<=c+))/', 'offsets' => ['10.32' => 0, '10.42' => 0, '10.43' => 0, '10.49' => 0], 'code' => ErrorCode::LookbehindTooLong];
        yield 'item past the limit before an unbounded branch' => ['pattern' => '/(?<=a{65535}b?|c+)/', 'offsets' => ['10.32' => 0, '10.42' => 0, '10.43' => 0, '10.49' => 0], 'code' => ErrorCode::LookbehindTooLong];
        yield 'optional group past the limit before an unbounded branch' => ['pattern' => '/(?<=a{30000}(?:b{40000})?|c+)/', 'offsets' => ['10.32' => 0, '10.42' => 0, '10.43' => 0, '10.49' => 0], 'code' => ErrorCode::LookbehindTooLong];
        yield 'group repeated zero times past the limit before an unbounded branch' => ['pattern' => '/(?<=(?:a{30000}b{40000}){0}|c+)/', 'offsets' => ['10.32' => 0, '10.43' => 0], 'code' => ErrorCode::LookbehindTooLong];
        yield 'counted group past the limit in a branch of a group' => ['pattern' => '/(?<=(?:(?:a{40000}){2}|b))/', 'offsets' => ['10.32' => 0, '10.42' => 0, '10.43' => 0], 'code' => ErrorCode::LookbehindTooLong];
        yield 'item past the limit in a condition' => ['pattern' => '/(?<=(?(?=x)a{65535}b?))/', 'offsets' => ['10.32' => 0, '10.42' => 0, '10.43' => 0], 'code' => ErrorCode::LookbehindTooLong];
        yield 'item past the limit in a nested lookbehind' => ['pattern' => '/(?<=x(?<=a{65535}b?))/', 'offsets' => ['10.32' => 5, '10.42' => 5, '10.49' => 5], 'code' => ErrorCode::LookbehindTooLong];
        yield 'item past the limit in a negative lookbehind' => ['pattern' => '/(?<!a{65535}b?)/', 'offsets' => ['10.32' => 0, '10.42' => 0], 'code' => ErrorCode::LookbehindTooLong];
        yield 'item past the limit in an alphabetic lookbehind' => ['pattern' => '/(*plb:a{65535}b?)/', 'offsets' => ['10.33' => 2, '10.42' => 2, '10.49' => 2], 'code' => ErrorCode::LookbehindTooLong];
        yield 'branch past a 32-bit length' => ['pattern' => '/(?<=(?:a{65535}){65535})/', 'offsets' => ['10.34' => 0, '10.49' => 0], 'code' => ErrorCode::LookbehindTooLong];
        yield 'repeat past a 32-bit length before an unbounded lookbehind' => ['pattern' => '/(?<=(?:a{40000}){65534}(?<=c+))/', 'offsets' => ['10.34' => 0, '10.42' => 0, '10.49' => 0], 'code' => ErrorCode::LookbehindTooLong];
        // Without u a character of several bytes is that many items, the
        // repeat taking the last: its first bytes count before the repeat.
        yield 'two-byte character repeated zero times past the limit' => ['pattern' => '/(?<=a{65534}é{0})/', 'offsets' => ['10.32' => 0, '10.42' => 0, '10.43' => 0, '10.49' => 0], 'code' => ErrorCode::LookbehindTooLong];
        yield 'three-byte character repeated zero times past the limit' => ['pattern' => '/(?<=a{65533}€{0})/', 'offsets' => ['10.32' => 0, '10.49' => 0], 'code' => ErrorCode::LookbehindTooLong];
        yield 'octal escape and a digit repeated zero times past the limit' => ['pattern' => '/(?<=a{65534}\\1000{0})/', 'offsets' => ['10.32' => 0, '10.49' => 0], 'code' => ErrorCode::LookbehindTooLong];
        yield 'optional two-byte character past the limit' => ['pattern' => '/(?<=a{65534}é?)/', 'offsets' => ['10.32' => 0, '10.42' => 0, '10.43' => 0], 'code' => ErrorCode::LookbehindTooLong];
        yield 'character past the limit in UTF mode' => ['pattern' => '/(?<=\\x{100}{65535}b?)/u', 'offsets' => ['10.32' => 0, '10.42' => 0, '10.43' => 0], 'code' => ErrorCode::LookbehindTooLong];
        // Each branch of a condition is measured, and counted.
        yield 'conditions of one branch past the budget' => ['pattern' => '/(a)(?<='.str_repeat('(?(1)a)', 2001).')/', 'offsets' => ['10.32' => 3, '10.42' => 3, '10.43' => 3, '10.49' => 3], 'code' => ErrorCode::LookbehindTooComplex];
        yield 'conditions of two branches past the budget' => ['pattern' => '/(a)(?<='.str_repeat('(?(1)a|b)', 1001).')/', 'offsets' => ['10.32' => 3, '10.43' => 3, '10.49' => 3], 'code' => ErrorCode::LookbehindTooComplex];
    }

    private static function parser(string $release): RegexParser
    {
        return RegexParser::create(['php_version' => PcreTarget::bundledWith(80000)->phpVersionId, 'pcre_version' => $release, 'runtime_pcre_validation' => false]);
    }
}
