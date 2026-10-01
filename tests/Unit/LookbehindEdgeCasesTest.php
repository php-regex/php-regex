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

namespace PhpRegex\Tests\Unit;

use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class LookbehindEdgeCasesTest extends TestCase
{
    private Regex $regexService;

    protected function setUp(): void
    {
        $this->regexService = Regex::create();
    }

    public function test_fixed_length_literal_lookbehind(): void
    {
        $result = $this->regexService->validate('/(?<=foo)bar/');

        $this->assertTrue($result->isValid, 'Fixed-length lookbehind should be valid');
    }

    public function test_fixed_length_digit_lookbehind(): void
    {
        $result = $this->regexService->validate('/(?<=\d{3})test/');

        $this->assertTrue($result->isValid);
    }

    public function test_fixed_length_escaped_chars_lookbehind(): void
    {
        $result = $this->regexService->validate('/(?<=\(\d{2}\))/');

        $this->assertTrue($result->isValid);
    }

    public function test_fixed_length_char_class_lookbehind(): void
    {
        $result = $this->regexService->validate('/(?<=[a-z]{5})/');

        $this->assertTrue($result->isValid);
    }

    public function test_fixed_length_char_sequence_lookbehind(): void
    {
        $result = $this->regexService->validate('/(?<=\w\d)/');

        $this->assertTrue($result->isValid);
    }

    public function test_fixed_length_with_escape_lookbehind(): void
    {
        $result = $this->regexService->validate('/(?<=test\.)pattern/');

        $this->assertTrue($result->isValid);
    }

    public function test_fixed_length_backslash_lookbehind(): void
    {
        $result = $this->regexService->validate('/(?<=\\\\)/');

        $this->assertTrue($result->isValid);
    }

    public function test_fixed_length_single_char_class_lookbehind(): void
    {
        $result = $this->regexService->validate('/(?<=[A-Z])/');

        $this->assertTrue($result->isValid);
    }

    public function test_variable_length_star_quantifier_is_valid(): void
    {
        $result = $this->regexService->validate('/(?<=a*)b/');

        $this->assertFalse($result->isValid, 'Unbounded lookbehind should be rejected.');
    }

    public function test_variable_length_plus_quantifier_is_valid(): void
    {
        $result = $this->regexService->validate('/(?<=a+)b/');

        $this->assertFalse($result->isValid, 'Unbounded lookbehind should be rejected.');
    }

    public function test_variable_length_optional_quantifier_is_valid(): void
    {
        // Variable-length lookbehinds arrived in PCRE2 10.43, which PHP bundles from 8.4.
        $result = Regex::create(['php_version' => '8.4'])->validate('/(?<=a?)b/');

        $this->assertTrue($result->isValid, 'Variable-length optional quantifier should be valid in PCRE2');
    }

    public function test_variable_length_unbounded_range_is_valid(): void
    {
        $result = $this->regexService->validate('/(?<=a{1,})b/');

        $this->assertFalse($result->isValid, 'Unbounded lookbehind should be rejected.');
    }

    public function test_variable_length_char_class_star_is_valid(): void
    {
        $result = $this->regexService->validate('/(?<=[a-z]*)test/');

        $this->assertFalse($result->isValid, 'Unbounded lookbehind should be rejected.');
    }

    public function test_variable_length_digit_plus_is_valid(): void
    {
        $result = $this->regexService->validate('/(?<=\d+)end/');

        $this->assertFalse($result->isValid, 'Unbounded lookbehind should be rejected.');
    }

    public function test_variable_length_alternation_is_valid(): void
    {
        // Variable-length lookbehinds arrived in PCRE2 10.43, which PHP bundles from 8.4.
        $result = Regex::create(['php_version' => '8.4'])->validate('/(?<=(a|ab))c/');

        $this->assertTrue($result->isValid, 'Alternation with different lengths should be valid in PCRE2');
    }

    public function test_variable_length_group_star_is_valid(): void
    {
        $result = $this->regexService->validate('/(?<=(test)*)/');

        $this->assertFalse($result->isValid, 'Unbounded lookbehind should be rejected.');
    }

    public function test_mixed_fixed_and_variable_length_is_valid(): void
    {
        $result = $this->regexService->validate('/(?<=\d{3}a+)/');

        $this->assertFalse($result->isValid, 'Unbounded lookbehind should be rejected.');
    }

    public function test_literal_repeat_plus_variable_is_valid(): void
    {
        $result = $this->regexService->validate('/(?<=[a]{3}b*)/');

        $this->assertFalse($result->isValid, 'Unbounded lookbehind should be rejected.');
    }

    public function test_optional_group_is_valid(): void
    {
        // Variable-length lookbehinds arrived in PCRE2 10.43, which PHP bundles from 8.4.
        $result = Regex::create(['php_version' => '8.4'])->validate('/(?<=(?:test)?)/');

        $this->assertTrue($result->isValid, 'Optional group should be valid in PCRE2');
    }

    public function test_variable_range_bound_is_valid(): void
    {
        // Variable-length lookbehinds arrived in PCRE2 10.43, which PHP bundles from 8.4.
        $result = Regex::create(['php_version' => '8.4'])->validate('/(?<=\w{0,5})/');

        $this->assertTrue($result->isValid, 'Variable range bound should be valid in PCRE2');
    }

    public function test_negative_variable_lookbehind_is_valid(): void
    {
        $result = $this->regexService->validate('/(?<!a+)/');

        $this->assertFalse($result->isValid, 'Unbounded lookbehind should be rejected.');
    }

    public function test_fixed_length_exactly_three_chars(): void
    {
        $result = $this->regexService->validate('/(?<=abc)/');

        $this->assertTrue($result->isValid);
    }

    public function test_fixed_length_multiple_digits(): void
    {
        $result = $this->regexService->validate('/(?<=\d\d\d)/');

        $this->assertTrue($result->isValid);
    }

    public function test_fixed_range_lookbehind(): void
    {
        $result = $this->regexService->validate('/(?<=[a-z]{3,3})/');

        $this->assertTrue($result->isValid);
    }

    public function test_complex_fixed_pattern(): void
    {
        $result = $this->regexService->validate('/(?<=test\d{2})/');

        $this->assertTrue($result->isValid);
    }
}
