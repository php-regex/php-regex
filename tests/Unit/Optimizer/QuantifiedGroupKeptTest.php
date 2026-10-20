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
 * A quantifier repeats the one item written before it: once the group it
 * repeated is gone, "(?:ab)+" would read "ab+" and repeat the "b" alone.
 * A group the quantifier still applies to stays, however deep a quantifier
 * of its own sits inside it, and a run compacted to a count never repeats
 * more than one character without a group around it.
 */
final class QuantifiedGroupKeptTest extends TestCase
{
    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideQuantifiedGroups')]
    public function test_the_rewrite_matches_what_the_pattern_matched(string $pattern, string $expected, array $subjects): void
    {
        $regex = Regex::create(['cache' => null]);

        foreach ([[], ['possessive' => true, 'factorize' => true], ['min_quantifier_count' => 2]] as $options) {
            $optimized = $regex->optimize($pattern, $options)->optimized;

            foreach ($subjects as $subject) {
                $this->assertSame(
                    [preg_match($pattern, $subject, $before), $before],
                    [preg_match($optimized, $subject, $after), $after],
                    \sprintf('%s rewritten as %s under %s differs on %s.', $pattern, $optimized, json_encode($options), json_encode($subject)),
                );
            }
        }

        $this->assertSame($expected, $regex->optimize($pattern)->optimized);
    }

    /**
     * @return iterable<string, array{pattern: string, expected: string, subjects: list<string>}>
     */
    public static function provideQuantifiedGroups(): iterable
    {
        yield 'a count of one inside a repeated group' => [
            'pattern' => '/^(?:a{1}b)+$/',
            'expected' => '/^(?:ab)+$/',
            'subjects' => ['abab', 'ab', 'abb'],
        ];

        yield 'a repeat of zero inside a repeated group' => [
            'pattern' => '/b(?:B{0})+/',
            'expected' => '/b(?:)+/',
            'subjects' => ['bbb', 'b'],
        ];

        yield 'a count of one at the start of a starred group' => [
            'pattern' => '/x(?:a{1}bc)*y/',
            'expected' => '/x(?:abc)*y/',
            'subjects' => ['xabcabcy', 'xabccy', 'xy'],
        ];

        yield 'an escaped newline counted once in a counted group' => [
            'pattern' => '/(?:c\n{1}){2}/',
            'expected' => '/(?:c\n){2}/',
            'subjects' => ["c\nc\n", "c\n\n"],
        ];

        yield 'a count of one under an alternation in a group' => [
            'pattern' => '/^(?:a{1}b|c)+$/',
            'expected' => '/^(?:ab|c)+$/',
            'subjects' => ['abcab', 'abb', 'c'],
        ];

        yield 'a count of one two groups deep' => [
            'pattern' => '/^(?:(?:a{1}b)c)+$/',
            'expected' => '/^(?:(?:ab)c)+$/',
            'subjects' => ['abcabc', 'abcc', 'abc'],
        ];

        yield 'a lazy repeat over a group holding a count' => [
            'pattern' => '/^(?:a{1}b)+?$/',
            'expected' => '/^(?:ab)+?$/',
            'subjects' => ['abab', 'abb'],
        ];

        yield 'a group repeated four times in a row' => [
            'pattern' => '/^(?:ab)(?:ab)(?:ab)(?:ab)$/',
            'expected' => '/^(?:ab){4}$/',
            'subjects' => ['abababab', 'abbbb'],
        ];

        yield 'a counted group repeated in a row' => [
            'pattern' => '/^(?:ab){2}(?:ab){2}$/',
            'expected' => '/^(?:ab){4}$/',
            'subjects' => ['abababab', 'abbbb'],
        ];

        yield 'a group of one character counted once, then repeated' => [
            'pattern' => '/^(?:a{1}){3}$/',
            'expected' => '/^a{3}$/',
            'subjects' => ['aaa', 'aa'],
        ];

        yield 'a group emptied by a repeat of zero, after a letter' => [
            'pattern' => '/(?:é)(?:(?:a{0})){0,2}/u',
            'expected' => '/é(?:(?:)){0,2}/u',
            'subjects' => ['', 'é', 'éé'],
        ];

        yield 'assertions written in a row' => [
            'pattern' => '/a$$$$\b\b\b\b/m',
            'expected' => '/a$$$$\b\b\b\b/m',
            'subjects' => ['a', "a\nb", 'ab'],
        ];

        yield 'a class written before a group with a count of one' => [
            'pattern' => '/-[^[:alpha:]c.]{1,3}+(?:\$\.{1}){0,2}[^\]]/U',
            'expected' => '/-[^[:alpha:]c.]{1,3}+(?:\$\.){0,2}[^\]]/U',
            'subjects' => ["-1_\n\t", '-1$.$.x', '-1..'],
        ];
    }

    /**
     * "(?:B{0}){2}" repeats nothing twice: the rewrite once handed the
     * solver "/{2}/", a pattern nobody wrote, and the parse error escaped.
     */
    #[Test]
    public function test_a_group_repeating_nothing_is_kept_whole(): void
    {
        $result = Regex::create(['cache' => null])->optimize('/(?:B{0}){2}/');

        $this->assertSame(1, preg_match($result->optimized, 'B', $matches));
        $this->assertSame([''], $matches);
        $this->assertSame('/(?:){2}/', $result->optimized);
    }
}
