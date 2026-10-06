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

namespace PHPRegex\Tests\Unit\Internal;

use PHPRegex\Parser\Internal\DisplayEscaper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Under x the shown form drops each "#" comment, which ends at the first
 * line break of the newline convention. Under (*ANY) and (*ANYCRLF) several
 * sequences end a comment; finding the first of them must not read the rest
 * of the pattern again for each comment: twice as many comments take about
 * twice as long, not four times.
 */
final class DisplayEscaperLinearTimeTest extends TestCase
{
    /**
     * The escape map is built once per process; each test builds it again,
     * so what it measures does not depend on a test run before it.
     */
    protected function setUp(): void
    {
        (new \ReflectionProperty(DisplayEscaper::class, 'escapes'))->setValue(null, []);
    }

    #[Test]
    #[DataProvider('provideNewlineConventions')]
    public function test_escape_drops_many_comments_in_linear_time(string $verb, int $comments): void
    {
        $build = static fn (int $count): string => '/'.$verb.str_repeat("#\n", $count).'a/x';

        // Every comment is dropped: the shown form keeps no line break.
        $this->assertStringNotContainsString('#', DisplayEscaper::escape($build(3)));

        $budget = 1.0;

        $small = self::bestTime($build($comments));
        $this->assertLessThan($budget, $small, \sprintf('%s, %d comments: %.3f s.', $verb, $comments, $small));

        $large = self::bestTime($build(2 * $comments));
        $this->assertLessThan($budget, $large, \sprintf('%s, %d comments: %.3f s, %.3f s for half as many.', $verb, 2 * $comments, $large, $small));

        // Below a few milliseconds the clock says more than the reading.
        if ($large >= 0.02) {
            $this->assertLessThan(3.0, $large / $small, \sprintf('%s: %.3f s for %d comments, %.3f s for %d.', $verb, $small, $comments, $large, 2 * $comments));
        }
    }

    /**
     * Sizes where reading the rest of the pattern for each comment takes
     * more than three times as long for twice as many comments.
     *
     * @return iterable<string, array{verb: string, comments: int}>
     */
    public static function provideNewlineConventions(): iterable
    {
        yield '(*ANY)' => ['verb' => '(*ANY)', 'comments' => 24000];
        yield '(*ANYCRLF)' => ['verb' => '(*ANYCRLF)', 'comments' => 32000];
    }

    /**
     * The best of three readings: a busy machine or a coverage driver slows
     * one reading down, rarely all three.
     */
    private static function bestTime(string $pattern): float
    {
        $best = \INF;
        for ($run = 0; $run < 3; $run++) {
            $start = hrtime(true);
            DisplayEscaper::escape($pattern);
            $best = min($best, (hrtime(true) - $start) / 1e9);
        }

        return $best;
    }
}
