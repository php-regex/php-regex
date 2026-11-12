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

namespace PHPRegex\Tests\Unit\Lint\Extraction;

use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A ternary, a null coalescing and a match are the same expressions
 * however they are spaced, and a ternary may stand in the then arm of
 * another with no parenthesis: its ":" is the one its own "?" opens. The
 * arms are read as with the spaces PHP does not ask for.
 *
 * PHP 8.4: $flag ? $k ? '/x/' : '/y/' : '/z/' is "/x/" with both true,
 * "/y/" with $k false and "/z/" with $flag false; "$flag ? $re = $k ?
 * '/a/' : '/b/' : null;" assigns "/a/" or "/b/" to $re when $flag is
 * true.
 *
 * Every case runs through both strategies.
 */
final class BranchSpellingTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * Each row gives the argument and the patterns of its arms, in the
     * order they are written.
     *
     * @return iterable<string, array{argument: string, patterns: list<string>}>
     */
    public static function provideBranchesWrittenWithNoSpace(): iterable
    {
        yield 'a ternary with no space around its arms' => ['argument' => "\$flag ?'/a/':'/b/'", 'patterns' => ['/a/', '/b/']];
        yield 'a ternary with no space after its colon' => ['argument' => "\$flag ? '/a/' :'/b/'", 'patterns' => ['/a/', '/b/']];
        yield 'a ternary in the then arm of a ternary' => ['argument' => "\$flag ? \$k ? '/x/' : '/y/' : '/z/'", 'patterns' => ['/x/', '/y/', '/z/']];
        yield 'a match with no space before its subject' => ['argument' => "match(\$k) { 1 => '/one/', default => '/other/' }", 'patterns' => ['/one/', '/other/']];
        yield 'a match with no space before its brace' => ['argument' => "match (\$k){ 1 => '/one/', default => '/other/' }", 'patterns' => ['/one/', '/other/']];
        yield 'a match with no space in its arms' => ['argument' => "match (\$k) {1 =>'/one/',default =>'/other/'}", 'patterns' => ['/one/', '/other/']];
        // The "=>" of an array is no arrow of the match.
        yield 'a match on arrays with keys, with no space in its arms' => ['argument' => "match (\$pair) {['k' => 1] => '/one/',['k' => 2] => '/other/'}", 'patterns' => ['/one/', '/other/']];
    }

    /**
     * @param list<string> $patterns
     */
    #[Test]
    #[DataProvider('provideBranchesWrittenWithNoSpace')]
    public function test_a_branch_written_with_no_space_gives_its_arms(string $argument, array $patterns): void
    {
        $code = "<?php\n\nfunction run(string \$s, bool \$flag, int \$k, array \$pair): int\n{\n    return preg_match(".$argument.", \$s);\n}\n";

        $this->assertExtractedByBoth(array_map(
            static fn (string $pattern): array => ['pattern' => $pattern, 'at' => "'".$pattern."'", 'source' => 'preg_match()', 'location' => null],
            $patterns,
        ), $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => $argument, 'at' => $argument.', $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * The item may be missing: its fallback is read beside the values of
     * the list, though no space parts it from the "??".
     */
    #[Test]
    public function test_a_fallback_written_right_after_the_null_coalescing_is_read(): void
    {
        $code = <<<'PHP'
            <?php

            final class Matcher
            {
                private const LIST = ['/l-a/', '/l-b/'];

                public function run(string $s, int $k): int
                {
                    return preg_match(self::LIST[$k] ??'/fallback/', $s);
                }
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/l-a/', 'at' => "'/l-a/'", 'location' => null],
            ['pattern' => '/l-b/', 'at' => "'/l-b/'", 'location' => null],
            ['pattern' => '/fallback/', 'at' => "'/fallback/'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => "self::LIST[\$k] ??'/fallback/'"],
        ], $code);
    }

    /**
     * The value assigned in the then arm of a ternary ends at the ":" of
     * that ternary, past the one a ternary of its own opens: the variable
     * holds the two arms of the inner one.
     */
    #[Test]
    public function test_a_ternary_assigned_in_the_then_arm_of_a_ternary_is_read_whole(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, bool $flag, int $k): int
            {
                $flag ? $re = $k ? '/a/' : '/b/' : null;

                return preg_match($re, $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/a/', 'at' => "'/a/'", 'location' => 'via $re'],
            ['pattern' => '/b/', 'at' => "'/b/'", 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => '$re', 'at' => '$re, $s)'],
        ], $code);
    }
}
