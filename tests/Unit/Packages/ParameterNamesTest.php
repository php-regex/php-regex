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

namespace PHPRegex\Tests\Unit\Packages;

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\TrivialMatchClassifier;
use PHPRegex\Optimizer\RedosRepairer;
use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\Analysis\GroupNumberingCollector;
use PHPRegex\Parser\Analysis\PatternInfoAnalyzer;
use PHPRegex\Parser\Analysis\RequiredLiteralAnalyzer;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Transpiler\Transpiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A parameter name is part of the API once a caller can name the argument.
 * One concept takes one name: "$regex" is a pattern with its delimiters and
 * flags, as everywhere on the facade ("$pattern" is the body alone), the
 * parsed tree is "$ast", the text matched against is "$subject" and the
 * parser a service takes is "$parser".
 */
final class ParameterNamesTest extends TestCase
{
    /**
     * @param class-string $class
     * @param list<string> $names
     */
    #[Test]
    #[DataProvider('provideSignatures')]
    public function test_a_public_method_names_its_parameters_as_the_facade_does(string $class, string $method, array $names): void
    {
        $parameters = array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            (new \ReflectionMethod($class, $method))->getParameters(),
        );

        $this->assertSame($names, \array_slice($parameters, 0, \count($names)), $class.'::'.$method.'()');
    }

    /**
     * The dialects a transpiler knows are the library's: its constructor
     * takes the parser alone, and builds the default one when none is given.
     */
    #[Test]
    public function test_the_transpiler_takes_the_parser_alone(): void
    {
        $parameters = (new \ReflectionMethod(Transpiler::class, '__construct'))->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertSame('?'.RegexParser::class, (string) $parameters[0]->getType());
        $this->assertTrue($parameters[0]->isDefaultValueAvailable());
        $this->assertNull($parameters[0]->getDefaultValue());
    }

    #[Test]
    public function test_the_transpiler_without_a_parser_reads_as_the_default_one(): void
    {
        $this->assertEquals(
            (new Transpiler(RegexParser::create()))->transpile('/(?P<word>\\w+)\\k{word}/i', 'javascript'),
            (new Transpiler())->transpile('/(?P<word>\\w+)\\k{word}/i', 'javascript'),
        );
        $this->assertSame('/(?<word>\\w+)\\k<word>/i', (new Transpiler())->transpile('/(?P<word>\\w+)\\k{word}/i', 'javascript')->literal);
    }

    /**
     * @return iterable<string, array{class: class-string, method: string, names: list<string>}>
     */
    public static function provideSignatures(): iterable
    {
        yield 'Transpiler::__construct' => ['class' => Transpiler::class, 'method' => '__construct', 'names' => ['parser']];
        yield 'Transpiler::transpile' => ['class' => Transpiler::class, 'method' => 'transpile', 'names' => ['regex', 'target']];
        yield 'LanguageSolver::acceptsPrefix' => ['class' => LanguageSolver::class, 'method' => 'acceptsPrefix', 'names' => ['regex', 'subject']];
        yield 'LanguageSolver::language' => ['class' => LanguageSolver::class, 'method' => 'language', 'names' => ['regex']];
        yield 'LanguageSolver::compile' => ['class' => LanguageSolver::class, 'method' => 'compile', 'names' => ['regex']];
        yield 'TrivialMatchClassifier::classify' => ['class' => TrivialMatchClassifier::class, 'method' => 'classify', 'names' => ['regex']];
        yield 'TrivialMatchClassifier::matchedLiteral' => ['class' => TrivialMatchClassifier::class, 'method' => 'matchedLiteral', 'names' => ['regex']];
        yield 'RedosRepairer::repair' => ['class' => RedosRepairer::class, 'method' => 'repair', 'names' => ['regex']];
        yield 'PcreEngine::compile' => ['class' => PcreEngine::class, 'method' => 'compile', 'names' => ['regex']];
        yield 'PcreEngine::match' => ['class' => PcreEngine::class, 'method' => 'match', 'names' => ['regex', 'subject']];
        yield 'PcreEngine::test' => ['class' => PcreEngine::class, 'method' => 'test', 'names' => ['regex', 'subject']];
        yield 'PcreEngine::matchAll' => ['class' => PcreEngine::class, 'method' => 'matchAll', 'names' => ['regex', 'subject']];
        yield 'CaptureShapeAnalyzer::__construct' => ['class' => CaptureShapeAnalyzer::class, 'method' => '__construct', 'names' => ['target']];
        yield 'CaptureShapeAnalyzer::analyze' => ['class' => CaptureShapeAnalyzer::class, 'method' => 'analyze', 'names' => ['ast']];
        yield 'PatternInfoAnalyzer::analyze' => ['class' => PatternInfoAnalyzer::class, 'method' => 'analyze', 'names' => ['ast']];
        yield 'PatternInfoAnalyzer::analyzePattern' => ['class' => PatternInfoAnalyzer::class, 'method' => 'analyzePattern', 'names' => ['parser', 'regex']];
        yield 'RequiredLiteralAnalyzer::analyze' => ['class' => RequiredLiteralAnalyzer::class, 'method' => 'analyze', 'names' => ['ast']];
        yield 'GroupNumberingCollector::collect' => ['class' => GroupNumberingCollector::class, 'method' => 'collect', 'names' => ['ast']];
    }
}
