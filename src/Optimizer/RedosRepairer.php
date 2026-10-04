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
use PHPRegex\Parser\Internal\PatternParser;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosProof;

/**
 * Rewrites a pattern open to catastrophic backtracking and keeps the
 * rewrites the backtracking model proves linear, each with whether the
 * automata prove it matches the same subjects (After Li et al., "RegexScalpel",
 * USENIX Security 2022, and Chida, Kuramitsu and Terauchi, "Repairing DoS
 * Vulnerability of Real-World Regexes", IEEE S&P 2022.)
 *
 * The rewrites: a repeat of a repeat flattened, "(?:a+)+" to "a+"; the
 * optimizer's own rewrite; a quantifier made possessive. Certified repairs
 * come first.
 *
 *     (new RedosRepairer())->repair('/^(?:a+)+$/')[0]->pattern; // "/^a+$/"
 */
final readonly class RedosRepairer
{
    private const FLATTENABLE = [GroupType::NonCapturing, GroupType::Capturing, GroupType::Named];

    public function __construct(
        private ?RegexParser $parser = null,
        private ?RedosAnalyzer $analyzer = null,
        private ?LanguageSolver $solver = null,
    ) {}

    /**
     * The repairs proven linear, certified ones first; none when the
     * pattern is not open to catastrophic backtracking.
     *
     * @return list<RedosRepair>
     */
    public function repair(string $pattern): array
    {
        $parser = $this->parser ?? RegexParser::create();

        try {
            $ast = $parser->parse($pattern);
        } catch (ExceptionInterface) {
            return [];
        }

        $analyzer = $this->analyzer ?? new RedosAnalyzer($parser);
        $verdict = $analyzer->analyze($pattern)->complexity;
        if (RedosComplexity::Exponential !== $verdict && RedosComplexity::Polynomial !== $verdict) {
            return [];
        }

        $engine = new PcreEngine();
        $repairs = [];
        foreach ($this->candidates($ast, $pattern, $parser) as $candidate) {
            if (null !== $engine->compile($candidate)) {
                continue; // the edits are textual: a guard, as none of them breaks a pattern today
            }

            $analysis = $analyzer->analyze($candidate);
            if (RedosProof::Proven !== $analysis->proof || RedosComplexity::Linear !== $analysis->complexity) {
                continue;
            }

            // A rewrite proven to match other subjects is no repair.
            $sameSubjects = $this->sameSubjects($pattern, $candidate, $parser);
            if (false === $sameSubjects) {
                continue; // a guard: flattening keeps the language, a possessive the solver reads is inert
            }

            $repairs[] = new RedosRepair($candidate, $sameSubjects, $this->sameMatches($pattern, $candidate, $parser), $analysis->complexity);
        }

        // Certified first, then those that also keep $matches.
        usort($repairs, static fn (RedosRepair $a, RedosRepair $b): int => [$b->isCertified(), true === $b->sameMatches] <=> [$a->isCertified(), true === $a->sameMatches]);

        return $repairs;
    }

    /**
     * The rewrites to try, each once, in order.
     *
     * @return list<string>
     */
    private function candidates(RegexNode $ast, string $pattern, RegexParser $parser): array
    {
        $source = $ast->source ?? '';
        $edits = [];
        $possessive = [];
        foreach (self::quantifiers($ast->pattern) as $quantifier) {
            $flat = self::flattened($quantifier, $source);
            if (null !== $flat) {
                $edits[] = [$quantifier->getStartPosition(), $quantifier->getEndPosition(), $flat];
            }

            if (QuantifierType::Greedy === $quantifier->type && \in_array($quantifier->quantifier, ['*', '+'], true)) {
                $possessive[] = [$quantifier->getEndPosition(), $quantifier->getEndPosition(), '+'];
            }
        }

        $closing = PatternParser::closingDelimiter($ast->delimiter);
        $wrap = static fn (string $body): string => $ast->delimiter.$body.$closing.$ast->flags;

        $candidates = [];
        foreach ($edits as $edit) {
            $candidates[] = $wrap(self::apply($source, [$edit]));
        }
        $candidates[] = (new Optimizer($parser))->optimize($pattern)->optimized;
        foreach ($possessive as $edit) {
            $candidates[] = $wrap(self::apply($source, [$edit]));
        }
        if (\count($possessive) > 1) {
            $candidates[] = $wrap(self::apply($source, $possessive));
        }

        return array_values(array_unique(array_filter($candidates, static fn (string $candidate): bool => $candidate !== $pattern)));
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
     * @return list<QuantifierNode>
     */
    private static function quantifiers(NodeInterface $node): array
    {
        $found = $node instanceof QuantifierNode ? [$node] : [];
        foreach ($node->getChildren() as $child) {
            $found = [...$found, ...self::quantifiers($child)];
        }

        return $found;
    }

    private function sameSubjects(string $pattern, string $candidate, RegexParser $parser): ?bool
    {
        try {
            return ($this->solver ?? new LanguageSolver($parser))->equivalent($pattern, $candidate, new SolverOptions(matchMode: MatchMode::Partial))->isEquivalent;
        } catch (ComplexityException) {
            return null;
        }
    }

    private function sameMatches(string $pattern, string $candidate, RegexParser $parser): ?bool
    {
        try {
            return ($this->solver ?? new LanguageSolver($parser))->matchEquivalent($pattern, $candidate)->isEquivalent;
        } catch (ComplexityException) {
            return null;
        }
    }
}
