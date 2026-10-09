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
 * The lexer alone: the token stream of the realistic corpus and of the
 * pathological lexer cases. The lexer keeps no cache of its own, so there is
 * no warm twin.
 *
 * Cold subjects run once per iteration. Their before-method runs the same
 * work once, so the code is loaded, then empties the caches: the measured run
 * pays for cold caches, not for compiling the library.
 *
 * Every before-method ends by resetting the peak memory, so mem_peak covers
 * the measured run only; benchNoop's before-method loads the same code and
 * inputs first, so its peak is the floor the subjects are read against.
 *
 * Run with: composer bench -- --group=lexer
 */
#[Groups(['lexer'])]
#[Revs(1)]
#[Iterations(10)]
#[Warmup(0)]
#[OutputTimeUnit('milliseconds')]
final class LexerBench
{
    /**
     * @var list<string>
     */
    private array $patterns = [];

    private string $pattern = '';

    /**
     * The memory floor: the same code and inputs loaded, no work.
     */
    #[BeforeMethods('setUpNoop')]
    public function benchNoop(): void {}

    #[BeforeMethods('coldRealistic')]
    public function benchTokenizeRealistic(): void
    {
        foreach ($this->patterns as $pattern) {
            Regex::tokenize($pattern);
        }
    }

    /**
     * @param array{key: string} $params
     */
    #[BeforeMethods('coldCase')]
    #[ParamProviders('provideCases')]
    public function benchTokenizeCase(array $params): void
    {
        Regex::tokenize($this->pattern);
    }

    public function setUpNoop(): void
    {
        $this->setUpRealistic();
        BenchCases::all();
        Regex::tokenize('/a/');
        ColdStart::regex();
        memory_reset_peak_usage();
    }

    public function coldRealistic(): void
    {
        $this->setUpRealistic();
        $this->benchTokenizeRealistic();
        ColdStart::regex();
        memory_reset_peak_usage();
    }

    /**
     * @param array{key: string} $params
     */
    public function coldCase(array $params): void
    {
        $this->pattern = BenchCases::get($params['key'])->pattern;
        $this->benchTokenizeCase($params);
        ColdStart::regex();
        memory_reset_peak_usage();
    }

    /**
     * @return array<string, array{key: string}>
     */
    public function provideCases(): array
    {
        return BenchCases::params('lexer');
    }

    private function setUpRealistic(): void
    {
        $this->patterns = array_values(BenchCases::realistic());
        ColdStart::regex();
    }
}
