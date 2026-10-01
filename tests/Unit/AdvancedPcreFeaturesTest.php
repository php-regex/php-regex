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

namespace PhpRegex\Tests\Unit;

use PhpRegex\Explain\HtmlExplainer;
use PhpRegex\Explain\TextExplainer;
use PhpRegex\Generator\SampleGenerator;
use PhpRegex\Optimizer\Rewriter;
use PhpRegex\Parser\Analysis\ComplexityScorer;
use PhpRegex\Parser\Exception\ParserException;
use PhpRegex\Parser\Exception\SemanticErrorException;
use PhpRegex\Parser\Printer\NodeDumper;
use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Parser\Validation\Validator;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdvancedPcreFeaturesTest extends TestCase
{
    #[DataProvider('provideCalloutPatterns')]
    public function test_it_parses_and_compiles_callouts_correctly(string $pattern, ?string $expectedIdentifier): void
    {
        $regexService = Regex::create();
        $ast = $regexService->parse($pattern);

        $compiler = new PatternPrinter();
        $recompiled = $ast->accept($compiler);

        $this->assertSame($pattern, $recompiled);

        // Verify the identifier in the AST (using Dumper for inspection)
        $dumper = new NodeDumper();
        $dump = $ast->accept($dumper);
        if (null === $expectedIdentifier) {
            $this->assertStringContainsString('Callout()', $dump);
        } elseif (is_numeric($expectedIdentifier)) {
            $this->assertStringContainsString("Callout({$expectedIdentifier})", $dump);
        } else {
            $this->assertStringContainsString("Callout('{$expectedIdentifier}')", $dump);
        }
    }

    public static function provideCalloutPatterns(): \Iterator
    {
        yield 'bare callout' => ['/(?C)abc/', null];
        yield 'numeric callout' => ['/(?C1)abc/', '1'];
        yield 'string callout' => ['/(?C"debug_log")abc/', 'debug_log'];
        yield 'callout with spaces' => ['/(?C"my function")def/', 'my function'];
        yield 'callout with special chars' => ['/(?C"func_123-abc")xyz/', 'func_123-abc'];
        yield 'callout with zero' => ['/(?C0)test/', '0'];
        yield 'callout with max int' => ['/(?C255)foo/', '255'];
    }

    /**
     * Rows that once sat in provideCalloutPatterns(), on a pattern PHP refuses.
     */
    #[DataProvider('provideCalloutPatternsPhpRefuses')]
    public function test_it_refuses_callouts_php_refuses(string $pattern): void
    {
        $this->assertFalse(Regex::create()->validate($pattern)->isValid);
    }

    public static function provideCalloutPatternsPhpRefuses(): \Iterator
    {
        // preg_match() on PCRE2 10.48: "unrecognized string delimiter follows (?C at offset 4".
        // A callout takes a number or a delimited string, not a bare name.
        yield 'named callout' => ['/(?Cfoo)abc/'];
    }

    public function test_it_validates_callout_arguments(): void
    {
        $regexService = Regex::create();
        $validator = new Validator();

        // Valid numeric callout
        $ast = $regexService->parse('/(?C123)abc/');
        $ast->accept($validator); // Should not throw exception

        // Valid string callout
        $ast = $regexService->parse('/(?C"my_func")abc/');
        $ast->accept($validator); // Should not throw exception

        // Invalid numeric callout (too high)
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Callout identifier must be between 0 and 255, got 256.');
        $ast = $regexService->parse('/(?C256)abc/');
        $ast->accept($validator);
    }

    public function test_it_accepts_an_empty_string_callout_identifier(): void
    {
        // preg_match() compiles (?C""), (?C'') and (?C{}) on PCRE2 10.40 and 10.48.
        foreach (['/(?C"")abc/', "/(?C'')abc/", '/(?C{})abc/'] as $pattern) {
            $this->assertTrue(Regex::create()->validate($pattern)->isValid, $pattern);
        }
    }

    public function test_it_explains_callouts_correctly(): void
    {
        $regexService = Regex::create();
        $ast = $regexService->parse('/(?C1)abc/');
        $explainer = new TextExplainer();
        $explanation = $ast->accept($explainer);
        $this->assertStringContainsString('Callout: passes control to user function with argument 1', $explanation);

        $ast = $regexService->parse('/(?C"my_func")abc/');
        $explainer = new TextExplainer();
        $explanation = $ast->accept($explainer);
        $this->assertStringContainsString('Callout: passes control to user function with argument "my_func"', $explanation);
    }

    public function test_it_html_explains_callouts_correctly(): void
    {
        $regexService = Regex::create();
        $ast = $regexService->parse('/(?C1)abc/');
        $explainer = new HtmlExplainer();
        $explanation = $ast->accept($explainer);
        $this->assertStringContainsString('Callout: <strong>(?C1)</strong></span></li>', $explanation);
        $this->assertStringContainsString('title="passes control to user function with argument 1"', $explanation);

        $ast = $regexService->parse('/(?C"my_func")abc/');
        $explainer = new HtmlExplainer();
        $explanation = $ast->accept($explainer);
        $this->assertStringContainsString('Callout: <strong>(?C&quot;my_func&quot;)</strong></span></li>', $explanation);
        $this->assertStringContainsString('title="passes control to user function with argument &quot;my_func&quot;"', $explanation);
    }

    public function test_it_explains_callouts_without_argument(): void
    {
        $regexService = Regex::create();
        $ast = $regexService->parse('/(?C)abc/');

        $explainer = new TextExplainer();
        $explanation = $ast->accept($explainer);
        $this->assertStringContainsString('Callout: passes control to user function with no argument', $explanation);

        $htmlExplainer = new HtmlExplainer();
        $html = $ast->accept($htmlExplainer);
        $this->assertStringContainsString('Callout: <strong>(?C)</strong></span></li>', $html);
        $this->assertStringContainsString('title="passes control to user function with no argument"', $html);
    }

    public function test_it_optimizes_callouts_correctly(): void
    {
        $regexService = Regex::create();
        $ast = $regexService->parse('/(?C1)abc/');
        $optimizer = new Rewriter();
        $optimizedAst = $ast->accept($optimizer);
        // Callouts are atomic and should not be changed by the optimizer
        $compiler = new PatternPrinter();
        $this->assertSame('/(?C1)abc/', $optimizedAst->accept($compiler));
    }

    public function test_it_generates_sample_for_callouts_correctly(): void
    {
        $regexService = Regex::create();
        $ast = $regexService->parse('/a(?C1)b/');
        $sampleGenerator = new SampleGenerator();
        $sample = $ast->accept($sampleGenerator);
        // Callouts do not match characters, so they should not appear in the sample
        $this->assertSame('ab', $sample);
    }

    public function test_it_calculates_complexity_score_for_callouts(): void
    {
        $regexService = Regex::create();
        $ast = $regexService->parse('/a(?C1)b/');
        $complexityVisitor = new ComplexityScorer();
        $score = $ast->accept($complexityVisitor);
        // a (1) + (?C1) (5) + b (1) = 7
        $this->assertSame(7, $score);
    }

    public function test_it_allows_duplicate_group_names_with_j_flag(): void
    {
        $pattern = '/(?J)(?<name>a)|(?<name>b)/';
        $regexService = Regex::create();
        $ast = $regexService->parse($pattern);

        $validator = new Validator();
        $ast->accept($validator); // Should not throw an exception

        $compiler = new PatternPrinter();
        $this->assertSame($pattern, $ast->accept($compiler));
    }

    public function test_it_throws_exception_for_duplicate_group_names_without_j_flag(): void
    {
        $this->expectException(ParserException::class);
        // PHP: "two named subpatterns have the same name ... at offset 19",
        // past the ">" that closes the second name.
        $this->expectExceptionMessage('Duplicate group name "name" at position 19.');

        $pattern = '/(?<name>a)|(?<name>b)/';
        $regexService = Regex::create();
        $ast = $regexService->parse($pattern);

        $validator = new Validator();
        $ast->accept($validator);
    }

    public function test_it_handles_inline_j_flag_for_duplicate_group_names(): void
    {
        $pattern = '/(?-J)(?J)(?<name>a)|(?<name>b)/'; // Disable J, then enable J
        $regexService = Regex::create();
        $ast = $regexService->parse($pattern);

        $validator = new Validator();
        $ast->accept($validator); // Should not throw an exception

        $compiler = new PatternPrinter();
        $this->assertSame($pattern, $ast->accept($compiler));
    }

    public function test_it_respects_j_flag_scope_in_inline_modifiers(): void
    {
        $this->expectException(ParserException::class);
        // PHP: "two named subpatterns have the same name ... at offset 27",
        // past the ">" that closes the second name.
        $this->expectExceptionMessage('Duplicate group name "name" at position 27.');

        // J is enabled globally, then disabled for the second group
        $pattern = '/(?J)(?<name>a)(?-J)(?<name>b)/';
        $regexService = Regex::create();
        $ast = $regexService->parse($pattern);

        $validator = new Validator();
        $ast->accept($validator);
    }

    public function test_extended_mode_ignores_whitespace_and_comments(): void
    {
        $regexService = Regex::create();

        // Test whitespace ignored
        $ast = $regexService->parse('/a b c/x');
        $dumper = new NodeDumper();
        $dump = $ast->accept($dumper);
        $this->assertStringContainsString('Sequence', $dump);
        $this->assertStringContainsString("Literal('a')", $dump);
        $this->assertStringContainsString("Literal('b')", $dump);
        $this->assertStringContainsString("Literal('c')", $dump);
        // Should not contain whitespace nodes

        // Test comments ignored
        $ast = $regexService->parse("/a#comment\nb/x");
        $dump = $ast->accept($dumper);
        $this->assertStringContainsString('Sequence', $dump);
        $this->assertStringContainsString("Literal('a')", $dump);
        $this->assertStringContainsString("Literal('b')", $dump);
        // Should not contain comment text

        // Test compilation
        $compiler = new PatternPrinter();
        $recompiled = $ast->accept($compiler);
        $this->assertSame("/a#comment\nb/x", $recompiled);
    }

    public function test_inline_modifier_validation_throws_on_unknown_flags(): void
    {
        $regexService = Regex::create();

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Invalid group modifier syntax');

        $regexService->parse('/(?z)a/');
    }
}
