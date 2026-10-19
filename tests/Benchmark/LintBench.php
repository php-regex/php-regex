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
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Config\LintExtractorFactory;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PhpFilePatternSource;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Tests\Benchmark\Support\BenchCases;
use PHPRegex\Tests\Benchmark\Support\ColdStart;
use PHPRegex\Tests\Benchmark\Support\PeakMemory;

/**
 * A whole lint run over a fixed directory of PHP files, in one process
 * (jobs=1), with the options the command line defaults to: patterns
 * collected from the files, validated, linted, optimizations suggested and
 * checked with the automata, ReDoS off.
 *
 * The run is cold: its before-method lints the directory once, so the code is
 * loaded, then empties the caches and resets the peak memory, so mem_peak
 * covers the measured run only. benchNoop's before-method is the same, so its
 * peak is the floor the subject is read against.
 *
 * The rules alone, the default ones, on trees parsed beforehand: over the
 * realistic corpus, and on the lint cases of data/.
 *
 * Run with: composer bench -- --group=lint
 */
#[Groups(['lint'])]
#[Revs(1)]
#[Iterations(10)]
#[Warmup(0)]
#[OutputTimeUnit('milliseconds')]
final class LintBench
{
    private const FIXTURE = __DIR__.'/Fixtures/lint';

    private ?LintService $service = null;

    private ?LintRequest $request = null;

    /**
     * @var list<RegexNode>
     */
    private array $trees = [];

    private function setUp(): void
    {
        $regex = ColdStart::regex();
        $sources = new PatternSourceCollection([new PhpFilePatternSource((new LintExtractorFactory())->create())]);

        $this->service = new LintService(new AnalysisService($regex->parser()), $sources);
        $this->request = new LintRequest(
            paths: [self::FIXTURE],
            excludePaths: [],
            minSavings: 1,
            analysisWorkers: 1,
            optimizations: new OptimizerOptions(verifyWithAutomata: true),
        );
    }

    /**
     * The memory floor: the same lint run once untimed and service rebuilt, no
     * work.
     */
    #[BeforeMethods('coldLint')]
    public function benchNoop(): void {}

    #[BeforeMethods('coldLint')]
    public function benchLint(): void
    {
        if (null === $this->service || null === $this->request) {
            throw new \LogicException('The lint service is built before the run.');
        }

        $this->service->analyze($this->service->collectPatterns($this->request), $this->request);
    }

    #[BeforeMethods('coldRulesRealistic')]
    public function benchLintRulesRealistic(): void
    {
        foreach ($this->trees as $tree) {
            $tree->accept(new PatternLinter());
        }
    }

    /**
     * @param array{key: string} $params
     */
    #[BeforeMethods('coldRulesCase')]
    #[ParamProviders('provideCases')]
    public function benchLintRulesCase(array $params): void
    {
        $this->benchLintRulesRealistic();
    }

    public function coldRulesRealistic(): void
    {
        $regex = ColdStart::regex();
        $this->trees = [];
        foreach (BenchCases::realistic() as $pattern) {
            if ($regex->validate($pattern)->isValid) {
                $this->trees[] = $regex->parse($pattern);
            }
        }

        $this->benchLintRulesRealistic();
        ColdStart::regex();
        PeakMemory::reset();
    }

    /**
     * @param array{key: string} $params
     */
    public function coldRulesCase(array $params): void
    {
        $this->trees = [ColdStart::regex()->parse(BenchCases::get($params['key'])->pattern)];
        $this->benchLintRulesRealistic();
        ColdStart::regex();
        PeakMemory::reset();
    }

    /**
     * @return array<string, array{key: string}>
     */
    public function provideCases(): array
    {
        return BenchCases::params('lint');
    }

    public function coldLint(): void
    {
        $this->setUp();
        $this->benchLint();
        $this->setUp();
        PeakMemory::reset();
    }
}
