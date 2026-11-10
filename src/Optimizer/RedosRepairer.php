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

namespace PHPRegex\Optimizer;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Syntax\Delimiters;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;

/**
 * Rewrites a pattern open to catastrophic backtracking and keeps the
 * rewrites the backtracking model proves linear, each with whether the
 * automata prove it matches the same subjects (After Li et al., "RegexScalpel",
 * USENIX Security 2022, and Chida, Kuramitsu and Terauchi, "Repairing DoS
 * Vulnerability of Real-World Regexes", IEEE S&P 2022.)
 *
 * The rewrites: a repeat of a repeat flattened, "(?:a+)+" to "a+"; the
 * optimizer's own rewrite; a quantifier made possessive. Those of the
 * quantifiers the analysis blames are tried first. Certified repairs come
 * first.
 *
 *     (new RedosRepairer())->repair('/^(?:a+)+$/')[0]->pattern; // "/^a+$/"
 */
final class RedosRepairer
{
    private const FLATTENABLE = [GroupType::NonCapturing, GroupType::Capturing, GroupType::Named];

    /**
     * The subjects of each kind the engine is asked about.
     */
    private const SUBJECTS = 64;

    private readonly RegexParser $parser;

    private readonly RedosAnalyzer $analyzer;

    private readonly LanguageSolver $solver;

    public function __construct(
        ?RegexParser $parser = null,
        ?RedosAnalyzer $analyzer = null,
        ?LanguageSolver $solver = null,
    ) {
        $this->parser = $parser ?? RegexParser::create();
        $this->analyzer = $analyzer ?? new RedosAnalyzer($this->parser);
        $this->solver = $solver ?? new LanguageSolver($this->parser);
    }

    /**
     * The repairs proven linear, certified ones first; none when the
     * pattern is not open to catastrophic backtracking.
     *
     * @return list<RedosRepair>
     */
    public function repair(string $regex): array
    {
        $parser = $this->parser;

        try {
            $ast = $parser->parse($regex);
        } catch (ExceptionInterface) {
            return [];
        }

        $analyzer = $this->analyzer;
        $analysis = $analyzer->analyze($regex);
        if (RedosComplexity::Exponential !== $analysis->complexity && RedosComplexity::Polynomial !== $analysis->complexity) {
            return [];
        }

        $engine = new PcreEngine();
        // The pattern is the same for every rewrite: what the engine answers
        // for it is asked once, as far as a rewrite needs it.
        $answers = $this->answers($regex);
        $asked = [];
        $repairs = [];
        foreach ($this->candidates($ast, $regex, $parser, $analysis) as $candidate) {
            if (null !== $engine->compile($candidate)) {
                continue; // the edits are textual: a guard, as none of them breaks a pattern today
            }

            // Most rewrites are dropped here: the proof alone decides, and
            // nothing of a rewrite is kept in the parser.
            if (!$analyzer->provesSafe($candidate)) {
                continue;
            }

            // A rewrite proven to match other subjects is no repair, nor one
            // the engine tells apart where the automata cannot judge: they
            // do not read a possessive, which may keep what the pattern
            // gives back ("/(x++x+)+y/" refuses "xxy").
            $sameSubjects = $this->sameSubjects($regex, $candidate);
            if (false === $sameSubjects || (null === $sameSubjects && self::engineTellsApart($candidate, $answers, $asked))) {
                continue;
            }

            $repairs[] = new RedosRepair($candidate, $sameSubjects, $this->sameMatches($regex, $candidate));
        }

        // Certified first, then those that also keep $matches.
        usort($repairs, static fn (RedosRepair $a, RedosRepair $b): int => [$b->isCertified(), true === $b->sameMatches] <=> [$a->isCertified(), true === $a->sameMatches]);

        return $repairs;
    }

    /**
     * The rewrites to try, each once, in order: those of the quantifiers
     * the analysis blames, then the others, each group in the order it
     * had: the flattenings, the optimizer's rewrite, the possessives, and
     * every possessive at once. A quantifier is blamed when a hotspot
     * covers it or lies in it, the culprit being one of the hotspots: the
     * loop around a blamed repeat is the one to flatten. None is left out:
     * a pattern is repaired where the analysis names nothing.
     *
     * @return list<string>
     */
    private function candidates(RegexNode $ast, string $pattern, RegexParser $parser, RedosAnalysis $analysis): array
    {
        $source = $ast->source ?? '';
        // Each edit with its rank: 0 for a blamed quantifier, 1 for another.
        $flat = [];
        $possessive = [];
        foreach (self::quantifiers($ast->pattern) as $quantifier) {
            $rank = self::blamed($quantifier, $analysis) ? 0 : 1;
            $flattened = self::flattened($quantifier, $source);
            if (null !== $flattened) {
                $flat[] = [$rank, [$quantifier->getStartPosition(), $quantifier->getEndPosition(), $flattened]];
            }

            if (QuantifierType::Greedy === $quantifier->type && \in_array($quantifier->quantifier, ['*', '+'], true)) {
                $possessive[] = [$rank, [$quantifier->getEndPosition(), $quantifier->getEndPosition(), '+']];
            }
        }

        $closing = Delimiters::closing($ast->delimiter);
        $wrap = static fn (string $body): string => $ast->delimiter.$body.$closing.$ast->flags;

        $tries = [];
        foreach ($flat as [$rank, $edit]) {
            $tries[] = [$rank, $wrap(self::apply($source, [$edit]))];
        }
        // The optimizer's rewrite, proven to write the same $matches, as the
        // lint's suggestions are: one it cannot prove is no candidate.
        $tries[] = [1, (new Optimizer($parser))->optimize($pattern, new OptimizerOptions(verifyWithAutomata: true))->optimized];
        foreach ($possessive as [$rank, $edit]) {
            $tries[] = [$rank, $wrap(self::apply($source, [$edit]))];
        }
        // The sort is stable: each rank keeps the order it had.
        usort($tries, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $candidates = array_column($tries, 1);
        if (\count($possessive) > 1) {
            $candidates[] = $wrap(self::apply($source, array_column($possessive, 1)));
        }

        return array_values(array_unique(array_filter($candidates, static fn (string $candidate): bool => $candidate !== $pattern)));
    }

    private static function blamed(QuantifierNode $quantifier, RedosAnalysis $analysis): bool
    {
        foreach ($analysis->hotspots as $hotspot) {
            if ($quantifier->getStartPosition() < $hotspot->end && $hotspot->start < $quantifier->getEndPosition()) {
                return true;
            }
        }

        return false;
    }

    /**
     * A repeat of a repeat as one repeat: "(?:x+)+" is "x+", "(x*)+" is
     * "(x*)", the group kept when it captures; null for anything else.
     */
    private static function flattened(QuantifierNode $outer, string $source): ?string
    {
        $group = $outer->node;
        if (QuantifierType::Greedy !== $outer->type || !\in_array($outer->quantifier, ['*', '+'], true)
            || !$group instanceof GroupNode || !\in_array($group->type, self::FLATTENABLE, true)) {
            return null;
        }

        $inner = $group->child;
        if (!$inner instanceof QuantifierNode || QuantifierType::Greedy !== $inner->type || !\in_array($inner->quantifier, ['*', '+'], true)) {
            return null;
        }

        $atom = substr($source, $inner->node->getStartPosition(), $inner->node->getEndPosition() - $inner->node->getStartPosition());
        $repeat = '+' === $outer->quantifier && '+' === $inner->quantifier ? '+' : '*';
        if (GroupType::NonCapturing === $group->type) {
            return $atom.$repeat;
        }

        $opener = substr($source, $group->getStartPosition(), $inner->getStartPosition() - $group->getStartPosition());

        return $opener.$atom.$repeat.')';
    }

    /**
     * @param list<array{int, int, string}> $edits non-overlapping [start, end, text]
     */
    private static function apply(string $source, array $edits): string
    {
        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($edits as [$start, $end, $text]) {
            $source = substr($source, 0, $start).$text.substr($source, $end);
        }

        return $source;
    }

    /**
     * @return \Generator<int, QuantifierNode>
     */
    private static function quantifiers(NodeInterface $node): \Generator
    {
        if ($node instanceof QuantifierNode) {
            yield $node;
        }

        foreach ($node->getChildren() as $child) {
            yield from self::quantifiers($child);
        }
    }

    private function sameSubjects(string $pattern, string $candidate): ?bool
    {
        try {
            return $this->solver->equivalent($pattern, $candidate, new SolverOptions(matchMode: MatchMode::Partial))->isEquivalent;
        } catch (ComplexityException) {
            return null;
        }
    }

    /**
     * What the engine answers for the pattern on the subjects the automata
     * spell for it: the shortest strings it matches, then the shortest it
     * rejects, SUBJECTS of each. They end where the automata give up.
     *
     * @return \Generator<int, array{string, bool|null}>
     */
    private function answers(string $pattern): \Generator
    {
        $engine = new PcreEngine();

        try {
            $language = $this->solver->language($pattern, new SolverOptions(matchMode: MatchMode::Partial));
            foreach ([$language->strings(), $language->nonMembers()] as $subjects) {
                $read = 0;
                foreach ($subjects as $subject) {
                    yield [$subject, $engine->match($pattern, $subject)->matched];

                    if (++$read >= self::SUBJECTS) {
                        break;
                    }
                }
            }
        } catch (ComplexityException) {
            // The subjects read so far are all there is to ask about.
        }
    }

    /**
     * Whether the engine answers otherwise for the rewrite on a subject of
     * the pattern: those already asked for an earlier rewrite, then the
     * next ones, read as far as this rewrite needs them.
     *
     * @param \Generator<int, array{string, bool|null}> $answers
     * @param list<array{string, bool|null}>            $asked
     *
     * @param-out list<array{string, bool|null}> $asked
     */
    private static function engineTellsApart(string $candidate, \Generator $answers, array &$asked): bool
    {
        $engine = new PcreEngine();
        for ($i = 0; ; $i++) {
            if (!isset($asked[$i])) {
                if (!$answers->valid()) {
                    return false;
                }

                $asked[] = $answers->current();
                $answers->next();
            }

            [$subject, $matched] = $asked[$i];
            if ($matched !== $engine->match($candidate, $subject)->matched) {
                return true;
            }
        }
    }

    private function sameMatches(string $pattern, string $candidate): ?bool
    {
        try {
            return $this->solver->matchEquivalent($pattern, $candidate)->isEquivalent;
        } catch (ComplexityException) {
            return null;
        }
    }
}
