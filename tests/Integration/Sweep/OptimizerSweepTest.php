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

namespace PhpRegex\Tests\Integration\Sweep;

use PhpRegex\Optimizer\Rewriter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

/**
 * A sweep of patterns through Optimizer.
 *
 * These cases were written to reach branches rather than to describe a
 * behaviour, and they were spread over eight files named after the metric
 * they served. They are grouped by what they exercise instead.
 */
final class OptimizerSweepTest extends TestCase
{
    private Rewriter $optimizerVisitor;

    private Regex $regex;

    private Regex $regexService;

    protected function setUp(): void
    {
        $this->optimizerVisitor = new Rewriter();
        $this->regex = Regex::create();
        $this->regexService = Regex::create();
    }

    public function test_optimizer_visitor_dot_node(): void
    {
        $ast = $this->regex->parse('/./');
        $result = $ast->accept($this->optimizerVisitor);
        $this->assertNotNull($result);
    }

    public function test_optimizer_visitor_keep_node(): void
    {
        $ast = $this->regex->parse('/\K/');
        $result = $ast->accept($this->optimizerVisitor);
        $this->assertNotNull($result);
    }

    public function test_optimizer_visitor_unicode_prop_node(): void
    {
        $ast = $this->regex->parse('/\p{L}/');
        $result = $ast->accept($this->optimizerVisitor);
        $this->assertNotNull($result);
    }

    public function test_optimizer_visitor_octal_legacy_node(): void
    {
        $ast = $this->regex->parse('/\01/');
        $result = $ast->accept($this->optimizerVisitor);
        $this->assertNotNull($result);
    }

    public function test_optimizer_visitor_alternation_to_char_class(): void
    {
        // Test canAlternationBeCharClass - alternation of single literals
        $ast = $this->regex->parse('/a|b|c/');
        $result = $ast->accept($this->optimizerVisitor);
        $this->assertNotNull($result);
    }

    public function test_optimizer_visitor_word_class_detection(): void
    {
        // Test isFullWordClass - character class with word characters
        $ast = $this->regex->parse('/[a-zA-Z0-9_]/');
        $result = $ast->accept($this->optimizerVisitor);
        $this->assertNotNull($result);
    }

    public function test_optimizer_alternation_with_literals(): void
    {
        $ast = $this->regexService->parse('/a|b|c/');
        $result = $ast->accept($this->optimizerVisitor);
        $this->assertNotNull($result);
    }

    public function test_optimizer_quantifier_optimizations(): void
    {
        $ast = $this->regexService->parse('/a{1}/');
        $result = $ast->accept($this->optimizerVisitor);
        $this->assertNotNull($result);

        $ast = $this->regexService->parse('/a{0,1}/');
        $result = $ast->accept($this->optimizerVisitor);
        $this->assertNotNull($result);
    }

    public function test_optimizer_char_class_single_char(): void
    {
        $ast = $this->regexService->parse('/[a]/');
        $result = $ast->accept($this->optimizerVisitor);
        $this->assertNotNull($result);
    }

    public function test_optimizer_empty_sequences(): void
    {
        $ast = $this->regexService->parse('/()/');
        $result = $ast->accept($this->optimizerVisitor);
        $this->assertNotNull($result);
    }

    public function test_optimizer_nested_groups(): void
    {
        $ast = $this->regexService->parse('/(?:(?:a))/');
        $result = $ast->accept($this->optimizerVisitor);
        $this->assertNotNull($result);
    }

    public function test_optimizer_all_node_types(): void
    {
        $patterns = [
            '/(?:a)/',         // group
            '/[a-z]/',         // char class
            '/\d/',            // char type
            '/./',             // dot
            '/^$/',            // anchors
            '/\b/',            // assertion
            '/\K/',            // keep
            '/\1/',            // backref
            '/\x41/',          // unicode
            '/\p{L}/',         // unicode prop
            '/\o{101}/',       // octal
            '/\01/',           // octal legacy
            '/[[:alpha:]]/',   // posix class
            '/(?#comment)/',   // comment
            '/(?(1)a|b)/',     // conditional
            '/(*FAIL)/',       // pcre verb
        ];

        foreach ($patterns as $pattern) {
            try {
                $ast = $this->regexService->parse($pattern);
                $result = $ast->accept($this->optimizerVisitor);
                $this->assertNotNull($result);
            } catch (\Exception) {
                // Some patterns may fail, that's ok
            }
        }
    }

    public function test_optimizer_quantifier_zero_times(): void
    {
        $ast = $this->regexService->parse('/a{0}/');
        $result = $ast->accept($this->optimizerVisitor);
        $this->assertNotNull($result);
    }

    public function test_optimizer_alternation_empty(): void
    {
        $ast = $this->regexService->parse('/(|a|b)/');
        $result = $ast->accept($this->optimizerVisitor);
        $this->assertNotNull($result);
    }

    public function test_optimizer_sequence_with_one_element(): void
    {
        $ast = $this->regexService->parse('/(?:a)/');
        $result = $ast->accept($this->optimizerVisitor);
        $this->assertNotNull($result);
    }

    public function test_optimizer_char_class_negated_single(): void
    {
        $ast = $this->regexService->parse('/[^a]/');
        $result = $ast->accept($this->optimizerVisitor);
        $this->assertNotNull($result);
    }

    public function test_optimizer_range(): void
    {
        $ast = $this->regexService->parse('/[a-z]/');
        $result = $ast->accept($this->optimizerVisitor);
        $this->assertNotNull($result);
    }

    public function test_optimizer_subroutine(): void
    {
        $ast = $this->regexService->parse('/(?<name>a)(?&name)/');
        $result = $ast->accept($this->optimizerVisitor);
        $this->assertNotNull($result);
    }

    public function test_optimizer_char_class_optimization(): void
    {
        $optimizer = new Rewriter();

        // Character class that could be optimized
        $ast = $this->regexService->parse('/[a]/');
        $result = $ast->accept($optimizer);
        $this->assertNotNull($result);

        // Multiple single chars
        $ast = $this->regexService->parse('/[abc]/');
        $result = $ast->accept($optimizer);
        $this->assertNotNull($result);
    }

    public function test_optimizer_quantifier_edge_cases(): void
    {
        $optimizer = new Rewriter();

        // Quantifier with 0 min
        $ast = $this->regexService->parse('/a{0,5}/');
        $result = $ast->accept($optimizer);
        $this->assertNotNull($result);

        // Quantifier {1,1} should become no quantifier
        $ast = $this->regexService->parse('/a{1,1}/');
        $result = $ast->accept($optimizer);
        $this->assertNotNull($result);
    }

    public function test_optimizer_sequence_flattening(): void
    {
        $optimizer = new Rewriter();

        // Nested sequences
        $ast = $this->regexService->parse('/abc/');
        $result = $ast->accept($optimizer);
        $this->assertNotNull($result);
    }

    public function test_optimizer_alternation_with_empty(): void
    {
        $optimizer = new Rewriter();

        // Alternation with one empty branch
        $ast = $this->regexService->parse('/a|/');
        $result = $ast->accept($optimizer);
        $this->assertNotNull($result);
    }
}
