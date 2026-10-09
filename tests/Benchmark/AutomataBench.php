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
use PHPRegex\Automata\Builder\DfaBuilder;
use PHPRegex\Automata\Determinization\DeterminizationAlgorithm;
use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Minimization\DfaMinimizer;
use PHPRegex\Automata\Minimization\HopcroftWorklist;
use PHPRegex\Automata\Minimization\MinimizationAlgorithmInterface;
use PHPRegex\Automata\Minimization\MoorePartitionRefinement;
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Model\DfaState;
use PHPRegex\Automata\Model\Nfa;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Solver\InMemoryDfaCache;
use PHPRegex\Automata\Transform\HirToNfaTransformer;
use PHPRegex\Automata\Transform\RegularSubsetValidator;
use PHPRegex\Parser\Hir\Hir;
use PHPRegex\Parser\Hir\HirTranslator;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Benchmark\Support\BenchCases;
use PHPRegex\Tests\Benchmark\Support\ColdStart;
use PHPRegex\Tests\Benchmark\Support\GrowthSeries;
use PHPRegex\Tests\Benchmark\Support\Workloads;

/**
 * The automata: each stage of the construction (HIR to NFA, determinization
 * by both algorithms, minimization by both algorithms), the effective
 * alphabet on Unicode blocks, the language questions, the pathological
 * automata cases and the growth series.
 *
 * A pathological case compiled under the default options may stop on a
 * limit: the time to reach the guard is what a caller pays, so the subject
 * measures it, but only for a case that declares "guard". The before-method
 * runs the compilation once and errors when the outcome differs from the
 * declared one (every growth point: complete), so a change that moves a case
 * between the construction and the guard breaks the variant on that side
 * instead of timing other work under the same name. The NFA and
 * determinization subjects raise the NFA limit only and catch nothing: every
 * case builds its NFA, and determinizes under the default DFA and transition
 * limits by both algorithms; a case that stops surfaces as an error.
 *
 * Cold subjects run once per iteration. Their before-method runs the same
 * work once, so the code is loaded, then empties the caches: the measured run
 * pays for cold caches, not for compiling the library. The *Warm subjects
 * share one DFA cache across revolutions, as a long-running process does;
 * their before-method runs the subject once to fill it (phpbench's own
 * warmup would run after the memory reset).
 *
 * Every before-method ends by resetting the peak memory, so mem_peak covers
 * the measured revolutions only; benchNoop's before-method loads the same
 * code first, so its peak is the floor the subjects are read against.
 *
 * Run with: composer bench -- --group=automata
 */
#[Groups(['automata'])]
#[Revs(1)]
#[Iterations(10)]
#[Warmup(0)]
#[OutputTimeUnit('milliseconds')]
final class AutomataBench
{
    /**
     * The random DFA the minimizers are compared on.
     */
    private const MINIMIZER_SEED = 1337;

    private const MINIMIZER_STATES = 160;

    private const MINIMIZER_ALPHABET = 180;

    private const UNICODE_BLOCKS = [
        'arabic-block' => '/[\x{0600}-\x{06FF}]{2,}/u',
        'emoji-block' => '/[\x{1F600}-\x{1F64F}]{2,}/u',
        'mixed-blocks' => '/[\x{0600}-\x{06FF}\x{1F600}-\x{1F64F}]{2,}/u',
    ];

    private const PAIRS = [
        'identifiers' => ['/^[A-Za-z_]\w*$/', '/^[a-z_][a-z0-9_]*$/i'],
        'dates' => ['/^\d{4}-\d{2}-\d{2}$/', '/^[0-9]{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12]\d|3[01])$/'],
        'suffix-window' => ['/(a|b)*a(a|b){6}/', '/(a|b)*a(a|b){5}/'],
    ];

    /**
     * The NFA limit for the NFA and determinization subjects: high enough
     * that every case builds its NFA, so the measured set does not depend on
     * the NFA limit of the code being measured. The DFA limits stay at their
     * defaults.
     */
    private const MEASURED_NFA_STATES = 1_000_000;

    private RegexParser $parser;

    private string $pattern = '';

    private string $left = '';

    private string $right = '';

    private ?Hir $hir = null;

    private bool $unicode = false;

    private ?Nfa $nfa = null;

    private ?Dfa $dfa = null;

    /**
     * Whether the case benchCompileCase() measures declares the guard.
     */
    private bool $guardExpected = false;

    private SolverOptions $options;

    private DfaMinimizer $minimizer;

    private LanguageSolver $solver;

    public function __construct()
    {
        $this->parser = RegexParser::create(['cache' => null]);
        $this->options = new SolverOptions();
        $this->minimizer = new DfaMinimizer();
        $this->solver = new LanguageSolver($this->parser, new InMemoryDfaCache());
    }

    /**
     * The memory floor: the same code loaded and objects built, no work.
     */
    #[BeforeMethods('setUpNoop')]
    public function benchNoop(): void {}

    /**
     * @param array{key: string} $params
     */
    #[BeforeMethods('coldNfa')]
    #[ParamProviders('provideNfaCases')]
    public function benchHirToNfa(array $params): void
    {
        $this->buildNfa($this->hir());
    }

    /**
     * @param array{key: string, algorithm: string} $params
     */
    #[BeforeMethods('coldDeterminize')]
    #[ParamProviders(['provideNfaCases', 'provideDeterminizers'])]
    public function benchDeterminize(array $params): void
    {
        (new DfaBuilder())->determinize($this->nfa(), $this->options);
    }

    /**
     * @param array{algorithm: string} $params
     */
    #[BeforeMethods('coldMinimize')]
    #[ParamProviders('provideMinimizers')]
    public function benchMinimize(array $params): void
    {
        $this->minimizer->minimize($this->dfa());
    }

    /**
     * @param array{key: string} $params
     */
    #[BeforeMethods('coldEffectiveAlphabet')]
    #[ParamProviders('provideUnicodeBlocks')]
    public function benchEffectiveAlphabet(array $params): void
    {
        (new DfaBuilder())->determinize($this->nfa(), $this->options);
    }

    /**
     * benchHirToNfa's single cold call lasts a few microseconds, near the
     * timer's step of one: this reads the steady cost of the same work.
     *
     * @param array{key: string} $params
     */
    #[BeforeMethods('warmNfa')]
    #[ParamProviders('provideNfaCases')]
    #[Revs(100)]
    #[Iterations(5)]
    #[OutputTimeUnit('microseconds')]
    public function benchHirToNfaWarm(array $params): void
    {
        $this->buildNfa($this->hir());
    }

    /**
     * benchEffectiveAlphabet's single cold call lasts a few microseconds,
     * near the timer's step of one: this reads the steady cost of the same
     * work.
     *
     * @param array{key: string} $params
     */
    #[BeforeMethods('warmEffectiveAlphabet')]
    #[ParamProviders('provideUnicodeBlocks')]
    #[Revs(100)]
    #[Iterations(5)]
    #[OutputTimeUnit('microseconds')]
    public function benchEffectiveAlphabetWarm(array $params): void
    {
        (new DfaBuilder())->determinize($this->nfa(), $this->options);
    }

    /**
     * @param array{key: string} $params
     */
    #[BeforeMethods('coldCase')]
    #[ParamProviders('provideCases')]
    public function benchCompileCase(array $params): void
    {
        try {
            Workloads::automata($this->pattern, $this->parser);
        } catch (ComplexityException $e) {
            // The time to reach the guard is the measurement, for a case
            // that declares it; any other case stops here as an error.
            if (!$this->guardExpected) {
                throw $e;
            }
        }
    }

    /**
     * @param array{key: string} $params
     */
    #[BeforeMethods('coldGrowth')]
    #[ParamProviders('provideGrowth')]
    public function benchGrowth(array $params): void
    {
        Workloads::automata($this->pattern, $this->parser);
    }

    /**
     * @param array{key: string} $params
     */
    #[BeforeMethods('coldPair')]
    #[ParamProviders('providePairs')]
    public function benchIntersection(array $params): void
    {
        $this->solver->intersection($this->left, $this->right);
    }

    /**
     * @param array{key: string} $params
     */
    #[BeforeMethods('warmIntersection')]
    #[ParamProviders('providePairs')]
    #[Revs(100)]
    #[Iterations(5)]
    #[OutputTimeUnit('microseconds')]
    public function benchIntersectionWarm(array $params): void
    {
        $this->solver->intersection($this->left, $this->right);
    }

    /**
     * @param array{key: string} $params
     */
    #[BeforeMethods('coldPair')]
    #[ParamProviders('providePairs')]
    public function benchEquivalence(array $params): void
    {
        $this->solver->equivalent($this->left, $this->right);
    }

    /**
     * @param array{key: string} $params
     */
    #[BeforeMethods('warmEquivalence')]
    #[ParamProviders('providePairs')]
    #[Revs(100)]
    #[Iterations(5)]
    #[OutputTimeUnit('microseconds')]
    public function benchEquivalenceWarm(array $params): void
    {
        $this->solver->equivalent($this->left, $this->right);
    }

    /**
     * @param array{key: string} $params
     */
    #[BeforeMethods('coldPair')]
    #[ParamProviders('providePairs')]
    public function benchSubset(array $params): void
    {
        $this->solver->subsetOf($this->left, $this->right);
    }

    /**
     * @param array{key: string} $params
     */
    #[BeforeMethods('warmSubset')]
    #[ParamProviders('providePairs')]
    #[Revs(100)]
    #[Iterations(5)]
    #[OutputTimeUnit('microseconds')]
    public function benchSubsetWarm(array $params): void
    {
        $this->solver->subsetOf($this->left, $this->right);
    }

    public function setUpNoop(): void
    {
        BenchCases::all();
        GrowthSeries::forGroup('automata');
        $this->setUpCold();
        Workloads::automata('/a/', $this->parser);
        $this->setUpCold();
        memory_reset_peak_usage();
    }

    /**
     * @param array{key: string} $params
     */
    public function coldNfa(array $params): void
    {
        $this->pattern = BenchCases::get($params['key'])->pattern;
        $this->options = new SolverOptions(maxNfaStates: self::MEASURED_NFA_STATES);
        $this->setUpCold();
        $this->hir = $this->translate($this->pattern);
        $this->benchHirToNfa($params);
        $this->setUpCold();
        memory_reset_peak_usage();
    }

    /**
     * @param array{key: string, algorithm: string} $params
     */
    public function coldDeterminize(array $params): void
    {
        $this->pattern = BenchCases::get($params['key'])->pattern;
        $this->options = new SolverOptions(maxNfaStates: self::MEASURED_NFA_STATES, minimizeDfa: false, determinizationAlgorithm: DeterminizationAlgorithm::from($params['algorithm']));
        $this->setUpCold();
        $this->nfa = $this->buildNfa($this->translate($this->pattern));
        $this->benchDeterminize($params);
        $this->setUpCold();
        memory_reset_peak_usage();
    }

    /**
     * @param array{algorithm: string} $params
     */
    public function coldMinimize(array $params): void
    {
        $this->minimizer = new DfaMinimizer(self::minimizationAlgorithm($params['algorithm']));
        $this->dfa = self::randomDfa();
        $this->benchMinimize($params);
        $this->setUpCold();
        memory_reset_peak_usage();
    }

    /**
     * @param array{key: string} $params
     */
    public function coldEffectiveAlphabet(array $params): void
    {
        $this->pattern = self::UNICODE_BLOCKS[$params['key']] ?? throw new \OutOfBoundsException(\sprintf('No Unicode block "%s".', $params['key']));
        $this->options = new SolverOptions(minimizeDfa: false);
        $this->setUpCold();
        $this->nfa = $this->buildNfa($this->translate($this->pattern));
        $this->benchEffectiveAlphabet($params);
        $this->setUpCold();
        memory_reset_peak_usage();
    }

    /**
     * @param array{key: string} $params
     */
    public function warmNfa(array $params): void
    {
        $this->coldNfa($params);
        $this->benchHirToNfaWarm($params);
        memory_reset_peak_usage();
    }

    /**
     * @param array{key: string} $params
     */
    public function warmEffectiveAlphabet(array $params): void
    {
        $this->coldEffectiveAlphabet($params);
        $this->benchEffectiveAlphabetWarm($params);
        memory_reset_peak_usage();
    }

    /**
     * @param array{key: string} $params
     */
    public function coldCase(array $params): void
    {
        $case = BenchCases::get($params['key']);
        $expect = $case->expect ?? throw new \UnexpectedValueException(\sprintf('The case "%s" declares no outcome.', $case->key));
        $this->pattern = $case->pattern;
        $this->guardExpected = 'guard' === $expect;
        $this->setUpCold();
        Workloads::warmUp('automata', $this->pattern, $expect, $this->parser);
        $this->setUpCold();
        memory_reset_peak_usage();
    }

    /**
     * @param array{key: string} $params
     */
    public function coldGrowth(array $params): void
    {
        $this->pattern = GrowthSeries::pattern('automata', $params['key']);
        $this->setUpCold();
        Workloads::warmUp('automata', $this->pattern, 'complete', $this->parser);
        $this->setUpCold();
        memory_reset_peak_usage();
    }

    /**
     * Each language question primes its own pair, then starts from an empty
     * DFA cache: the measured run compiles both DFAs.
     *
     * @param array{key: string} $params
     */
    public function coldPair(array $params): void
    {
        [$this->left, $this->right] = self::PAIRS[$params['key']] ?? throw new \OutOfBoundsException(\sprintf('No pattern pair "%s".', $params['key']));
        $this->setUpCold();
        $this->solver->intersection($this->left, $this->right);
        $this->solver->equivalent($this->left, $this->right);
        $this->solver->subsetOf($this->left, $this->right);
        $this->setUpCold();
        memory_reset_peak_usage();
    }

    /**
     * @param array{key: string} $params
     */
    public function warmIntersection(array $params): void
    {
        $this->warmPair($params);
        $this->benchIntersectionWarm($params);
        memory_reset_peak_usage();
    }

    /**
     * @param array{key: string} $params
     */
    public function warmEquivalence(array $params): void
    {
        $this->warmPair($params);
        $this->benchEquivalenceWarm($params);
        memory_reset_peak_usage();
    }

    /**
     * @param array{key: string} $params
     */
    public function warmSubset(array $params): void
    {
        $this->warmPair($params);
        $this->benchSubsetWarm($params);
        memory_reset_peak_usage();
    }

    /**
     * Every automata case: the NFA subjects raise the NFA limit, so none is
     * left out.
     *
     * @return array<string, array{key: string}>
     */
    public function provideNfaCases(): array
    {
        return BenchCases::params('automata');
    }

    /**
     * A fixed list, not the enum's cases: the measured set must not move with
     * the code being measured. coldDeterminize() maps each name to the enum.
     *
     * @return array<string, array{algorithm: string}>
     */
    public function provideDeterminizers(): array
    {
        return [
            'subset' => ['algorithm' => 'subset'],
            'subset-indexed' => ['algorithm' => 'subset-indexed'],
        ];
    }

    /**
     * @return array<string, array{algorithm: string}>
     */
    public function provideMinimizers(): array
    {
        return [
            'hopcroft' => ['algorithm' => 'hopcroft'],
            'moore' => ['algorithm' => 'moore'],
        ];
    }

    /**
     * @return array<string, array{key: string}>
     */
    public function provideUnicodeBlocks(): array
    {
        $params = [];
        foreach (array_keys(self::UNICODE_BLOCKS) as $key) {
            $params[$key] = ['key' => $key];
        }

        return $params;
    }

    /**
     * @return array<string, array{key: string}>
     */
    public function provideCases(): array
    {
        return BenchCases::params('automata');
    }

    /**
     * @return array<string, array{key: string}>
     */
    public function provideGrowth(): array
    {
        return GrowthSeries::params('automata');
    }

    /**
     * @return array<string, array{key: string}>
     */
    public function providePairs(): array
    {
        $params = [];
        foreach (array_keys(self::PAIRS) as $key) {
            $params[$key] = ['key' => $key];
        }

        return $params;
    }

    private function setUpCold(): void
    {
        $this->parser = ColdStart::regex()->parser();
        $this->solver = new LanguageSolver($this->parser, new InMemoryDfaCache());
    }

    /**
     * @param array{key: string} $params
     */
    private function warmPair(array $params): void
    {
        [$this->left, $this->right] = self::PAIRS[$params['key']] ?? throw new \OutOfBoundsException(\sprintf('No pattern pair "%s".', $params['key']));
        $this->parser = RegexParser::create();
        $this->solver = new LanguageSolver($this->parser, new InMemoryDfaCache());
    }

    /**
     * The HIR of the pattern, and whether it reads UTF-8, for buildNfa().
     *
     * @throws ComplexityException
     */
    private function translate(string $pattern): Hir
    {
        $ast = $this->parser->parse($pattern);
        $this->unicode = HirTranslator::unicodeOf($ast);

        return (new RegularSubsetValidator())->assertSupported($ast, $pattern, $this->options);
    }

    /**
     * @throws ComplexityException
     */
    private function buildNfa(Hir $hir): Nfa
    {
        return (new HirToNfaTransformer($this->pattern, $this->unicode))->transform($hir, $this->options);
    }

    private function hir(): Hir
    {
        return $this->hir ?? throw new \LogicException('The HIR is built before the run.');
    }

    private function nfa(): Nfa
    {
        return $this->nfa ?? throw new \LogicException('The NFA is built before the run.');
    }

    private function dfa(): Dfa
    {
        return $this->dfa ?? throw new \LogicException('The DFA is built before the run.');
    }

    private static function minimizationAlgorithm(string $name): MinimizationAlgorithmInterface
    {
        return match ($name) {
            'hopcroft' => new HopcroftWorklist(),
            'moore' => new MoorePartitionRefinement(),
            default => throw new \OutOfBoundsException(\sprintf('No minimization algorithm "%s".', $name)),
        };
    }

    /**
     * A total DFA with random transitions and accepting states, the same on
     * every call.
     */
    private static function randomDfa(): Dfa
    {
        mt_srand(self::MINIMIZER_SEED);

        $alphabet = [];
        for ($i = 0; $i < self::MINIMIZER_ALPHABET; $i++) {
            $alphabet[] = $i * 3 + 1;
        }

        $states = [];
        for ($stateId = 0; $stateId < self::MINIMIZER_STATES; $stateId++) {
            $transitions = [];
            foreach ($alphabet as $symbol) {
                $transitions[$symbol] = mt_rand(0, self::MINIMIZER_STATES - 1);
            }

            $states[$stateId] = new DfaState($stateId, $transitions, (bool) mt_rand(0, 1));
        }

        return new Dfa(0, $states);
    }
}
