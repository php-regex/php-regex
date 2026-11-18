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

namespace PHPRegex\Tests\Unit\Optimizer;

use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A lookaround reads nothing, yet it looks at the characters a repetition
 * before it would give back: "a+(?!b)" matches "a" in "aab", where
 * "a++(?!b)" matches nothing. Under the possessive option, a repetition
 * followed by a lookaround, before anything that reads, is kept as written,
 * and the other rewrites of the pattern still apply.
 */
final class PossessiveBeforeLookaroundTest extends TestCase
{
    private const SUBJECTS = ['aab', 'ab', 'a.b', 'a-', 'ab.c', 'b', '', 'x.y.', 'abc.', "x \ny", "x \nz", 'a_b', 'a__b', 'ab_'];

    #[Test]
    public function test_a_possessive_repetition_before_a_lookahead_matches_less(): void
    {
        $this->assertSame(1, preg_match('/a+(?!b)/', 'aab'), 'Oracle: the repetition gives an "a" back.');
        $this->assertSame(0, preg_match('/a++(?!b)/', 'aab'), 'Oracle: the possessive one does not.');
    }

    #[Test]
    #[DataProvider('provideRepetitionsBeforeALookaround')]
    public function test_a_repetition_before_a_lookaround_stays_greedy(string $pattern, string $optimized): void
    {
        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(possessive: true));

        $this->assertSame($optimized, $result->optimized);
        foreach (self::SUBJECTS as $subject) {
            $this->assertSame(preg_match($pattern, $subject), preg_match($optimized, $subject), \sprintf('%s rewritten as %s differs on "%s".', $pattern, $optimized, $subject));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, optimized: string}>
     */
    public static function provideRepetitionsBeforeALookaround(): iterable
    {
        yield 'a negative lookahead' => ['pattern' => '/a+(?!b)/', 'optimized' => '/a+(?!b)/'];
        yield 'a negative lookahead on the class it repeats' => ['pattern' => '/[a-z.-]*(?![a-z.])/', 'optimized' => '/[\-.a-z]*(?![.a-z])/'];
        yield 'a negative lookahead on another letter' => ['pattern' => '/[a-z.-]*(?!b)/', 'optimized' => '/[\-.a-z]*(?!b)/'];
        yield 'a negative lookbehind' => ['pattern' => '/[a-z.-]*(?<!b)/', 'optimized' => '/[\-.a-z]*(?<!b)/'];
        yield 'a lookahead in a group' => ['pattern' => '/[a-z.-]*(?:(?!b))/', 'optimized' => '/[\-.a-z]*(?:(?!b))/'];
        yield 'a lookahead that left the other rewrites undone' => ['pattern' => '/x\s+(?!y)/s', 'optimized' => '/x\s+(?!y)/'];
        yield 'a lookahead at the end of a loop body' => ['pattern' => '/^[a-z](?:[a-z0-9_](?!__))*[a-z0-9]+$/', 'optimized' => '/^[a-z](?:[0-9_a-z](?!__))*[0-9a-z]+$/'];
    }
}
