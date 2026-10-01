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

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Generator\TestCaseGenerator;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class TestCaseGeneratorNodeVisitorTest extends TestCase
{
    private TestCaseGenerator $visitor;

    protected function setUp(): void
    {
        $this->visitor = new TestCaseGenerator();
    }

    public function test_literal(): void
    {
        $ast = Regex::create()->parse('/abc/');
        $cases = $ast->accept($this->visitor);

        $this->assertContains('abc', $cases['matching']);
        $this->assertNotEmpty($cases['non_matching']);
    }

    public function test_quantifier(): void
    {
        $ast = Regex::create()->parse('/a+/');
        $cases = $ast->accept($this->visitor);

        $this->assertContains('a', $cases['matching']);
        $this->assertContains('aa', $cases['matching']);
        $this->assertContains('', $cases['non_matching']); // Too few
    }

    public function test_alternation(): void
    {
        $ast = Regex::create()->parse('/(a|b)/');
        $cases = $ast->accept($this->visitor);

        $this->assertContains('a', $cases['matching']);
        $this->assertNotEmpty($cases['non_matching']);
    }

    public function test_char_class(): void
    {
        $ast = Regex::create()->parse('/[abc]/');
        $cases = $ast->accept($this->visitor);

        $this->assertNotEmpty($cases['matching']);
        $this->assertContains('!', $cases['non_matching']);
    }

    public function test_dot(): void
    {
        $ast = Regex::create()->parse('/./');
        $cases = $ast->accept($this->visitor);

        $this->assertContains('a', $cases['matching']);
        $this->assertContains("\n", $cases['non_matching']);
    }

    public function test_char_type_digit(): void
    {
        $ast = Regex::create()->parse('/\d/');
        $cases = $ast->accept($this->visitor);

        $this->assertContains('0', $cases['matching']);
        $this->assertNotEmpty($cases['non_matching']);
    }

    public function test_char_type_non_digit(): void
    {
        $ast = Regex::create()->parse('/\D/');
        $cases = $ast->accept($this->visitor);

        $this->assertContains('a', $cases['matching']);
        $this->assertNotEmpty($cases['non_matching']);
    }

    public function test_char_type_whitespace(): void
    {
        $ast = Regex::create()->parse('/\s/');
        $cases = $ast->accept($this->visitor);

        $this->assertContains(' ', $cases['matching']);
        $this->assertNotEmpty($cases['non_matching']);
    }

    public function test_char_type_word(): void
    {
        $ast = Regex::create()->parse('/\w/');
        $cases = $ast->accept($this->visitor);

        $this->assertContains('a', $cases['matching']);
        $this->assertNotEmpty($cases['non_matching']);
    }

    public function test_anchor_start(): void
    {
        $ast = Regex::create()->parse('/^a/');
        $cases = $ast->accept($this->visitor);

        $this->assertNotEmpty($cases['matching']);
        $this->assertNotEmpty($cases['non_matching']);
    }

    public function test_anchor_end(): void
    {
        $ast = Regex::create()->parse('/a$/');
        $cases = $ast->accept($this->visitor);

        $this->assertNotEmpty($cases['matching']);
        $this->assertNotEmpty($cases['non_matching']);
    }

    public function test_assertion_lookahead(): void
    {
        $ast = Regex::create()->parse('/a(?=b)/');
        $cases = $ast->accept($this->visitor);

        $this->assertNotEmpty($cases['matching']);
        $this->assertNotEmpty($cases['non_matching']);
    }

    public function test_range(): void
    {
        $ast = Regex::create()->parse('/[a-z]/');
        $cases = $ast->accept($this->visitor);

        $this->assertContains('a', $cases['matching']);
        $this->assertNotEmpty($cases['non_matching']);
    }

    public function test_backreference(): void
    {
        $ast = Regex::create()->parse('/(a)\1/');
        $cases = $ast->accept($this->visitor);

        $this->assertNotEmpty($cases['matching']);
        $this->assertNotEmpty($cases['non_matching']);
    }

    public function test_unicode(): void
    {
        $ast = Regex::create()->parse('/\x{0041}/');
        $cases = $ast->accept($this->visitor);

        $this->assertContains('A', $cases['matching']);
        $this->assertNotEmpty($cases['non_matching']);
    }

    public function test_unicode_property(): void
    {
        $ast = Regex::create()->parse('/\p{L}/');
        $cases = $ast->accept($this->visitor);

        $this->assertContains('a', $cases['matching']);
        $this->assertNotEmpty($cases['non_matching']);
    }

    public function test_posix_class(): void
    {
        $ast = Regex::create()->parse('/[[:alpha:]]/');
        $cases = $ast->accept($this->visitor);

        $this->assertContains('a', $cases['matching']);
        $this->assertNotEmpty($cases['non_matching']);
    }

    public function test_comment(): void
    {
        $ast = Regex::create()->parse('/(?#comment)a/');
        $cases = $ast->accept($this->visitor);

        $this->assertNotEmpty($cases['matching']);
        $this->assertNotEmpty($cases['non_matching']);
    }

    public function test_conditional(): void
    {
        $ast = Regex::create()->parse('/(?(condition)yes|no)/');
        $cases = $ast->accept($this->visitor);

        $this->assertNotEmpty($cases['matching']);
        $this->assertNotEmpty($cases['non_matching']);
    }

    public function test_subroutine(): void
    {
        $ast = Regex::create()->parse('/(?1)/');
        $cases = $ast->accept($this->visitor);

        $this->assertNotEmpty($cases['matching']);
        $this->assertNotEmpty($cases['non_matching']);
    }

    public function test_pcre_verb(): void
    {
        $ast = Regex::create()->parse('/(*VERB)a/');
        $cases = $ast->accept($this->visitor);

        $this->assertNotEmpty($cases['matching']);
        $this->assertNotEmpty($cases['non_matching']);
    }

    public function test_define(): void
    {
        $ast = Regex::create()->parse('/(?(DEFINE)...)/');
        $cases = $ast->accept($this->visitor);

        $this->assertNotEmpty($cases['matching']);
        $this->assertNotEmpty($cases['non_matching']);
    }

    public function test_limit_match(): void
    {
        $ast = Regex::create()->parse('/(*LIMIT_MATCH=100)a/');
        $cases = $ast->accept($this->visitor);

        $this->assertNotEmpty($cases['matching']);
        $this->assertNotEmpty($cases['non_matching']);
    }

    public function test_callout(): void
    {
        $ast = Regex::create()->parse('/(?C)a/');
        $cases = $ast->accept($this->visitor);

        $this->assertNotEmpty($cases['matching']);
        $this->assertNotEmpty($cases['non_matching']);
    }

    public function test_keep(): void
    {
        $ast = Regex::create()->parse('/\Ka/');
        $cases = $ast->accept($this->visitor);

        $this->assertNotEmpty($cases['matching']);
        $this->assertNotEmpty($cases['non_matching']);
    }
}
