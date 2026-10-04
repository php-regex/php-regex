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
 * A run of one character compacts to a count, "aaa" to "a{3}"; a run that
 * ends with a newline is no run: "aaa\n" stays four different things, or the
 * rewrite matches "aaaa" where the pattern matched "aaa\n".
 */
final class LiteralRunCompactionTest extends TestCase
{
    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_a_compacted_run_matches_what_the_pattern_matched(string $pattern, array $subjects): void
    {
        $optimized = (new Optimizer(RegexParser::create()))->optimize($pattern)->optimized;

        foreach ($subjects as $subject) {
            $this->assertSame(preg_match($pattern, $subject), preg_match($optimized, $subject), \sprintf('%s rewritten as %s differs on %s.', $pattern, $optimized, json_encode($subject)));
        }
    }

    #[Test]
    public function test_a_plain_run_still_compacts(): void
    {
        $this->assertSame('/a{4}/', (new Optimizer(RegexParser::create()))->optimize('/aaaa/')->optimized);
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function providePatterns(): iterable
    {
        yield 'run then a newline' => ['pattern' => "/aaa\n/", 'subjects' => ["aaa\n", 'aaaa']];
        yield 'escaped newline' => ['pattern' => '/^---\n(.+)$/', 'subjects' => ["---\nx", '----x']];
        yield 'run of dashes' => ['pattern' => '/----/', 'subjects' => ['----', '---']];
    }
}
