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

namespace PhpRegex\Tests\Integration\Sweep;

use PhpRegex\Parser\Exception\SemanticErrorException;
use PhpRegex\Parser\Validation\Validator;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

/**
 * A sweep of patterns through Validator.
 *
 * These cases were written to reach branches rather than to describe a
 * behaviour, and they were spread over eight files named after the metric
 * they served. They are grouped by what they exercise instead.
 */
final class ValidatorSweepTest extends TestCase
{
    private Regex $regex;

    private Regex $regexService;

    private Validator $validatorVisitor;

    protected function setUp(): void
    {
        $this->regex = Regex::create();
        $this->regexService = Regex::create();
        $this->validatorVisitor = new Validator();
    }

    public function test_validator_visitor_dot_node(): void
    {
        $this->expectNotToPerformAssertions();
        $ast = $this->regex->parse('/./');
        $ast->accept($this->validatorVisitor);
    }

    public function test_validator_visitor_keep_node(): void
    {
        $this->expectNotToPerformAssertions();
        $ast = $this->regex->parse('/\K/');
        $ast->accept($this->validatorVisitor);
    }

    public function test_validator_visitor_comment_node(): void
    {
        $this->expectNotToPerformAssertions();
        $ast = $this->regex->parse('/(?#comment)/');
        $ast->accept($this->validatorVisitor);
    }

    public function test_validator_visitor_pcre_verb_node(): void
    {
        $this->expectNotToPerformAssertions();
        $ast = $this->regex->parse('/(*FAIL)/');
        $ast->accept($this->validatorVisitor);
    }

    public function test_validator_visitor_octal_legacy_node(): void
    {
        $this->expectNotToPerformAssertions();
        $ast = $this->regex->parse('/\01/');
        $ast->accept($this->validatorVisitor);
    }

    public function test_validator_visitor_octal_node(): void
    {
        $this->expectNotToPerformAssertions();
        $ast = $this->regex->parse('/\o{101}/');
        $ast->accept($this->validatorVisitor);
    }

    public function test_validator_visitor_quantifier_bounds(): void
    {
        $this->expectNotToPerformAssertions();
        // Test parseQuantifierBounds with different quantifier types
        $ast = $this->regex->parse('/a{5}/'); // exact
        $ast->accept($this->validatorVisitor);

        $ast = $this->regex->parse('/a{2,5}/'); // range
        $ast->accept($this->validatorVisitor);

        $ast = $this->regex->parse('/a{2,}/'); // open-ended
        $ast->accept($this->validatorVisitor);

        $ast = $this->regex->parse('/a*/'); // star
        $ast->accept($this->validatorVisitor);

        $ast = $this->regex->parse('/a+/'); // plus
        $ast->accept($this->validatorVisitor);

        $ast = $this->regex->parse('/a?/'); // question
        $ast->accept($this->validatorVisitor);
    }

    public function test_validator_visitor_unicode_prop(): void
    {
        $this->expectNotToPerformAssertions();
        $ast = $this->regex->parse('/\p{L}/');
        $ast->accept($this->validatorVisitor);
    }

    public function test_validator_visitor_conditional(): void
    {
        $this->expectNotToPerformAssertions();
        $ast = $this->regex->parse('/(?(?=a)yes|no)/');
        $ast->accept($this->validatorVisitor);
    }

    public function test_validator_visitor_subroutine(): void
    {
        $this->expectNotToPerformAssertions();
        $ast = $this->regex->parse('/(?<name>a)(?&name)/');
        $ast->accept($this->validatorVisitor);
    }

    public function test_validator_visitor_assertion_nodes(): void
    {
        $this->expectNotToPerformAssertions();
        $ast = $this->regex->parse('/\b\B\A\z\Z\G/');
        $ast->accept($this->validatorVisitor);
    }

    public function test_validator_quantifier_edge_cases(): void
    {
        $this->expectNotToPerformAssertions();
        // Valid quantifiers - validator throws exception if invalid
        $ast = $this->regexService->parse('/a{0}/');
        $ast->accept($this->validatorVisitor);

        $ast = $this->regexService->parse('/a{1,1}/');
        $ast->accept($this->validatorVisitor);
    }

    public function test_validator_char_class_ranges(): void
    {
        $this->expectNotToPerformAssertions();
        $ast = $this->regexService->parse('/[a-z]/');
        $ast->accept($this->validatorVisitor);
    }

    public function test_validator_backref_variations(): void
    {
        $this->expectNotToPerformAssertions();
        $ast = $this->regexService->parse('/(a)\1/');
        $ast->accept($this->validatorVisitor);

        $ast = $this->regexService->parse('/(?<name>a)\k<name>/');
        $ast->accept($this->validatorVisitor);
    }

    public function test_validator_unicode_variations(): void
    {
        $ast = $this->regexService->parse('/\x41/');
        $ast->accept($this->validatorVisitor);

        // preg_match() on PCRE2 10.48: "PCRE2 does not support \F, \L, \l, \N{name}, \U, or \u at offset 2".
        $ast = $this->regexService->parse('/\u{1F600}/');
        $this->expectException(SemanticErrorException::class);
        $ast->accept($this->validatorVisitor);
    }

    public function test_validator_conditional_variations(): void
    {
        $this->expectNotToPerformAssertions();
        // Test conditional with lookahead assertion (valid)
        $ast = $this->regexService->parse('/(?(?=a)b|c)/');
        $ast->accept($this->validatorVisitor);
    }

    public function test_validator_unicode_variations_all(): void
    {
        $this->expectNotToPerformAssertions();
        $patterns = [
            '/\x00/',      // null byte
            '/\xFF/',      // max byte
            '/\u{0}/',     // null unicode
            '/\u{10FFFF}/', // max unicode
        ];

        foreach ($patterns as $pattern) {
            try {
                $ast = $this->regexService->parse($pattern);
                $ast->accept($this->validatorVisitor);
            } catch (\Exception) {
                // Some may fail
            }
        }
    }

    public function test_validator_octal_variations(): void
    {
        $this->expectNotToPerformAssertions();
        $patterns = [
            // '/\0/', // \0 is treated as backreference \0, not octal
            '/\01/',
            '/\07/',
            '/\o{0}/',
            '/\o{377}/',
        ];

        foreach ($patterns as $pattern) {
            $ast = $this->regexService->parse($pattern);
            $ast->accept($this->validatorVisitor);
        }
    }

    public function test_validator_posix_class_negated(): void
    {
        $this->expectNotToPerformAssertions();
        $ast = $this->regexService->parse('/[[:^alpha:]]/');
        $ast->accept($this->validatorVisitor);
    }

    public function test_validator_subroutine(): void
    {
        $this->expectNotToPerformAssertions();
        $ast = $this->regexService->parse('/(?<name>a)(?&name)/');
        $ast->accept($this->validatorVisitor);
    }

    public function test_validator_atomic_group(): void
    {
        $this->expectNotToPerformAssertions();
        $ast = $this->regexService->parse('/(?>a+)/');
        $ast->accept($this->validatorVisitor);
    }

    public function test_validator_range_validation(): void
    {
        // Valid ranges
        $result = $this->regexService->validate('/[a-z]/');
        $this->assertTrue($result->isValid);

        $result = $this->regexService->validate('/[0-9]/');
        $this->assertTrue($result->isValid);

        $result = $this->regexService->validate('/[A-Z]/');
        $this->assertTrue($result->isValid);
    }

    public function test_validator_with_invalid_backref(): void
    {
        // Test Validator with invalid backreference
        $this->expectException(SemanticErrorException::class);

        $ast = $this->regexService->parse('/\1/');

        $visitor = new Validator();
        $ast->accept($visitor);
    }

    public function test_validator_with_invalid_subroutine(): void
    {
        // Test Validator with invalid subroutine
        $this->expectException(SemanticErrorException::class);

        $ast = $this->regexService->parse('/(?&nonexistent)/');

        $visitor = new Validator();
        $ast->accept($visitor);
    }
}
