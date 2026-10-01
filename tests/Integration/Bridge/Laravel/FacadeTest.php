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

namespace PhpRegex\Tests\Integration\Bridge\Laravel;

use PhpRegex\Laravel\Facades\Regex;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Parser\TolerantParseResult;
use PhpRegex\Parser\Validation\ValidationResult;
use PhpRegex\Toolkit\AnalysisReport;
use PhpRegex\Redos\RedosAnalysis;
use PhpRegex\Redos\RedosSeverity;
use PhpRegex\Optimizer\OptimizationResult;
use PhpRegex\Transpiler\TranspileResult;
use PhpRegex\Parser\Analysis\LiteralExtractionResult;
use PhpRegex\Laravel\PhpRegexServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Tests for the Laravel Regex Facade.
 */
final class FacadeTest extends TestCase
{
    public function test_parse_returns_regex_node(): void
    {
        $ast = Regex::parse('/^[a-z]+$/');

        $this->assertInstanceOf(RegexNode::class, $ast);
        $this->assertNotNull($ast->pattern);
    }

    public function test_parse_with_tolerant_mode_returns_tolerant_result(): void
    {
        $result = Regex::parse('/^[a-z]+$/', tolerant: true);

        $this->assertInstanceOf(TolerantParseResult::class, $result);
        $this->assertInstanceOf(RegexNode::class, $result->ast);
        $this->assertEmpty($result->errors);
    }

    public function test_parse_tolerant_mode_with_invalid_pattern(): void
    {
        $result = Regex::parse('/^(unclosed/', tolerant: true);

        $this->assertInstanceOf(TolerantParseResult::class, $result);
        $this->assertNotEmpty($result->errors);
    }

    public function test_validate_returns_validation_result(): void
    {
        $result = Regex::validate('/^[a-z]+$/');

        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertTrue($result->isValid);
    }

    public function test_validate_detects_invalid_patterns(): void
    {
        $result = Regex::validate('/^(unclosed/');

        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertFalse($result->isValid);
        $this->assertNotNull($result->error);
    }

    public function test_analyze_returns_analysis_report(): void
    {
        $report = Regex::analyze('/^[a-z]+$/');

        $this->assertInstanceOf(AnalysisReport::class, $report);
        $this->assertTrue($report->isValid);
    }

    public function test_redos_returns_redos_analysis(): void
    {
        $analysis = Regex::redos('/^(a+)+$/');

        $this->assertInstanceOf(RedosAnalysis::class, $analysis);
    }

    public function test_redos_detects_vulnerable_patterns(): void
    {
        $analysis = Regex::redos('/^(a+)+$/');

        $this->assertInstanceOf(RedosAnalysis::class, $analysis);
        // Use isSafe() method - vulnerable patterns are NOT safe
        $this->assertFalse($analysis->isSafe());
        // Or check severity is not SAFE or LOW
        $this->assertNotSame(RedosSeverity::Safe, $analysis->severity);
    }

    public function test_optimize_returns_optimization_result(): void
    {
        $result = Regex::optimize('/[0-9]+/');

        $this->assertInstanceOf(OptimizationResult::class, $result);
    }

    public function test_optimize_suggests_improvements(): void
    {
        $result = Regex::optimize('/[0-9]+/');

        $this->assertInstanceOf(OptimizationResult::class, $result);
        // [0-9] should be optimized to \d
        $this->assertStringContainsString('\\d', $result->optimized);
    }

    public function test_transpile_returns_transpile_result(): void
    {
        $result = Regex::transpile('/^[a-z]+$/', 'javascript');

        $this->assertInstanceOf(TranspileResult::class, $result);
        $this->assertNotEmpty($result->pattern);
    }

    public function test_explain_returns_string(): void
    {
        $explanation = Regex::explain('/^[a-z]+$/');

        $this->assertIsString($explanation);
        $this->assertNotEmpty($explanation);
    }

    public function test_explain_html_format(): void
    {
        $explanation = Regex::explain('/^[a-z]+$/', format: 'html');

        $this->assertIsString($explanation);
        $this->assertNotEmpty($explanation);
    }

    public function test_highlight_returns_string(): void
    {
        $highlighted = Regex::highlight('/^[a-z]+$/');

        $this->assertIsString($highlighted);
        $this->assertNotEmpty($highlighted);
    }

    public function test_highlight_html_format(): void
    {
        $highlighted = Regex::highlight('/^[a-z]+$/', format: 'html');

        $this->assertIsString($highlighted);
        $this->assertStringContainsString('<', $highlighted);
    }

    public function test_literals_returns_literal_extraction_result(): void
    {
        $result = Regex::literals('/^hello world$/');

        $this->assertInstanceOf(LiteralExtractionResult::class, $result);
    }

    public function test_generate_returns_sample_string(): void
    {
        $sample = Regex::generate('/^[a-z]{3}$/');

        $this->assertIsString($sample);
        $this->assertSame(3, \strlen($sample));
        $this->assertMatchesRegularExpression('/^[a-z]{3}$/', $sample);
    }

    public function test_parse_pattern_with_separate_components(): void
    {
        $ast = Regex::parsePattern('[a-z]+', 'i', '/');

        $this->assertInstanceOf(RegexNode::class, $ast);
        $this->assertStringContainsString('i', $ast->flags);
    }

    /**
     * Get package providers.
     *
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PhpRegexServiceProvider::class,
        ];
    }

    /**
     * Get package aliases.
     *
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'Regex' => Regex::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('regex-parser.cache.directory', null);
        $app['config']->set('regex-parser.cache.store', null);
        $app['config']->set('regex-parser.runtime_pcre_validation', false);
    }
}
