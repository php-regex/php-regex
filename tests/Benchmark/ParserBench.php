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

namespace PhpRegex\Tests\Benchmark;

use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpRegex\Explain\TextExplainer;
use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Toolkit\Regex;

/**
 * Comprehensive performance benchmarks for RegexParser.
 *
 * Run with: vendor/bin/phpbench run tests/Benchmark/ParserBench.php
 */
#[Iterations(5)]
#[Revs(1000)]
#[BeforeMethods('setUp')]
final class ParserBench
{
    private Regex $regex;

    private PatternPrinter $compiler;

    private TextExplainer $explainer;

    public function setUp(): void
    {
        $this->regex = Regex::create();
        $this->compiler = new PatternPrinter();
        $this->explainer = new TextExplainer();
    }

    /**
     * Benchmark: Parse simple pattern /abc/
     * Expected: ~50-100 microseconds per iteration
     */
    public function benchSimplePattern(): void
    {
        $this->regex->parse('/abc/');
    }

    /**
     * Benchmark: Parse typical email validation regex
     * Expected: ~100-200 microseconds per iteration
     */
    public function benchComplexPattern(): void
    {
        // Realistic email validation pattern
        $this->regex->parse('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/');
    }

    /**
     * Benchmark: Parse deeply nested pattern
     * Tests recursion depth handling: ((((a))))
     * Expected: ~200-500 microseconds per iteration
     */
    public function benchDeeplyNested(): void
    {
        $this->regex->parse('/((((((((((a))))))))))/');
    }

    /**
     * Benchmark: Parse character class with ranges
     * Expected: ~80-150 microseconds per iteration
     */
    public function benchCharacterClass(): void
    {
        $this->regex->parse('/[a-zA-Z0-9_.-]/');
    }

    /**
     * Benchmark: Parse pattern with quantifiers
     * Expected: ~100-200 microseconds per iteration
     */
    public function benchQuantifiers(): void
    {
        $this->regex->parse('/a{2,5}b+c*/');
    }

    /**
     * Benchmark: Parse with alternation
     * Expected: ~120-250 microseconds per iteration
     */
    public function benchAlternation(): void
    {
        $this->regex->parse('/(apple|banana|cherry|date|elderberry)/');
    }

    /**
     * Benchmark: Parse and compile (full cycle)
     * Expected: ~150-300 microseconds per iteration
     */
    public function benchParseAndCompile(): void
    {
        $ast = $this->regex->parse('/[a-z]+/');
        $ast->accept($this->compiler);
    }

    /**
     * Benchmark: Parse and explain
     * Expected: ~200-400 microseconds per iteration
     */
    public function benchParseAndExplain(): void
    {
        $ast = $this->regex->parse('/\d{3}-\d{3}-\d{4}/');
        $ast->accept($this->explainer);
    }
}
