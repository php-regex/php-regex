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

use PhpRegex\Parser\Analysis\MetricsCollector;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class MetricsNodeVisitorTest extends TestCase
{
    public function test_it_collects_counts_and_depth(): void
    {
        $ast = Regex::create()->parse('/(a|b)c/');
        $metrics = $ast->accept(new MetricsCollector());

        $this->assertSame(7, $metrics['total']);
        $this->assertSame(3, $metrics['counts']['LiteralNode'] ?? null);
        $this->assertSame(1, $metrics['counts']['AlternationNode'] ?? null);
        $this->assertGreaterThanOrEqual(4, $metrics['maxDepth']);
    }

    public function test_it_counts_quantifier_nodes(): void
    {
        $ast = Regex::create()->parse('/a+/');
        $metrics = $ast->accept(new MetricsCollector());

        $this->assertArrayHasKey('QuantifierNode', $metrics['counts']);
        $this->assertGreaterThan(0, $metrics['counts']['QuantifierNode']);
    }

    public function test_it_counts_char_type_nodes(): void
    {
        $ast = Regex::create()->parse('/\d+/');
        $metrics = $ast->accept(new MetricsCollector());

        $this->assertArrayHasKey('CharTypeNode', $metrics['counts']);
        $this->assertGreaterThan(0, $metrics['counts']['CharTypeNode']);
    }

    public function test_it_counts_dot_nodes(): void
    {
        $ast = Regex::create()->parse('/./');
        $metrics = $ast->accept(new MetricsCollector());

        $this->assertArrayHasKey('DotNode', $metrics['counts']);
        $this->assertGreaterThan(0, $metrics['counts']['DotNode']);
    }

    public function test_it_counts_anchor_nodes(): void
    {
        $ast = Regex::create()->parse('/^a$/');
        $metrics = $ast->accept(new MetricsCollector());

        $this->assertArrayHasKey('AnchorNode', $metrics['counts']);
        $this->assertGreaterThan(0, $metrics['counts']['AnchorNode']);
    }

    public function test_it_counts_assertion_nodes(): void
    {
        $ast = Regex::create()->parse('/\b/');
        $metrics = $ast->accept(new MetricsCollector());

        $this->assertArrayHasKey('AssertionNode', $metrics['counts']);
        $this->assertGreaterThan(0, $metrics['counts']['AssertionNode']);
    }

    public function test_it_counts_keep_nodes(): void
    {
        $ast = Regex::create()->parse('/a\K/');
        $metrics = $ast->accept(new MetricsCollector());

        $this->assertArrayHasKey('KeepNode', $metrics['counts']);
        $this->assertGreaterThan(0, $metrics['counts']['KeepNode']);
    }

    public function test_it_counts_char_class_nodes(): void
    {
        $ast = Regex::create()->parse('/[abc]/');
        $metrics = $ast->accept(new MetricsCollector());

        $this->assertArrayHasKey('CharClassNode', $metrics['counts']);
        $this->assertGreaterThan(0, $metrics['counts']['CharClassNode']);
    }

    public function test_it_counts_range_nodes(): void
    {
        $ast = Regex::create()->parse('/[a-z]/');
        $metrics = $ast->accept(new MetricsCollector());

        $this->assertArrayHasKey('RangeNode', $metrics['counts']);
        $this->assertGreaterThan(0, $metrics['counts']['RangeNode']);
    }

    public function test_it_counts_backreference_nodes(): void
    {
        $ast = Regex::create()->parse('/(a)\1/');
        $metrics = $ast->accept(new MetricsCollector());

        $this->assertArrayHasKey('BackrefNode', $metrics['counts']);
        $this->assertGreaterThan(0, $metrics['counts']['BackrefNode']);
    }

    public function test_it_counts_unicode_nodes(): void
    {
        $ast = Regex::create()->parse('/\x{0041}/');
        $metrics = $ast->accept(new MetricsCollector());

        $this->assertArrayHasKey('CharLiteralNode', $metrics['counts']);
        $this->assertGreaterThan(0, $metrics['counts']['CharLiteralNode']);
    }

    public function test_it_counts_unicode_prop_nodes(): void
    {
        $ast = Regex::create()->parse('/\p{L}/');
        $metrics = $ast->accept(new MetricsCollector());

        $this->assertArrayHasKey('UnicodePropNode', $metrics['counts']);
        $this->assertGreaterThan(0, $metrics['counts']['UnicodePropNode']);
    }

    public function test_it_counts_posix_class_nodes(): void
    {
        $ast = Regex::create()->parse('/[[:alpha:]]/');
        $metrics = $ast->accept(new MetricsCollector());

        $this->assertArrayHasKey('PosixClassNode', $metrics['counts']);
        $this->assertGreaterThan(0, $metrics['counts']['PosixClassNode']);
    }

    public function test_it_counts_comment_nodes(): void
    {
        $ast = Regex::create()->parse('/(?#comment)a/');
        $metrics = $ast->accept(new MetricsCollector());

        $this->assertArrayHasKey('CommentNode', $metrics['counts']);
        $this->assertGreaterThan(0, $metrics['counts']['CommentNode']);
    }

    public function test_it_counts_conditional_nodes(): void
    {
        $ast = Regex::create()->parse('/(?(condition)yes|no)/');
        $metrics = $ast->accept(new MetricsCollector());

        $this->assertArrayHasKey('ConditionalNode', $metrics['counts']);
        $this->assertGreaterThan(0, $metrics['counts']['ConditionalNode']);
    }

    public function test_it_counts_subroutine_nodes(): void
    {
        $ast = Regex::create()->parse('/(?1)/');
        $metrics = $ast->accept(new MetricsCollector());

        $this->assertArrayHasKey('SubroutineNode', $metrics['counts']);
        $this->assertGreaterThan(0, $metrics['counts']['SubroutineNode']);
    }

    public function test_it_counts_pcre_verb_nodes(): void
    {
        $ast = Regex::create()->parse('/(*VERB)a/');
        $metrics = $ast->accept(new MetricsCollector());

        $this->assertArrayHasKey('PcreVerbNode', $metrics['counts']);
        $this->assertGreaterThan(0, $metrics['counts']['PcreVerbNode']);
    }

    public function test_it_counts_define_nodes(): void
    {
        $ast = Regex::create()->parse('/(?(DEFINE)...)/');
        $metrics = $ast->accept(new MetricsCollector());

        $this->assertArrayHasKey('DefineNode', $metrics['counts']);
        $this->assertGreaterThan(0, $metrics['counts']['DefineNode']);
    }

    public function test_it_counts_limit_match_nodes(): void
    {
        $ast = Regex::create()->parse('/(*LIMIT_MATCH=100)a/');
        $metrics = $ast->accept(new MetricsCollector());

        $this->assertArrayHasKey('LimitMatchNode', $metrics['counts']);
        $this->assertGreaterThan(0, $metrics['counts']['LimitMatchNode']);
    }

    public function test_it_counts_callout_nodes(): void
    {
        $ast = Regex::create()->parse('/(?C)a/');
        $metrics = $ast->accept(new MetricsCollector());

        $this->assertArrayHasKey('CalloutNode', $metrics['counts']);
        $this->assertGreaterThan(0, $metrics['counts']['CalloutNode']);
    }
}
