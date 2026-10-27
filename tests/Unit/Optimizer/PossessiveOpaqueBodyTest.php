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
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A repetition whose body holds a DEFINE group or a script run, under the
 * possessive option: a DEFINE group matches nothing, so the letter after it
 * decides, and the repetition becomes possessive when what follows cannot
 * start where it ends; a script run is read as a body of unknown length,
 * and the repetition is kept as written. Either way the rewrite matches
 * what the pattern matched.
 */
final class PossessiveOpaqueBodyTest extends TestCase
{
    private const SUBJECTS = ['y', 'by', 'bby', 'aby', 'ay', 'aay', 'bbb', 'x'];

    #[Test]
    #[DataProvider('provideRepetitions')]
    public function test_the_possessive_rewrite_of_an_opaque_body_matches_what_the_pattern_matched(string $pattern, string $optimized): void
    {
        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(possessive: true));

        $this->assertSame($optimized, $result->optimized);
        foreach (self::SUBJECTS as $subject) {
            $this->assertSame(preg_match($pattern, $subject), preg_match($optimized, $subject), \sprintf('%s rewritten as %s differs on "%s".', $pattern, $optimized, $subject));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, optimized: string}>
     */
    public static function provideRepetitions(): iterable
    {
        yield 'a DEFINE group before a letter' => ['pattern' => '/(?:(?(DEFINE)a)b)+y/', 'optimized' => '/(?:(?(DEFINE)a)b)++y/'];
        yield 'a script run before a letter' => ['pattern' => '/(?:(*sr:a)b)+y/', 'optimized' => '/(?:(*sr:a)b)+y/'];
        yield 'a script run before an optional letter' => ['pattern' => '/(?:(*sr:a)b?)+y/', 'optimized' => '/(?:(*sr:a)b?)+y/'];
        yield 'a script run or a letter' => ['pattern' => '/(?:(*sr:a)|b)+y/', 'optimized' => '/(?:(*sr:a)|b)+y/'];
    }
}
