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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Parser\Cache\ArrayCache;
use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
        $this->assertEquals(Regex::create()->tokenize('/a+(b)/'), RegexParser::create()->tokenize('/a+(b)/'));
        $this->assertTrue(array_is_list(RegexParser::create()->tokenize('/a+(b)/')));
    }

    #[Test]
    public function test_the_facade_shares_its_parser(): void
    {
        $regex = Regex::create(['cache' => null, 'php_version' => '8.3']);

        $this->assertSame($regex->target(), $regex->parser()->target());
        $this->assertInstanceOf(NullCache::class, $regex->parser()->getCache());
    }

    /**
     * A parser for another target keeps every other option: the limits,
     * the cache, and the runtime check, which only the running engine
     * makes.
     */
    #[Test]
    public function test_a_parser_for_another_target_keeps_the_other_options(): void
    {
        $cache = new ArrayCache();
        $parser = RegexParser::create(['cache' => $cache, 'max_pattern_length' => 10, 'max_lookbehind_length' => 7, 'max_recursion_depth' => 64, 'runtime_pcre_validation' => true]);
        $target = new PcreTarget(80200, '10.40', 'C');

        $derived = $parser->withTarget($target);

        $this->assertSame($target, $derived->target());
        $this->assertSame($cache, $derived->getCache());
        $this->assertSame(10, (new \ReflectionProperty($derived, 'maxPatternLength'))->getValue($derived));
        $this->assertSame(7, (new \ReflectionProperty($derived, 'maxLookbehindLength'))->getValue($derived));
        $this->assertSame(64, (new \ReflectionProperty($derived, 'maxRecursionDepth'))->getValue($derived));
        $this->assertFalse((new \ReflectionProperty($derived, 'runtimePcreValidation'))->getValue($derived));
        $this->assertTrue((new \ReflectionProperty($parser->withTarget(PcreTarget::runtime()), 'runtimePcreValidation'))->getValue($parser->withTarget(PcreTarget::runtime())));
        $this->assertSame($parser, $parser->withTarget($parser->target()));
    }

    #[Test]
    public function test_a_facade_for_another_target_keeps_the_other_options(): void
    {
        $regex = Regex::create(['cache' => null, 'redos_ignored_patterns' => ['/a+/']]);
        $target = new PcreTarget(80200, '10.40', 'C');

        $derived = $regex->withTarget($target);

        $this->assertSame($target, $derived->target());
        $this->assertSame($regex->parser()->getCache(), $derived->parser()->getCache());
        $this->assertSame(['/a+/'], (new \ReflectionProperty($derived, 'redosIgnoredPatterns'))->getValue($derived));
        $this->assertSame($regex, $regex->withTarget($regex->target()));
    }
}
