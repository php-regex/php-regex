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
    }

    private static function parser(string $release): RegexParser
    {
        return RegexParser::create(['php_version' => PcreTarget::bundledWith(80000)->phpVersionId, 'pcre_version' => $release, 'runtime_pcre_validation' => false]);
    }
}
