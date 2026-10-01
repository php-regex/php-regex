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

namespace PHPRegex\Tests\Unit\ReDoS\Proven;

use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pattern the library's static validation rejects is never "safe
 * (proven)": PCRE refuses to compile it, so there is no match attempt to
 * prove anything about. The theoretical path runs no engine: the static
 * validation decides.
 */
final class RedosStaticValidationTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRejectedPatterns')]
    public function test_rejected_pattern_is_not_analyzed_through_the_analyzer(string $pattern): void
    {
        $this->assertNotAnalyzedWithError((new RedosAnalyzer())->analyze($pattern), $pattern);
    }

    #[Test]
    #[DataProvider('provideRejectedPatterns')]
    public function test_rejected_pattern_is_not_analyzed_through_the_facade(string $pattern): void
    {
        $this->assertNotAnalyzedWithError(Regex::create()->redos($pattern), $pattern);
    }

    /**
     * The engine fact behind each row, on the CI matrix.
     */
    #[Test]
    #[DataProvider('provideEngineFacts')]
    public function test_engine_refuses_to_compile(string $pattern, string $pcreMessage): void
    {
        $this->assertFalse(@preg_match($pattern, ''), $pattern);
        $error = error_get_last();
        $this->assertNotNull($error);
        $this->assertStringContainsString($pcreMessage, $error['message']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRejectedPatterns(): iterable
    {
        foreach (self::provideEngineFacts() as $name => [$pattern]) {
            yield $name => [$pattern];
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideEngineFacts(): iterable
    {
        // preg_match(): Compilation failed: range out of order in character class at offset 4
        yield 'reversed class range' => ['/[z-a]/', 'range out of order'];
        // preg_match(): Compilation failed: numbers out of order in {} quantifier at offset 5
        yield 'reversed quantifier bounds' => ['/a{2,1}/', 'numbers out of order'];
    }

    private function assertNotAnalyzedWithError(RedosAnalysis $analysis, string $pattern): void
    {
        $this->assertSame(RedosProof::NotAnalyzed, $analysis->proof, $pattern);
        $this->assertSame(RedosComplexity::Unknown, $analysis->complexity, $pattern);
        $this->assertSame(RedosSeverity::Unknown, $analysis->severity, $pattern);
        $this->assertNotNull($analysis->error, $pattern);
        $this->assertNull($analysis->witness, $pattern);
        $this->assertFalse($analysis->isProvenSafe(), $pattern);
        $this->assertFalse($analysis->isSafe(), $pattern);
    }
}
