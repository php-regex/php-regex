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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\RangePoints;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PcreRule;
use PHPRegex\Tests\Support\RangeFixtureReadings;
use PHPRegex\Tests\Support\RangePatterns;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The verdict of each version of a range on one pattern, the floor first,
 * as the single-pattern commands and the MCP tools report it: whether the
 * version accepts the pattern, its error when it does not, and whether it
 * reads the pattern into another tree than the floor.
 */
final class RangePointsTest extends TestCase
{
    use PcreRule;
    use RangeFixtureReadings;

    #[Test]
    public function test_each_point_gives_its_verdict_the_floor_first(): void
    {
        self::assertEngineKeepInLookbehind();

        $points = self::points('>=8.1', RangePatterns::KEEP_IN_LOOKBEHIND);

        $this->assertSame(array_map(ProjectTarget::phpLabel(...), RangePatterns::FROM_8_1), array_column($points, 'php'));
        $this->assertSame(array_map(self::judgedPcre(...), RangePatterns::FROM_8_1), array_column($points, 'pcre'));
        foreach ($points as $index => $point) {
            $refused = RangePatterns::FROM_8_1[$index] >= 80500;
            $this->assertSame(['php', 'pcre', 'is_valid', 'error', 'error_code', 'parses_differently'], array_keys($point));
            $this->assertSame(!$refused, $point['is_valid'], $point['php']);
            $this->assertSame($refused ? 'regex.keep.in_lookaround' : null, $point['error_code'], $point['php']);
            $this->assertSame($refused, \is_string($point['error']), $point['php']);
            // A refused point reads no tree to compare.
            $this->assertFalse($point['parses_differently'], $point['php']);
        }
    }

    #[Test]
    public function test_a_point_that_reads_another_tree_than_the_floor_says_so(): void
    {
        self::assertEngineReadsQuantifierWithoutMin();
        $reads = static fn (int $point): bool => version_compare(self::judgedPcre($point), '10.43', '>=');

        $points = self::points('>=8.1', RangePatterns::QUANTIFIER_WITHOUT_MIN);

        foreach ($points as $index => $point) {
            $this->assertTrue($point['is_valid'], $point['php']);
            $this->assertNull($point['error'], $point['php']);
            $this->assertSame($reads(RangePatterns::FROM_8_1[$index]) !== $reads(RangePatterns::FROM_8_1[0]), $point['parses_differently'], $point['php']);
        }
        $this->assertFalse($points[0]['parses_differently']);
    }

    #[Test]
    public function test_a_single_version_gives_the_floor_alone(): void
    {
        $points = self::points('8.3', '/a/');

        $this->assertSame([['php' => '8.3', 'pcre' => self::judgedPcre(80300), 'is_valid' => true, 'error' => null, 'error_code' => null, 'parses_differently' => false]], $points);
    }

    #[Test]
    public function test_a_pattern_the_floor_refuses_is_refused_at_the_floor(): void
    {
        $points = self::points('>=8.4', '/(/');

        $this->assertFalse($points[0]['is_valid']);
        $this->assertIsString($points[0]['error']);
        $this->assertIsString($points[0]['error_code']);
        $this->assertSame(array_fill(0, \count($points), false), array_column($points, 'parses_differently'));
    }

    /**
     * "(?<=a{1,3})b", a lookbehind of variable length, is refused before
     * PCRE2 10.43 and compiled from it: the floor refuses it, each later
     * version that reads it accepts it, and none of them reads a tree to
     * compare with a floor that has none. The running engine compiles it
     * from 10.43 too.
     */
    #[Test]
    public function test_a_later_version_may_accept_a_pattern_the_floor_refuses(): void
    {
        $compiles = version_compare(explode(' ', \PCRE_VERSION)[0], '10.43', '>=');
        $this->assertSame($compiles ? 1 : false, @preg_match('/(?<=a{1,3})b/', 'ab'), 'PCRE2 '.\PCRE_VERSION);
        // 8.1 bundles 10.39; where PHP 8.1 links 10.43 or later, the floor
        // accepts it and 8.2 and 8.3 refuse it, reading no tree either.
        $accepts = static fn (int $point): bool => version_compare(self::judgedPcre($point), '10.43', '>=');

        $points = self::points('>=8.1', '/(?<=a{1,3})b/');

        foreach ($points as $index => $point) {
            $this->assertSame($accepts(RangePatterns::FROM_8_1[$index]), $point['is_valid'], $point['php']);
            $this->assertSame($point['is_valid'] ? null : 'regex.lookbehind.variable_length_not_supported', $point['error_code'], $point['php']);
        }
        $this->assertSame(array_fill(0, \count($points), false), array_column($points, 'parses_differently'));
    }

    /**
     * A pattern past the library's size limit is no verdict the parser can
     * give: each point says it is not valid, with the limit as its error,
     * and nothing is thrown.
     */
    #[Test]
    public function test_a_pattern_past_the_size_limit_is_not_valid_at_any_point(): void
    {
        $pattern = '/a(?#'.str_repeat('x', 100_000).')/';

        $points = self::points('>=8.1', $pattern);

        $this->assertCount(\count(RangePatterns::FROM_8_1), $points);
        foreach ($points as $point) {
            $this->assertFalse($point['is_valid'], $point['php']);
            $this->assertIsString($point['error'], $point['php']);
            $this->assertStringContainsString('exceeds the maximum length of 100000 bytes', (string) $point['error'], $point['php']);
            $this->assertFalse($point['parses_differently'], $point['php']);
        }
    }

    /**
     * @return list<array{php: string, pcre: string, is_valid: bool, error: string|null, error_code: string|null, parses_differently: bool}>
     */
    private static function points(string $constraint, string $pattern): array
    {
        $target = ProjectTarget::fromSources(['--php-version' => $constraint], [], null, [], ['--locale' => 'C']);
        $floor = RegexParser::create($target->regexOptions());

        return RangePoints::of($floor, $target->rangeParsers($floor), $pattern);
    }
}
