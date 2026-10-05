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

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pattern under /x is rewritten from its pretty-printed tree, so that its
 * comments and layout survive the suggestion. The /x flag is read after the
 * bytes PHP skips before the delimiter (C's isspace(): space, \t, \n, \v,
 * \f, \r), and never behind a NUL byte, which PHP refuses as a delimiter.
 */
final class ExtendedModeDelimiterTest extends TestCase
{
    private const PATTERN = "/a{1}  # one\n/x";

    private AnalysisService $analysis;

    protected function setUp(): void
    {
        $this->analysis = new AnalysisService(RegexParser::create(['cache' => null]));
    }

    /**
     * @return iterable<string, array{lead: string}>
     */
    public static function provideLeadingWhitespace(): iterable
    {
        yield 'space' => ['lead' => ' '];
        yield 'tab' => ['lead' => "\t"];
        yield 'newline' => ['lead' => "\n"];
        yield 'carriage return' => ['lead' => "\r"];
        yield 'vertical tab' => ['lead' => "\x0B"];
        yield 'form feed' => ['lead' => "\x0C"];
    }

    #[Test]
    #[DataProvider('provideLeadingWhitespace')]
    public function test_suggest_optimizations_reads_x_after_the_whitespace_php_skips(string $lead): void
    {
        // Oracle: PHP reads the delimiter after the whitespace, and /x
        // drops the comment.
        $this->assertSame(1, preg_match($lead.self::PATTERN, 'a'));

        $withLead = $this->onlySuggestion($lead.self::PATTERN);
        $without = $this->onlySuggestion(self::PATTERN);

        // The /x path rewrites the pretty-printed tree, which knows nothing
        // of the bytes before the delimiter: both suggestions are the same.
        $this->assertSame($without->original, $withLead->original);
        $this->assertSame($without->optimized, $withLead->optimized);
        $this->assertStringContainsString('# one', $withLead->optimized);
    }

    #[Test]
    public function test_suggest_optimizations_skips_a_pattern_behind_a_nul_byte(): void
    {
        $pattern = self::nulByte().self::PATTERN;
        $this->assertFalse(@preg_match($pattern, 'a'));

        $result = $this->analysis->suggestOptimizations([new PatternOccurrence($pattern, 'test.php', 1, 'preg_match')], 0);

        $this->assertSame([], $result);
    }

    #[Test]
    #[DataProvider('provideLeadingWhitespace')]
    public function test_uses_extended_mode_skips_what_php_skips(string $lead): void
    {
        $this->assertSame(1, preg_match($lead.'/a b/x', 'ab'));

        $this->assertTrue($this->usesExtendedMode($lead.'/a b/x'));
    }

    #[Test]
    public function test_uses_extended_mode_refuses_a_nul_byte(): void
    {
        $pattern = self::nulByte().'/a b/x';
        $this->assertFalse(@preg_match($pattern, 'ab'));

        $this->assertFalse($this->usesExtendedMode($pattern));
    }

    private function onlySuggestion(string $pattern): OptimizationResult
    {
        $result = $this->analysis->suggestOptimizations([new PatternOccurrence($pattern, 'test.php', 1, 'preg_match')], 0);
        $this->assertCount(1, $result, json_encode($pattern, \JSON_THROW_ON_ERROR));

        return $result[0]['optimization'];
    }

    /**
     * Through a call, so that static analysis does not compile the patterns
     * PHP refuses.
     */
    private static function nulByte(): string
    {
        return "\0";
    }

    private function usesExtendedMode(string $pattern): bool
    {
        $result = (new \ReflectionMethod($this->analysis, 'usesExtendedMode'))->invoke($this->analysis, $pattern);
        $this->assertIsBool($result);

        return $result;
    }
}
