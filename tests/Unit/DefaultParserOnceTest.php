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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Solver\EquivalenceResult;
use PHPRegex\Automata\TrivialMatch;
use PHPRegex\Automata\TrivialMatchClassifier;
use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Optimizer\RedosRepairer;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A service built without a parser reads with one of its own, and keeps it:
 * two calls on one instance share one parser and one tree cache, where a
 * parser built per call would read every pattern anew.
 */
final class DefaultParserOnceTest extends TestCase
{
    /**
     * @param \Closure(): mixed $call Reads one pattern through the service
     */
    #[Test]
    #[DataProvider('services')]
    public function test_the_default_parser_is_kept_between_calls(object $service, \Closure $call): void
    {
        $call();
        $first = self::parserOf($service);
        $this->assertInstanceOf(RegexParser::class, $first);
        $hitsAfterOneCall = $first->getCacheStats()['hits'];
        $call();
        $second = self::parserOf($service);

        $this->assertSame($first, $second, 'Two calls read with one parser.');
        $this->assertGreaterThan($hitsAfterOneCall, $first->getCacheStats()['hits'], 'The second call finds the tree the first one read.');
    }

    /**
     * @return iterable<string, array{object, \Closure(): mixed}>
     */
    public static function services(): iterable
    {
        $analyzer = new RedosAnalyzer();
        yield 'redos analyzer' => [$analyzer, static fn (): RedosAnalysis => $analyzer->analyze('/(a+)+b/')];

        $solver = new LanguageSolver();
        yield 'language solver' => [$solver, static fn (): EquivalenceResult => $solver->equivalent('/a+/', '/aa*/')];

        $classifier = new TrivialMatchClassifier();
        yield 'trivial match classifier' => [$classifier, static fn (): ?TrivialMatch => $classifier->classify('/^abc$/')];

        $repairer = new RedosRepairer();
        yield 'redos repairer' => [$repairer, static fn (): array => $repairer->repair('/(a+)+b/')];

        $generator = new SampleGenerator();
        yield 'sample generator' => [$generator, static fn (): string => $generator->generate('/a+b/')];
    }

    private static function parserOf(object $service): mixed
    {
        return (new \ReflectionProperty($service, 'parser'))->getValue($service);
    }
}
