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

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Explain\AsciiTreeRenderer;
use PhpRegex\Explain\Highlighter\ConsoleHighlighter;
use PhpRegex\Explain\Highlighter\HtmlHighlighter;
use PhpRegex\Explain\HtmlExplainer;
use PhpRegex\Explain\MermaidRenderer;
use PhpRegex\Explain\RailroadSvgRenderer;
use PhpRegex\Explain\TextExplainer;
use PhpRegex\Generator\SampleGenerator;
use PhpRegex\Generator\TestCaseGenerator;
use PhpRegex\Linter\PatternLinter;
use PhpRegex\Optimizer\Modernizer;
use PhpRegex\Optimizer\Rewriter;
use PhpRegex\Parser\Analysis\ComplexityScorer;
use PhpRegex\Parser\Analysis\LengthRangeCalculator;
use PhpRegex\Parser\Analysis\LiteralExtractor;
use PhpRegex\Parser\Analysis\MetricsCollector;
use PhpRegex\Parser\NodeVisitorInterface;
use PhpRegex\Parser\Printer\NodeDumper;
use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Parser\Validation\Validator;
use PhpRegex\Redos\RedosProfiler;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VisitorReturnTypesTest extends TestCase
{
    private Regex $regex;

    protected function setUp(): void
    {
        $this->regex = Regex::create();
    }

    /**
     * This provider includes rare PCRE constructs to hit specific branches
     * in ExplainVisitor, PatternPrinter, and NodeDumper.
     */
    public static function provideRareConstructs(): \Iterator
    {
        // Assertions
        yield ['/\A\Z\z\G\b\B/'];

        // Char Types (vertical/horizontal/newline)
        yield ['/\v\V\h\H\R/'];

        // Escapes
        yield ['/\012\o{123}\x{F}\u{1F600}/'];

        // Backreferences & Subroutines
        yield ['/(a)\g{1}\g{-1}\g<1>/']; // \g as backref
        yield ['/(?<name>a)(?&name)(?P>name)\g<name>/']; // \g as subroutine

        // PCRE Verbs
        yield ['/(*FAIL)(*ACCEPT)(*COMMIT)(*PRUNE)(*SKIP)(*THEN)(*UTF8)/'];

        // Conditionals
        yield ['/(?(1)yes|no)/']; // Numeric ref
        yield ['/(?(<name>)yes)/']; // Named ref
        yield ['/(?(R)yes)/']; // Recursion check
        yield ['/(?(?=a)yes)/']; // Lookahead check
        yield ['/(?(DEFINE)a)/']; // Define

        // POSIX classes
        yield ['/[[:alpha:][:digit:][:xdigit:][:punct:][:graph:][:print:][:cntrl:]]/'];
    }

    #[DataProvider('provideRareConstructs')]
    public function test_visitors_handle_construct(string $regex): void
    {
        // We deliberately suppress errors for compilation of exotic features
        // that might not be supported by the underlying PCRE version of the OS,
        // but our Parser supports them.
        try {
            $ast = $this->regex->parse($regex);
        } catch (\Exception $e) {
            // If the parser fails, the test fails.
            $this->fail('Parser failed on: '.$regex.' Error: '.$e->getMessage());
        }

        // 1. Test Compiler (Round-trip)
        $compiler = new PatternPrinter();
        $compiled = $ast->accept($compiler);
        $this->assertNotEmpty($compiled);

        // 2. Test Dumper (String representation)
        $dumper = new NodeDumper();
        $dump = $ast->accept($dumper);
        $this->assertNotEmpty($dump);

        // 3. Test Explain (Text)
        $explainer = new TextExplainer();
        $explanation = $ast->accept($explainer);
        $this->assertNotEmpty($explanation);

        // 4. Test HTML Explain
        $htmlExplainer = new HtmlExplainer();
        $html = $ast->accept($htmlExplainer);
        $this->assertNotEmpty($html);
    }

    public function test_all_visitors_can_be_instantiated(): void
    {
        // Instantiate all visitor classes to cover class coverage
        $visitors = [
            new PatternPrinter(),
            new ComplexityScorer(),
            new ConsoleHighlighter(),
            new NodeDumper(),
            new TextExplainer(),
            new HtmlExplainer(),
            new HtmlHighlighter(),
            new LengthRangeCalculator(),
            new PatternLinter(),
            new LiteralExtractor(),
            new MermaidRenderer(),
            new MetricsCollector(),
            new Modernizer(),
            new Rewriter(),
            new AsciiTreeRenderer(),
            new RailroadSvgRenderer(),
            new RedosProfiler(),
            new SampleGenerator(),
            new TestCaseGenerator(),
            new Validator(),
        ];

        // Visitors instantiated for coverage testing
        $this->assertCount(20, $visitors);
        $this->assertContainsOnlyInstancesOf(NodeVisitorInterface::class, $visitors);
    }
}
