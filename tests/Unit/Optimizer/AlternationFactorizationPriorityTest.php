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
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE takes the first alternative that leads to a match, not the longest:
 * on "ab", /a|ab/ matches "a" and /ab|a/ matches "ab". Factoring a common
 * prefix or suffix out of an alternation keeps that order, or the rewrite
 * matches the same strings but writes another $matches.
 */
final class AlternationFactorizationPriorityTest extends TestCase
{
    private const SUBJECTS = ['', 'a', 'ab', 'abc', 'b', 'bc', 'abcd', 'x', 'xb', 'xc', 'xbc', 'xxbc', 'foo', 'foobar', 'foobarx', "\r\n", "\r", "\n", 'src="../', 'src="./', 'src="././', '/#!/', '/', '0a', '0b', '\\r'];

    #[Test]
    #[DataProvider('providePatterns')]
    public function test_a_factored_alternation_writes_the_same_matches(string $pattern): void
    {
        foreach ([false, true] as $verify) {
            $optimized = (new Optimizer(RegexParser::create()))->optimize($pattern, ['factorize' => true, 'verify_with_automata' => $verify])->optimized;

            foreach (self::SUBJECTS as $subject) {
                preg_match($pattern, $subject, $expected, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL);
                preg_match($optimized, $subject, $actual, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL);

                $this->assertSame($expected, $actual, \sprintf('%s rewritten as %s writes other matches on "%s".', $pattern, $optimized, $subject));
            }
        }
    }

    #[Test]
    #[DataProvider('provideFactoredPatterns')]
    public function test_the_prefix_is_still_factored_out(string $pattern, string $expected): void
    {
        $this->assertSame($expected, (new Optimizer(RegexParser::create()))->optimize($pattern, ['factorize' => true])->optimized);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'shorter first' => ['pattern' => '/a|ab/'];
        yield 'longer first' => ['pattern' => '/ab|a/'];
        yield 'shorter in a group' => ['pattern' => '/(a|ab)/'];
        yield 'word, then its extension' => ['pattern' => '/foo|foobar/'];
        yield 'shorter in the middle' => ['pattern' => '/xb|x|xc/'];
        yield 'shorter first of three' => ['pattern' => '/x|xb|xc/'];
        yield 'shorter last of three' => ['pattern' => '/xb|xc|x/'];
        yield 'shared suffix, shorter first' => ['pattern' => '/bc|abc/'];
        yield 'shared suffix, shorter last' => ['pattern' => '/abc|bc/'];
        yield 'shared suffix, anchored' => ['pattern' => '/^(?:bc|abc)$/'];
        yield 'shared suffix, shorter in the middle' => ['pattern' => '/abc|bc|xbc/'];
        yield 'after a prefix' => ['pattern' => '/a(?:b|bc)/'];
        yield 'a branch that is no literal' => ['pattern' => '/ab|a+/'];
        yield 'escapes sharing a backslash' => ['pattern' => '/(\r\n|\r|\n)/s'];
        yield 'escaped dots sharing a prefix' => ['pattern' => '/src="(\.\.\/|\.\/)+/'];
        yield 'escaped slashes sharing a prefix' => ['pattern' => '/(\/\#\!\/|\/)/'];
    }

    /**
     * @return iterable<string, array{pattern: string, expected: string}>
     */
    public static function provideFactoredPatterns(): iterable
    {
        yield 'shorter first goes lazy' => ['pattern' => '/a|ab/', 'expected' => '/a(?:b)??/'];
        yield 'shorter last stays greedy' => ['pattern' => '/ab|a/', 'expected' => '/a(?:b)?/'];
        yield 'no empty branch' => ['pattern' => '/ab|ac/', 'expected' => '/a(?:b|c)/'];
        yield 'prefix of a zero' => ['pattern' => '/0a|0b/', 'expected' => '/0(?:a|b)/'];
        yield 'prefix of escapes' => ['pattern' => '/\r\n|\r\t/', 'expected' => '/\r(?:\n|\t)/'];
    }
}
