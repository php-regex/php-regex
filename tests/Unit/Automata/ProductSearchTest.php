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

namespace PHPRegex\Tests\Unit\Automata;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\Model\Alphabet;
use PHPRegex\Automata\Model\Nfa;
use PHPRegex\Automata\Model\PackedNfa;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Solver\LazyDfa;
use PHPRegex\Automata\Solver\ProductSearch;
use PHPRegex\Automata\Transform\HirToNfaTransformer;
use PHPRegex\Parser\Hir\HirTranslator;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Benchmark\Support\PeakMemory;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The search for a witness on a product built as it is reached: it stops at
 * the depth of the shortest witness, keeps its pairs under the limit before
 * it builds them, and numbers a pair within the integers of the running PHP.
 */
final class ProductSearchTest extends TestCase
{
    use PcreGates;
    use PhpUnitForwardCompat;

    /**
     * "a" is in both languages at depth one, where the "b" loops meet only
     * after 143 characters, past the limit of 100 pairs: the search stops
     * at the first depth an accepting pair is found.
     */
    #[Test]
    public function test_an_intersection_stops_at_the_depth_of_the_shortest_witness(): void
    {
        $this->assertSame(1, preg_match('/^(?:a|(?:b{11})+)$/', 'a'));
        $this->assertSame(1, preg_match('/^(?:a|(?:b{13})+)$/', 'a'));
        $this->assertSame(1, preg_match('/^(?:a|(?:b{13})+)$/', str_repeat('b', 143)));
        $this->assertSame(0, preg_match('/^(?:a|(?:b{13})+)$/', str_repeat('b', 11)));

        [$left, $right] = PackedNfa::packPair(self::nfa('/a|(?:b{11})+/'), self::nfa('/a|(?:b{13})+/'));
        $classes = (new ProductSearch(new SolverOptions(maxDfaStates: 100)))->intersection($left, $right);

        $this->assertNotNull($classes);
        $this->assertSame('a', ProductSearch::spell($classes, $left->alphabet, true));
    }

    #[Test]
    public function test_a_difference_stops_at_the_depth_of_the_shortest_witness(): void
    {
        $this->assertSame(1, preg_match('/^(?:a|(?:b{11})+)$/', 'a'));
        $this->assertSame(0, preg_match('/^(?:(?:b{13})+)$/', 'a'));

        [$left, $right] = PackedNfa::packPair(self::nfa('/a|(?:b{11})+/'), self::nfa('/(?:b{13})+/'));
        $classes = (new ProductSearch(new SolverOptions(maxDfaStates: 100)))->difference($left, new LazyDfa($right, 100));

        $this->assertNotNull($classes);
        $this->assertSame('a', ProductSearch::spell($classes, $left->alphabet, true));
    }

    /**
     * Seven hundred branches on each side start 490,000 pairs: the limit is
     * met at its thousandth pair, before the rest are built.
     */
    #[Test]
    public function test_the_starting_pairs_are_kept_under_the_limit_before_they_are_built(): void
    {
        $this->requiresPhp('8.2', 'The peak memory of a search', 'has no memory_reset_peak_usage()');
        $branches = '/(?:'.implode('|', array_fill(0, 700, 'a')).')/';
        [$left, $right] = PackedNfa::packPair(self::nfa($branches), self::nfa($branches));

        PeakMemory::reset();
        $before = memory_get_peak_usage();

        try {
            (new ProductSearch(new SolverOptions(maxDfaStates: 1_000)))->intersection($left, $right);
            $this->fail('Expected the search to keep more pairs than the limit.');
        } catch (ComplexityException $exception) {
            $this->assertSame(['phase' => 'comparison', 'limit' => 1_000], $exception->getDiagnostic());
        }

        $grown = memory_get_peak_usage() - $before;
        $this->assertLessThan(16 * 1024 * 1024, $grown, 'The search grew the memory by '.$grown.' bytes.');
    }

    /**
     * After "x", fifteen hundred branches on each side lead to 2,250,000
     * pairs on one class: each is charged and kept one at a time.
     */
    #[Test]
    public function test_the_pairs_a_class_leads_to_are_kept_under_the_limit_before_they_are_built(): void
    {
        $this->requiresPhp('8.2', 'The peak memory of a search', 'has no memory_reset_peak_usage()');
        $branches = '/x(?:'.implode('|', array_fill(0, 1_500, 'a')).')/';
        [$left, $right] = PackedNfa::packPair(self::nfa($branches), self::nfa($branches));

        PeakMemory::reset();
        $before = memory_get_peak_usage();

        try {
            (new ProductSearch(new SolverOptions(maxDfaStates: 1_000)))->intersection($left, $right);
            $this->fail('Expected the search to keep more pairs than the limit.');
        } catch (ComplexityException $exception) {
            $this->assertSame(['phase' => 'comparison', 'limit' => 1_000], $exception->getDiagnostic());
        }

        $grown = memory_get_peak_usage() - $before;
        $this->assertLessThan(16 * 1024 * 1024, $grown, 'The search grew the memory by '.$grown.' bytes.');
    }

    /**
     * A pair is numbered by the states of both sides: where their product
     * passes the integers of the running PHP, as 2^31 does on 32-bit PHP,
     * the search gives up rather than read two pairs as one.
     */
    #[Test]
    public function test_an_intersection_too_wide_to_number_gives_up(): void
    {
        $alphabet = Alphabet::fromRanges([[0, 255]], [0]);
        $wide = new PackedNfa($alphabet, 0, \PHP_INT_MAX, [0, 0], [], [], [0, 0], [], "\0", 0, 255);
        $two = new PackedNfa($alphabet, 0, 2, [0, 0, 0], [], [], [0, 0, 0], [], "\0", 0, 255);

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains('The comparison holds more pairs of states than this PHP numbers.');

        (new ProductSearch(new SolverOptions()))->intersection($wide, $two);
    }

    /**
     * The right side of an inclusion grows as it is read: the second state
     * it builds is already past what a pair can number beside a left side
     * this wide.
     */
    #[Test]
    public function test_a_difference_too_wide_to_number_gives_up(): void
    {
        $alphabet = Alphabet::fromRanges([[0, 255]], [0]);
        // 0 moves on the one class to 1, which accepts.
        $wide = new PackedNfa($alphabet, 0, \PHP_INT_MAX, [0, 1, 1], [0], [1], [0, 0, 0], [], "\x02", 0, 255);
        // 0 moves on the one class to 1, which neither moves nor accepts.
        $right = new PackedNfa($alphabet, 0, 2, [0, 1, 1], [0], [1], [0, 0, 0], [], "\0", 0, 255);

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains('The comparison holds more pairs of states than this PHP numbers.');

        (new ProductSearch(new SolverOptions()))->difference($wide, new LazyDfa($right, 100));
    }

    private static function nfa(string $pattern): Nfa
    {
        $ast = RegexParser::create()->parse($pattern);

        return (new HirToNfaTransformer($pattern, false))->transform((new HirTranslator())->translate($ast), new SolverOptions());
    }
}
