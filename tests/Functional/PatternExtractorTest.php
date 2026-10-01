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

namespace PHPRegex\Tests\Functional;

use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Functional tests for pattern extraction to prevent false positives.
 *
 * These tests ensure that the extractor only detects actual regex patterns
 * passed to PCRE functions and ignores other strings like SQL, HTML, CSS, etc.
 */
final class PatternExtractorTest extends TestCase
{
    private TokenBasedExtractionStrategy $extractor;

    protected function setUp(): void
    {
        $this->extractor = new TokenBasedExtractionStrategy();
    }

    /**
     * Test that SQL queries are NOT detected as regex patterns.
     */
    public function test_sql_queries_are_not_detected(): void
    {
        $fixtureFile = __DIR__.'/../Fixtures/Functional/sql_query.php';

        $result = $this->extractor->extract([$fixtureFile]);

        $this->assertEmpty($result, 'SQL queries should not be detected as regex patterns');
    }

    /**
     * Test that HTML fragments are NOT detected as regex patterns.
     */
    public function test_html_fragments_are_not_detected(): void
    {
        $fixtureFile = __DIR__.'/../Fixtures/Functional/html_fragment.php';

        $result = $this->extractor->extract([$fixtureFile]);

        $this->assertEmpty($result, 'HTML fragments should not be detected as regex patterns');
    }

    /**
     * Test that CSS hex colors are NOT detected as regex patterns.
     */
    public function test_css_hex_colors_are_not_detected(): void
    {
        $fixtureFile = __DIR__.'/../Fixtures/Functional/css_color.php';

        $result = $this->extractor->extract([$fixtureFile]);

        $this->assertEmpty($result, 'CSS hex colors should not be detected as regex patterns');
    }

    /**
     * Test that simple text strings are NOT detected as regex patterns.
     */
    public function test_simple_text_is_not_detected(): void
    {
        $fixtureFile = __DIR__.'/../Fixtures/Functional/simple_text.php';

        $result = $this->extractor->extract([$fixtureFile]);

        $this->assertEmpty($result, 'Simple text should not be detected as regex patterns');
    }

    /**
     * Test that concatenated patterns are NOT detected (cannot be validated statically).
     */
    public function test_concatenated_patterns_are_not_detected(): void
    {
        $fixtureFile = __DIR__.'/../Fixtures/Functional/concatenated_pattern.php';

        $result = $this->extractor->extract([$fixtureFile]);

        $this->assertEmpty($result, 'Concatenated patterns should be skipped');
    }

    /**
     * Test that preg_match with a valid pattern IS detected.
     */
    public function test_preg_match_is_detected(): void
    {
        $fixtureFile = __DIR__.'/../Fixtures/Functional/valid_preg_match.php';

        $result = $this->extractor->extract([$fixtureFile]);

        $this->assertCount(1, $result);
        $this->assertSame('/^[a-z]+$/', $result[0]->pattern);
        $this->assertSame('preg_match()', $result[0]->source);
    }

    /**
     * Test that preg_replace with valid patterns IS detected.
     */
    public function test_preg_replace_is_detected(): void
    {
        $fixtureFile = __DIR__.'/../Fixtures/Functional/valid_preg_replace.php';

        $result = $this->extractor->extract([$fixtureFile]);

        $this->assertCount(1, $result);
        $this->assertSame('#pattern1#', $result[0]->pattern);
        $this->assertSame('preg_replace()', $result[0]->source);
    }

    /**
     * Test that custom functions can be configured for detection.
     */
    public function test_custom_functions_are_detected(): void
    {
        $extractor = new TokenBasedExtractionStrategy(['customRegexCheck']);

        $fixtureFile = __DIR__.'/../Fixtures/Functional/custom_function.php';

        $result = $extractor->extract([$fixtureFile]);

        $this->assertCount(1, $result);
        $this->assertSame('/custom-pattern/', $result[0]->pattern);
        $this->assertSame('customRegexCheck()', $result[0]->source);
    }

    /**
     * Test that custom static methods can be configured for detection.
     */
    public function test_custom_static_methods_are_detected(): void
    {
        $extractor = new TokenBasedExtractionStrategy(['MyClass::customRegexCheck']);

        $fixtureFile = __DIR__.'/../Fixtures/Functional/custom_static_method.php';

        $result = $extractor->extract([$fixtureFile]);

        $this->assertCount(1, $result);
        $this->assertSame('/static-pattern/', $result[0]->pattern);
        $this->assertSame('MyClass::customRegexCheck()', $result[0]->source);
    }

    /**
     * Test that multiple preg functions in one file are all detected.
     */
    public function test_multiple_preg_functions_are_detected(): void
    {
        $fixtureFile = __DIR__.'/../Fixtures/Functional/multiple_preg_functions.php';

        $result = $this->extractor->extract([$fixtureFile]);

        $this->assertCount(3, $result);
        $this->assertSame('/pattern1/', $result[0]->pattern);
        $this->assertSame('/pattern2/', $result[1]->pattern);
        $this->assertSame('/pattern3/', $result[2]->pattern);
    }

    /**
     * Test mixed file with both regex and non-regex strings.
     */
    public function test_mixed_file_only_detects_regex(): void
    {
        $fixtureFile = __DIR__.'/../Fixtures/Functional/mixed_content.php';

        $result = $this->extractor->extract([$fixtureFile]);

        // Should only detect the preg_match pattern, not SQL, HTML, or other strings
        $this->assertCount(1, $result);
        $this->assertSame('/^valid-regex$/', $result[0]->pattern);
    }

    /**
     * Test that escaped quantifiers (like \+) are preserved when extracting
     * patterns with non-slash delimiters (e.g., Symfony's JSON content-type
     * check using "{...}i").
     */
    public function test_escaped_plus_quantifier_is_preserved(): void
    {
        $fixtureFile = __DIR__.'/../Fixtures/Functional/escaped_quantifier.php';

        $result = $this->extractor->extract([$fixtureFile]);

        $this->assertCount(1, $result);
        $this->assertSame('{^application/(?:\w+\++)*json$}i', $result[0]->pattern);
    }

    /**
     * Test that preg_quote arguments are NOT detected as regex patterns.
     * preg_quote takes a raw string, not a regex pattern.
     */
    public function test_preg_quote_is_ignored(): void
    {
        $fixtureFile = __DIR__.'/../Fixtures/Functional/preg_quote_call.php';

        $result = $this->extractor->extract([$fixtureFile]);

        $this->assertEmpty($result, 'preg_quote arguments should not be detected as regex patterns');
    }

    /**
     * Test that strings passed to preg_* are extracted even if invalid.
     */
    public function test_invalid_delimiters_are_extracted(): void
    {
        $fixtureFile = __DIR__.'/../Fixtures/Functional/invalid_delimiter.php';

        $result = $this->extractor->extract([$fixtureFile]);

        $this->assertCount(4, $result);
        $this->assertSame('?entryPoint=', $result[0]->pattern);
        $this->assertSame('?foo=bar&baz=qux', $result[1]->pattern);
        $this->assertSame('^', $result[2]->pattern);
        $this->assertSame('[', $result[3]->pattern);
    }

    /**
     * Test that files with binary content (null bytes) are skipped.
     */
    public function test_binary_content_is_ignored(): void
    {
        $fixtureFile = __DIR__.'/../Fixtures/Functional/binary_content.php';

        $result = $this->extractor->extract([$fixtureFile]);

        $this->assertEmpty($result, 'Files with binary content should be skipped entirely');
    }
}
