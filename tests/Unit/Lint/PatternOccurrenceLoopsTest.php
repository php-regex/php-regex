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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\PatternOccurrence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The loops over every match an occurrence runs in: the one of its own
 * call, then those of the other calls that read its literal, each function
 * once, in the order of the calls, as a list.
 */
final class PatternOccurrenceLoopsTest extends TestCase
{
    /**
     * @param list<string> $elsewhere
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideLoops')]
    public function test_every_match_calls_names_each_loop_once_in_the_order_of_the_calls(?string $everyMatch, array $elsewhere, array $expected): void
    {
        $occurrence = new PatternOccurrence('/p/', 'file.php', 1, 'preg_match()', everyMatch: $everyMatch, everyMatchElsewhere: $elsewhere);

        $this->assertSame($expected, $occurrence->everyMatchCalls());
    }

    /**
     * @return iterable<string, array{everyMatch: string|null, elsewhere: list<string>, expected: list<string>}>
     */
    public static function provideLoops(): iterable
    {
        yield 'its own call and two others' => ['everyMatch' => 'preg_match_all', 'elsewhere' => ['preg_split', 'preg_replace'], 'expected' => ['preg_match_all', 'preg_split', 'preg_replace']];
        yield 'its own function named again by another call' => ['everyMatch' => 'preg_split', 'elsewhere' => ['preg_split', 'preg_replace'], 'expected' => ['preg_split', 'preg_replace']];
        yield 'no loop of its own, a function named twice' => ['everyMatch' => null, 'elsewhere' => ['preg_replace', 'preg_replace', 'preg_split'], 'expected' => ['preg_replace', 'preg_split']];
        yield 'no loop' => ['everyMatch' => null, 'elsewhere' => [], 'expected' => []];
    }
}
