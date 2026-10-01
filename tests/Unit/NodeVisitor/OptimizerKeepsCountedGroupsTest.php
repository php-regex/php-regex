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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "X{0}" and "X{1}" look like no-ops, and are not always. A group repeated
 * zero times still takes its number and can still be called: "(?1)(b){0}"
 * matches "b". "X{1}+" is possessive: it matches like "(?>X)", never giving
 * back what it took. The cases come from the PCRE2 test suite (testinput1
 * and 2); PHP decides every expected match.
 */
final class OptimizerKeepsCountedGroupsTest extends TestCase
{
    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_optimized_pattern_matches_like_the_original(string $pattern, array $subjects): void
    {
        $optimized = Regex::create(['cache' => null])->optimize($pattern)->optimized;

        $this->assertNotFalse(@preg_match($optimized, ''), \sprintf('%s optimized into %s, which PHP refuses.', $pattern, $optimized));
        foreach ($subjects as $subject) {
            preg_match($pattern, $subject, $expected);
            preg_match($optimized, $subject, $actual);

            $this->assertSame($expected, $actual, \sprintf('%s optimized into %s, which matches "%s" differently.', $pattern, $optimized, $subject));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function providePatterns(): iterable
    {
        // A group repeated zero times is still defined.
        yield 'called group' => ['pattern' => '/(?1)(?:(b)){0}/', 'subjects' => ['b', 'a']];
        yield 'called group with a verb' => ['pattern' => '/(a(*COMMIT)b){0}a(?1)|aac/', 'subjects' => ['aacab', 'aac']];
        yield 'group called by relative number' => ['pattern' => '/^(?+1)(?<a>x|y){0}z/', 'subjects' => ['xz', 'yz', 'z']];
        yield 'lookahead group repeated zero times' => ['pattern' => '/^(?=(a)){0}b(?1)/', 'subjects' => ['ba', 'b']];
        yield 'numbering after an unused group' => ['pattern' => '/(a){0}(b)\\2/', 'subjects' => ['bb', 'b']];

        // A possessive count of one is atomic.
        yield 'possessive branches' => ['pattern' => '/(?:a|ab){1}+c/', 'subjects' => ['abc', 'ac']];
        yield 'possessive capturing branches' => ['pattern' => '/(a|ab){1}+c/', 'subjects' => ['abc', 'ac']];
        yield 'possessive repeat inside' => ['pattern' => '/(a+){1}+a/', 'subjects' => ['aaaa']];
        yield 'possessive call' => ['pattern' => '/(?(DEFINE)(a|ab))(?1){1}+c/', 'subjects' => ['abc', 'ac']];

        // What stays a no-op is still simplified.
        yield 'plain count of one' => ['pattern' => '/(?:ab){1}c/', 'subjects' => ['abc']];
        yield 'plain count of zero' => ['pattern' => '/a(?:b){0}c/', 'subjects' => ['ac', 'abc']];
    }

    #[Test]
    public function test_a_count_that_changes_nothing_is_still_dropped(): void
    {
        $regex = Regex::create(['cache' => null]);

        $this->assertSame('/abc/', $regex->optimize('/a(?:b){1}c/')->optimized);
        $this->assertSame('/ac/', $regex->optimize('/a(?:b){0}c/')->optimized);
    }
}
