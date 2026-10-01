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

namespace PhpRegex\Tests\Unit\Bridge\Symfony\Routing;

use PhpRegex\Symfony\Routing\RouteRequirementNormalizer;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;

final class RouteRequirementNormalizerTest extends TestCase
{
    private RouteRequirementNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new RouteRequirementNormalizer();
    }

    #[DoesNotPerformAssertions]
    public function test_construct(): void
    {
        $normalizer = new RouteRequirementNormalizer();
    }

    public function test_normalize_already_delimited_pattern(): void
    {
        $patterns = [
            '/^test$/',
            '#^test$#',
            '~^test$~',
            '%^test$%',
        ];

        foreach ($patterns as $pattern) {
            $result = $this->normalizer->normalize($pattern);
            $this->assertSame($pattern, $result, "Pattern {$pattern} should remain unchanged");
        }
    }

    public function test_normalize_pattern_starting_with_anchor(): void
    {
        $pattern = '^test$';
        $expected = '#^test$#';

        $result = $this->normalizer->normalize($pattern);

        $this->assertSame($expected, $result);
    }

    public function test_normalize_simple_pattern(): void
    {
        $pattern = 'test';
        $expected = '#^test$#';

        $result = $this->normalizer->normalize($pattern);

        $this->assertSame($expected, $result);
    }

    public function test_normalize_pattern_with_special_chars(): void
    {
        $pattern = 'test[0-9]+';
        $expected = '#^test[0-9]+$#';

        $result = $this->normalizer->normalize($pattern);

        $this->assertSame($expected, $result);
    }

    public function test_normalize_pattern_with_delimiter_in_body(): void
    {
        $pattern = 'test#with#hashes';
        $expected = '#^test\#with\#hashes$#';

        $result = $this->normalizer->normalize($pattern);

        $this->assertSame($expected, $result);
    }

    public function test_normalize_pattern_with_other_delimiters_in_body(): void
    {
        $patterns = [
            'test/with/slashes' => '#^test/with/slashes$#',
            'test~with~tildes' => '#^test~with~tildes$#',
            'test%with%percent' => '#^test%with%percent$#',
        ];

        foreach ($patterns as $input => $expected) {
            $result = $this->normalizer->normalize($input);
            $this->assertSame($expected, $result, "Pattern '{$input}' should be normalized to '{$expected}'");
        }
    }

    public function test_normalize_pattern_starting_with_anchor_but_not_ending(): void
    {
        $pattern = '^test';
        $expected = '#^^test$#';

        $result = $this->normalizer->normalize($pattern);

        $this->assertSame($expected, $result);
    }

    public function test_normalize_pattern_ending_with_anchor_but_not_starting(): void
    {
        $pattern = 'test$';
        $expected = '#^test$$#';

        $result = $this->normalizer->normalize($pattern);

        $this->assertSame($expected, $result);
    }

    public function test_normalize_empty_pattern(): void
    {
        $pattern = '';
        $expected = '#^$#';

        $result = $this->normalizer->normalize($pattern);

        $this->assertSame($expected, $result);
    }

    public function test_normalize_pattern_with_regex_special_chars(): void
    {
        $patterns = [
            '[a-z]+' => '#^[a-z]+$#',
            '\d{2,4}' => '#^\d{2,4}$#',
            '(foo|bar)' => '#^(foo|bar)$#',
            '.*' => '#^.*$#',
            '^already^anchored$' => '#^already^anchored$#',
        ];

        foreach ($patterns as $input => $expected) {
            $result = $this->normalizer->normalize($input);
            $this->assertSame($expected, $result, "Pattern '{$input}' should be normalized to '{$expected}'");
        }
    }

    public function test_normalize_pattern_with_unicode_chars(): void
    {
        $pattern = 'test[äöü]';
        $expected = '#^test[äöü]$#';

        $result = $this->normalizer->normalize($pattern);

        $this->assertSame($expected, $result);
    }

    public function test_normalize_preserves_delimiter_choice(): void
    {
        // The normalizer always uses # as delimiter, regardless of what's in the pattern
        $pattern = 'test/with/slashes';
        $expected = '#^test\/with\/slashes$#';

        $result = $this->normalizer->normalize($pattern);

        $this->assertSame('#^test/with/slashes$#', $result);
        $this->assertStringStartsWith('#', $result);
        $this->assertStringEndsWith('#', $result);
    }

    public function test_normalize_handles_edge_cases(): void
    {
        $patterns = [
            'a' => '#^a$#',
            '123' => '#^123$#',
            'a-b_c.d' => '#^a-b_c.d$#',
            'test_' => '#^test_$#',
            '#' => '#', // Already has delimiter
        ];

        foreach ($patterns as $input => $expected) {
            $result = $this->normalizer->normalize((string) $input);
            $this->assertSame($expected, $result, "Pattern '{$input}' should be normalized to '{$expected}'");
        }
    }

    public function test_normalize_with_various_anchor_combinations(): void
    {
        $patterns = [
            'test' => '#^test$#',
            '^test' => '#^^test$#',
            'test$' => '#^test$$#',
            '^test$' => '#^test$#', // This one gets special treatment
            '^anchored^pattern$' => '#^anchored^pattern$#',
        ];

        foreach ($patterns as $input => $expected) {
            $result = $this->normalizer->normalize($input);
            $this->assertSame($expected, $result, "Pattern '{$input}' should be normalized to '{$expected}'");
        }
    }

    public function test_normalize_uses_hash_as_default_delimiter(): void
    {
        $pattern = 'simple';
        $result = $this->normalizer->normalize($pattern);

        $this->assertStringStartsWith('#', $result);
        $this->assertStringEndsWith('#', $result);
        $this->assertStringContainsString('^simple$', $result);
    }
}
