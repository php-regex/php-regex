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
use PHPRegex\Explain\TextExplainer;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Tests\Benchmark\Support\BenchCases;
use PHPRegex\Tests\Benchmark\Support\ColdStart;
use PHPRegex\Toolkit\Regex;

/**
 * The parser: the realistic corpus, the pathological parser cases, a few
 * everyday patterns, the cost of building the facade, and a parse followed
 * by printing or explaining the tree.
 *
 * Cold subjects run once per iteration. Their before-method runs the same
 * work once, so the code is loaded, then empties the caches: the measured run
 * pays for cold caches, not for compiling the library. The *Warm subjects
 * keep the default AST cache, as a long-running process does; their
 * before-method runs the subject once to fill it (phpbench's own warmup would
 * run after the memory reset).
 *
 * Every before-method ends by resetting the peak memory, so mem_peak covers
 * the measured revolutions only; benchNoop's before-method loads the same
 * code and inputs first, so its peak is the floor the subjects are read
 * against.
 *
 * Run with: composer bench -- --group=parser
 */
#[Groups(['parser'])]
#[Revs(1)]
#[Iterations(10)]
#[Warmup(0)]
#[OutputTimeUnit('milliseconds')]
final class ParserBench
{
    private const MICRO = [
        'simple' => '/abc/',
        'email' => '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/',
        'deeply-nested' => '/((((((((((a))))))))))/',
        'character-class' => '/[a-zA-Z0-9_.-]/',
        'quantifiers' => '/a{2,5}b+c*/',
        'alternation' => '/(apple|banana|cherry|date|elderberry)/',
        'named-groups' => '/^(?P<name>[a-z]+)([0-9]{1,3})?$/i',
    ];

    private Regex $regex;

    /**
     * @var list<string>
     */
    private array $patterns = [];

    private string $pattern = '';

    private readonly PatternPrinter $printer;

    public function __construct()
    {
        $this->regex = Regex::create(['cache' => null]);
        $this->printer = new PatternPrinter();
    }

    /**
     * The memory floor: the same code and inputs loaded, no work.
     */
    #[BeforeMethods('setUpNoop')]
    public function benchNoop(): void {}

    #[BeforeMethods('coldRealistic')]
    public function benchParseRealistic(): void
    {
        foreach ($this->patterns as $pattern) {
            $this->regex->parse($pattern);
        }
    }

    /**
     * @param array{key: string} $params
     */
    #[BeforeMethods('coldCase')]
    #[ParamProviders('provideCases')]
    public function benchParseCase(array $params): void
    {
        $this->regex->parse($this->pattern);
    }

    /**
     * @param array{key: string} $params
     */
    #[BeforeMethods('warmCase')]
    #[ParamProviders('provideCases')]
    #[Revs(100)]
    #[Iterations(5)]
    #[OutputTimeUnit('microseconds')]
    public function benchParseCaseWarm(array $params): void
    {
        $this->regex->parse($this->pattern);
    }

    /**
     * @param array{key: string} $params
     */
    #[BeforeMethods('coldMicro')]
    #[ParamProviders('provideMicro')]
    #[OutputTimeUnit('microseconds')]
    public function benchParseMicro(array $params): void
    {
        $this->regex->parse($this->pattern);
    }

    /**
     * @param array{key: string} $params
     */
    #[BeforeMethods('warmMicro')]
    #[ParamProviders('provideMicro')]
    #[Revs(1000)]
    #[Iterations(5)]
    #[OutputTimeUnit('microseconds')]
    public function benchParseMicroWarm(array $params): void
    {
        $this->regex->parse($this->pattern);
    }

    /**
     * What building the facade costs, options read and parser wired.
     */
    #[BeforeMethods('warmCreate')]
    #[Revs(1000)]
    #[Iterations(5)]
    #[OutputTimeUnit('microseconds')]
    public function benchCreate(): void
    {
        Regex::create();
    }

    #[BeforeMethods('warmPrint')]
    #[Revs(1000)]
    #[Iterations(5)]
    #[OutputTimeUnit('microseconds')]
    public function benchParseAndPrintWarm(): void
    {
        $this->regex->parse('/[a-z]+/')->accept($this->printer);
    }

    #[BeforeMethods('warmExplain')]
    #[Revs(1000)]
    #[Iterations(5)]
    #[OutputTimeUnit('microseconds')]
    public function benchParseAndExplainWarm(): void
    {
        // A fresh explainer per call, as Regex::explain() builds one: a
        // shared one would answer from its memo of the cached tree's nodes.
        $this->regex->parse('/\d{3}-\d{3}-\d{4}/')->accept(new TextExplainer());
    }

    public function setUpNoop(): void
    {
        $this->setUpRealistic();
        BenchCases::all();
        $this->regex->parse('/a/');
        $this->regex = ColdStart::regex();
        memory_reset_peak_usage();
    }

    public function coldRealistic(): void
    {
        $this->setUpRealistic();
        $this->benchParseRealistic();
        $this->regex = ColdStart::regex();
        memory_reset_peak_usage();
    }

    public function warmCreate(): void
    {
        $this->benchCreate();
        memory_reset_peak_usage();
    }

    public function warmPrint(): void
    {
        $this->setUpWarm();
        $this->benchParseAndPrintWarm();
        memory_reset_peak_usage();
    }

    public function warmExplain(): void
    {
        $this->setUpWarm();
        $this->benchParseAndExplainWarm();
        memory_reset_peak_usage();
    }

    /**
     * @param array{key: string} $params
     */
    public function coldCase(array $params): void
    {
        $this->pattern = BenchCases::get($params['key'])->pattern;
        $this->regex = ColdStart::regex();
        $this->benchParseCase($params);
        $this->regex = ColdStart::regex();
        memory_reset_peak_usage();
    }

    /**
     * @param array{key: string} $params
     */
    public function warmCase(array $params): void
    {
        $this->pattern = BenchCases::get($params['key'])->pattern;
        $this->setUpWarm();
        $this->benchParseCaseWarm($params);
        memory_reset_peak_usage();
    }

    /**
     * @param array{key: string} $params
     */
    public function coldMicro(array $params): void
    {
        $this->pattern = self::MICRO[$params['key']] ?? throw new \OutOfBoundsException(\sprintf('No micro pattern "%s".', $params['key']));
        $this->regex = ColdStart::regex();
        $this->benchParseMicro($params);
        $this->regex = ColdStart::regex();
        memory_reset_peak_usage();
    }

    /**
     * @param array{key: string} $params
     */
    public function warmMicro(array $params): void
    {
        $this->pattern = self::MICRO[$params['key']] ?? throw new \OutOfBoundsException(\sprintf('No micro pattern "%s".', $params['key']));
        $this->setUpWarm();
        $this->benchParseMicroWarm($params);
        memory_reset_peak_usage();
    }

    /**
     * @return array<string, array{key: string}>
     */
    public function provideCases(): array
    {
        return BenchCases::params('parser');
    }

    /**
     * @return array<string, array{key: string}>
     */
    public function provideMicro(): array
    {
        $params = [];
        foreach (array_keys(self::MICRO) as $key) {
            $params[$key] = ['key' => $key];
        }

        return $params;
    }

    private function setUpRealistic(): void
    {
        $this->patterns = array_values(BenchCases::realistic());
        $this->regex = ColdStart::regex();
    }

    private function setUpWarm(): void
    {
        $this->regex = Regex::create();
    }
}
