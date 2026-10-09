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
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Tests\Benchmark\Support\BenchCases;
use PHPRegex\Tests\Benchmark\Support\ColdStart;
use PHPRegex\Toolkit\Regex;

/**
 * The optimizer under its default options: the realistic corpus and the
 * pathological optimizer cases; and the same cases with every rewrite
 * verified by automata, the options the PHPStan extension ships with (five
 * iterations: one of its cases takes seconds). It keeps no cache of its own
 * beyond the AST cache, so there is no warm twin.
 *
 * Cold subjects run once per iteration. Their before-method runs the same
 * work once, so the code is loaded, then empties the caches: the measured run
 * pays for cold caches, not for compiling the library.
 *
 * Every before-method ends by resetting the peak memory, so mem_peak covers
 * the measured run only; benchNoop's before-method loads the same code and
 * inputs first, so its peak is the floor the subjects are read against.
 *
 * Run with: composer bench -- --group=optimizer
 */
#[Groups(['optimizer'])]
#[Revs(1)]
#[Iterations(10)]
#[Warmup(0)]
#[OutputTimeUnit('milliseconds')]
final class OptimizerBench
{
    private Regex $regex;

    /**
     * @var list<string>
     */
    private array $patterns = [];

    private string $pattern = '';

    public function __construct()
    {
        $this->regex = Regex::create(['cache' => null]);
    }

    /**
     * The memory floor: the same code and inputs loaded, no work.
     */
    #[BeforeMethods('setUpNoop')]
    public function benchNoop(): void {}

    #[BeforeMethods('coldRealistic')]
    public function benchOptimizeRealistic(): void
    {
        foreach ($this->patterns as $pattern) {
            $this->regex->optimize($pattern);
        }
    }

    /**
     * @param array{key: string} $params
     */
    #[BeforeMethods('coldCase')]
    #[ParamProviders('provideCases')]
    public function benchOptimizeCase(array $params): void
    {
        $this->regex->optimize($this->pattern);
    }

    /**
     * @param array{key: string} $params
     */
    #[BeforeMethods('coldVerifiedCase')]
    #[ParamProviders('provideCases')]
    #[Iterations(5)]
    public function benchOptimizeVerifiedCase(array $params): void
    {
        $this->regex->optimize($this->pattern, OptimizerOptions::fromCamelCaseArray(['verifyWithAutomata' => true]));
    }

    public function setUpNoop(): void
    {
        $this->setUpRealistic();
        BenchCases::all();
        $this->regex->optimize('/a/');
        $this->regex = ColdStart::regex();
        memory_reset_peak_usage();
    }

    public function coldRealistic(): void
    {
        $this->setUpRealistic();
        $this->benchOptimizeRealistic();
        $this->regex = ColdStart::regex();
        memory_reset_peak_usage();
    }

    /**
     * @param array{key: string} $params
     */
    public function coldCase(array $params): void
    {
        $this->pattern = BenchCases::get($params['key'])->pattern;
        $this->regex = ColdStart::regex();
        $this->benchOptimizeCase($params);
        $this->regex = ColdStart::regex();
        memory_reset_peak_usage();
    }

    /**
     * @param array{key: string} $params
     */
    public function coldVerifiedCase(array $params): void
    {
        $this->pattern = BenchCases::get($params['key'])->pattern;
        $this->regex = ColdStart::regex();
        $this->benchOptimizeVerifiedCase($params);
        $this->regex = ColdStart::regex();
        memory_reset_peak_usage();
    }

    /**
     * @return array<string, array{key: string}>
     */
    public function provideCases(): array
    {
        return BenchCases::params('optimizer');
    }

    private function setUpRealistic(): void
    {
        $this->patterns = array_values(BenchCases::realistic());
        $this->regex = ColdStart::regex();
    }
}
