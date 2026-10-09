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
use PHPRegex\Tests\Benchmark\Support\BenchCases;
use PHPRegex\Tests\Benchmark\Support\ColdStart;
use PHPRegex\Tests\Benchmark\Support\GrowthSeries;
use PHPRegex\Tests\Benchmark\Support\Workloads;

/**
 * The static ReDoS verdict, the path PHPStan and the linter take, on the
 * pathological ReDoS cases and on the growth series, whose points all stay
 * under the default budget. The per-pattern corpus verdict lives in
 * RedosCorpusBench (group redos-corpus), too long for a routine run.
 *
 * Cold subjects run once per iteration. Their before-method runs the same
 * work once, so the code is loaded, then empties the caches: the measured run
 * pays for cold caches, not for compiling the library. That untimed run must
 * reach the outcome the case declares (every growth point: proven), or the
 * variant errors: a change that sends a case onto the budget path breaks it
 * on that side instead of timing other work under the same name.
 *
 * Every before-method ends by resetting the peak memory, so mem_peak covers
 * the measured run only; benchNoop's before-method loads the same code first,
 * so its peak is the floor the subjects are read against.
 *
 * Run with: composer bench -- --group=redos
 */
#[Groups(['redos'])]
#[Revs(1)]
#[Iterations(10)]
#[Warmup(0)]
#[OutputTimeUnit('milliseconds')]
final class RedosBench
{
    private RegexParser $parser;

    private string $pattern = '';

    public function __construct()
    {
        $this->parser = RegexParser::create(['cache' => null]);
    }

    /**
     * The memory floor: the same code loaded and parser built, no work.
     */
    #[BeforeMethods('setUpNoop')]
    public function benchNoop(): void {}

    /**
     * @param array{key: string} $params
     */
    #[BeforeMethods('coldCase')]
    #[ParamProviders('provideCases')]
    public function benchRedosCase(array $params): void
    {
        Workloads::redos($this->pattern, $this->parser);
    }

    /**
     * @param array{key: string} $params
     */
    #[BeforeMethods('coldGrowth')]
    #[ParamProviders('provideGrowth')]
    public function benchGrowth(array $params): void
    {
        Workloads::redos($this->pattern, $this->parser);
    }

    public function setUpNoop(): void
    {
        BenchCases::all();
        GrowthSeries::forGroup('redos');
        $this->setUpCold();
        Workloads::redos('/a/', $this->parser);
        $this->setUpCold();
        memory_reset_peak_usage();
    }

    /**
     * @param array{key: string} $params
     */
    public function coldCase(array $params): void
    {
        $case = BenchCases::get($params['key']);
        $this->pattern = $case->pattern;
        $this->setUpCold();
        Workloads::warmUp('redos', $this->pattern, $case->expect ?? throw new \UnexpectedValueException(\sprintf('The case "%s" declares no outcome.', $case->key)), $this->parser);
        $this->setUpCold();
        memory_reset_peak_usage();
    }

    /**
     * @param array{key: string} $params
     */
    public function coldGrowth(array $params): void
    {
        $this->pattern = GrowthSeries::pattern('redos', $params['key']);
        $this->setUpCold();
        Workloads::warmUp('redos', $this->pattern, 'proven', $this->parser);
        $this->setUpCold();
        memory_reset_peak_usage();
    }

    /**
     * @return array<string, array{key: string}>
     */
    public function provideCases(): array
    {
        return BenchCases::params('redos');
    }

    /**
     * @return array<string, array{key: string}>
     */
    public function provideGrowth(): array
    {
        return GrowthSeries::params('redos');
    }

    private function setUpCold(): void
    {
        $this->parser = ColdStart::regex()->parser();
    }
}
