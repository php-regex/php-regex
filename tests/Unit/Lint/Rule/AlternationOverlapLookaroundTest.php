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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A lookaround reads nothing: two branches overlap on the characters they
 * take, never on what a lookaround in one of them looks at. The engine is
 * the oracle: the characters each branch starts a match it takes with, at
 * each offset of a text with a character before it or not.
 */
final class AlternationOverlapLookaroundTest extends TestCase
{
    private const CHARACTERS = ['a', 'b', 'x', '!', '>', '$', '\\', 'z'];

    /**
     * @param array{string, string} $branches
     */
    #[Test]
    #[DataProvider('provideAlternations')]
    public function test_branches_overlap_on_the_characters_they_take(string $pattern, array $branches, bool $reported): void
    {
        $shared = array_intersect(self::starts($branches[0]), self::starts($branches[1]));
        $this->assertSame($reported, [] !== $shared, \sprintf('Oracle: the branches of %s start a match with %s.', $pattern, implode(', ', $shared) ?: 'no shared character'));

        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);
        $ids = array_map(static fn (object $issue): string => $issue->id, $linter->getIssues());

        $this->assertSame($reported, \in_array('regex.lint.overlap.charset', $ids, true), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, branches: array{string, string}, reported: bool}>
     */
    public static function provideAlternations(): iterable
    {
        yield 'a negative lookahead before the shared character' => ['pattern' => '/^(?:(?!x)a|a)+$/', 'branches' => ['(?!x)a', 'a'], 'reported' => true];
        yield 'a lookbehind before a character the other branch never takes' => ['pattern' => '/(?:[^$]+|(?<=\\\\)\$)+/', 'branches' => ['[^$]+', '(?<=\\\\)\$'], 'reported' => false];
        yield 'a branch that is a lookahead alone' => ['pattern' => '/^(?:>(?:(?!!)|!))+$/', 'branches' => ['(?!!)', '!'], 'reported' => false];
        yield 'a positive lookahead wider than its branch' => ['pattern' => '/^(?:(?=[ab])a|b)+$/', 'branches' => ['(?=[ab])a', 'b'], 'reported' => false];
    }

    /**
     * The characters the branch starts a match it takes with, at the start
     * of a text and after each character.
     *
     * @return list<string>
     */
    private static function starts(string $branch): array
    {
        $starts = [];
        foreach (['', ...self::CHARACTERS] as $before) {
            foreach (self::CHARACTERS as $character) {
                if (1 === preg_match('/\G(?:'.$branch.')/', $before.$character.'zz', $match, 0, \strlen($before)) && '' !== $match[0]) {
                    $starts[$match[0][0]] = true;
                }
            }
        }

        return array_map(strval(...), array_keys($starts));
    }
}
