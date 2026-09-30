<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Cache\NullCache;
use RegexParser\Regex;
use RegexParser\RegexParser;

/**
 * Reading and judging a pattern is the core's alone: the facade hands it
 * to the one parser every layer shares, and answers what that parser does.
 */
final class RegexParserTest extends TestCase
{
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_tree_is_the_one_the_facade_gives(string $pattern): void
    {
        $parser = RegexParser::create(['cache' => null]);
        $facade = Regex::create(['cache' => null]);

        $verdict = $parser->validate($pattern);
        $this->assertEquals($facade->validate($pattern), $verdict, $pattern);
        if ($verdict->isValid) {
            $this->assertEquals($facade->parse($pattern), $parser->parse($pattern), $pattern);
        }
        $this->assertEquals($facade->parseTolerant($pattern), $parser->parseTolerant($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'plain' => ['/abc/i'];
        yield 'groups and references' => ['/(?<y>\\d{4})-(\\d{2})\\k<y>/'];
        yield 'error in a class before a later one' => ['/[z-a](?#/'];
        yield 'unterminated group' => ['/a(/'];
        yield 'unknown escape' => ['/\\j/'];
        yield 'bad flag' => ['/a/Q'];
        yield 'no delimiter' => ['abc'];
        yield 'extended class' => ['/(?[ \\d - [3] ])/'];
    }

    #[Test]
    public function test_the_options_name_the_target_and_the_limits(): void
    {
        $parser = RegexParser::create(['php_version' => '8.2', 'max_pattern_length' => 10]);

        $this->assertSame('10.40', $parser->target()->pcreVersion);
        $this->assertFalse($parser->validate('/'.str_repeat('a', 20).'/')->isValid);
        $this->assertSame(100_000, RegexParser::DEFAULT_MAX_PATTERN_LENGTH);
        $this->assertSame(255, RegexParser::DEFAULT_MAX_LOOKBEHIND_LENGTH);
        $this->assertSame(1024, RegexParser::DEFAULT_MAX_RECURSION_DEPTH);
    }

    #[Test]
    public function test_the_tokens_are_the_ones_the_facade_gives(): void
    {
        $this->assertEquals(Regex::tokenize('/a+(b)/'), RegexParser::create()->tokenize('/a+(b)/'));
    }

    #[Test]
    public function test_the_facade_shares_its_parser(): void
    {
        $regex = Regex::create(['cache' => null, 'php_version' => '8.3']);

        $this->assertInstanceOf(RegexParser::class, $regex->parser());
        $this->assertSame($regex->target(), $regex->parser()->target());
        $this->assertInstanceOf(NullCache::class, $regex->parser()->getCache());
    }
}
