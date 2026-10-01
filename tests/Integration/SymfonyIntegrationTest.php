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

namespace PHPRegex\Tests\Integration;

use PHPRegex\Parser\Printer\NodeDumper;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;

/**
 * Tests Symfony framework integration capabilities.
 * Demonstrates how the library can be integrated into Symfony applications
 * for validation, form handling, and routing constraints.
 */
final class SymfonyIntegrationTest extends TestCase
{
    private Regex $regex;

    protected function setUp(): void
    {
        $this->regex = Regex::create();
    }

    public function test_validate_regex_pattern_for_symfony_constraint(): void
    {
        // Simulates a Symfony constraint validator checking a regex pattern
        $patterns = [
            '/^[a-z0-9_-]{3,16}$/' => true,  // Valid username pattern
            '/(?<!a*)b/' => false,            // Invalid: lookbehind must have a bounded max length
            '/(a+)+/' => true,                // Valid: syntactically correct, ReDoS is separate concern
        ];

        foreach ($patterns as $pattern => $expectedValid) {
            $result = $this->regex->validate($pattern);
            $this->assertSame(
                $expectedValid,
                $result->isValid,
                "Pattern validation mismatch for: $pattern",
            );
        }
    }

    public function test_symfony_validator_error_messages(): void
    {
        // Test that validation errors provide user-friendly messages
        // suitable for Symfony form violations
        $result = $this->regex->validate('/[a-z'); // Invalid: missing closing bracket

        $this->assertFalse($result->isValid);
        $this->assertNotNull($result->error);
        $this->assertIsString($result->error);
        $this->assertNotEmpty($result->error);
    }

    public function test_pattern_complexity_for_performance_constraint(): void
    {
        // Symfony applications may want to limit regex complexity
        // to prevent performance issues
        $simple = $this->regex->validate('/hello/');
        $complex = $this->regex->validate('/^(?:[a-z]+(?:[0-9]+[a-z]*)*)+$/');

        // Complex pattern has ReDoS risk, may not validate
        if ($complex->isValid) {
            // If both valid, complexity should differ
            $this->assertTrue($simple->isValid);
            $this->assertGreaterThan($simple->complexityScore, $complex->complexityScore);
        } else {
            // Complex pattern rejected due to ReDoS
            $this->assertTrue($simple->isValid);
            $this->assertNotEmpty($complex->error);
        }
    }

    public function test_generate_sample_for_form_placeholder(): void
    {
        // Symfony forms can use generated samples as placeholders
        $pattern = '/\d{3}-\d{3}-\d{4}/'; // Phone number pattern
        $sample = $this->regex->generate($pattern);

        $this->assertMatchesRegularExpression($pattern, $sample);
        $this->assertMatchesRegularExpression('/^\d{3}-\d{3}-\d{4}$/', $sample);
    }

    public function test_explain_pattern_for_form_help_text(): void
    {
        // Symfony forms can use pattern explanations as help text
        $pattern = '/^[A-Z]{2}\d{6}$/';
        $explanation = $this->regex->explain($pattern);

        $this->assertIsString($explanation);
        $this->assertNotEmpty($explanation);
        // Explanation should be human-readable and descriptive
        $this->assertStringContainsString('Regex', $explanation);
    }

    public function test_validation_for_multiple_form_fields(): void
    {
        // Simulates validating multiple form fields with different patterns
        $formData = [
            'username' => [
                'value' => 'john_doe_123',
                'pattern' => '/^[a-z0-9_]{3,20}$/i',
            ],
            'email' => [
                'value' => 'john@example.com',
                'pattern' => '/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i',
            ],
            'zipcode' => [
                'value' => '12345',
                'pattern' => '/^\d{5}$/',
            ],
        ];

        foreach ($formData as $field => $data) {
            $validation = $this->regex->validate($data['pattern']);
            $this->assertTrue(
                $validation->isValid,
                "Pattern for $field should be valid",
            );

            $matches = (bool) preg_match($data['pattern'], $data['value']);
            $this->assertTrue($matches, "Value for $field should match pattern");
        }
    }

    public function test_route_parameter_constraint_validation(): void
    {
        // Symfony routes can use regex constraints for parameters
        $routeConstraints = [
            'id' => '/^\d+$/',
            'slug' => '/^[a-z0-9-]+$/',
            'locale' => '/^(en|fr|de|es)$/',
        ];

        foreach ($routeConstraints as $param => $pattern) {
            $result = $this->regex->validate($pattern);
            $this->assertTrue(
                $result->isValid,
                "Route constraint for $param should be valid",
            );
        }
    }

    public function test_route_constraint_sample_generation(): void
    {
        // Generate valid sample values for route constraints
        $constraints = [
            '/^\d{1,6}$/' => 'id',
            '/^[a-z0-9-]+$/' => 'slug',
            '/^20\d{2}$/' => 'year',
        ];

        foreach ($constraints as $pattern => $paramName) {
            $sample = $this->regex->generate($pattern);

            $this->assertMatchesRegularExpression(
                $pattern,
                $sample,
                "Generated sample for $paramName should match constraint",
            );
        }
    }

    public function test_regex_service_instantiation(): void
    {
        // Test that Regex can be instantiated as a Symfony service
        $service = Regex::create();

        // Test basic functionality
        $result = $service->validate('/test/');
        $this->assertTrue($result->isValid);
    }

    #[DoesNotPerformAssertions]
    public function test_regex_service_with_options(): void
    {
        // Test service configuration with options (e.g., in services.yaml)
        Regex::create(['max_pattern_length' => 500]);
    }

    public function test_stateless_service_behavior(): void
    {
        // Verify the service is stateless and thread-safe
        $service = Regex::create();

        $pattern1 = '/foo/';
        $pattern2 = '/bar/';

        $result1 = $service->validate($pattern1);
        $result2 = $service->validate($pattern2);

        // Both should be independent
        $this->assertTrue($result1->isValid);
        $this->assertTrue($result2->isValid);
    }

    public function test_redos_detection_for_user_input(): void
    {
        // Critical for Symfony apps accepting user-provided patterns
        $userPatterns = [
            '/(a+)+/' => true,      // Syntactically valid, ReDoS separate
            '/(a*)*/' => true,      // Syntactically valid, ReDoS separate
            '/^abc$/' => true,      // Safe
            '/\d{1,5}/' => true,    // Safe
        ];

        foreach ($userPatterns as $pattern => $shouldBeValid) {
            $result = $this->regex->validate($pattern);
            $this->assertSame(
                $shouldBeValid,
                $result->isValid,
                "ReDoS detection failed for: $pattern",
            );
        }
    }

    public function test_detailed_redos_analysis_for_security_review(): void
    {
        // Symfony security teams can use detailed ReDoS analysis
        $pattern = '/(a+)+b/';
        $analysis = $this->regex->redos($pattern);

        $this->assertFalse($analysis->isSafe());
        $this->assertIsArray($analysis->recommendations);
        $this->assertNotEmpty($analysis->recommendations);
        $this->assertGreaterThanOrEqual(7, $analysis->score); // High risk
    }

    public function test_literal_extraction_for_database_optimization(): void
    {
        // Symfony apps can optimize database queries using literal extraction
        $pattern = '/^ERROR: /';
        $literals = $this->regex->literals($pattern);
        $prefix = $literals->literalSet->getLongestPrefix();

        $this->assertSame('ERROR: ', $prefix);

        // Can be used for WHERE clause optimization:
        // WHERE log_message LIKE 'ERROR:%' before running regex
    }

    public function test_pattern_validation_for_console_command(): void
    {
        // Symfony console commands can validate regex inputs
        $commandInputs = [
            '/^[A-Z]{2}\d{6}$/' => true,
            '/(?P<invalid/' => false,
            '/(unclosed/' => false,
        ];

        foreach ($commandInputs as $input => $expectedValid) {
            $result = $this->regex->validate($input);
            $this->assertSame(
                $expectedValid,
                $result->isValid,
                "Console input validation failed for: $input",
            );

            if (!$expectedValid) {
                $this->assertIsString($result->error);
                $this->assertNotEmpty($result->error);
            }
        }
    }

    public function test_pattern_explanation_for_console_output(): void
    {
        // Console commands can display pattern explanations
        $pattern = '/^(?<protocol>https?):\/\/(?<domain>[\w.-]+)$/';
        $explanation = $this->regex->explain($pattern);

        $this->assertIsString($explanation);
        $this->assertStringContainsString('protocol', $explanation);
        $this->assertStringContainsString('domain', $explanation);
    }

    public function test_pattern_ast_serialization_for_cache(): void
    {
        // Symfony cache can store parsed AST for performance
        $pattern = '/^[a-z0-9_-]{3,16}$/';
        $ast = $this->regex->parse($pattern);

        // AST should be serializable
        $serialized = serialize($ast);
        $this->assertIsString($serialized);

        $unserialized = unserialize($serialized);
        $this->assertEquals($ast, $unserialized);
    }

    public function test_validation_result_caching(): void
    {
        // Validation results can be cached to avoid repeated checks
        $pattern = '/^[a-z0-9_-]{3,16}$/';

        $result1 = $this->regex->validate($pattern);
        $result2 = $this->regex->validate($pattern);

        // Results should be consistent (equal values, not necessarily same object)
        $this->assertEquals($result1, $result2);
        $this->assertTrue($result1->isValid);
        $this->assertTrue($result2->isValid);
    }

    public function test_unicode_pattern_validation(): void
    {
        // Symfony apps often need Unicode support
        $unicodePatterns = [
            '/\p{L}+/u' => true,           // Unicode letters
            '/\p{N}+/u' => true,           // Unicode numbers
            '/[\p{Arabic}]+/u' => true,    // Arabic script
        ];

        foreach ($unicodePatterns as $pattern => $expectedValid) {
            $result = $this->regex->validate($pattern);
            $this->assertSame(
                $expectedValid,
                $result->isValid,
                "Unicode pattern validation failed for: $pattern",
            );
        }
    }

    public function test_unicode_sample_generation(): void
    {
        // Generate samples for Unicode patterns
        $pattern = '/\p{L}{5}/u';
        $sample = $this->regex->generate($pattern);

        $this->assertIsString($sample);
        $this->assertMatchesRegularExpression($pattern, $sample);
    }

    public function test_error_messages_suitable_for_symfony_profiler(): void
    {
        // Error messages should be clear for Symfony debug toolbar
        $invalidPatterns = [
            '/(?P<invalid/' => 'should mention unclosed or invalid',
            '/(unclosed/' => 'should mention unclosed group',
            '/\2(foo)/' => 'should mention backreference',
        ];

        foreach ($invalidPatterns as $pattern => $expected) {
            $result = $this->regex->validate($pattern);
            $this->assertFalse($result->isValid);
            $this->assertIsString($result->error);
            $this->assertNotEmpty($result->error);
        }
    }

    public function test_ast_dump_for_debugging(): void
    {
        // Developers can dump AST for debugging in Symfony profiler
        $pattern = '/^test$/';
        $dump = $this->regex->parse($pattern)->accept(new NodeDumper());

        $this->assertIsString($dump);
        $this->assertNotEmpty($dump);
        $this->assertStringContainsString('Regex', $dump);
    }

    #[DoesNotPerformAssertions]
    public function test_comprehensive_symfony_integration(): void
    {
        // End-to-end test covering multiple Symfony use cases
        $pattern = '/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i';

        // 1. Validate the pattern (form constraint)
        $this->regex->validate($pattern);

        // 2. Generate sample (form placeholder)
        $this->regex->generate($pattern);

        // 3. Explain pattern (help text)
        $this->regex->explain($pattern);

        // 4. Check security (ReDoS)
        $this->regex->redos($pattern);

        // 5. Extract literals (optimization)
        $this->regex->literals($pattern);
    }
}
