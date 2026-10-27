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

namespace PHPRegex\Tests\Unit\Redos;

use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A repeated group whose body the proof does not model, a reference to a
 * group inside it, a condition on a group, a recursion, is left to the
 * heuristic: never called safe, and never called proven.
 */
final class RedosHeuristicShapesTest extends TestCase
{
    #[Test]
    #[DataProvider('provideUnmodelledBodies')]
    public function test_a_repeated_body_the_proof_does_not_model_is_never_called_safe(string $pattern): void
    {
        $this->assertTrue(self::compiles($pattern), 'Oracle: PCRE compiles the pattern.');

        $analysis = Regex::create(['cache' => null])->redos($pattern);

        $this->assertFalse($analysis->isProvenSafe());
        $this->assertNotSame(RedosSeverity::Safe, $analysis->severity);
        $this->assertSame('Potential backtracking (heuristic)', $analysis->headline());
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideUnmodelledBodies(): iterable
    {
        yield 'a reference to a group of the body' => ['pattern' => '/((a?)\2+)+/'];
        // The engine reads it in a constant number of steps: the heuristic
        // over-reports, and calls it no more than potential.
        yield 'a condition on the group itself' => ['pattern' => '/((?(1)|b))+/'];
        // With the start optimizations off, the engine stops the match on a
        // recursion that could loop, PREG_INTERNAL_ERROR, before any
        // backtracking.
        yield 'an optional recursion before an optional letter' => ['pattern' => '/((?R)?a?)+/'];
        yield 'an optional recursion before a possessive run' => ['pattern' => '/((?R)?a*+)+/'];
        yield 'an optional recursion before an optional atomic letter' => ['pattern' => '/((?R)?(?>a)?)+/'];
        yield 'an optional recursion before an atomic optional letter' => ['pattern' => '/((?R)?(?>a?))+/'];
    }

    /**
     * The reference shape is critical, and the engine agrees: with its
     * start optimizations off, each two more letters cost it more than
     * twice the steps before the "!" fails the match. Oracle (PCRE2 10.49,
     * JIT off): 60 steps at 6 letters, 411 at 10, 7,375 at 16.
     */
    #[Test]
    public function test_the_reference_shape_is_critical_as_the_engine_counts_it(): void
    {
        $pattern = '/(*NO_JIT)(*NO_START_OPT)((a?)\2+)+!/';
        $steps = [];
        foreach ([8, 10, 12] as $letters) {
            $steps[] = self::steps($pattern, str_repeat('a', $letters));
        }

        $this->assertGreaterThan(2 * $steps[0], $steps[1]);
        $this->assertGreaterThan(2 * $steps[1], $steps[2]);
        $this->assertSame(RedosSeverity::Critical, Regex::create(['cache' => null])->redos('/((a?)\2+)+/')->severity);
    }

    #[Test]
    public function test_the_engine_reads_the_condition_shape_in_constant_steps(): void
    {
        $pattern = '/(*NO_JIT)(*NO_START_OPT)((?(1)|b))+!/';

        $this->assertSame(self::steps($pattern, str_repeat('b', 6)), self::steps($pattern, str_repeat('b', 16)));
    }

    #[Test]
    public function test_the_engine_stops_the_recursion_shape_before_it_backtracks(): void
    {
        $this->assertFalse(@preg_match(PcreInterpreter::pattern('/(*NO_START_OPT)((?R)?a?)+!/'), str_repeat('a', 16)));
        $this->assertSame(\PREG_INTERNAL_ERROR, preg_last_error());
    }

    /**
     * Whether PCRE compiles the pattern: a refused pattern warns, where a
     * match the engine stops, as on a recursion that could loop, does not.
     */
    private static function compiles(string $pattern): bool
    {
        $warned = false;
        set_error_handler(static function () use (&$warned): bool {
            $warned = true;

            return true;
        });

        try {
            preg_match($pattern, '');
        } finally {
            restore_error_handler();
        }

        return !$warned;
    }

    /**
     * The smallest backtrack limit under which the match attempt ends.
     */
    private static function steps(string $pattern, string $subject): int
    {
        $saved = (string) ini_get('pcre.backtrack_limit');

        try {
            $low = 1;
            $high = 1 << 24;
            while ($low < $high) {
                $middle = intdiv($low + $high, 2);
                ini_set('pcre.backtrack_limit', (string) $middle);
                if (false === @preg_match($pattern, $subject)) {
                    $low = $middle + 1;
                } else {
                    $high = $middle;
                }
            }

            return $low;
        } finally {
            ini_set('pcre.backtrack_limit', $saved);
        }
    }
}
