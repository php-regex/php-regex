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
use RegexParser\Exception\SampleGenerationException;
use RegexParser\Regex;

/**
 * generate() gives a sample the running engine matches, or says it found
 * none: a pattern no subject matches, or one whose assertions the samples
 * miss, never yields a string that does not match.
 */
final class RegexGenerateTest extends TestCase
{
    #[Test]
    #[DataProvider('provideUnmatchable')]
    public function test_no_sample_is_given_for_a_pattern_the_samples_miss(string $pattern): void
    {
        $this->assertSame(0, preg_match($pattern, 'ab'));

        try {
            Regex::create(['cache' => null])->generate($pattern);
            self::fail(\sprintf('A sample was given for %s.', $pattern));
        } catch (SampleGenerationException $e) {
            $this->assertSame('regex.generate.no_match', $e->getErrorCode());
            $this->assertStringContainsString($pattern, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideUnmatchable(): iterable
    {
        yield 'failing verb' => ['pattern' => '/a+(*FAIL)/'];
        yield 'anchor inside' => ['pattern' => '/a^b/'];
        yield 'assertions that exclude each other' => ['pattern' => '/(?=a)(?=b)/'];
        yield 'empty negative lookahead' => ['pattern' => '/a(?!)b/'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideJitCrashes(): iterable
    {
        yield 'smallest known crash' => ['/(?|(\\*)(*napla:(.+))|()(?=\\S_(\\2?)))+_/'];
        yield 'branch reset with lookaheads and references' => ['/^(?|(\\*)(*napla:\\S*_(\\2?+.+))|(\\w)(?=\\S*_(\\2?+\\1)))+_\\2$/'];
    }

    #[Test]
    public function test_a_sample_past_a_mebibyte_is_not_built(): void
    {
        $regex = Regex::create(['cache' => null]);

        $this->assertSame(65535, \strlen($regex->generate('/^a{65535}$/')));

        foreach (['/(a{2000}){1000}/', "/(?1){3918}(((((0(\\k'R'))))(?J)(?'R'(?'R'\\3){99})))/"] as $pattern) {
            try {
                $regex->generate($pattern);
                self::fail(\sprintf('A sample was built for %s.', $pattern));
            } catch (SampleGenerationException $e) {
                $this->assertStringContainsString('1048576 bytes', $e->getMessage());
            }
        }
    }

    /**
     * The JIT of PCRE2 10.49 crashes PHP on this pattern for some samples,
     * "*a_cb2a1_a_1!_a_Z1a!_Z_Z" among them; the interpreter gives the same
     * answers without crashing.
     */
    #[Test]
    #[DataProvider('provideJitCrashes')]
    public function test_samples_are_checked_without_the_jit(string $pattern): void
    {
        $regex = Regex::create(['cache' => null]);

        for ($try = 0; $try < 64; $try++) {
            try {
                $this->assertSame(1, preg_match('/(*NO_JIT)'.substr($pattern, 1), $regex->generate($pattern)));
            } catch (SampleGenerationException $e) {
                $this->assertSame('regex.generate.no_match', $e->getErrorCode());
            }
        }
    }

    #[Test]
    public function test_a_sample_the_engine_matches_is_given(): void
    {
        $regex = Regex::create(['cache' => null]);

        foreach (['/\d{3}-[A-Z]{2}/', '/^(?:foo|bar)\b/', '/\bword\b/'] as $pattern) {
            $this->assertSame(1, preg_match($pattern, $regex->generate($pattern)), $pattern);
        }
    }
}
