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
use PHPRegex\Tests\Benchmark\Support\BenchCases;
use PHPRegex\Tests\Benchmark\Support\ColdStart;
use PHPRegex\Toolkit\Regex;

/**
 * Validation: the realistic corpus and the pathological validation cases,
 * those PCRE refuses included (the error path).
 *
 * Cold subjects run once per iteration. Their before-method runs the same
 * work once, so the code is loaded, then empties the caches: the measured run
 * pays for cold caches, not for compiling the library. The *Warm subject
 * keeps the default AST cache and the validator's caches; its before-method
 * runs it once to fill them (phpbench's own warmup would run after the
 * memory reset).
 *
 * Every before-method ends by resetting the peak memory, so mem_peak covers
 * the measured run only; benchNoop's before-method loads the same code and
 * inputs first, so its peak is the floor the subjects are read against.
 *
 * Run with: composer bench -- --group=validate
 */
#[Groups(['validate'])]
#[Revs(1)]
#[Iterations(10)]
#[Warmup(0)]
#[OutputTimeUnit('milliseconds')]
final class ValidateBench
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
    public function benchValidateRealistic(): void
    {
        foreach ($this->patterns as $pattern) {
            $this->regex->validate($pattern);
        }
    }

    /**
     * @param array{key: string} $params
     */
    #[BeforeMethods('coldCase')]
    #[ParamProviders('provideCases')]
    public function benchValidateCase(array $params): void
    {
        $this->regex->validate($this->pattern);
    }

    /**
     * @param array{key: string} $params
     */
    #[BeforeMethods('warmCase')]
    #[ParamProviders('provideCases')]
    #[Revs(100)]
    #[Iterations(5)]
    #[OutputTimeUnit('microseconds')]
    public function benchValidateCaseWarm(array $params): void
    {
        $this->regex->validate($this->pattern);
    }

    public function setUpNoop(): void
    {
        $this->setUpRealistic();
        BenchCases::all();
        $this->regex->validate('/a/');
        $this->regex = ColdStart::regex();
        memory_reset_peak_usage();
    }

    public function coldRealistic(): void
    {
        $this->setUpRealistic();
        $this->benchValidateRealistic();
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
        $this->benchValidateCase($params);
        $this->regex = ColdStart::regex();
        memory_reset_peak_usage();
    }

    /**
     * @param array{key: string} $params
     */
    public function warmCase(array $params): void
    {
        $this->pattern = BenchCases::get($params['key'])->pattern;
        $this->regex = Regex::create();
        $this->benchValidateCaseWarm($params);
        memory_reset_peak_usage();
    }

    /**
     * @return array<string, array{key: string}>
     */
    public function provideCases(): array
    {
        return BenchCases::params('validate');
    }

    private function setUpRealistic(): void
    {
        $this->patterns = array_values(BenchCases::realistic());
        $this->regex = ColdStart::regex();
    }
}
