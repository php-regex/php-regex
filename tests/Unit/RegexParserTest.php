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
use PHPRegex\Parser\Exception\RecursionLimitException;
use PHPRegex\Parser\Exception\ResourceLimitException;
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
        $this->assertSame(512, RegexParser::DEFAULT_MAX_RECURSION_DEPTH);
    }

    /**
     * PCRE refuses parentheses nested past 250 levels: the parser reads all
     * of them under its default limit, twice as deep, and stops short of
     * the depth where PHP runs out of a C stack of 1 MB, some 800 levels.
     */
    #[Test]
    public function test_the_default_depth_reads_every_nesting_pcre_accepts_and_stops_well_before_the_stack_runs_out(): void
    {
        $nested = static fn (int $depth): string => '/'.str_repeat('(', $depth).'a'.str_repeat(')', $depth).'/';
        $this->assertSame(1, @preg_match($nested(250), 'a'), 'Oracle: PCRE takes 250 levels.');
        $this->assertFalse(@preg_match($nested(251), 'a'), 'Oracle: PCRE refuses 251 levels.');

        $this->assertTrue(RegexParser::create(['cache' => null])->validate($nested(250))->isValid);

        try {
            RegexParser::create(['cache' => null])->parse($nested(600));
            $this->fail('Six hundred levels were read.');
        } catch (RecursionLimitException $e) {
            $this->assertSame('Recursion limit of 512 exceeded.', $e->getMessage());
        }
    }

    #[Test]
    public function test_the_tokens_are_the_ones_the_facade_gives(): void
    {
        $this->assertEquals(Regex::create()->tokenize('/a+(b)/'), RegexParser::create()->tokenize('/a+(b)/'));
        $this->assertTrue(array_is_list(RegexParser::create()->tokenize('/a+(b)/')));
    }

    #[Test]
    #[DataProvider('provideTokenizationLimits')]
    public function test_tokenization_enforces_the_configured_length_limit(bool $facade, string $pattern, bool $tooLong): void
    {
        $reader = $facade ? Regex::create(['max_pattern_length' => 10]) : RegexParser::create(['max_pattern_length' => 10]);
        if ($tooLong) {
            $this->expectException(ResourceLimitException::class);
        }

        $tokens = $reader->tokenize($pattern);
        $this->assertNotEmpty($tokens);
    }

    /**
     * @return iterable<string, array{facade: bool, pattern: string, tooLong: bool}>
     */
    public static function provideTokenizationLimits(): iterable
    {
        foreach ([false, true] as $facade) {
            yield (int) $facade.' at limit' => ['facade' => $facade, 'pattern' => '/abcdefgh/', 'tooLong' => false];
            yield (int) $facade.' past limit with modifier' => ['facade' => $facade, 'pattern' => '/abcdefgh/i', 'tooLong' => true];
            yield (int) $facade.' past limit with leading space' => ['facade' => $facade, 'pattern' => ' /abcdefgh/', 'tooLong' => true];
        }
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
        $target = new PcreTarget(70300, '10.32', 'C');

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

    /**
     * A parser that keeps no tree has every other option of the one it
     * comes from, and reads a pattern as that one does: what it parses
     * and judges never reaches the cache, nor the results the first keeps.
     */
    #[Test]
    public function test_a_parser_without_cache_keeps_the_other_options_and_no_tree(): void
    {
        $cache = new ArrayCache();
        $target = new PcreTarget(80200, '10.40', 'C');
        $parser = RegexParser::create(['cache' => $cache, 'max_pattern_length' => 10, 'max_lookbehind_length' => 7, 'max_recursion_depth' => 64, 'runtime_pcre_validation' => true]);

        foreach ([$parser, $parser->withTarget($target)] as $origin) {
            $derived = $origin->withoutCache();

            $this->assertNotSame($origin, $derived);
            $this->assertInstanceOf(NullCache::class, $derived->getCache());
            $this->assertSame($origin->target(), $derived->target());
            foreach (['maxPatternLength', 'maxLookbehindLength', 'maxRecursionDepth', 'runtimePcreValidation', 'engine'] as $option) {
                $this->assertSame((new \ReflectionProperty($origin, $option))->getValue($origin), (new \ReflectionProperty($derived, $option))->getValue($derived), $option);
            }
        }

        $derived = $parser->withoutCache();
        $this->assertEquals($parser->parse('/a+b/'), $derived->parse('/a+b/'));
        $this->assertTrue($derived->validate('/a+c/')->isValid);
        $this->assertFalse($derived->validate('/a{2,1}/')->isValid);
        $this->assertSame(['hits' => 0, 'misses' => 1], $cache->getStats(), 'Only the first parser asked the cache.');
        $this->assertSame([], (new \ReflectionProperty($parser, 'results'))->getValue($parser));

        try {
            $derived->parse('/abcdefghij/');
            $this->fail('The length limit was lost.');
        } catch (ResourceLimitException $e) {
            $this->assertStringContainsString('10', $e->getMessage());
        }
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
