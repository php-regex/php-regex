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

namespace PHPRegex\Tests\Benchmark;

use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\OutputTimeUnit;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Tests\Benchmark\Support\BenchCases;

/**
 * One theoretical ReDoS analysis per pattern of the lint corpus, each keyed
 * by a hash of the pattern, so the per-pattern cost can be read and compared
 * pattern by pattern. The analysis keeps the default AST cache across its
 * revolutions: the subject is warm. Its before-method runs the analysis
 * once, untimed, to fill that cache and load the code, then resets the peak
 * memory, so mem_peak covers the measured revolutions only (phpbench's own
 * warmup would run after that reset). benchNoop's before-method loads the
 * same code first, so its peak is the floor the subject is read against.
 *
 * One variant per corpus pattern makes this the longest group of the suite,
 * hence its own group, three iterations instead of ten and five revolutions
 * instead of the hundred the other warm subjects take: a single pass over
 * every variant already lasts about twenty minutes.
 *
 * Run with: composer bench -- --group=redos-corpus
 */
#[Groups(['redos-corpus'])]
#[Revs(5)]
#[Iterations(3)]
#[Warmup(0)]
#[OutputTimeUnit('milliseconds')]
final class RedosCorpusBench
{
    private RedosAnalyzer $analyzer;

    private string $pattern = '';

    public function __construct()
    {
        $this->analyzer = new RedosAnalyzer(RegexParser::create());
    }

    /**
     * The memory floor: the same code loaded and analyzer built, no work.
     */
    #[BeforeMethods('setUpNoop')]
    public function benchNoop(): void {}

    /**
     * @param array{key: string} $params
     */
    #[BeforeMethods('setUpVerdict')]
    #[ParamProviders('provideCorpusPatterns')]
    public function benchTheoreticalVerdictWarm(array $params): void
    {
        $this->analyzer->analyze($this->pattern, null, RedosMode::Theoretical);
    }

    /**
     * @param array{key: string} $params
     */
    public function setUpVerdict(array $params): void
    {
        $this->pattern = BenchCases::realistic()[$params['key']] ?? throw new \OutOfBoundsException(\sprintf('No corpus pattern "%s".', $params['key']));
        $this->analyzer = new RedosAnalyzer(RegexParser::create());
        $this->benchTheoreticalVerdictWarm($params);
        memory_reset_peak_usage();
    }

    public function setUpNoop(): void
    {
        BenchCases::realistic();
        $this->analyzer = new RedosAnalyzer(RegexParser::create());
        $this->analyzer->analyze('/a/', null, RedosMode::Theoretical);
        $this->analyzer = new RedosAnalyzer(RegexParser::create());
        memory_reset_peak_usage();
    }

    /**
     * @return array<string, array{key: string}>
     */
    public function provideCorpusPatterns(): array
    {
        return BenchCases::realisticParams();
    }
}
