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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Parser\Analysis\LiteralExtractor;
use PHPRegex\Parser\Analysis\LiteralSet;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class LiteralExtractorNodeVisitorTest extends TestCase
{
    private LiteralExtractor $visitor;

    private Regex $regexService;

    protected function setUp(): void
    {
        $this->visitor = new LiteralExtractor();
        $this->regexService = Regex::create();
    }

    public function test_visit_regex_case_sensitive(): void
    {
        $ast = $this->regexService->parse('/abc/');
        $result = $ast->accept($this->visitor);

        $this->assertInstanceOf(LiteralSet::class, $result);
        $this->assertContains('abc', $result->prefixes);
    }

    public function test_visit_regex_case_insensitive(): void
    {
        $ast = $this->regexService->parse('/abc/i');
        $result = $ast->accept($this->visitor);

        $this->assertInstanceOf(LiteralSet::class, $result);
        $this->assertContains('abc', $result->prefixes);
        $this->assertContains('ABC', $result->prefixes);
    }

    public function test_visit_alternation_common_literals(): void
    {
        $ast = $this->regexService->parse('/foo|bar/');
        $result = $ast->accept($this->visitor);

        $this->assertInstanceOf(LiteralSet::class, $result);
        $this->assertNotEmpty($result->prefixes);
    }

    public function test_visit_alternation_with_common_prefix(): void
    {
        $ast = $this->regexService->parse('/pre|pre/');
        $result = $ast->accept($this->visitor);

        $this->assertInstanceOf(LiteralSet::class, $result);
        $this->assertContains('pre', $result->prefixes);
    }

    public function test_visit_sequence_concatenation(): void
    {
        $ast = $this->regexService->parse('/abc/');
        $result = $ast->accept($this->visitor);

        $this->assertContains('abc', $result->prefixes);
    }

    public function test_visit_sequence_empty(): void
    {
        $sequence = new SequenceNode([], 0, 0);
        $result = $this->visitor->visitSequence($sequence);

        $this->assertEquals(LiteralSet::fromString(''), $result);
    }

    public function test_visit_group_without_flags(): void
    {
        $ast = $this->regexService->parse('/(abc)/');
        $result = $ast->accept($this->visitor);

        $this->assertContains('abc', $result->prefixes);
    }

    public function test_visit_group_with_inline_i_flag(): void
    {
        $ast = $this->regexService->parse('/(?i:abc)/');
        $result = $ast->accept($this->visitor);

        $this->assertContains('abc', $result->prefixes);
        $this->assertContains('ABC', $result->prefixes);
    }

    public function test_visit_group_with_inline_minus_i_flag(): void
    {
        $ast = $this->regexService->parse('/(?-i:abc)/i');
        $result = $ast->accept($this->visitor);

        $this->assertContains('abc', $result->prefixes);
        $this->assertNotContains('ABC', $result->prefixes);
    }

    public function test_visit_quantifier_exact_repeat(): void
    {
        $ast = $this->regexService->parse('/a{3}/');
        $result = $ast->accept($this->visitor);

        $this->assertContains('aaa', $result->prefixes);
    }

    public function test_visit_quantifier_exact_zero(): void
    {
        $ast = $this->regexService->parse('/a{0}/');
        $result = $ast->accept($this->visitor);

        $this->assertEquals(LiteralSet::fromString(''), $result);
    }

    public function test_visit_quantifier_plus(): void
    {
        $ast = $this->regexService->parse('/a+/');
        $result = $ast->accept($this->visitor);

        $this->assertContains('a', $result->prefixes);
        $this->assertEmpty($result->suffixes);
        $this->assertFalse($result->complete);
    }

    public function test_visit_quantifier_star(): void
    {
        $ast = $this->regexService->parse('/a*/');
        $result = $ast->accept($this->visitor);

        $this->assertEquals(LiteralSet::empty(), $result);
    }

    public function test_visit_quantifier_question(): void
    {
        $ast = $this->regexService->parse('/a?/');
        $result = $ast->accept($this->visitor);

        $this->assertEquals(LiteralSet::empty(), $result);
    }

    public function test_visit_char_class_simple_literal(): void
    {
        $ast = $this->regexService->parse('/[a]/');
        $result = $ast->accept($this->visitor);

        $this->assertContains('a', $result->prefixes);
    }

    public function test_visit_char_class_multiple_literals(): void
    {
        $ast = $this->regexService->parse('/[ab]/');
        $result = $ast->accept($this->visitor);

        $this->assertContains('a', $result->prefixes);
        $this->assertContains('b', $result->prefixes);
    }

    public function test_visit_char_class_negated(): void
    {
        $ast = $this->regexService->parse('/[^a]/');
        $result = $ast->accept($this->visitor);

        $this->assertEquals(LiteralSet::empty(), $result);
    }

    public function test_visit_char_class_with_range(): void
    {
        $ast = $this->regexService->parse('/[a-z]/');
        $result = $ast->accept($this->visitor);

        $this->assertEquals(LiteralSet::empty(), $result);
    }

    public function test_expand_case_insensitive_short(): void
    {
        $ref = new \ReflectionClass($this->visitor);
        $method = $ref->getMethod('expandCaseInsensitive');

        $result = $method->invoke($this->visitor, 'a');

        $this->assertInstanceOf(LiteralSet::class, $result);
        $this->assertContains('a', $result->prefixes);
        $this->assertContains('A', $result->prefixes);
    }

    public function test_expand_case_insensitive_too_long(): void
    {
        $ref = new \ReflectionClass($this->visitor);
        $method = $ref->getMethod('expandCaseInsensitive');

        $result = $method->invoke($this->visitor, 'abcdefghijk');

        $this->assertEquals(LiteralSet::empty(), $result);
    }

    public function test_expand_case_insensitive_too_many_permutations(): void
    {
        $ref = new \ReflectionClass($this->visitor);
        $method = $ref->getMethod('expandCaseInsensitive');

        // 2^8 = 256, but MAX_LITERALS_COUNT is 128, so should be empty
        $result = $method->invoke($this->visitor, 'aaaaaaaa');

        $this->assertEquals(LiteralSet::empty(), $result);
    }

    public function test_visit_assertion_via_regex(): void
    {
        $ast = $this->regexService->parse('/(?=a)b/');
        $result = $ast->accept($this->visitor);

        $this->assertInstanceOf(LiteralSet::class, $result);
    }

    public function test_visit_keep_via_regex(): void
    {
        $ast = $this->regexService->parse('/a\\Kb/');
        $result = $ast->accept($this->visitor);

        $this->assertInstanceOf(LiteralSet::class, $result);
    }

    public function test_visit_range_via_regex(): void
    {
        $ast = $this->regexService->parse('/[a-z]/');
        $result = $ast->accept($this->visitor);

        $this->assertInstanceOf(LiteralSet::class, $result);
    }

    public function test_visit_backref_via_regex(): void
    {
        $ast = $this->regexService->parse('/(a)\\1/');
        $result = $ast->accept($this->visitor);

        $this->assertInstanceOf(LiteralSet::class, $result);
    }

    public function test_visit_unicode_prop_via_regex(): void
    {
        $ast = $this->regexService->parse('/\\p{L}/');
        $result = $ast->accept($this->visitor);

        $this->assertInstanceOf(LiteralSet::class, $result);
    }

    public function test_visit_posix_class_via_regex(): void
    {
        $ast = $this->regexService->parse('/[[:alpha:]]/');
        $result = $ast->accept($this->visitor);

        $this->assertInstanceOf(LiteralSet::class, $result);
    }

    public function test_visit_comment_via_regex(): void
    {
        $ast = $this->regexService->parse('/(?#comment)abc/');
        $result = $ast->accept($this->visitor);

        $this->assertInstanceOf(LiteralSet::class, $result);
    }

    public function test_visit_conditional_via_regex(): void
    {
        $ast = $this->regexService->parse('/(?(1)a|b)/');
        $result = $ast->accept($this->visitor);

        $this->assertInstanceOf(LiteralSet::class, $result);
    }

    public function test_visit_subroutine_via_regex(): void
    {
        $ast = $this->regexService->parse('/(?<name>a)(?&name)/');
        $result = $ast->accept($this->visitor);

        $this->assertInstanceOf(LiteralSet::class, $result);
    }

    public function test_visit_pcre_verb_via_regex(): void
    {
        $ast = $this->regexService->parse('/(*FAIL)abc/');
        $result = $ast->accept($this->visitor);

        $this->assertInstanceOf(LiteralSet::class, $result);
    }

    public function test_visit_define_via_regex(): void
    {
        $ast = $this->regexService->parse('/(?(DEFINE)(?<name>a))b/');
        $result = $ast->accept($this->visitor);

        $this->assertInstanceOf(LiteralSet::class, $result);
    }

    public function test_visit_limit_match_via_regex(): void
    {
        $ast = $this->regexService->parse('/(*LIMIT_MATCH=1)abc/');
        $result = $ast->accept($this->visitor);

        $this->assertInstanceOf(LiteralSet::class, $result);
    }

    public function test_visit_callout_via_regex(): void
    {
        $ast = $this->regexService->parse('/(?C1)abc/');
        $result = $ast->accept($this->visitor);

        $this->assertInstanceOf(LiteralSet::class, $result);
    }
}
