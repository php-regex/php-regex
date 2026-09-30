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

namespace RegexParser\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Automata\Api\RegexLanguageSolver;
use RegexParser\Automata\Solver\RegexSolver;
use RegexParser\Lint\RegexAnalysisService;
use RegexParser\ReDoS\ReDoSAnalyzer;
use RegexParser\ReDoS\ReDoSSeverity;
use RegexParser\RegexParser;
use RegexParser\Transpiler\RegexTranspiler;

/**
 * What reads patterns beside the facade takes the core parser, and reads
 * them for its target: ReDoS analysis, automata, the transpiler and the
 * linter never need the facade.
 */
final class ParserConsumersTest extends TestCase
{
    #[Test]
    public function test_the_analyses_read_patterns_with_the_parser_they_are_given(): void
    {
        $parser = RegexParser::create(['cache' => null, 'php_version' => '8.2']);

        $this->assertSame(ReDoSSeverity::CRITICAL, (new ReDoSAnalyzer($parser))->analyze('/(a+)+$/')->severity);
        $this->assertTrue((new RegexSolver($parser))->equivalent('/a|a/', '/a/')->isEquivalent);
        $this->assertTrue(RegexLanguageSolver::forRegex($parser)->equivalent('/ab?/', '/a|ab/')->isEquivalent);
        $this->assertSame('/a+/', (new RegexTranspiler($parser))->transpile('/a+/', 'javascript')->literal);
    }

    #[Test]
    public function test_the_linter_reads_patterns_with_the_parser_it_is_given(): void
    {
        $parser = RegexParser::create(['cache' => null]);
        $analysis = new RegexAnalysisService($parser);

        $this->assertSame($parser, $analysis->getParser());
    }
}
