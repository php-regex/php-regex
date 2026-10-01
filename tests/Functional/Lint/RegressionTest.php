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

use PhpRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PhpRegex\Linter\PatternExtractor;
use PhpRegex\Linter\PatternOccurrence;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the linter to ensure false positives are avoided
 * and string escaping is handled correctly.
 */
final class RegressionTest extends TestCase
{
    private PatternExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new PatternExtractor(new TokenBasedExtractionStrategy());
    }

    /**
     * Test that Unicode hex sequences like \x{2000} are preserved correctly.
     * This was causing "Invalid range" errors because the backslash was being eaten.
     */
    public function test_unicode_hex_sequences_in_double_quoted_strings(): void
    {
        $occurrences = $this->extractFromFixture('regression_unicode_hex_double.php');

        $this->assertCount(1, $occurrences);
        $this->assertSame("/[\x{2000}-\x{2FFF}]/u", $occurrences[0]->pattern);
    }

    /**
     * Test that Unicode hex sequences work in single-quoted strings too.
     */
    public function test_unicode_hex_sequences_in_single_quoted_strings(): void
    {
        $occurrences = $this->extractFromFixture('regression_unicode_hex_single.php');

        $this->assertCount(1, $occurrences);
        $this->assertSame('/[\x{2000}-\x{2FFF}]/u', $occurrences[0]->pattern);
    }

    /**
     * Test that standard PHP escape sequences in double-quoted strings are handled.
     */
    public function test_standard_escape_sequences_in_double_quoted_strings(): void
    {
        $occurrences = $this->extractFromFixture('regression_escape_double.php');

        $this->assertCount(1, $occurrences);
        // The \n should be converted to an actual newline character
        $this->assertSame("/hello\nworld/", $occurrences[0]->pattern);
    }

    /**
     * Test that escape sequences in single-quoted strings are NOT interpreted
     * (except for \\ and \').
     */
    public function test_escape_sequences_in_single_quoted_strings(): void
    {
        $occurrences = $this->extractFromFixture('regression_escape_single.php');

        $this->assertCount(1, $occurrences);
        $this->assertSame('/hello\nworld/', $occurrences[0]->pattern);
    }

    /**
     * Test complex escaping with multiple backslashes.
     * In PHP: '\\\\' = two backslashes, "\\\\" = two backslashes
     */
    public function test_complex_backslash_escaping_single_quoted(): void
    {
        $occurrences = $this->extractFromFixture('regression_backslash_single.php');

        $this->assertCount(1, $occurrences);
        // Single-quoted '\\\\' becomes \\ (two backslashes become one each)
        $this->assertSame('/\\\\/', $occurrences[0]->pattern);
    }

    /**
     * Test complex escaping with multiple backslashes in double-quoted strings.
     */
    public function test_complex_backslash_escaping_double_quoted(): void
    {
        $occurrences = $this->extractFromFixture('regression_backslash_double.php');

        $this->assertCount(1, $occurrences);
        // Double-quoted "\\\\" becomes \\ (two backslashes become one each)
        $this->assertSame('/\\\\/', $occurrences[0]->pattern);
    }

    /**
     * Test that URL-like strings passed to preg_* are still extracted.
     */
    public function test_url_query_strings_are_extracted(): void
    {
        $occurrences = $this->extractFromFixture('regression_query_string.php');

        $this->assertCount(1, $occurrences);
        $this->assertSame('/?entryPoint=main&action=test', $occurrences[0]->pattern);
    }

    /**
     * Test that HTTP URLs passed to preg_* are still extracted.
     */
    public function test_http_urls_are_extracted(): void
    {
        $occurrences = $this->extractFromFixture('regression_url.php');

        $this->assertCount(1, $occurrences);
        $this->assertSame('/http://example.com/path/', $occurrences[0]->pattern);
    }

    /**
     * Test that file-path-like strings passed to preg_* are still extracted.
     */
    public function test_file_paths_are_extracted(): void
    {
        $occurrences = $this->extractFromFixture('regression_filepath.php');

        $this->assertCount(1, $occurrences);
        $this->assertSame('/path/to/some/file/', $occurrences[0]->pattern);
    }

    /**
     * Test that valid regexes ARE still detected.
     */
    public function test_valid_regex_is_detected(): void
    {
        $occurrences = $this->extractFromFixture('regression_case_insensitive.php');

        $this->assertCount(1, $occurrences);
        $this->assertSame('/^[a-z]+$/i', $occurrences[0]->pattern);
    }

    /**
     * Test that regexes with escaped delimiters are handled correctly.
     */
    public function test_regex_with_escaped_delimiter(): void
    {
        $occurrences = $this->extractFromFixture('regression_http_url.php');

        $this->assertCount(1, $occurrences);
        $this->assertSame('/https?:\/\/[^\/]+/', $occurrences[0]->pattern);
    }

    /**
     * Test that regexes with alternative delimiters work.
     */
    public function test_regex_with_alternative_delimiter(): void
    {
        $occurrences = $this->extractFromFixture('regression_hash_delimiter.php');

        $this->assertCount(1, $occurrences);
        $this->assertSame('#^/path/to/.*$#', $occurrences[0]->pattern);
    }

    /**
     * Test that regexes with tilde delimiter work.
     */
    public function test_regex_with_tilde_delimiter(): void
    {
        $occurrences = $this->extractFromFixture('regression_tilde_delimiter.php');

        $this->assertCount(1, $occurrences);
        $this->assertSame('~[a-z]+~i', $occurrences[0]->pattern);
    }

    /**
     * Test hex escape sequences in double-quoted strings.
     * \x41 should become 'A' (ASCII 65).
     */
    public function test_hex_escape_in_double_quoted_string(): void
    {
        $occurrences = $this->extractFromFixture('regression_hex_escape.php');

        $this->assertCount(1, $occurrences);
        // \x41 should be converted to 'A'
        $this->assertSame('/A+/', $occurrences[0]->pattern);
    }

    /**
     * Test octal escape sequences in double-quoted strings.
     * \101 should become 'A' (ASCII 65).
     */
    public function test_octal_escape_in_double_quoted_string(): void
    {
        $occurrences = $this->extractFromFixture('regression_octal_escape.php');

        $this->assertCount(1, $occurrences);
        // \101 (octal) should be converted to 'A'
        $this->assertSame('/A+/', $occurrences[0]->pattern);
    }

    /**
     * Test PHP 7+ Unicode escape sequences.
     * \u{0041} should become 'A'.
     */
    public function test_php7_unicode_escape_in_double_quoted_string(): void
    {
        $occurrences = $this->extractFromFixture('regression_unicode_escape.php');

        $this->assertCount(1, $occurrences);
        // \u{0041} should be converted to 'A'
        $this->assertSame('/A+/', $occurrences[0]->pattern);
    }

    /**
     * Test that dollar sign escaping works in double-quoted strings.
     */
    public function test_dollar_sign_escape_in_double_quoted_string(): void
    {
        $occurrences = $this->extractFromFixture('regression_variable.php');

        $this->assertCount(1, $occurrences);
        // \$ should become literal $
        $this->assertSame('/$var/', $occurrences[0]->pattern);
    }

    /**
     * Test that preg_match_all is also detected.
     */
    public function test_preg_match_all_is_detected(): void
    {
        $occurrences = $this->extractFromFixture('regression_match_all.php');

        $this->assertCount(1, $occurrences);
        $this->assertSame('/\d+/', $occurrences[0]->pattern);
    }

    /**
     * Test that preg_replace is also detected.
     */
    public function test_preg_replace_is_detected(): void
    {
        $occurrences = $this->extractFromFixture('regression_preg_replace.php');

        $this->assertCount(1, $occurrences);
        $this->assertSame('/\s+/', $occurrences[0]->pattern);
    }

    /**
     * Helper method to extract patterns from fixture file.
     *
     * @return array<PatternOccurrence>
     */
    private function extractFromFixture(string $fixtureName): array
    {
        $fixtureFile = __DIR__.'/../../Fixtures/Lint/'.$fixtureName;

        return $this->extractor->extract([$fixtureFile]);
    }
}
