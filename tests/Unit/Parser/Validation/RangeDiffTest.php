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

namespace PHPRegex\Tests\Unit\Parser\Validation;

use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\RangeDiff;
use PHPRegex\Parser\Validation\TargetVerdict;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Whether a pattern changes between two PHP versions: refused by a later
 * one, read into another tree by a later one, and which releases of the
 * range are judged with another release's rules.
 */
final class RangeDiffTest extends TestCase
{
    use PhpUnitForwardCompat;

    #[Test]
    public function test_the_range_holds_every_boundary_from_the_first_php_to_the_last(): void
    {
        $targets = RangeDiff::between(70400, 80300)->targets();

        $this->assertSame(
            [[70400, '10.33'], [80000, '10.35'], [80100, '10.39'], [80200, '10.40'], [80300, '10.42']],
            array_map(static fn (PcreTarget $target): array => [$target->phpVersionId, $target->pcreVersion], $targets),
        );
    }

    #[Test]
    public function test_the_range_starts_on_a_patch_release_and_stops_at_the_last_boundary_reached(): void
    {
        // PHP 8.6 changes no verdict 8.5.10 does not: it adds no point.
        $targets = RangeDiff::between(80426, 80600)->targets();

        $this->assertSame([80426, 80500, 80510], array_map(static fn (PcreTarget $target): int => $target->phpVersionId, $targets));
    }

    #[Test]
    public function test_the_first_and_the_last_parser_of_the_range_are_named(): void
    {
        $range = RangeDiff::between(70300, 80510);

        $this->assertSame([70300, '10.32'], [$range->from()->target()->phpVersionId, $range->from()->target()->pcreVersion]);
        $this->assertSame([80510, '10.44'], [$range->to()->target()->phpVersionId, $range->to()->target()->pcreVersion]);
        $single = RangeDiff::between(80300, 80300);
        $this->assertSame($single->from(), $single->to());
    }

    #[Test]
    public function test_one_php_is_a_range_of_one(): void
    {
        $verdict = RangeDiff::between(80300, 80300)->diff('/a{,3}/');

        $this->assertSame([80300], array_map(static fn (PcreTarget $target): int => $target->phpVersionId, RangeDiff::between(80300, 80300)->targets()));
        $this->assertSame([], $verdict->later);
        $this->assertNull($verdict->readsOtherwise);
        $this->assertNull($verdict->refusedBy());
    }

    #[Test]
    public function test_a_range_that_ends_before_it_starts_is_refused(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains('A range of PHP versions ends at or after its start, not at 8.2 from 8.4.');

        RangeDiff::between(80400, 80200);
    }

    #[Test]
    public function test_a_range_that_ends_before_it_starts_names_the_patch_releases(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains('A range of PHP versions ends at or after its start, not at 8.4.25 from 8.4.26.');

        RangeDiff::between(80426, 80425);
    }

    #[Test]
    public function test_a_pattern_read_alike_everywhere_differs_nowhere(): void
    {
        $verdict = RangeDiff::between(80200, 80510)->diff('/^[a-z]+$/');

        $this->assertTrue($verdict->from->validation->isValid);
        $this->assertSame(80200, $verdict->from->target->phpVersionId);
        $this->assertCount(5, $verdict->later);
        $this->assertNull($verdict->refusedBy());
        $this->assertNull($verdict->readsOtherwise);
        $this->assertSame([], $verdict->judgedWith);
        $this->assertNull($verdict->notice());
    }

    /**
     * Compiles on PHP 8.4.26 (preg_match() returns 1); PHP 8.5 compiles
     * without PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK, and PCRE2 then refuses it
     * (pcre2test 10.49: error 199).
     */
    #[Test]
    public function test_a_pattern_a_later_php_refuses_is_refused_by_the_lowest_one(): void
    {
        $verdict = RangeDiff::between(80200, 80510)->diff('/(?<=a\Kb)c/');

        $this->assertTrue($verdict->from->validation->isValid);
        $refusal = $verdict->refusedBy();
        $this->assertInstanceOf(TargetVerdict::class, $refusal);
        $this->assertSame(80500, $refusal->target->phpVersionId);
        $this->assertFalse($refusal->validation->isValid);
        $this->assertSame(
            [true, true, true, false, false],
            array_map(static fn ($later): bool => $later->validation->isValid, $verdict->later),
        );
        // A refused pattern is not compared tree by tree.
        $this->assertNull($verdict->readsOtherwise);
    }

    /**
     * "a{,3}" is text before PCRE2 10.43 and a quantifier from it:
     * preg_match('/a{,3}/', 'a{,3}') is 1 on PHP 8.2, and on PHP 8.4 the
     * match is "a".
     */
    #[Test]
    public function test_a_pattern_a_later_release_reads_otherwise_names_the_lowest_one(): void
    {
        $verdict = RangeDiff::between(80200, 80510)->diff('/a{,3}/');

        $this->assertNull($verdict->refusedBy());
        $this->assertInstanceOf(PcreTarget::class, $verdict->readsOtherwise);
        $this->assertSame(80400, $verdict->readsOtherwise->phpVersionId);
        $this->assertSame('10.44', $verdict->readsOtherwise->pcreVersion);
    }

    #[Test]
    public function test_a_pattern_the_first_php_refuses_is_neither_refused_later_nor_compared(): void
    {
        // "(?a)" arrived in PCRE2 10.43: PHP 8.2's 10.40 refuses it.
        $verdict = RangeDiff::between(80200, 80510)->diff('/(?a)x/');

        $this->assertFalse($verdict->from->validation->isValid);
        $this->assertNull($verdict->refusedBy());
        $this->assertNull($verdict->readsOtherwise);
    }

    #[Test]
    public function test_the_trees_are_not_compared_when_asked_not_to(): void
    {
        $verdict = RangeDiff::between(80200, 80510)->diff('/a{,3}/', compareTrees: false);

        $this->assertNull($verdict->readsOtherwise);
    }

    /**
     * @param array<string, string> $judgedWith
     */
    #[Test]
    #[DataProvider('provideOldRanges')]
    public function test_a_range_below_10_39_names_the_rules_it_is_judged_with(int $from, int $to, array $judgedWith, string $notice): void
    {
        $verdict = RangeDiff::between($from, $to)->diff('/a/');

        $this->assertSame($judgedWith, $verdict->judgedWith);
        $this->assertSame($notice, $verdict->notice());
    }

    /**
     * @return iterable<string, array{from: int, to: int, judgedWith: array<string, string>, notice: string}>
     */
    public static function provideOldRanges(): iterable
    {
        yield 'PHP 7.3 alone' => [
            'from' => 70300,
            'to' => 70300,
            'judgedWith' => ['10.32' => '10.39'],
            'notice' => 'PCRE2 10.32 is judged with the PCRE2 10.39 rules, the oldest this library knows in full.',
        ];
        yield 'PHP 7.3 to 8.4' => [
            'from' => 70300,
            'to' => 80400,
            'judgedWith' => ['10.32' => '10.39', '10.33' => '10.39', '10.35' => '10.39'],
            'notice' => 'PCRE2 10.32, 10.33 and 10.35 are judged with the PCRE2 10.39 rules, the oldest this library knows in full.',
        ];
        yield 'PHP 8.0 to 8.3' => [
            'from' => 80000,
            'to' => 80300,
            'judgedWith' => ['10.35' => '10.39'],
            'notice' => 'PCRE2 10.35 is judged with the PCRE2 10.39 rules, the oldest this library knows in full.',
        ];
    }

    #[Test]
    public function test_the_parsers_of_a_range_can_be_named_one_by_one(): void
    {
        $diff = new RangeDiff(
            RegexParser::create(['php_version' => 80200, 'pcre_version' => '10.40']),
            [RegexParser::create(['php_version' => 80200, 'pcre_version' => '10.43', 'runtime_pcre_validation' => false])],
        );

        $verdict = $diff->diff('/a{,3}/');

        $this->assertInstanceOf(PcreTarget::class, $verdict->readsOtherwise);
        $this->assertSame('10.43', $verdict->readsOtherwise->pcreVersion);
        $this->assertSame([[80200, '10.40'], [80200, '10.43']], array_map(static fn (PcreTarget $target): array => [$target->phpVersionId, $target->pcreVersion], $diff->targets()));
    }
}
