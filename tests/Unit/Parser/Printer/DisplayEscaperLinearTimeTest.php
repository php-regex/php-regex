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

namespace PHPRegex\Tests\Unit\Parser\Printer;

use PHPRegex\Parser\Printer\DisplayEscaper;
use PHPRegex\Tests\Support\LinearTimeAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Under x the shown form drops each "#" comment, which ends at the first
 * line break of the newline convention. Under (*ANY) and (*ANYCRLF) several
 * sequences end a comment; finding the first of them must not read the rest
 * of the pattern again for each comment: four times as many comments take
 * about four times as long, not sixteen.
 */
final class DisplayEscaperLinearTimeTest extends TestCase
{
    use LinearTimeAssertions;

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

        $this->assertLinearTime(static function (int $count) use ($build): void {
            DisplayEscaper::escape($build($count));
        }, $comments, $verb.' before comments');
    }

    /**
     * Sizes where reading the rest of the pattern for each comment takes
     * thirteen times as long for four times as many comments: below them
     * the search itself is so fast that what is read once per comment hides
     * it, and four times as many take nine to ten times as long.
     *
     * @return iterable<string, array{verb: string, comments: int}>
     */
    public static function provideNewlineConventions(): iterable
    {
        yield '(*ANY)' => ['verb' => '(*ANY)', 'comments' => 48000];
        yield '(*ANYCRLF)' => ['verb' => '(*ANYCRLF)', 'comments' => 64000];
    }
}
