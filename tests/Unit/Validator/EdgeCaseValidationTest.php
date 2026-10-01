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

namespace PhpRegex\Tests\Unit\Validator;

use PhpRegex\Parser\Exception\ParserException;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class EdgeCaseValidationTest extends TestCase
{
    private Regex $regexService;

    protected function setUp(): void
    {
        $this->regexService = Regex::create();
    }

    public function test_back_reference_to_non_existent_group(): void
    {
        // Multi-digit \NN without a matching group and without an octal
        // fallback (leading 8/9) is a compile error in PCRE.
        $result = $this->regexService->validate('/(a)\81/');
        $this->assertFalse($result->isValid, 'Should be invalid');
        $this->assertStringContainsString('Backreference to non-existent group: \81', (string) $result->error);
    }

    public function test_back_reference_octal_fallback_is_valid(): void
    {
        // With only one capture group, PCRE reads \10 as the octal escape
        // \010 (backspace), so the pattern is valid.
        $result = $this->regexService->validate('/(a)\10/');
        $this->assertTrue($result->isValid, '\10 with one group must fall back to octal, as in PCRE');
    }

    public function test_back_reference_to_non_existent_named_group(): void
    {
        $result = $this->regexService->validate('/(?<n>a)\k<missing>/');
        $this->assertFalse($result->isValid, 'Should be invalid');
        $this->assertStringContainsString('Backreference to non-existent named group: "missing"', (string) $result->error);
    }

    public function test_variable_length_lookbehind(): void
    {
        $result = $this->regexService->validate('/(?<=a+)/');
        $this->assertFalse($result->isValid, 'Unbounded lookbehind should be rejected.');
    }

    public function test_variable_length_lookbehind_with_range(): void
    {
        // Variable-length lookbehinds arrived in PCRE2 10.43, which PHP bundles from 8.4.
        $result = Regex::create(['php_version' => '8.4'])->validate('/(?<=a{1,3})/');
        $this->assertTrue($result->isValid, 'Variable-length lookbehind with range should be valid in PCRE2');
    }

    public function test_variable_length_lookbehind_is_rejected_for_older_php_versions(): void
    {
        $regex = Regex::create(['php_version' => '7.2']);
        $result = $regex->validate('/(?<=a{1,3})/');

        $this->assertFalse($result->isValid, 'Variable-length lookbehind should be rejected before PHP 7.3.');
        $this->assertStringContainsString('Variable-length lookbehind', (string) $result->error);
    }

    public function test_invalid_range_start_greater_than_end(): void
    {
        $result = $this->regexService->validate('/[z-a]/');
        $this->assertFalse($result->isValid, 'Should be invalid');
        $this->assertStringContainsString('Invalid range "z-a"', (string) $result->error);
    }

    public function test_chartype_range_endpoint_is_invalid(): void
    {
        // PCRE compile-errors on /[\w-_]/: a character type cannot be a
        // range endpoint ("invalid range in character class").
        $result = $this->regexService->validate('/[\\w-_]/');
        $this->assertFalse($result->isValid, 'CharType as a range endpoint must be rejected, as in PCRE');
    }

    public function test_chartype_followed_by_trailing_hyphen_is_valid(): void
    {
        // A hyphen that cannot form a range (end of class) stays literal.
        $result = $this->regexService->validate('/[\\w-]/');
        $this->assertTrue($result->isValid, 'Trailing hyphen after a CharType is a literal');
    }

    public function test_duplicate_group_name(): void
    {
        $result = $this->regexService->validate('/(?<n>a)(?<n>b)/');
        $this->assertFalse($result->isValid, 'Should be invalid');
        $this->assertStringContainsString('Duplicate group name "n"', (string) $result->error);
    }

    public function test_conditional_numeric_reference_is_valid(): void
    {
        $result = $this->regexService->validate('/(a)(b)(?(2)\\2)/');
        $this->assertTrue($result->isValid, 'Numeric conditional references should be allowed.');
    }

    public function test_conditional_numeric_reference_with_else_is_valid(): void
    {
        $result = $this->regexService->validate('/(a)(?(1)b|c)/');
        $this->assertTrue($result->isValid, 'Numeric conditionals with else branch should be allowed.');
    }

    public function test_mark_verb_shorthand_is_valid(): void
    {
        $result = $this->regexService->validate('/foo(*:first)|bar(*:second)([x])/');
        $this->assertTrue($result->isValid, 'MARK shorthand verbs should be accepted.');
    }

    public function test_mark_verb_equals_shorthand_is_refused(): void
    {
        // preg_match() on PCRE2 10.48: "(*VERB) not recognized or malformed at offset 5".
        // PCRE writes a mark "(*MARK:name)" or "(*:name)"; "(*=name)" is not one.
        $result = $this->regexService->validate('/foo(*=first)|bar(*=second)([x])/');
        $this->assertFalse($result->isValid, 'MARK "=" shorthand verbs are refused by PHP.');
    }

    public function test_unconsumed_tokens(): void
    {
        // Test that invalid flags are properly rejected.
        // The pattern '/foo/z' has an invalid flag 'z' which should trigger validation.
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Unknown regex flag(s) found: "z"');

        $this->regexService->parse('/foo/z');
    }

    public function test_valid_octal_is_accepted(): void
    {
        // \10 should be valid if there are 10 groups
        $pattern = '/(a)(a)(a)(a)(a)(a)(a)(a)(a)(a)\10/';
        $result = $this->regexService->validate($pattern);
        $this->assertTrue($result->isValid, 'Should be valid');
    }
}
