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
 * A rewrite matches what preg_match() matches, not what the PCRE2
 * documentation says it should. Two of the engine's own optimizations
 * read items a rewrite would drop or reshape, on PCRE2 10.44 and 10.49,
 * with and without the JIT:
 *
 * - an item repeated zero times whose last branch is an assertion still
 *   tells the engine where a match may start: "/(?:x|(?=a)){0}\w/" never
 *   matches "\tb", where "/\w/" does;
 * - "\N" repeated right before "\R" is made possessive, though both match
 *   "\r": "/\N*\R/" does not match "a\r\x0Bb\r", where "/(?:\N)*\R/" does.
 */
final class EngineAgreementTest extends TestCase
{
    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_rewrite_matches_what_the_engine_matched(string $pattern, string $expected, array $subjects): void
    {
        $optimized = Regex::create(['cache' => null])->optimize($pattern)->optimized;

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
        yield 'a lookahead last in an item repeated zero times' => [
            'pattern' => '/(?:x|(?=a)){0}\w/',
            'expected' => '/(?:x|(?=a)){0}\w/',
            'subjects' => ["\tb", 'ab'],
        ];

        yield 'a start of line last in an item repeated zero times' => [
            'pattern' => '/(?:x|^){0}\w/',
            'expected' => '/(?:x|^){0}\w/',
            'subjects' => ["\tb", 'b'],
        ];

        yield 'a possessive zero repeat of a start of line' => [
            'pattern' => '/c(?:1|^a){0}+\w/',
            'expected' => '/c(?:1|^a){0}+\w/',
            'subjects' => ['cb', "\tcb"],
        ];

        yield 'a zero repeat without an assertion still goes' => [
            'pattern' => '/a(?:x|y){0}b/',
            'expected' => '/ab/',
            'subjects' => ['ab', 'axb'],
        ];

        yield 'a grouped non-newline repeated before a newline sequence' => [
            'pattern' => '/(?:\N)*\R/',
            'expected' => '/(?:\N)*\R/',
            'subjects' => ["a\r\x0Bb\r", "a\n"],
        ];

        yield 'a grouped non-newline repeated lazily before a newline sequence' => [
            'pattern' => '/(?:\s{4}){2,}(?:\N){0,2}?\R/',
            'expected' => '/(?:\s{4}){2,}(?:\N){0,2}?\R/',
            'subjects' => ["\t\n \n \n\t\t  \t\t?\r"],
        ];
    }
}
