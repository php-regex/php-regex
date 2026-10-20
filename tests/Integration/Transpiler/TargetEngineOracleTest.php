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

namespace PHPRegex\Tests\Integration\Transpiler;

use PHPRegex\Tests\Integration\Transpiler\Oracle\DifferentialRun;
use PHPRegex\Tests\Integration\Transpiler\Oracle\OracleCorpus;
use PHPRegex\Tests\Integration\Transpiler\Oracle\TargetEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The transpiler checked against the engines it writes for: each pattern
 * of the corpus is matched by preg_match() and, transpiled, by Node's
 * RegExp or Python's re, on the same subjects, and both must find the same
 * match and the same groups. A pattern the transpiler refuses is left out;
 * one it transpiles into something that matches otherwise, or that the
 * engine does not compile, fails the test.
 *
 * The engines are child processes, Node 20 or later and Python 3.11 or
 * later (TRANSPILER_ORACLE_NODE and TRANSPILER_ORACLE_PYTHON name other
 * binaries). The group runs on its own:
 *
 *     tools/phpunit/vendor/bin/phpunit --group transpiler-oracle
 */
#[Group('transpiler-oracle')]
final class TargetEngineOracleTest extends TestCase
{
    /**
     * How many random patterns join the fixtures.
     */
    private const GENERATED = 3000;

    /**
     * The fewest comparisons a run makes: fewer means the corpus or the
     * subjects shrank, and the run checks less than it claims.
     */
    private const MINIMUM_COMPARISONS = 100000;

    private static ?OracleCorpus $corpus = null;

    /**
     * @var list<string>|null
     */
    private static ?array $patterns = null;

    #[Test]
    #[DataProvider('provideTargets')]
    public function test_the_target_engine_matches_what_preg_match_matches(string $target): void
    {
        $engine = 'python' === $target ? TargetEngine::python() : TargetEngine::node();
        if (!$engine->isAvailable()) {
            $this->markTestSkipped(\sprintf('The %s oracle needs %s, which is not installed or too old here.', $target, 'python' === $target ? 'Python 3.11 or later' : 'Node 20 or later'));
        }

        self::$corpus ??= new OracleCorpus(\dirname(__DIR__, 3));
        self::$patterns ??= self::$corpus->patterns(self::GENERATED);
        $run = (new DifferentialRun($target, $engine, self::$corpus, self::$patterns))->run();

        $summary = \sprintf(
            '%d patterns, %d transpiled, %d refused; %d comparisons, %d differ; %d patterns unanswered by %s; %d subjects left out for a Unicode table of another version, %d for a match inside a surrogate pair.',
            $run->checked,
            $run->transpiled,
            $run->refused,
            $run->comparisons,
            \count($run->divergences),
            $run->timedOut,
            $engine->name,
            $run->tableSkews,
            $run->insidePairs,
        );

        $this->assertGreaterThanOrEqual(self::MINIMUM_COMPARISONS, $run->comparisons, $summary);
        $this->assertSame([], \array_slice($run->divergences, 0, 25), $summary);
    }

    /**
     * @return iterable<string, array{target: string}>
     */
    public static function provideTargets(): iterable
    {
        yield 'javascript' => ['target' => 'javascript'];
        yield 'html-pattern' => ['target' => 'html-pattern'];
        yield 'python' => ['target' => 'python'];
    }
}
