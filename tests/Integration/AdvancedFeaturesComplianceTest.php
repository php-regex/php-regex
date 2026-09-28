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

namespace RegexParser\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RegexParser\NodeVisitor\CompilerNodeVisitor;
use RegexParser\NodeVisitor\ReDoSProfileNodeVisitor;
use RegexParser\NodeVisitor\SampleGeneratorNodeVisitor;
use RegexParser\NodeVisitor\ValidatorNodeVisitor;
use RegexParser\ReDoS\ReDoSSeverity;
use RegexParser\Regex;

final class AdvancedFeaturesComplianceTest extends TestCase
{
    #[DataProvider('provideRecursionSeeds')]
    public function test_sample_generator_handles_recursion(int $seed, string $expectedSample): void
    {
        $regex = Regex::create()->parse('/a(?R)?z/');
        $generator = new SampleGeneratorNodeVisitor();
        $generator->setSeed($seed);

        $this->assertSame($expectedSample, $regex->accept($generator));
    }

    public static function provideRecursionSeeds(): \Iterator
    {
        yield 'single depth' => [2, 'az'];
        yield 'double depth' => [5, 'aazz'];
        yield 'triple depth' => [1, 'aaazzz'];
    }

    #[DataProvider('provideControlVerbPatterns')]
    public function test_redos_analyzer_respects_commit(string $pattern, ReDoSSeverity $expected): void
    {
        $regex = Regex::create()->parse($pattern);
        $visitor = new ReDoSProfileNodeVisitor();
        $regex->accept($visitor);
        $result = $visitor->getResult();

        $this->assertSame($expected, $result['severity']);
    }

    public static function provideControlVerbPatterns(): \Iterator
    {
        yield 'commit' => ['/(a+(*COMMIT))+/', ReDoSSeverity::SAFE];
        yield 'prune' => ['/(a+(*PRUNE))+/', ReDoSSeverity::SAFE];
        yield 'skip' => ['/(a+(*SKIP))+/', ReDoSSeverity::SAFE];
    }

    #[DataProvider('provideRecursiveConditionPatterns')]
    public function test_recursive_condition_validation(string $pattern): void
    {
        $regex = Regex::create()->parse($pattern);
        $validator = new ValidatorNodeVisitor();

        $regex->accept($validator); // Validation passed without exception.

        $this->assertGreaterThan(0, \strlen($pattern));
    }

    public static function provideRecursiveConditionPatterns(): \Iterator
    {
        yield 'explicit group recursion' => ['/((a))(?(R1)a|b)/'];
        yield 'root recursion check' => ['/(?(R)a|b)/'];
    }

    #[DataProvider('provideQuantifierPatterns')]
    public function test_php84_quantifier_missing_min_syntax(string $pattern, string $expectedCompiled): void
    {
        $regex = Regex::create()->parse($pattern);
        $compiler = new CompilerNodeVisitor();
        $compiled = $regex->accept($compiler);

        $this->assertSame($expectedCompiled, $compiled, "PHP 8.4 quantifier syntax should compile correctly: {$pattern}");
    }

    public static function provideQuantifierPatterns(): \Iterator
    {
        yield 'missing_min_no_spaces' => ['/a{,3}/', '/a{,3}/'];
        yield 'missing_min_with_spaces' => ['/a{ , 3 }/', '/a{,3}/'];
        yield 'missing_min_with_single_space' => ['/a{ ,3}/', '/a{,3}/'];
        yield 'missing_max_with_spaces' => ['/a{2, }/', '/a{2,}/'];
        yield 'both_with_spaces' => ['/a{ 2 , 3 }/', '/a{2,3}/'];
        yield 'no_spaces' => ['/a{2,3}/', '/a{2,3}/'];
    }

    #[DataProvider('provideNewlineVerbPatterns')]
    public function test_newline_verbs(string $pattern): void
    {
        $regex = Regex::create()->parse($pattern);
        $compiler = new CompilerNodeVisitor();
        $compiled = $regex->accept($compiler);

        $this->assertSame($pattern, $compiled, "Newline verb should round-trip: {$pattern}");
    }

    public static function provideNewlineVerbPatterns(): \Iterator
    {
        yield 'cr_newline' => ['/(*CR)a/'];
        yield 'lf_newline' => ['/(*LF)a/'];
        yield 'crlf_newline' => ['/(*CRLF)a/'];
    }

    #[DataProvider('provideEncodingVerbPatterns')]
    public function test_encoding_verbs(string $pattern): void
    {
        $regex = Regex::create()->parse($pattern);
        $compiler = new CompilerNodeVisitor();
        $compiled = $regex->accept($compiler);

        $this->assertSame($pattern, $compiled, "Encoding verb should round-trip: {$pattern}");
    }

    public static function provideEncodingVerbPatterns(): \Iterator
    {
        yield 'utf8' => ['/(*UTF8)a/'];
        yield 'ucp' => ['/(*UCP)a/'];
    }

    #[DataProvider('provideMatchControlVerbPatterns')]
    public function test_match_control_verbs(string $pattern): void
    {
        $regex = Regex::create()->parse($pattern);
        $compiler = new CompilerNodeVisitor();
        $compiled = $regex->accept($compiler);

        $this->assertSame($pattern, $compiled, "Match control verb should round-trip: {$pattern}");
    }

    public static function provideMatchControlVerbPatterns(): \Iterator
    {
        yield 'notempty' => ['/(*NOTEMPTY)a+/'];
        yield 'notempty_atstart' => ['/(*NOTEMPTY_ATSTART)^a+/'];
    }

    #[DataProvider('provideUnicodePropertyPatterns')]
    public function test_unicode_properties(string $pattern, string $expected): void
    {
        $regex = Regex::create()->parse($pattern);
        $compiler = new CompilerNodeVisitor();
        $compiled = $regex->accept($compiler);

        $this->assertSame($expected, $compiled, "Unicode property should compile to: {$expected}");
    }

    public static function provideUnicodePropertyPatterns(): \Iterator
    {
        yield 'general_category' => ['/\p{L}/', '/\p{L}/'];
        yield 'general_category_negated' => ['/\P{L}/', '/\P{L}/'];
        yield 'script' => ['/\p{Arabic}/', '/\p{Arabic}/'];
        yield 'script_negated' => ['/\P{Arabic}/', '/\P{Arabic}/'];
        yield 'property' => ['/\p{Alpha}/', '/\p{Alpha}/'];
        yield 'combined' => ['/[\p{L}\p{M}]/', '/[\p{L}\p{M}]/'];
        yield 'with_braces' => ['/\p{Latin}/', '/\p{Latin}/'];
        yield 'negated_with_braces' => ['/\P{Latin}/', '/\P{Latin}/'];
        yield 'short_form' => ['/\pL/', '/\pL/'];
        yield 'short_form_negated' => ['/\PL/', '/\PL/'];
    }

    #[DataProvider('providePcre84Features')]
    public function test_pcre_84_features_implemented(string $pattern, string $description): void
    {
        $regex = Regex::create();

        // Should parse without exception
        $ast = $regex->parse($pattern);

        // Should compile back to valid regex
        $compiler = new CompilerNodeVisitor();
        $compiled = $ast->accept($compiler);

        // Should validate without errors
        $validator = new ValidatorNodeVisitor();
        $ast->accept($validator);

        // Ensure the pattern is preserved or normalized correctly
        $this->assertIsString($compiled, "Pattern {$pattern} should compile successfully: {$description}");
    }

    public static function providePcre84Features(): \Iterator
    {
        // 1. {,n} quantifier syntax (PHP 8.4+)
        yield 'omitted_min_no_spaces' => ['/a{,3}/', '{,n} quantifier syntax'];
        yield 'omitted_min_with_spaces' => ['/a{ , 3 }/', '{ , n } quantifier syntax'];
        yield 'omitted_min_single_space' => ['/a{ ,3}/', '{ ,n } quantifier syntax'];

        // 2. Spaces in quantifier braces
        yield 'spaces_in_quantifier' => ['/a{ 2 , 5 }/', 'spaces in quantifier braces'];
        yield 'spaces_both_missing' => ['/a{ , }/', 'both min and max omitted with spaces'];

        // 3. Newline convention verbs
        yield 'newline_cr' => ['/(*CR)a/', '(*CR) newline convention verb'];
        yield 'newline_lf' => ['/(*LF)b/', '(*LF) newline convention verb'];
        yield 'newline_crlf' => ['/(*CRLF)c/', '(*CRLF) newline convention verb'];

        // 4. Control verbs
        yield 'control_mark' => ['/(*MARK:label)a/', '(*MARK) control verb'];
        yield 'control_prune' => ['/(*PRUNE)b/', '(*PRUNE) control verb'];
        yield 'control_skip' => ['/(*SKIP)c/', '(*SKIP) control verb'];
        yield 'control_then' => ['/(*THEN)d/', '(*THEN) control verb'];

        // 5. Encoding control verbs
        yield 'encoding_utf8' => ['/(*UTF8)pattern/', '(*UTF8) encoding control verb'];
        yield 'encoding_ucp' => ['/(*UCP)test/', '(*UCP) encoding control verb'];

        // 6. Match control verbs
        yield 'match_notempty' => ['/(*NOTEMPTY)a+/', '(*NOTEMPTY) match control verb'];
        yield 'match_notempty_atstart' => ['/(*NOTEMPTY_ATSTART)^a+/', '(*NOTEMPTY_ATSTART) match control verb'];

        // 7. \R backreference (as char type, not backreference)
        yield 'r_char_type' => ['/\R/', '\\R as char type (line ending)'];

        // 8. Possessive quantifiers in char classes (edge cases - quantifiers not allowed in char classes)
        yield 'char_class_with_plus' => ['/[a+]/', 'plus literal in char class'];
        yield 'char_class_with_star' => ['/[b*]/', 'star literal in char class'];
        yield 'char_class_with_double_plus' => ['/[a++]/', 'double plus literal in char class'];
        yield 'char_class_with_star_plus' => ['/[b*+]/', 'star plus literal in char class'];
        yield 'char_class_with_question' => ['/[c?]/', 'question mark literal in char class'];

        // 9. Extended Unicode properties
        yield 'unicode_extended_script' => ['/\p{Greek}/', 'extended Unicode script property'];
        yield 'unicode_extended_category' => ['/\p{Ll}/', 'extended Unicode category property'];
        yield 'unicode_extended_property' => ['/\p{Alpha}/', 'extended Unicode binary property'];
        yield 'unicode_script_equals' => ['/\p{Script=Greek}/', 'extended Unicode script= property'];
        yield 'unicode_block_equals' => ['/\p{Block=Basic_Latin}/', 'extended Unicode block= property'];

        // 10. Callouts
        yield 'callout_bare' => ['/(?C)abc/', 'bare callout'];
        yield 'callout_numeric' => ['/(?C1)abc/', 'numeric callout'];
        yield 'callout_string' => ['/(?C"debug")def/', 'string callout'];

        // Additional combinations
        yield 'complex_quantifiers_and_verbs' => ['/(?C1)a{,3}(*MARK:pos)b{ 2 , 5 }(*PRUNE)/', 'complex combination of features'];
    }

    #[DataProvider('providePcre84InvalidPatterns')]
    public function test_pcre_84_invalid_patterns(string $pattern, string $description): void
    {
        $regex = Regex::create();
        $result = $regex->validate($pattern);

        $this->assertFalse($result->isValid, "Pattern should be invalid: {$description}");
    }

    #[DataProvider('provideComprehensivePcre84Patterns')]
    public function test_comprehensive_pcre84_parsing(string $pattern, string $description): void
    {
        $regex = Regex::create()->parse($pattern);
        $compiler = new CompilerNodeVisitor();
        $compiled = $regex->accept($compiler);

        $this->assertIsString($compiled, "Pattern should parse and compile successfully: {$description}");
    }

    public static function provideComprehensivePcre84Patterns(): \Iterator
    {
        // Quantifier variations
        yield 'quantifier_comma_n_variations' => ['/a{,3}/', 'basic {,n}'];
        yield 'quantifier_comma_n_zero' => ['/a{,0}/', '{,0}'];
        yield 'quantifier_comma_n_large' => ['/a{,100}/', '{,100}'];
        yield 'quantifier_spaces_single' => ['/a{ 1 }/', '{ 1 }'];
        yield 'quantifier_spaces_both' => ['/a{ 1 , 3 }/', '{ 1 , 3 }'];
        yield 'quantifier_spaces_min' => ['/a{ 2 ,}/', '{ 2 ,}'];
        yield 'quantifier_spaces_max' => ['/a{ , 5 }/', '{ , 5 }'];
        yield 'quantifier_multiple_spaces' => ['/a{  2  ,  5  }/', 'multiple spaces'];

        // Newline verbs
        yield 'newline_cr_standalone' => ['/(*CR)abc/', '(*CR) standalone'];
        yield 'newline_lf_standalone' => ['/(*LF)def/', '(*LF) standalone'];
        yield 'newline_crlf_standalone' => ['/(*CRLF)ghi/', '(*CRLF) standalone'];

        // Control verbs
        yield 'control_mark_no_arg' => ['/(*MARK)abc/', '(*MARK) no arg'];
        yield 'control_mark_with_arg' => ['/(*MARK:start)def/', '(*MARK:start)'];
        yield 'control_prune' => ['/(*PRUNE)ghi/', '(*PRUNE)'];
        yield 'control_skip' => ['/(*SKIP)jkl/', '(*SKIP)'];
        yield 'control_then' => ['/(*THEN)mno/', '(*THEN)'];

        // Encoding verbs
        yield 'encoding_utf8' => ['/(*UTF8)abc/', '(*UTF8)'];
        yield 'encoding_ucp' => ['/(*UCP)def/', '(*UCP)'];

        // Match control verbs
        yield 'match_notempty' => ['/(*NOTEMPTY)abc+/', '(*NOTEMPTY)'];
        yield 'match_notempty_atstart' => ['/(*NOTEMPTY_ATSTART)^abc+/', '(*NOTEMPTY_ATSTART)'];

        // \R char type
        yield 'r_char_type' => ['/\Ra/', '\R char type'];
        yield 'r_in_class' => ['/[a\Rb]/', '\R in char class'];

        // Possessive in char class (as literal)
        yield 'possessive_in_class_star' => ['/[a*+b]/', '*+ in class'];
        yield 'possessive_in_class_plus' => ['/[a++b]/', '++ in class'];
        yield 'possessive_in_class_question' => ['/[a?+b]/', '?+ in class'];

        // Extended Unicode properties
        yield 'unicode_block' => ['/\p{Block=Basic_Latin}/', 'Block= property'];
        yield 'unicode_script' => ['/\p{Script=Latin}/', 'Script= property'];
        yield 'unicode_category' => ['/\p{General_Category=Letter}/', 'General_Category='];
        yield 'unicode_negated' => ['/\P{Block=Basic_Latin}/', 'negated Block='];

        // Callouts
        yield 'callout_empty' => ['/(?C)abc/', 'empty callout'];
        yield 'callout_zero' => ['/(?C0)def/', 'callout 0'];
        yield 'callout_large' => ['/(?C255)ghi/', 'callout 255'];
        yield 'callout_string' => ['/(?C"callback")jkl/', 'string callout'];
        // An empty string argument is allowed: preg_match() compiles it on PCRE2 10.40 and 10.48.
        yield 'callout_empty_string' => ['/(?C"")abc/', 'empty callout string'];

        // Complex combinations
        yield 'complex_1' => ['/(?C1)(*CR)a{,3}\p{L}+/', 'complex 1'];
        yield 'complex_3' => ['/(*NOTEMPTY_ATSTART)^[a\Rb]{1,10}(*THEN)/', 'complex 3'];
    }

    public static function providePcre84InvalidPatterns(): \Iterator
    {
        yield 'invalid_quantifier_range' => ['/a{5,2}/', 'min > max'];
        yield 'invalid_callout_range' => ['/(?C256)abc/', 'callout identifier out of range'];
        yield 'invalid_pcre_verb' => ['/(*INVALID)a/', 'unsupported PCRE verb'];
        yield 'invalid_group_verb' => ['/(?(*INVALID)a)/', 'unsupported verb in modifier group'];

        // Rows that once sat in the accepting providers above, on patterns PHP refuses.
        // "(?(" takes a condition, and a verb is not one: preg_match() on PCRE2 10.48 gives
        // "atomic assertion expected after (?( or (?(?C) at offset 3" for each of these.
        yield 'newline_cr_group' => ['/(?(*CR)a)/', '(*CR) newline verb inside modifier group'];
        yield 'newline_lf_group' => ['/(?(*LF)b)/', '(*LF) newline verb inside modifier group'];
        yield 'newline_crlf_group' => ['/(?(*CRLF)c)/', '(*CRLF) newline verb inside modifier group'];
        yield 'control_mark_group' => ['/(?(*MARK:label)a)/', '(*MARK) control verb inside modifier group'];
        yield 'control_prune_group' => ['/(?(*PRUNE)b)/', '(*PRUNE) control verb inside modifier group'];
        yield 'control_skip_group' => ['/(?(*SKIP)c)/', '(*SKIP) control verb inside modifier group'];
        yield 'control_then_group' => ['/(?(*THEN)d)/', '(*THEN) control verb inside modifier group'];
        yield 'encoding_utf8_group' => ['/(?(*UTF8)a)/', '(*UTF8) encoding verb inside modifier group'];
        yield 'encoding_ucp_group' => ['/(?(*UCP)b)/', '(*UCP) encoding verb inside modifier group'];
        yield 'match_notempty_group' => ['/(?(*NOTEMPTY)a)/', '(*NOTEMPTY) match control verb inside modifier group'];
        yield 'match_notempty_atstart_group' => ['/(?(*NOTEMPTY_ATSTART)b)/', '(*NOTEMPTY_ATSTART) match control verb inside modifier group'];
        yield 'comprehensive_newline_cr_group' => ['/(?(*CR)abc)/', '(*CR) in group'];
        yield 'comprehensive_newline_lf_group' => ['/(?(*LF)def)/', '(*LF) in group'];
        yield 'comprehensive_newline_crlf_group' => ['/(?(*CRLF)ghi)/', '(*CRLF) in group'];
        yield 'comprehensive_control_mark_group' => ['/(?(*MARK)abc)/', '(*MARK) in group'];
        yield 'comprehensive_control_prune_group' => ['/(?(*PRUNE)def)/', '(*PRUNE) in group'];
        yield 'comprehensive_encoding_utf8_group' => ['/(?(*UTF8)abc)/', '(*UTF8) in group'];
        yield 'comprehensive_encoding_ucp_group' => ['/(?(*UCP)def)/', '(*UCP) in group'];
        yield 'comprehensive_match_notempty_group' => ['/(?(*NOTEMPTY)abc)/', '(*NOTEMPTY) in group'];
        yield 'comprehensive_match_notempty_atstart_group' => ['/(?(*NOTEMPTY_ATSTART)def)/', '(*NOTEMPTY_ATSTART) in group'];
        yield 'comprehensive_complex_2' => ['/(?(*UTF8)(*MARK:pos)b{ 2 , 5 }(*PRUNE))/', 'complex 2'];

        // A callout takes a number or a delimited string: preg_match() on PCRE2 10.48 gives
        // "unrecognized string delimiter follows (?C at offset 4".
        yield 'callout_named' => ['/(?Cmyfunc)xyz/', 'named callout'];
        yield 'comprehensive_callout_named' => ['/(?Ccallback)mno/', 'named callout'];

        // A start-of-pattern setting is only one at the start: preg_match() on PCRE2 10.48 gives
        // "(*VERB) not recognized or malformed at offset 31".
        yield 'unicode_with_newlines' => ['/(*CRLF)\p{L}+(*NOTEMPTY_ATSTART)/', 'Unicode properties with newline verbs'];
    }
}
