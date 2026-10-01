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

namespace PhpRegex\Tests\Integration;

use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

/**
 * Tests compatibility between parsed/compiled patterns and PHP's native preg_* functions.
 * Ensures that patterns processed through the parser behave identically to the originals.
 */
final class PregFunctionCompatibilityTest extends TestCase
{
    private Regex $regexService;

    private PatternPrinter $compiler;

    protected function setUp(): void
    {
        $this->regexService = Regex::create();
        $this->compiler = new PatternPrinter();
    }

    public function test_preg_match_simple_literal(): void
    {
        $pattern = '/hello/';
        $compiled = $this->roundTripPattern($pattern);
        $subject = 'say hello world';

        $originalResult = preg_match($pattern, $subject, $originalMatches);
        $compiledResult = preg_match($compiled, $subject, $compiledMatches);

        $this->assertSame($originalResult, $compiledResult);
        $this->assertSame($originalMatches, $compiledMatches);
    }

    public function test_preg_match_with_flags(): void
    {
        $pattern = '/HELLO/i';
        $compiled = $this->roundTripPattern($pattern);
        $subject = 'say hello world';

        $originalResult = preg_match($pattern, $subject, $originalMatches);
        $compiledResult = preg_match($compiled, $subject, $compiledMatches);

        $this->assertSame($originalResult, $compiledResult);
        $this->assertSame($originalMatches, $compiledMatches);
    }

    public function test_preg_match_with_offset(): void
    {
        $pattern = '/\d+/';
        $compiled = $this->roundTripPattern($pattern);
        $subject = 'abc123def456';

        $originalResult = preg_match($pattern, $subject, $originalMatches, 0, 6);
        $compiledResult = preg_match($compiled, $subject, $compiledMatches, 0, 6);

        $this->assertSame($originalResult, $compiledResult);
        $this->assertSame($originalMatches, $compiledMatches);
    }

    public function test_preg_match_capturing_groups(): void
    {
        $pattern = '/(\w+)@(\w+)\.(\w+)/';
        $compiled = $this->roundTripPattern($pattern);
        $subject = 'user@example.com';

        $originalResult = preg_match($pattern, $subject, $originalMatches);
        $compiledResult = preg_match($compiled, $subject, $compiledMatches);

        $this->assertSame($originalResult, $compiledResult);
        $this->assertSame($originalMatches, $compiledMatches);
        $this->assertCount(4, $compiledMatches); // Full match + 3 groups
    }

    public function test_preg_match_named_groups(): void
    {
        $pattern = '/(?<user>\w+)@(?<domain>\w+\.\w+)/';
        $compiled = $this->roundTripPattern($pattern);
        $subject = 'admin@test.com';

        preg_match($pattern, $subject, $originalMatches);
        preg_match($compiled, $subject, $compiledMatches);

        $this->assertArrayHasKey('user', $originalMatches);
        $this->assertArrayHasKey('domain', $originalMatches);
        $this->assertArrayHasKey('user', $compiledMatches);
        $this->assertArrayHasKey('domain', $compiledMatches);
        $this->assertSame($originalMatches['user'], $compiledMatches['user']);
        $this->assertSame($originalMatches['domain'], $compiledMatches['domain']);
    }

    public function test_preg_match_no_match(): void
    {
        $pattern = '/xyz/';
        $compiled = $this->roundTripPattern($pattern);
        $subject = 'abc def';

        $originalResult = preg_match($pattern, $subject);
        $compiledResult = preg_match($compiled, $subject);

        $this->assertSame(0, $originalResult);
        $this->assertSame($originalResult, $compiledResult);
    }

    public function test_preg_match_all_basic(): void
    {
        $pattern = '/\d+/';
        $compiled = $this->roundTripPattern($pattern);
        $subject = 'abc123def456ghi789';

        $originalCount = preg_match_all($pattern, $subject, $originalMatches);
        $compiledCount = preg_match_all($compiled, $subject, $compiledMatches);

        $this->assertSame($originalCount, $compiledCount);
        $this->assertSame($originalMatches, $compiledMatches);
        $this->assertSame(3, $compiledCount);
    }

    public function test_preg_match_all_with_pattern_order(): void
    {
        $pattern = '/(\w+)=(\d+)/';
        $compiled = $this->roundTripPattern($pattern);
        $subject = 'foo=123 bar=456';

        preg_match_all($pattern, $subject, $originalMatches, \PREG_PATTERN_ORDER);
        preg_match_all($compiled, $subject, $compiledMatches, \PREG_PATTERN_ORDER);

        $this->assertSame($originalMatches, $compiledMatches);
    }

    public function test_preg_match_all_with_set_order(): void
    {
        $pattern = '/(\w+)=(\d+)/';
        $compiled = $this->roundTripPattern($pattern);
        $subject = 'foo=123 bar=456';

        preg_match_all($pattern, $subject, $originalMatches, \PREG_SET_ORDER);
        preg_match_all($compiled, $subject, $compiledMatches, \PREG_SET_ORDER);

        $this->assertSame($originalMatches, $compiledMatches);
    }

    public function test_preg_match_all_with_offset_capture(): void
    {
        $pattern = '/\w+/';
        $compiled = $this->roundTripPattern($pattern);
        $subject = 'foo bar baz';

        preg_match_all($pattern, $subject, $originalMatches, \PREG_OFFSET_CAPTURE);
        preg_match_all($compiled, $subject, $compiledMatches, \PREG_OFFSET_CAPTURE);

        $this->assertSame($originalMatches, $compiledMatches);
        // Verify offset capture structure
        $this->assertIsArray($compiledMatches[0][0]);
        $this->assertCount(2, $compiledMatches[0][0]); // [match, offset]
    }

    public function test_preg_match_all_no_matches(): void
    {
        $pattern = '/xyz/';
        $compiled = $this->roundTripPattern($pattern);
        $subject = 'abc def';

        $originalCount = preg_match_all($pattern, $subject, $originalMatches);
        $compiledCount = preg_match_all($compiled, $subject, $compiledMatches);

        $this->assertSame(0, $originalCount);
        $this->assertSame($originalCount, $compiledCount);
    }

    public function test_preg_replace_basic(): void
    {
        $pattern = '/\d+/';
        $compiled = $this->roundTripPattern($pattern);
        $subject = 'abc123def456';
        $replacement = 'XXX';

        $originalResult = preg_replace($pattern, $replacement, $subject);
        $compiledResult = preg_replace($compiled, $replacement, $subject);

        $this->assertSame($originalResult, $compiledResult);
        $this->assertSame('abcXXXdefXXX', $compiledResult);
    }

    public function test_preg_replace_with_backreferences(): void
    {
        $pattern = '/(\w+)@(\w+)/';
        $compiled = $this->roundTripPattern($pattern);
        $subject = 'user@example';
        $replacement = '$2@$1';

        $originalResult = preg_replace($pattern, $replacement, $subject);
        $compiledResult = preg_replace($compiled, $replacement, $subject);

        $this->assertSame($originalResult, $compiledResult);
        $this->assertSame('example@user', $compiledResult);
    }

    public function test_preg_replace_multiple_occurrences(): void
    {
        $pattern = '/foo/';
        $compiled = $this->roundTripPattern($pattern);
        $subject = 'foo bar foo baz foo';
        $replacement = 'qux';

        $originalResult = preg_replace($pattern, $replacement, $subject);
        $compiledResult = preg_replace($compiled, $replacement, $subject);

        $this->assertSame($originalResult, $compiledResult);
        $this->assertSame('qux bar qux baz qux', $compiledResult);
    }

    public function test_preg_replace_with_limit(): void
    {
        $pattern = '/\d/';
        $compiled = $this->roundTripPattern($pattern);
        $subject = '1a2b3c4d';
        $replacement = 'X';

        $originalResult = preg_replace($pattern, $replacement, $subject, 2);
        $compiledResult = preg_replace($compiled, $replacement, $subject, 2);

        $this->assertSame($originalResult, $compiledResult);
        $this->assertSame('XaXb3c4d', $compiledResult);
    }

    public function test_preg_replace_no_match(): void
    {
        $pattern = '/xyz/';
        $compiled = $this->roundTripPattern($pattern);
        $subject = 'abc def';
        $replacement = 'XXX';

        $originalResult = preg_replace($pattern, $replacement, $subject);
        $compiledResult = preg_replace($compiled, $replacement, $subject);

        $this->assertSame($originalResult, $compiledResult);
        $this->assertSame($subject, $compiledResult);
    }

    public function test_preg_replace_callback_basic(): void
    {
        $pattern = '/\d+/';
        $compiled = $this->roundTripPattern($pattern);
        $subject = 'abc123def456';

        $originalResult = preg_replace_callback($pattern, static fn (array $matches): string => '['.(string) $matches[0].']', $subject);
        $compiledResult = preg_replace_callback($compiled, static fn (array $matches): string => '['.(string) $matches[0].']', $subject);

        $this->assertSame($originalResult, $compiledResult);
        $this->assertSame('abc[123]def[456]', $compiledResult);
    }

    public function test_preg_replace_callback_with_groups(): void
    {
        $pattern = '/(\w+)=(\d+)/';
        $compiled = $this->roundTripPattern($pattern);
        $subject = 'foo=123 bar=456';

        $originalResult = preg_replace_callback($pattern, static fn (array $matches): string => (string) $matches[1].':'.((int) $matches[2] * 2), $subject);
        $compiledResult = preg_replace_callback($compiled, static fn (array $matches): string => (string) $matches[1].':'.((int) $matches[2] * 2), $subject);

        $this->assertSame($originalResult, $compiledResult);
        $this->assertSame('foo:246 bar:912', $compiledResult);
    }

    public function test_preg_replace_callback_with_named_captures(): void
    {
        $pattern = '/(?<name>\w+)@(?<domain>\w+)/';
        $compiled = $this->roundTripPattern($pattern);
        $subject = 'user@example';

        $originalResult = preg_replace_callback($pattern, static fn (array $matches): string => strtoupper((string) $matches['name']).'@'.strtoupper((string) $matches['domain']), $subject);
        $compiledResult = preg_replace_callback($compiled, static fn (array $matches): string => strtoupper((string) $matches['name']).'@'.strtoupper((string) $matches['domain']), $subject);

        $this->assertSame($originalResult, $compiledResult);
        $this->assertSame('USER@EXAMPLE', $compiledResult);
    }

    public function test_preg_replace_callback_uppercase_conversion(): void
    {
        $pattern = '/\b\w+\b/';
        $compiled = $this->roundTripPattern($pattern);
        $subject = 'hello world';

        $originalResult = preg_replace_callback($pattern, static fn (array $matches): string => strtoupper((string) $matches[0]), $subject);
        $compiledResult = preg_replace_callback($compiled, static fn (array $matches): string => strtoupper((string) $matches[0]), $subject);

        $this->assertSame($originalResult, $compiledResult);
        $this->assertSame('HELLO WORLD', $compiledResult);
    }

    public function test_preg_split_basic(): void
    {
        $pattern = '/\s+/';
        $compiled = $this->roundTripPattern($pattern);
        $subject = 'foo   bar    baz';

        $originalResult = preg_split($pattern, $subject);
        $compiledResult = preg_split($compiled, $subject);

        $this->assertSame($originalResult, $compiledResult);
        $this->assertSame(['foo', 'bar', 'baz'], $compiledResult);
    }

    public function test_preg_split_with_limit(): void
    {
        $pattern = '/,/';
        $compiled = $this->roundTripPattern($pattern);
        $subject = 'a,b,c,d,e';

        $originalResult = preg_split($pattern, $subject, 3);
        $compiledResult = preg_split($compiled, $subject, 3);

        $this->assertSame($originalResult, $compiledResult);
        $this->assertSame(['a', 'b', 'c,d,e'], $compiledResult);
    }

    public function test_preg_split_with_delim_capture(): void
    {
        $pattern = '/([,;])/';
        $compiled = $this->roundTripPattern($pattern);
        $subject = 'a,b;c,d';

        $originalResult = preg_split($pattern, $subject, -1, \PREG_SPLIT_DELIM_CAPTURE);
        $compiledResult = preg_split($compiled, $subject, -1, \PREG_SPLIT_DELIM_CAPTURE);

        $this->assertSame($originalResult, $compiledResult);
        $this->assertIsArray($compiledResult);
        $this->assertContains(',', $compiledResult);
        $this->assertContains(';', $compiledResult);
    }

    public function test_preg_split_no_empty(): void
    {
        $pattern = '/\s+/';
        $compiled = $this->roundTripPattern($pattern);
        $subject = '  foo   bar  ';

        $originalResult = preg_split($pattern, $subject, -1, \PREG_SPLIT_NO_EMPTY);
        $compiledResult = preg_split($compiled, $subject, -1, \PREG_SPLIT_NO_EMPTY);

        $this->assertSame($originalResult, $compiledResult);
        $this->assertSame(['foo', 'bar'], $compiledResult);
    }

    public function test_preg_split_offset_capture(): void
    {
        $pattern = '/,/';
        $compiled = $this->roundTripPattern($pattern);
        $subject = 'a,b,c';

        $originalResult = preg_split($pattern, $subject, -1, \PREG_SPLIT_OFFSET_CAPTURE);
        $compiledResult = preg_split($compiled, $subject, -1, \PREG_SPLIT_OFFSET_CAPTURE);

        $this->assertSame($originalResult, $compiledResult);

        $this->assertIsArray($compiledResult);
        $firstItem = $compiledResult[0];
        $this->assertIsArray($firstItem);
        $this->assertCount(2, $firstItem);
    }

    public function test_preg_grep_basic(): void
    {
        $pattern = '/^\d+$/';
        $compiled = $this->roundTripPattern($pattern);
        $input = ['abc', '123', 'def', '456', 'ghi'];

        $originalResult = preg_grep($pattern, $input);
        $compiledResult = preg_grep($compiled, $input);

        $this->assertSame($originalResult, $compiledResult);
        $this->assertSame([1 => '123', 3 => '456'], $compiledResult);
    }

    public function test_preg_grep_inverted(): void
    {
        $pattern = '/^\d+$/';
        $compiled = $this->roundTripPattern($pattern);
        $input = ['abc', '123', 'def', '456', 'ghi'];

        $originalResult = preg_grep($pattern, $input, \PREG_GREP_INVERT);
        $compiledResult = preg_grep($compiled, $input, \PREG_GREP_INVERT);

        $this->assertSame($originalResult, $compiledResult);
        $this->assertSame([0 => 'abc', 2 => 'def', 4 => 'ghi'], $compiledResult);
    }

    public function test_preg_grep_with_anchors(): void
    {
        $pattern = '/^test/';
        $compiled = $this->roundTripPattern($pattern);
        $input = ['test1', 'test2', 'notest', 'test3'];

        $originalResult = preg_grep($pattern, $input);
        $compiledResult = preg_grep($compiled, $input);

        $this->assertSame($originalResult, $compiledResult);
    }

    public function test_preg_grep_case_insensitive(): void
    {
        $pattern = '/TEST/i';
        $compiled = $this->roundTripPattern($pattern);
        $input = ['Test', 'test', 'TEST', 'other'];

        $originalResult = preg_grep($pattern, $input);
        $compiledResult = preg_grep($compiled, $input);

        $this->assertSame($originalResult, $compiledResult);
        $this->assertIsArray($compiledResult);
        $this->assertCount(3, $compiledResult);
    }

    public function test_preg_grep_empty_array(): void
    {
        $pattern = '/test/';
        $compiled = $this->roundTripPattern($pattern);
        $input = [];

        $originalResult = preg_grep($pattern, $input);
        $compiledResult = preg_grep($compiled, $input);

        $this->assertSame($originalResult, $compiledResult);
        $this->assertSame([], $compiledResult);
    }

    public function test_preg_quote_special_characters(): void
    {
        $string = 'a.b*c?d+e(f)g[h]i{j}k^l$m|n\\o';
        $quoted = preg_quote($string, '/');

        // Verify that the quoted string can be used in a pattern
        $pattern = '/'.$quoted.'/';
        $this->assertMatchesRegularExpression($pattern, $string);
    }

    public function test_preg_quote_with_delimiter(): void
    {
        $string = 'test/path';
        $quoted = preg_quote($string, '/');

        $this->assertStringContainsString('\/', $quoted);

        $pattern = '/'.$quoted.'/';
        $this->assertMatchesRegularExpression($pattern, $string);
    }

    public function test_preg_quote_without_delimiter(): void
    {
        $string = 'test.path';
        $quoted = preg_quote($string);

        $this->assertStringContainsString('\.', $quoted);
        $this->assertStringNotContainsString('\/', $quoted);
    }

    public function test_preg_quote_preserves_literals(): void
    {
        $string = 'hello world';
        $quoted = preg_quote($string, '/');

        // No special chars, should be unchanged
        $this->assertSame($string, $quoted);
    }

    public function test_preg_quote_integration_with_parse(): void
    {
        // Demonstrate using preg_quote with the parser
        $literal = 'user.name+tag@example.com';
        $escaped = preg_quote($literal, '/');
        $pattern = '/^'.$escaped.'$/';

        // Parse and compile the pattern
        $compiled = $this->roundTripPattern($pattern);

        // Should match the exact literal
        $this->assertMatchesRegularExpression($compiled, $literal);

        // Should not match variations
        $this->assertDoesNotMatchRegularExpression($compiled, 'userXnameXtag@exampleXcom');
    }

    public function test_all_preg_functions_preserve_behavior(): void
    {
        $pattern = '/\b\w{3,}\b/';
        $compiled = $this->roundTripPattern($pattern);
        $subject = 'The quick brown fox';

        // preg_match
        $m1 = preg_match($pattern, $subject);
        $m2 = preg_match($compiled, $subject);
        $this->assertSame($m1, $m2);

        // preg_match_all
        $c1 = preg_match_all($pattern, $subject, $matches1);
        $c2 = preg_match_all($compiled, $subject, $matches2);
        $this->assertSame($c1, $c2);

        // preg_replace
        $r1 = preg_replace($pattern, 'X', $subject);
        $r2 = preg_replace($compiled, 'X', $subject);
        $this->assertSame($r1, $r2);

        // preg_split
        $s1 = preg_split($pattern, $subject);
        $s2 = preg_split($compiled, $subject);
        $this->assertSame($s1, $s2);

        // preg_grep
        $words = ['The', 'quick', 'brown', 'fox'];
        $g1 = preg_grep($pattern, $words);
        $g2 = preg_grep($compiled, $words);
        $this->assertSame($g1, $g2);
    }

    /**
     * Helper method to compile a pattern through parse -> compile cycle.
     */
    private function roundTripPattern(string $pattern): string
    {
        $ast = $this->regexService->parse($pattern);

        return $ast->accept($this->compiler);
    }
}
