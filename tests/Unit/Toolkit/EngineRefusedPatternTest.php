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

namespace PHPRegex\Tests\Unit\Toolkit;

use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pattern the engine refuses, one policy: a method that states what the
 * pattern matches refuses it, as validate() does; a rendering of its text
 * still answers. PHP 8.4.27 (PCRE2 10.49) refuses both patterns at compile
 * time: "/\k<x>/" names no group, "/(?:\d){65536}/" counts past 65535.
 */
final class EngineRefusedPatternTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRefusedPatterns')]
    public function test_the_engine_refuses_the_pattern(string $pattern): void
    {
        $this->assertFalse(@preg_match($pattern, ''));
    }

    #[Test]
    #[DataProvider('provideFactsAboutMatches')]
    public function test_a_fact_about_the_matches_refuses_the_pattern(string $method, string $pattern): void
    {
        $this->expectException(SemanticErrorException::class);

        Regex::create(['cache' => null])->{$method}($pattern);
    }

    /**
     * @return iterable<string, array{method: string, pattern: string}>
     */
    public static function provideFactsAboutMatches(): iterable
    {
        foreach (['info', 'captureShape', 'literals', 'optimize'] as $method) {
            foreach (self::provideRefusedPatterns() as $name => ['pattern' => $pattern]) {
                yield $method.', '.$name => ['method' => $method, 'pattern' => $pattern];
            }
        }
    }

    #[Test]
    #[DataProvider('provideRenderings')]
    public function test_a_rendering_of_the_text_answers(string $method): void
    {
        $this->assertNotEmpty((string) json_encode(Regex::create(['cache' => null])->{$method}('/\k<x>/')));
    }

    /**
     * @return iterable<string, array{method: string}>
     */
    public static function provideRenderings(): iterable
    {
        yield 'explain' => ['method' => 'explain'];
        yield 'format' => ['method' => 'format'];
        yield 'highlight' => ['method' => 'highlight'];
    }

    /**
     * Called directly, the optimizer rewrites nothing of a pattern the
     * engine refuses: "\d{65536}" would be refused alike.
     */
    #[Test]
    #[DataProvider('provideRefusedPatterns')]
    public function test_the_optimizer_leaves_a_refused_pattern_as_written(string $pattern): void
    {
        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern);

        $this->assertSame($pattern, $result->optimized);
        $this->assertSame([], $result->changes);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRefusedPatterns(): iterable
    {
        yield 'a reference to no group' => ['pattern' => '/\k<x>/'];
        yield 'a count past the limit' => ['pattern' => '/(?:\d){65536}/'];
    }
}
