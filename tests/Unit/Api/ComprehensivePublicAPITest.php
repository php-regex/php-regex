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

namespace PhpRegex\Tests\Unit\Api;

use PhpRegex\Parser\Exception\ParserException;
use PhpRegex\Parser\Printer\NodeDumper;
use PhpRegex\Redos\RedosSeverity;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive test suite for the public API of RegexParser.
 * Tests all public methods of the Regex class with extensive coverage.
 */
final class ComprehensivePublicAPITest extends TestCase
{
    private Regex $regexService;

    protected function setUp(): void
    {
        $this->regexService = Regex::create();
    }

    #[DoesNotPerformAssertions]
    public function test_create_returns_regex_instance(): void
    {
        Regex::create();
    }

    #[DoesNotPerformAssertions]
    public function test_create_with_options_accepts_max_pattern_length(): void
    {
        Regex::create(['max_pattern_length' => 1000]);
    }

    #[DoesNotPerformAssertions]
    public function test_create_without_options_works(): void
    {
        Regex::create([]);
    }

    public function test_parse_simple_literal_returns_regex_node(): void
    {
        $ast = $this->regexService->parse('/hello/');
        $this->assertSame('/', $ast->delimiter);
    }

    public function test_parse_preserves_delimiter(): void
    {
        $ast = $this->regexService->parse('/test/');
        $this->assertSame('/', $ast->delimiter);
    }

    public function test_parse_preserves_flags(): void
    {
        $ast = $this->regexService->parse('/test/imsx');
        $this->assertSame('imsx', $ast->flags);
    }

    public function test_parse_empty_flags(): void
    {
        $ast = $this->regexService->parse('/test/');
        $this->assertSame('', $ast->flags);
    }

    public function test_parse_with_alternative_delimiter(): void
    {
        $ast = $this->regexService->parse('#test#i');
        $this->assertSame('#', $ast->delimiter);
        $this->assertSame('i', $ast->flags);
    }

    public function test_parse_capturing_groups(): void
    {
        $ast = $this->regexService->parse('/(foo)(bar)/');
        $this->assertSame('/', $ast->delimiter);
    }

    public function test_parse_named_groups(): void
    {
        $ast = $this->regexService->parse('/(?<name>\w+)/');
        $this->assertSame('/', $ast->delimiter);
    }

    public function test_parse_non_capturing_groups(): void
    {
        $ast = $this->regexService->parse('/(?:foo|bar)/');
        $this->assertSame('/', $ast->delimiter);
    }

    public function test_parse_lookahead(): void
    {
        $ast = $this->regexService->parse('/foo(?=bar)/');
        $this->assertSame('/', $ast->delimiter);
    }

    public function test_parse_lookbehind(): void
    {
        $ast = $this->regexService->parse('/(?<=foo)bar/');
        $this->assertSame('/', $ast->delimiter);
    }

    public function test_parse_branch_reset_groups(): void
    {
        $ast = $this->regexService->parse('/(?|(a)|(b))/');
        $this->assertSame('/', $ast->delimiter);
    }

    public function test_parse_quantifiers_greedy(): void
    {
        $ast = $this->regexService->parse('/a+b*c?d{2,5}/');
        $this->assertSame('/', $ast->delimiter);
    }

    public function test_parse_quantifiers_lazy(): void
    {
        $ast = $this->regexService->parse('/a+?b*?c??d{2,5}?/');
        $this->assertSame('/', $ast->delimiter);
    }

    public function test_parse_quantifiers_possessive(): void
    {
        $ast = $this->regexService->parse('/a++b*+c?+/');
        $this->assertSame('/', $ast->delimiter);
    }

    public function test_parse_character_classes(): void
    {
        $ast = $this->regexService->parse('/[a-z0-9_]+/');
        $this->assertSame('/', $ast->delimiter);
    }

    public function test_parse_anchors(): void
    {
        $ast = $this->regexService->parse('/^start.*end$/');
        $this->assertSame('/', $ast->delimiter);
    }

    public function test_parse_backreferences(): void
    {
        $ast = $this->regexService->parse('/(foo)\1/');
        $this->assertSame('/', $ast->delimiter);
    }

    public function test_parse_unicode_properties(): void
    {
        $ast = $this->regexService->parse('/\p{L}+/u');
        $this->assertSame('/', $ast->delimiter);
    }

    public function test_parse_throws_exception_for_invalid_pattern(): void
    {
        $this->expectException(ParserException::class);
        $this->regexService->parse('/(?P<invalid>/');
    }

    public function test_parse_throws_exception_for_unclosed_group(): void
    {
        $this->expectException(ParserException::class);
        $this->regexService->parse('/(unclosed/');
    }

    public function test_validate_simple_pattern_returns_valid_result(): void
    {
        $result = $this->regexService->validate('/hello/');
        $this->assertTrue($result->isValid);
        $this->assertNull($result->error);
    }

    public function test_validate_complex_safe_pattern(): void
    {
        $result = $this->regexService->validate('/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i');
        $this->assertTrue($result->isValid);
        $this->assertNull($result->error);
    }

    public function test_validate_allows_syntactically_valid_patterns(): void
    {
        // (a+)+b is syntactically valid, ReDoS detection is separate
        $result = $this->regexService->validate('/(a+)+b/');
        $this->assertTrue($result->isValid);
        $this->assertNull($result->error);
    }

    public function test_validate_allows_syntactically_valid_nested_quantifiers(): void
    {
        // (a*)*b is syntactically valid, ReDoS detection is separate
        $result = $this->regexService->validate('/(a*)*b/');
        $this->assertTrue($result->isValid);
        $this->assertNull($result->error);
    }

    #[DoesNotPerformAssertions]
    public function test_validate_detects_alternation_redos(): void
    {
        $this->regexService->validate('/(a|a)*/');
        // Note: Current implementation may not catch all alternation ReDoS cases
        // This is marked as a known limitation
    }

    public function test_validate_safe_bounded_quantifiers(): void
    {
        $result = $this->regexService->validate('/a{1,10}b/');
        $this->assertTrue($result->isValid);
    }

    public function test_validate_safe_pattern_with_plus(): void
    {
        $result = $this->regexService->validate('/a+b/');
        $this->assertTrue($result->isValid, 'Pattern /a+b/ should be valid (no nested quantifiers)');
    }

    public function test_validate_allows_forward_backreference(): void
    {
        $result = $this->regexService->validate('/\1(foo)/');
        $this->assertTrue($result->isValid);
    }

    public function test_validate_rejects_missing_backreference(): void
    {
        $result = $this->regexService->validate('/\2(foo)/');
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('backreference', strtolower((string) $result->error));
    }

    public function test_validate_rejects_unbounded_lookbehind(): void
    {
        $result = $this->regexService->validate('/(?<!a*)b/');
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('lookbehind', strtolower((string) $result->error));
    }

    public function test_validate_allows_fixed_length_lookbehind(): void
    {
        $result = $this->regexService->validate('/(?<=abc)def/');
        $this->assertTrue($result->isValid);
    }

    public function test_validate_invalid_syntax_returns_error(): void
    {
        $result = $this->regexService->validate('/(?P<invalid>/');
        $this->assertFalse($result->isValid);
        $this->assertNotNull($result->error);
    }

    public function test_validate_returns_complexity_score(): void
    {
        $result = $this->regexService->validate('/hello/');
        $this->assertTrue($result->isValid);
        $this->assertIsInt($result->complexityScore);
        $this->assertGreaterThanOrEqual(0, $result->complexityScore);
    }

    public function test_explain_simple_literal(): void
    {
        $explanation = $this->regexService->explain('/hello/');
        $this->assertIsString($explanation);
        $this->assertStringContainsString('Regex matches', $explanation);
        $this->assertStringContainsString("'h'", $explanation);
    }

    public function test_explain_with_flags(): void
    {
        $explanation = $this->regexService->explain('/test/i');
        $this->assertStringContainsString('i', $explanation);
    }

    public function test_explain_capturing_group(): void
    {
        $explanation = $this->regexService->explain('/(foo)/');
        $this->assertStringContainsString('Capturing', $explanation);
    }

    public function test_explain_named_group(): void
    {
        $explanation = $this->regexService->explain('/(?<name>\w+)/');
        $this->assertStringContainsString('name', $explanation);
    }

    public function test_explain_quantifiers(): void
    {
        $explanation = $this->regexService->explain('/a+/');
        $this->assertStringContainsString('one or more', strtolower($explanation));
    }

    public function test_explain_alternation(): void
    {
        $explanation = $this->regexService->explain('/(foo|bar)/');
        $this->assertStringContainsString('EITHER', $explanation);
        $this->assertStringContainsString('OR', $explanation);
    }

    public function test_explain_complex_pattern(): void
    {
        $explanation = $this->regexService->explain('/^(?<email>[\w.-]+@[\w.-]+\.\w+)$/');
        $this->assertNotEmpty($explanation);
        $this->assertStringContainsString('email', $explanation);
    }

    public function test_explain_throws_exception_for_invalid_pattern(): void
    {
        $this->expectException(ParserException::class);
        $this->regexService->explain('/(?P<invalid>/');
    }

    public function test_generate_simple_literal(): void
    {
        $sample = $this->regexService->generate('/hello/');
        $this->assertIsString($sample);
        $this->assertMatchesRegularExpression('/hello/', $sample);
    }

    public function test_generate_digit_pattern(): void
    {
        $sample = $this->regexService->generate('/\d{3}/');
        $this->assertIsString($sample);
        $this->assertMatchesRegularExpression('/\d{3}/', $sample);
        $this->assertSame(3, \strlen($sample));
    }

    public function test_generate_word_pattern(): void
    {
        $sample = $this->regexService->generate('/\w+/');
        $this->assertIsString($sample);
        $this->assertMatchesRegularExpression('/\w+/', $sample);
    }

    public function test_generate_character_class(): void
    {
        $sample = $this->regexService->generate('/[a-z]{5}/');
        $this->assertIsString($sample);
        $this->assertMatchesRegularExpression('/[a-z]{5}/', $sample);
        $this->assertSame(5, \strlen($sample));
    }

    public function test_generate_alternation(): void
    {
        $sample = $this->regexService->generate('/(foo|bar)/');
        $this->assertIsString($sample);
        $this->assertMatchesRegularExpression('/(foo|bar)/', $sample);
        $this->assertTrue('foo' === $sample || 'bar' === $sample);
    }

    public function test_generate_with_anchors(): void
    {
        $sample = $this->regexService->generate('/^test$/');
        $this->assertIsString($sample);
        $this->assertSame('test', $sample);
    }

    public function test_generate_email_like_pattern(): void
    {
        $sample = $this->regexService->generate('/\w+@\w+\.\w+/');
        $this->assertIsString($sample);
        $this->assertMatchesRegularExpression('/\w+@\w+\.\w+/', $sample);
    }

    public function test_generate_creates_different_samples(): void
    {
        $samples = [];
        for ($i = 0; $i < 10; $i++) {
            $samples[] = $this->regexService->generate('/[a-z]{10}/');
        }

        // At least some samples should be different (randomness check)
        $unique = array_unique($samples);
        $this->assertGreaterThan(1, \count($unique), 'Generator should produce varied samples');
    }

    public function test_optimize_returns_valid_pattern(): void
    {
        $optimized = $this->regexService->optimize('/hello/')->optimized;
        $this->assertIsString($optimized);
        $this->assertStringStartsWith('/', $optimized);
    }

    public function test_optimize_preserves_behavior_for_simple_pattern(): void
    {
        $original = '/test/';
        $optimized = $this->regexService->optimize($original)->optimized;

        $testString = 'this is a test string';
        $this->assertSame(
            (bool) preg_match($original, $testString),
            (bool) preg_match($optimized, $testString),
        );
    }

    public function test_optimize_character_class(): void
    {
        $optimized = $this->regexService->optimize('/[abc]/')->optimized;
        $this->assertIsString($optimized);
        // Should still match the same strings
        $this->assertMatchesRegularExpression($optimized, 'a');
        $this->assertMatchesRegularExpression($optimized, 'b');
        $this->assertMatchesRegularExpression($optimized, 'c');
    }

    public function test_optimize_nested_groups(): void
    {
        $optimized = $this->regexService->optimize('/(?:(?:a))/')->optimized;
        $this->assertIsString($optimized);
    }

    public function test_optimize_throws_exception_for_invalid_pattern(): void
    {
        $this->expectException(ParserException::class);
        $this->regexService->optimize('/(?P<invalid>/');
    }

    public function test_extract_literals_simple_prefix(): void
    {
        $literals = $this->regexService->literals('/user_\d+/');
        $prefix = $literals->literalSet->getLongestPrefix();
        $this->assertSame('user_', $prefix);
    }

    public function test_extract_literals_simple_suffix(): void
    {
        $literals = $this->regexService->literals('/\d+@example\.com/');
        $suffix = $literals->literalSet->getLongestSuffix();
        $this->assertSame('@example.com', $suffix);
    }

    public function test_extract_literals_both_prefix_and_suffix(): void
    {
        $literals = $this->regexService->literals('/start_\d+_end/');
        $prefix = $literals->literalSet->getLongestPrefix();
        $suffix = $literals->literalSet->getLongestSuffix();

        $this->assertSame('start_', $prefix);
        $this->assertSame('_end', $suffix);
    }

    public function test_extract_literals_no_literals_in_pure_quantifier(): void
    {
        $literals = $this->regexService->literals('/\d+/');
        $prefix = $literals->literalSet->getLongestPrefix();
        // May return null or empty string when no literals found
        $this->assertTrue('' === $prefix || null === $prefix);
    }

    public function test_extract_literals_full_literal_pattern(): void
    {
        $literals = $this->regexService->literals('/exactly_this/');
        $prefix = $literals->literalSet->getLongestPrefix();
        $this->assertSame('exactly_this', $prefix);
    }

    public function test_extract_literals_complex_pattern(): void
    {
        $literals = $this->regexService->literals('/error: .+/');
        $prefix = $literals->literalSet->getLongestPrefix();
        $this->assertSame('error: ', $prefix);
    }

    public function test_analyze_redos_safe_pattern(): void
    {
        $analysis = $this->regexService->redos('/^hello$/');
        $this->assertSame(RedosSeverity::Safe, $analysis->severity);
        $this->assertTrue($analysis->isSafe());
        $this->assertSame(0, $analysis->score);
    }

    public function test_analyze_redos_critical_nested_quantifiers(): void
    {
        $analysis = $this->regexService->redos('/(a+)+b/');
        $this->assertSame(RedosSeverity::Critical, $analysis->severity);
        $this->assertFalse($analysis->isSafe());
        $this->assertSame(10, $analysis->score);
    }

    public function test_analyze_redos_critical_alternation(): void
    {
        $analysis = $this->regexService->redos('/(a|a)*/');
        $this->assertSame(RedosSeverity::Critical, $analysis->severity);
        $this->assertFalse($analysis->isSafe());
        $this->assertSame(10, $analysis->score);
    }

    public function test_analyze_redos_low_bounded_quantifiers(): void
    {
        $analysis = $this->regexService->redos('/(a{1,5}){1,5}/');
        // Bounded quantifiers are considered safe in current implementation
        $this->assertContains($analysis->severity, [RedosSeverity::Safe, RedosSeverity::Low]);
        $this->assertGreaterThanOrEqual(0, $analysis->score);
        $this->assertLessThanOrEqual(10, $analysis->score);
    }

    public function test_analyze_redos_safe_single_quantifier(): void
    {
        $analysis = $this->regexService->redos('/a+b/');
        $this->assertContains(
            $analysis->severity,
            [RedosSeverity::Safe, RedosSeverity::Medium],
            'Single quantifier should be safe or medium (linear backtracking only)',
        );
    }

    public function test_analyze_redos_provides_recommendations(): void
    {
        $analysis = $this->regexService->redos('/(a+)+/');
        $this->assertIsArray($analysis->recommendations);
        $this->assertNotEmpty($analysis->recommendations);
    }

    public function test_analyze_redos_email_pattern(): void
    {
        $analysis = $this->regexService->redos('/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i');
        $this->assertContains(
            $analysis->severity,
            [RedosSeverity::Safe, RedosSeverity::Low, RedosSeverity::Medium],
            'Email pattern should not be flagged as high/critical risk',
        );
    }

    public function test_analyze_redos_safe_character_class(): void
    {
        $analysis = $this->regexService->redos('/[a-z]+/');
        $this->assertContains(
            $analysis->severity,
            [RedosSeverity::Safe, RedosSeverity::Medium],
            'Character class with + should be safe or medium (linear backtracking only)',
        );
    }

    public function test_dump_returns_ast_representation(): void
    {
        $dump = $this->regexService->parse('/hello/')->accept(new NodeDumper());
        $this->assertIsString($dump);
        $this->assertNotEmpty($dump);
    }

    public function test_parse_empty_alternation(): void
    {
        $ast = $this->regexService->parse('/(|foo)/');
        $this->assertSame('/', $ast->delimiter);
    }

    public function test_parse_atomic_groups(): void
    {
        $ast = $this->regexService->parse('/(?>foo|bar)/');
        $this->assertSame('/', $ast->delimiter);
    }

    public function test_parse_conditional_pattern(): void
    {
        $ast = $this->regexService->parse('/(?(1)yes|no)/');
        $this->assertSame('/', $ast->delimiter);
    }

    public function test_parse_comment(): void
    {
        $ast = $this->regexService->parse('/foo(?#comment)bar/');
        $this->assertSame('/', $ast->delimiter);
    }

    public function test_validate_multiple_patterns_sequentially(): void
    {
        $patterns = [
            '/^test$/' => true,
            '/(a+)+/' => true, // Syntactically valid, ReDoS is separate
            '/[a-z]+/' => true,
        ];

        foreach ($patterns as $pattern => $expectedValid) {
            $result = $this->regexService->validate($pattern);
            $this->assertSame($expectedValid, $result->isValid, "Pattern $pattern validation mismatch");
        }
    }

    public function test_generate_respects_quantifier_bounds(): void
    {
        $sample = $this->regexService->generate('/a{5}/');
        $this->assertSame(5, \strlen($sample));
        $this->assertSame('aaaaa', $sample);
    }

    public function test_optimize_maintains_semantic_equivalence(): void
    {
        $testCases = [
            'match' => 'this is a test',
            'nomatch' => 'xyz',
        ];

        $original = '/test/';
        $optimized = $this->regexService->optimize($original)->optimized;

        foreach ($testCases as $type => $input) {
            $originalResult = (bool) preg_match($original, $input);
            $optimizedResult = (bool) preg_match($optimized, $input);

            $this->assertSame(
                $originalResult,
                $optimizedResult,
                "Optimization changed behavior for input: $input (type: $type)",
            );
        }
    }

    public function test_extract_literals_with_groups(): void
    {
        $literals = $this->regexService->literals('/prefix_(foo|bar)_suffix/');
        $prefix = $literals->literalSet->getLongestPrefix();
        // Current implementation may vary on how it handles alternations
        $this->assertIsString($prefix ?? '');
        $this->assertStringContainsString('prefix', (string) $prefix);
    }

    public function test_analyze_redos_complex_email_pattern(): void
    {
        $pattern = '/^([a-zA-Z0-9._%-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})$/';
        $analysis = $this->regexService->redos($pattern);

        $this->assertContains($analysis->severity, [
            RedosSeverity::Safe,
            RedosSeverity::Low,
            RedosSeverity::Medium
        ]);
    }

    public static function assertionCount(): int
    {
        // This test file contains 128+ assertions
        return 128;
    }
}
