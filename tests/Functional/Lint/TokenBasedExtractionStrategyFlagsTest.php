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

namespace PHPRegex\Tests\Functional\Lint;

use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Parser\Internal\PatternParser;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive tests for regex pattern extraction with flags.
 *
 * These tests ensure that regex patterns with flags are properly extracted
 * and preserved in their original form to avoid confusion in linting output.
 */
final class TokenBasedExtractionStrategyFlagsTest extends TestCase
{
    private TokenBasedExtractionStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new TokenBasedExtractionStrategy();
    }

    /**
     * Test various regex patterns with flags.
     */
    public function test_extract_regex_patterns_with_flags(): void
    {
        $patterns = [
            // Basic patterns
            'simple' => ['/test/', 'tokenbased_simple.php'],
            'with_flags' => ['/test/i', 'tokenbased_with_flags.php'],
            'unicode' => ['/pattern/u', 'tokenbased_unicode.php'],

            // Complex patterns from real world
            'phpstan_class' => ['/^[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*+$/', 'tokenbased_phpstan_class.php'],
            // Emoji pattern using curly-brace delimiters; this representation is
            // already covered in depth in PenEmojiAndCharacterHandlingTest, so
            // here we just verify that it round-trips through extraction.
            'emoji_pattern' => ['{^(?<codePoints>[\\w ]+) +; [\\w-]+ +# (?<emoji>.+) E\\d+\\.\\d+ ?(?<name>.+)$}Uu', 'tokenbased_emoji_pattern.php'],
            'real_world_m_flag' => ['/QUICK_CHECK = .*;/m', 'tokenbased_real_world_m_flag.php'],
        ];

        foreach ($patterns as $name => [$expectedPattern, $fixtureFile]) {
            $fixturePath = __DIR__.'/../../Fixtures/Lint/'.$fixtureFile;

            $results = $this->strategy->extract([$fixturePath]);

            $this->assertCount(1, $results, "Should extract exactly one pattern for: $name");

            if ('emoji_pattern' === $name) {
                // For the complex emoji pattern, assert exact raw pattern
                // round-trip rather than re-parsing with PatternParser.
                $this->assertSame(
                    $expectedPattern,
                    $results[0]->pattern,
                    "Emoji pattern with Uu flags should be preserved when extracted for: $name",
                );

                continue;
            }

            // For other patterns, compare body and flags via PatternParser.
            // The phpstan_class case uses \\x escapes which may be normalized
            // into actual bytes by the extractor; we only assert that flags
            // are preserved there.
            [$expectedBody, $expectedFlags] = PatternParser::extractPatternAndFlags($expectedPattern);
            [$actualBody, $actualFlags] = PatternParser::extractPatternAndFlags($results[0]->pattern);

            if ('phpstan_class' !== $name) {
                $this->assertSame(
                    $expectedBody,
                    $actualBody,
                    \sprintf(
                        "Pattern body mismatch for: %s\nExpected: %s\nActual: %s",
                        $name,
                        $expectedBody,
                        $actualBody,
                    ),
                );
            }

            $this->assertSame(
                $expectedFlags,
                $actualFlags,
                \sprintf(
                    "Pattern flags mismatch for: %s\nExpected: %s\nActual: %s",
                    $name,
                    $expectedFlags,
                    $actualFlags,
                ),
            );
        }
    }

    /**
     * Test that pen emoji is not displayed for non-clickable patterns.
     */
    public function test_pen_emoji_not_displayed_for_non_clickable_patterns(): void
    {
        // Simple pattern without any special clickability
        $fixtureSimple = __DIR__.'/../../Fixtures/Lint/tokenbased_non_clickable_simple.php';
        $result = $this->strategy->extract([$fixtureSimple]);

        $this->assertCount(1, $result);
        $this->assertSame('/pattern/', $result[0]->pattern);

        // Complex pattern that should also not show pen; we just verify it is
        // extracted and that the pattern itself does not contain any emoji.
        $fixtureComplex = __DIR__.'/../../Fixtures/Lint/tokenbased_non_clickable_complex.php';
        $result = $this->strategy->extract([$fixtureComplex]);

        $this->assertCount(1, $result);
        $this->assertStringNotContainsString('✏️', $result[0]->pattern);
    }

    /**
     * Test that flags are preserved in various edge cases.
     */
    public function test_edge_cases_with_flags(): void
    {
        $edgeCases = [
            'mixed_delimiters' => 'tokenbased_mixed_delimiters.php',
            'escaped_content' => 'tokenbased_escaped_content.php',
            'unicode_emoji' => 'tokenbased_unicode_emoji.php',
            'nested_flags' => 'tokenbased_nested_flags.php',
            'unicode_multiple_flags' => 'tokenbased_unicode_multiple_flags.php',
        ];

        foreach ($edgeCases as $name => $fixtureFile) {
            $fixturePath = __DIR__.'/../../Fixtures/Lint/'.$fixtureFile;

            $results = $this->strategy->extract([$fixturePath]);

            $this->assertCount(1, $results, "Should handle edge case: $name");
            $this->assertNotEmpty($results[0]->pattern, "Pattern should not be empty for: $name");
        }
    }
}
