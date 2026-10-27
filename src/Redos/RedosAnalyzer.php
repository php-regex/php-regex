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

namespace PHPRegex\Redos;

use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Analysis\LiteralExtractor;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Syntax\PatternParser;
use PHPRegex\Redos\Backtrack\BacktrackProver;
use PHPRegex\Redos\Backtrack\ProofResult;
use PHPRegex\Redos\Backtrack\SearchCostProver;
use PHPRegex\Redos\Backtrack\WitnessReplayer;

/**
 * The ReDoS verdict of a pattern. The backtracking model proves the
 * complexity class of one match attempt and builds the witness; outside
 * the model, or over its budget, the structural heuristics decide, and the
 * result says which of the two it is.
 */
final class RedosAnalyzer
{
    /**
     * The version of the model a verdict was computed with, bumped when the
     * model changes.
     */
    public const ANALYSIS_VERSION = '1';

    /**
     * The literals a match ends with that the replay weaves into its
     * witnesses, at most.
     */
    private const MAX_WOVEN_LITERALS = 4;

    /**
     * What the heuristics report where a proof settles the verdict: nothing.
     */
    private const NO_HEURISTICS = [
        'severity' => RedosSeverity::Safe,
        'recommendations' => [],
        'vulnerablePattern' => null,
        'trigger' => null,
        'confidence' => null,
        'falsePositiveRisk' => null,
        'suggestedRewrite' => null,
        'findings' => [],
    ];

    /**
     * @var array<string>
     */
    private array $ignoredPatternsNormalized = [];

    private readonly RedosOptions $options;

    /**
     * @param array<string> $ignoredPatterns
     */
    public function __construct(
        private readonly ?RegexParser $parser = null,
        /**
         * @var array<string>
         */
        private array $ignoredPatterns = [],
        private readonly RedosSeverity $threshold = RedosSeverity::High,
        private readonly ?ConfirmationRunnerInterface $confirmationRunner = null,
        ?RedosOptions $options = null,
    ) {
        $this->ignoredPatterns = array_values(array_unique($this->ignoredPatterns));
        $this->ignoredPatternsNormalized = $this->normalizeIgnoredPatterns($this->ignoredPatterns);
        $this->options = $options ?? new RedosOptions();
    }

    public function analyze(
        string $regex,
        ?RedosSeverity $threshold = null,
        RedosMode $mode = RedosMode::Theoretical,
        ?ConfirmationOptions $confirmOptions = null,
    ): RedosAnalysis {
        $threshold ??= $this->threshold;

        if (RedosMode::Off === $mode || $this->shouldIgnore($regex)) {
            return RedosAnalysis::skipped($mode);
        }

        try {
            $parser = $this->parser ?? RegexParser::create();
            $ast = $parser->parse($regex);

            // A pattern the library's static checks reject is never proven
            // safe: PCRE refuses to compile it.
            $validation = $parser->validate($regex);
            if (!$validation->isValid) {
                return RedosAnalysis::failed($mode, $validation->error ?? 'The pattern is invalid.');
            }

            $prover = new BacktrackProver(
                $this->options->maxStates,
                $this->options->maxSteps,
                $this->options->boundedRepeatCutoff,
            );

            $outcome = $prover->prove($ast, $regex, $parser->target());
            $proof = $outcome->result;
            $limit = $outcome->limit;
            $proofKind = match (true) {
                null === $limit => RedosProof::Proven,
                $limit->budgetExceeded => RedosProof::BudgetExceeded,
                default => RedosProof::Heuristic,
            };
            $keepsAbstractions = null !== $limit && ($limit->budgetExceeded || $limit->approximated);
            $reason = $limit?->abstraction;

            // A proof of a linear attempt settles the verdict: the structural
            // heuristics would only guess at a risk it rules out, so they do
            // not run, and the result carries none of their findings.
            $profiler = null;
            $heuristics = self::NO_HEURISTICS;
            if (RedosComplexity::Linear !== $proof?->complexity) {
                $profiler = new RedosProfiler(CharSetAnalyzer::forRegex($ast));
                $ast->accept($profiler);
                $heuristics = $profiler->getResult();
            }

            $severity = null === $proof ? $heuristics['severity'] : self::provenSeverity($proof);
            $witness = null === $proof || null === $proof->pump
                ? null
                : new RedosWitness((string) $proof->prefix, $proof->pump, (string) $proof->suffix, $proof->unicode);

            // One attempt proven linear: what the retries of the search cost,
            // replayed in confirmed mode when its severity, that of a proven
            // quadratic attempt, reaches the threshold.
            $searchCost = RedosComplexity::Linear === $proof?->complexity
                ? (new SearchCostProver())->find($regex, $ast, $outcome, RedosMode::Confirmed === $mode && RedosSeverity::Medium->rank() >= $threshold->rank(), $parser->target())
                : null;

            $analysis = new RedosAnalysis(
                severity: $severity,
                score: self::score($severity),
                vulnerablePart: $heuristics['vulnerablePattern'],
                recommendations: array_values($heuristics['recommendations']),
                trigger: $heuristics['trigger'],
                confidence: null === $proof ? ($heuristics['confidence'] ?? RedosConfidence::Low) : self::provenConfidence($proof, null),
                falsePositiveRisk: $heuristics['falsePositiveRisk'],
                findings: array_values($heuristics['findings']),
                suggestedRewrite: $heuristics['suggestedRewrite'],
                culpritNode: $profiler?->getCulpritNode(),
                hotspots: $profiler?->getHotspots() ?? [],
                mode: $mode,
                complexity: $proof->complexity ?? RedosComplexity::Unknown,
                degree: $proof?->degree,
                proof: $proofKind,
                witness: $witness,
                abstractions: RedosProof::Proven === $proofKind || $keepsAbstractions
                    ? [...$outcome->abstractions, ...(null === $reason ? [] : [$reason])]
                    : [],
                upperBoundDegree: $outcome->stepBound,
                searchCost: $searchCost,
            );

            if (RedosMode::Confirmed !== $mode || !$analysis->exceedsThreshold($threshold)) {
                return $analysis;
            }

            // The witness runs as the pattern runs: under the tables of the
            // target's locale.
            $confirmed = $analysis;
            CharacterTables::under($parser->target()->locale, function () use ($regex, $analysis, $proof, $confirmOptions, &$confirmed): void {
                $confirmed = $this->confirmed($regex, $analysis, $proof, $confirmOptions);
            });

            return $confirmed;
        } catch (ExceptionInterface $e) {
            return RedosAnalysis::failed($mode, $e::class.': '.$e->getMessage(), 'Analysis incomplete: '.$e->getMessage());
        }
    }

    /**
     * The analysis with the confirmation of its witness: the proven one
     * replayed, or the heuristic one confirmed by the runner.
     */
    private function confirmed(string $regex, RedosAnalysis $analysis, ?ProofResult $proof, ?ConfirmationOptions $confirmOptions): RedosAnalysis
    {
        if (null !== $proof) {
            return $this->replay($regex, $analysis, $proof, $confirmOptions);
        }

        $runner = $this->confirmationRunner ?? new ConfirmationRunner();
        $confirmation = $runner->confirm($regex, $analysis, $confirmOptions);

        return $analysis->withConfirmation(
            $confirmation,
            $confirmation->confirmed ? RedosConfidence::High : $analysis->confidence,
            $analysis->witness,
            null,
        );
    }

    /**
     * Replays an exponential witness on the running engine. PCRE gives up at
     * once on a subject without a literal every match needs: when the bare
     * witness does not reproduce, the replay tries the suffix the model
     * found to reject while ending with that literal, and publishes the
     * witness that reproduced. A polynomial verdict is never replayed.
     *
     * A replay the engine cannot make (its limits cannot be set) is skipped:
     * the verdict stands, "replayed" stays null, and the confirmation says
     * why.
     */
    private function replay(string $regex, RedosAnalysis $analysis, ProofResult $proof, ?ConfirmationOptions $options): RedosAnalysis
    {
        $witness = $analysis->witness;
        if (RedosComplexity::Exponential !== $proof->complexity || null === $witness) {
            return $analysis;
        }

        $candidates = [$witness];
        foreach ($proof->otherSuffixes as $suffix) {
            if ($suffix !== $witness->suffix) {
                $candidates[] = new RedosWitness($witness->prefix, $witness->pump, $suffix, $witness->unicode);
            }
        }

        // PCRE2 looks for the code unit every match requires before it tries
        // an unanchored pattern, on subjects of up to 5,000,000 code units: a
        // witness without it is rejected at once. Each literal a match ends
        // with, woven in ahead of the attack, makes a candidate of a second
        // replay, run when the first reproduced nothing.
        $woven = [];
        foreach (self::endingLiterals($this->parser ?? RegexParser::create(), $regex) as $literal) {
            foreach ($candidates as $candidate) {
                if (!str_contains($candidate->build(1), $literal)) {
                    $woven[] = new RedosWitness($literal.$candidate->prefix, $candidate->pump, $candidate->suffix, $candidate->unicode);
                }
            }
        }

        if (null === $this->confirmationRunner) {
            // One budget of work for every candidate; the call without
            // $matches when the witness needs PHP's retry of an empty match.
            [$confirmation, $reproduced] = (new WitnessReplayer())->replay($regex, $candidates, $options ?? new ConfirmationOptions(), $proof->withoutMatches);
            if (null === $reproduced && [] !== $woven) {
                [$wovenConfirmation, $reproduced] = (new WitnessReplayer())->replay($regex, $woven, $options ?? new ConfirmationOptions(), $proof->withoutMatches);
                $confirmation = null === $reproduced ? $confirmation : $wovenConfirmation;
            }

            if (null !== $reproduced) {
                return $analysis->withConfirmation($confirmation, self::provenConfidence($proof, true), $reproduced, true);
            }

            return $analysis->withConfirmation($confirmation, self::provenConfidence($proof, false), $witness, $confirmation->wasSkipped() ? null : false);
        }

        $first = null;
        foreach ([...$candidates, ...$woven] as $candidate) {
            $confirmation = $this->confirmationRunner->confirm($regex, $analysis->withConfirmation(null, $analysis->confidence, $candidate, null), $options);
            $first ??= $confirmation;
            if (self::reproduced($confirmation)) {
                return $analysis->withConfirmation($confirmation, self::provenConfidence($proof, true), $candidate, true);
            }
        }

        return $analysis->withConfirmation($first, self::provenConfidence($proof, false), $witness, $first->wasSkipped() ? null : false);
    }

    /**
     * The literals a match of the pattern ends with, a few of them; none
     * when the pattern does not parse or a match may end anywhere.
     *
     * @return list<string>
     */
    private static function endingLiterals(RegexParser $parser, string $regex): array
    {
        try {
            $suffixes = $parser->parse($regex)->accept(new LiteralExtractor())->suffixes;
        } catch (ExceptionInterface) {
            return [];
        }

        return \array_slice(array_values(array_unique(array_filter($suffixes, static fn (string $suffix): bool => '' !== $suffix))), 0, self::MAX_WOVEN_LITERALS);
    }

    /**
     * Whether the confirmation ran the witness into the backtrack limit: the
     * only evidence of a replay, whatever runner produced it (a recursion
     * limit or a JIT stack fails for another reason).
     */
    private static function reproduced(Confirmation $confirmation): bool
    {
        $errors = false;
        foreach ($confirmation->samples as $sample) {
            if (\PREG_BACKTRACK_LIMIT_ERROR === $sample->pregErrorCode) {
                return true;
            }

            $errors = $errors || null !== $sample->pregErrorCode;
        }

        // A runner that records no error code in its samples speaks through
        // its evidence.
        return !$errors && $confirmation->confirmed && 'backtrack_limit' === $confirmation->evidence;
    }

    private static function provenSeverity(ProofResult $proof): RedosSeverity
    {
        return match ($proof->complexity) {
            RedosComplexity::Exponential => RedosSeverity::Critical,
            RedosComplexity::Polynomial => ($proof->degree ?? 2) >= 3 ? RedosSeverity::High : RedosSeverity::Medium,
            default => RedosSeverity::Safe,
        };
    }

    /**
     * A proven safe verdict is certain; a proven vulnerable one only once
     * the engine reproduced it.
     */
    private static function provenConfidence(ProofResult $proof, ?bool $replayed): RedosConfidence
    {
        if (RedosComplexity::Linear === $proof->complexity) {
            return RedosConfidence::High;
        }

        return true === $replayed ? RedosConfidence::High : RedosConfidence::Medium;
    }

    private static function score(RedosSeverity $severity): int
    {
        return match ($severity) {
            RedosSeverity::Safe => 0,
            RedosSeverity::Low => 2,
            RedosSeverity::Medium => 5,
            RedosSeverity::High => 8,
            RedosSeverity::Critical => 10,
            RedosSeverity::Unknown => 5,
        };
    }

    private function shouldIgnore(string $regex): bool
    {
        if ([] === $this->ignoredPatterns) {
            return false;
        }

        $normalized = $this->normalizePattern($regex);

        return \in_array($normalized, $this->ignoredPatternsNormalized, true)
            || \in_array($normalized, $this->ignoredPatterns, true)
            || \in_array($regex, $this->ignoredPatterns, true);
    }

    private function normalizePattern(string $regex): string
    {
        try {
            [$pattern] = PatternParser::extractPatternAndFlags($regex, $this->parser?->target());

            return $pattern;
        } catch (\Throwable) {
            return $regex;
        }
    }

    /**
     * @param array<string> $patterns
     *
     * @return array<string>
     */
    private function normalizeIgnoredPatterns(array $patterns): array
    {
        $normalized = [];
        foreach ($patterns as $pattern) {
            $normalized[] = $this->normalizePattern($pattern);
        }

        return array_values(array_unique($normalized));
    }
}
