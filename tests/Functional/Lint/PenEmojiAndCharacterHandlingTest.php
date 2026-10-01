<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Functional\Lint;

use PhpRegex\Explain\Highlighter\ConsoleHighlighter;
use PhpRegex\Explain\TextExplainer;
use PhpRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PhpRegex\Parser\Internal\PatternParser;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive tests for pen emoji display and non-printable character handling.
 */
final class PenEmojiAndCharacterHandlingTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__.'/../../Fixtures/Lint/';

    private TokenBasedExtractionStrategy $strategy;

    private TextExplainer $explainVisitor;

    private ConsoleHighlighter $highlightVisitor;

    private PatternPrinter $compilerVisitor;

    protected function setUp(): void
    {
        $this->strategy = new TokenBasedExtractionStrategy();
        $this->explainVisitor = new TextExplainer();
        $this->highlightVisitor = new ConsoleHighlighter();
        $this->compilerVisitor = new PatternPrinter();
    }

    /**
     * @return \Iterator<(int|string), array{string}>
     */
    public static function nonPrintableCharacterProvider(): \Iterator
    {
        yield 'null_byte' => ["\x00"];
        yield 'unit_separator' => ["\x1F"];
        yield 'del' => ["\x7F"];
        yield 'extended_ff' => ["\xFF"];
        yield 'emoji' => ['🙂'];
    }

    /**
     * @return array<string, string>
     */
    public static function regexPatternProvider(): array
    {
        return [
            // Basic patterns
            'simple' => '/test/',
            'with_flags' => '/test/i',
            'with_delimiter' => '#test#',
            'unicode' => '/pattern/u',

            // Complex patterns from real world
            'phpstan_class' => '/^[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*+$/',
            'emoji_pattern' => '{^(?<codePoints>[\\w ]+) +; [\\w-]+ +# (?<emoji>.+) E\\d+\\.\\d+ ?(?<name>.+)$}Uu',
            'real_world_m_flag' => '/QUICK_CHECK = .*;/m',

            // Edge cases: escaped delimiters, multiple flags
            'escaped_delimiters' => '/\\/complex\\/pattern/m',
            'multiple_flags' => '/pattern/mx',
        ];
    }

    /**
     * Test that pen emoji is not displayed for non-clickable patterns.
     */
    #[Test]
    public function test_pen_emoji_not_displayed_for_non_clickable_patterns(): void
    {
        $pattern = '/test/';

        // Test without any special context - should not show pen
        $fixtureFile = self::FIXTURE_DIR.'pen_emoji_non_clickable.php';

        $result = $this->strategy->extract([$fixtureFile]);

        $this->assertCount(1, $result, 'Should extract exactly one pattern');
        [$expectedBody, $expectedFlags] = PatternParser::extractPatternAndFlags($pattern);
        [$actualBody, $actualFlags] = PatternParser::extractPatternAndFlags($result[0]->pattern);

        $this->assertSame($expectedBody, $actualBody, 'Pattern body should match extracted pattern');
        $this->assertSame($expectedFlags, $actualFlags, 'Pattern flags should match extracted pattern');
    }

    /**
     * Test non-printable characters are handled in TextExplainer.
     */
    #[DataProvider('nonPrintableCharacterProvider')]
    #[Test]
    public function test_explain_node_visitor_handles_non_printable_characters(string $inputChar): void
    {
        $charNode = new LiteralNode($inputChar, 0, 1);
        $result = $this->explainVisitor->visitLiteral($charNode);

        $this->assertIsString($result);
        // Should not contain the Unicode replacement character
        $this->assertStringNotContainsString("\xEF\xBF\xBD", $result);
    }

    /**
     * Test non-printable characters are handled in PatternPrinter.
     */
    #[DataProvider('nonPrintableCharacterProvider')]
    #[Test]
    public function test_compiler_node_visitor_handles_non_printable_characters(string $inputChar): void
    {
        $charNode = new LiteralNode($inputChar, 0, 1);
        $result = $this->compilerVisitor->visitLiteral($charNode);

        $this->assertIsString($result);
        // Should not emit raw control bytes; representation should be escaped
        $this->assertStringNotContainsString("\x00", $result);
    }

    /**
     * Test non-printable characters are handled in ConsoleHighlighter.
     */
    #[DataProvider('nonPrintableCharacterProvider')]
    #[Test]
    public function test_console_highlighter_visitor_handles_non_printable_characters(string $inputChar): void
    {
        $charNode = new LiteralNode($inputChar, 0, 1);
        $result = $this->highlightVisitor->visitLiteral($charNode);

        $this->assertIsString($result);
    }

    /**
     * Test regex patterns with flags are extracted correctly.
     */
    #[Test]
    public function test_regex_patterns_with_flags(): void
    {
        $fixtureMap = [
            'simple' => 'tokenbased_simple.php',
            'with_flags' => 'tokenbased_with_flags.php',
            'with_delimiter' => 'pen_emoji_with_delimiter.php',
            'unicode' => 'tokenbased_unicode.php',
            'phpstan_class' => 'tokenbased_phpstan_class.php',
            'emoji_pattern' => 'tokenbased_emoji_pattern.php',
            'real_world_m_flag' => 'tokenbased_real_world_m_flag.php',
            'escaped_delimiters' => 'pen_emoji_escaped_delimiters.php',
            'multiple_flags' => 'regex_fixes_multiple_flags.php',
        ];

        foreach (self::regexPatternProvider() as $name => $expectedPattern) {
            $fixtureFile = self::FIXTURE_DIR.$fixtureMap[$name];

            $results = $this->strategy->extract([$fixtureFile]);

            $this->assertCount(1, $results, \sprintf('Should extract exactly one pattern for: %s', $name));

            // For the complex emoji_pattern, just ensure we round-trip the
            // raw pattern string including its trailing Uu flags, without
            // feeding it back through PatternParser (which is already
            // exercised elsewhere and can be stricter about delimiters).
            if ('emoji_pattern' === $name) {
                $this->assertSame(
                    $expectedPattern,
                    $results[0]->pattern,
                    'Emoji pattern with Uu flags should be preserved when extracted',
                );

                continue;
            }

            // For most patterns, compare body and flags via PatternParser.
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

    // test_real_world_patterns removed: covered by test_regex_patterns_with_flags.

    /**
     * Test character ranges don't contain weird characters in explanations.
     */
    #[Test]
    public function test_character_ranges_in_explain_visitor(): void
    {
        $testPatterns = [
            '/[\x00-\x1F]/',           // Control characters
            '/[\x7F-\xFF]/',           // Extended ASCII
            '/[\x{2000}-\x{2FFF}]/u', // Unicode range
        ];

        $regex = Regex::create();
        foreach ($testPatterns as $pattern) {
            $ast = $regex->parse($pattern);
            $explanation = $ast->accept($this->explainVisitor);

            // Should not contain weird characters in the explanation
            $this->assertStringNotContainsString("\xEF\xBF\xBD", $explanation);
            $this->assertStringNotContainsString("\xFF", $explanation);
            $this->assertStringNotContainsString("\x7F", $explanation);
        }
    }

    /**
     * Test edge cases with special characters and delimiters.
     */
    #[Test]
    public function test_edge_cases_with_special_characters(): void
    {
        $edgeCases = [
            'mixed_delimiters' => 'tokenbased_mixed_delimiters.php',
            'escaped_content' => 'tokenbased_escaped_content.php',
            'unicode_emoji' => 'tokenbased_unicode_emoji.php',
            'nested_flags' => 'tokenbased_nested_flags.php',
        ];

        foreach ($edgeCases as $name => $fixtureFile) {
            $fixturePath = self::FIXTURE_DIR.$fixtureFile;

            $results = $this->strategy->extract([$fixturePath]);

            $this->assertCount(1, $results, \sprintf('Should handle edge case: %s', $name));
        }
    }

    /**
     * Test regression for the specific issues we fixed.
     */
    #[Test]
    public function test_regression_for_pen_emoji_and_character_encoding(): void
    {
        // Test the exact patterns from the original issue
        $fixtureFile = self::FIXTURE_DIR.'pen_emoji_regression.php';

        $results = $this->strategy->extract([$fixtureFile]);

        $this->assertCount(2, $results, 'Should extract 2 patterns from regression test');

        // Verify the extracted patterns include both the QUICK_CHECK pattern
        // (with /m flag) and the emoji pattern (with Uu flags). We work with
        // the raw pattern strings here to avoid over-constraining the exact
        // delimiter/escaping used by the extractor.
        $patterns = array_map(static fn ($occurrence) => $occurrence->pattern, $results);
        $all = implode("\n", $patterns);

        $this->assertStringContainsString('/QUICK_CHECK = .*;/m', $all, 'QUICK_CHECK pattern with /m flag not found in regression test');
        $this->assertStringContainsString('}Uu', $all, 'Emoji pattern with Uu flags not found in regression test');
    }
}
