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

use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A quantifier made possessive gives nothing back, which keeps the match
 * only where what follows never starts with what it took. The character
 * sets read the flags the pattern is written with: under "U" a plain
 * quantifier is lazy, and an inline flag changes what a node matches from
 * where it stands to the end of its group, so neither is possessified. A
 * rewrite that both drops a possessive quantifier and adds one is checked
 * as any other that adds one.
 */
final class PossessiveFlagScopeTest extends TestCase
{
    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_rewrite_matches_what_the_pattern_matched(string $pattern, string $expected, array $subjects): void
    {
        // The rewriter and the gate the possessive rewrites always pass, the
        // proof of the other rewrites left to the caller.
        $optimized = Regex::create(['cache' => null])->optimize($pattern, ['possessive' => true, 'verify_with_automata' => false])->optimized;

        foreach ($subjects as $subject) {
            $this->assertSame(
                [preg_match($pattern, $subject, $before), $before],
                [preg_match($optimized, $subject, $after), $after],
                \sprintf('%s rewritten as %s differs on %s.', $pattern, $optimized, json_encode($subject)),
            );
        }

        $this->assertSame($expected, $optimized);
    }

    /**
     * @return iterable<string, array{pattern: string, expected: string, subjects: list<string>}>
     */
    public static function providePatterns(): iterable
    {
        yield 'a scoped caseless group after a dropped possessive repeat' => [
            'pattern' => '/a{0}+(?i:b+[\sZA-C])/',
            'expected' => '/(?i:b+[A-CZ\s])/',
            'subjects' => ['bB', 'b ', 'BB'],
        ];

        yield 'a lazy repeat under U after a dropped possessive repeat' => [
            'pattern' => '/\R{0}+\RB+(?<!a)/U',
            'expected' => '/\RB+(?<!a)/U',
            'subjects' => ["\rBBB", "\nB"],
        ];

        yield 'a caseless option set before the repeat' => [
            'pattern' => '/x{0}+(?i)a+A/',
            'expected' => '/(?i)a+A/',
            'subjects' => ['aa', 'aA', 'a'],
        ];

        yield 'a plain repeat is still made possessive' => [
            'pattern' => '/a{0}+b+c/',
            'expected' => '/b++c/',
            'subjects' => ['bbc', 'bc', 'c'],
        ];
    }
}
