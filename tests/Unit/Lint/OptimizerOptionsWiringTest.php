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

namespace RegexParser\Tests\Unit\Lint;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Bridge\PHPStan\RegexParserRule;
use RegexParser\Bridge\Symfony\Command\RegexLintCommand;
use RegexParser\Lint\Formatter\FormatterRegistry;
use RegexParser\Lint\RegexAnalysisService;
use RegexParser\Lint\RegexLintRequest;
use RegexParser\Lint\RegexLintService;
use RegexParser\Lint\RegexPatternOccurrence;
use RegexParser\Lint\RegexPatternSourceCollection;
use RegexParser\Optimizer\OptimizerOptions;
use RegexParser\RegexParser;

/**
 * Every lint entry point hands the optimizer the options its configuration
 * names, with one default of its own: a rewrite is checked with the automata
 * unless the configuration turns that off.
 */
final class OptimizerOptionsWiringTest extends TestCase
{
    #[Test]
    public function test_a_lint_request_checks_rewrites_with_the_automata_by_default(): void
    {
        $this->assertEquals(new OptimizerOptions(verifyWithAutomata: true), (new RegexLintRequest([], [], 1))->optimizations);
    }

    #[Test]
    public function test_suggestions_follow_the_options_given(): void
    {
        $analysis = new RegexAnalysisService(RegexParser::create());
        $occurrence = [new RegexPatternOccurrence('/[0-9]/', 'test.php', 1, 'preg_match')];

        $suggestions = $analysis->suggestOptimizations($occurrence, 1);
        $this->assertCount(1, $suggestions);
        $this->assertSame('/\\d/', $suggestions[0]['optimization']->optimized);

        $this->assertSame([], $analysis->suggestOptimizations($occurrence, 1, new OptimizerOptions(digits: false)));
    }

    #[Test]
    public function test_symfony_bundle_options_reach_the_optimizer(): void
    {
        $this->assertEquals(
            new OptimizerOptions(possessive: true, minQuantifierCount: 3, verifyWithAutomata: true),
            $this->symfonyOptions(['possessive' => true, 'min_quantifier_count' => 3]),
        );
        $this->assertFalse($this->symfonyOptions(['verify_with_automata' => false])->verifyWithAutomata);
    }

    #[Test]
    public function test_phpstan_parameters_reach_the_optimizer(): void
    {
        $this->assertEquals(
            new OptimizerOptions(factorize: true, minQuantifierCount: 5, verifyWithAutomata: true),
            $this->phpstanOptions(['factorize' => true, 'minQuantifierCount' => 5]),
        );
        $this->assertFalse($this->phpstanOptions(['verifyWithAutomata' => false])->verifyWithAutomata);
    }

    /**
     * @param array<string, bool|int> $optimizations
     */
    private function symfonyOptions(array $optimizations): OptimizerOptions
    {
        $analysis = new RegexAnalysisService(RegexParser::create());
        $command = new RegexLintCommand(
            lint: new RegexLintService($analysis, new RegexPatternSourceCollection([])),
            analysis: $analysis,
            formatterRegistry: new FormatterRegistry(),
            defaultOptimizations: $optimizations,
        );

        $options = (new \ReflectionProperty($command, 'defaultOptimizations'))->getValue($command);
        $this->assertInstanceOf(OptimizerOptions::class, $options);

        return $options;
    }

    /**
     * @param array<string, bool|int> $options
     */
    private function phpstanOptions(array $options): OptimizerOptions
    {
        $rule = new RegexParserRule(['checks' => ['optimizations' => ['enabled' => true, 'options' => $options]]]);

        $resolved = (new \ReflectionProperty($rule, 'optimizationOptions'))->getValue($rule);
        $this->assertInstanceOf(OptimizerOptions::class, $resolved);

        return $resolved;
    }
}
