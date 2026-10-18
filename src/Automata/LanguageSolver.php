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

namespace PHPRegex\Automata;

use PHPRegex\Automata\Builder\DfaBuilder;
use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\Match\MatchExplorer;
use PHPRegex\Automata\Match\MatchRun;
use PHPRegex\Automata\Match\PriorityNfa;
use PHPRegex\Automata\Match\PriorityNfaBuilder;
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Solver\DfaCacheInterface;
use PHPRegex\Automata\Solver\EquivalenceResult;
use PHPRegex\Automata\Solver\IntersectionResult;
use PHPRegex\Automata\Solver\MatchEquivalenceResult;
use PHPRegex\Automata\Solver\PrefixReader;
use PHPRegex\Automata\Solver\RewriteSites;
use PHPRegex\Automata\Solver\SubsetResult;
use PHPRegex\Automata\Transform\HirToNfaTransformer;
use PHPRegex\Automata\Transform\RegularSubsetValidator;
use PHPRegex\Automata\Unicode\CodePointHelper;
use PHPRegex\Parser\Hir\AlternationHir;
use PHPRegex\Parser\Hir\AssertionHir;
use PHPRegex\Parser\Hir\AssertionKind;
use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Hir\ClassHir;
use PHPRegex\Parser\Hir\ConcatHir;
use PHPRegex\Parser\Hir\ConditionalHir;
use PHPRegex\Parser\Hir\EmptyHir;
use PHPRegex\Parser\Hir\Greed;
use PHPRegex\Parser\Hir\Hir;
use PHPRegex\Parser\Hir\HirTranslator;
use PHPRegex\Parser\Hir\LiteralHir;
use PHPRegex\Parser\Hir\LookHir;
use PHPRegex\Parser\Hir\LookKind;
use PHPRegex\Parser\Hir\OpaqueHir;
use PHPRegex\Parser\Hir\RepetitionHir;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\NodeWalker;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Text\Bytes;
use PHPRegex\Parser\TraversalAction;

/**
 * Answers questions about the languages regexes match: whether two overlap,
 * whether one is contained in another, whether they match the same strings.
 *
 * Each pattern is compiled to a DFA, so only the regular subset is supported.
 * A plain lookaround is read; backreferences, recursion, a lookaround nested
 * in another, an anchor or a boundary inside one, (*napla:...) and the like
 * throw a ComplexityException instead of an answer that would be wrong.
 *
 * @phpstan-import-type AlphabetRange from Dfa
 */
final class LanguageSolver
{
    private const MAX_BYTE = 255;

    /**
     * The state a DFA falls into on a character it has no transition for:
     * it stays there and never accepts, so both DFAs are total over the
     * merged alphabet and a character only one side can take is still
     * observed.
     */
    private const DEAD = -1;

    /**
     * @param RegexParser|null       $parser   Reads the patterns, for its PHP and PCRE2 target; a default parser when null
     * @param DfaCacheInterface|null $dfaCache Keeps compiled DFAs between questions; nothing is kept when null
     */
    public function __construct(private readonly ?RegexParser $parser = null, private readonly ?DfaCacheInterface $dfaCache = null) {}

    /**
     * Whether some string matches both patterns, and the shortest such string.
     *
     * @throws ComplexityException When a pattern leaves the regular subset or a limit is reached
     */
    public function intersection(string $left, string $right, ?SolverOptions $options = null): IntersectionResult
    {
        $options ??= new SolverOptions();
        [$leftDfa, $rightDfa] = $this->buildDfas($left, $right, $options);

        $example = $this->findExample(
            $leftDfa,
            $rightDfa,
            static fn (bool $leftAccept, bool $rightAccept): bool => $leftAccept && $rightAccept,
        );

        return new IntersectionResult(null === $example, $example);
    }

    /**
     * Whether every string the left pattern matches is matched by the right one,
     * and the shortest string that is not.
     *
     * @throws ComplexityException When a pattern leaves the regular subset or a limit is reached
     */
    public function subsetOf(string $left, string $right, ?SolverOptions $options = null): SubsetResult
    {
        $options ??= new SolverOptions();
        [$leftDfa, $rightDfa] = $this->buildDfas($left, $right, $options);

        $example = $this->findExample(
            $leftDfa,
            $rightDfa,
            static fn (bool $leftAccept, bool $rightAccept): bool => $leftAccept && !$rightAccept,
        );

        return new SubsetResult(null === $example, $example);
    }

    /**
     * Whether both patterns match exactly the same strings, and the shortest
     * string only one side matches, for each side.
     *
     * @throws ComplexityException When a pattern leaves the regular subset or a limit is reached
     */
    public function equivalent(string $left, string $right, ?SolverOptions $options = null): EquivalenceResult
    {
        $options ??= new SolverOptions();
        [$leftDfa, $rightDfa] = $this->buildDfas($left, $right, $options);

        $leftOnlyExample = $this->findExample(
            $leftDfa,
            $rightDfa,
            static fn (bool $leftAccept, bool $rightAccept): bool => $leftAccept && !$rightAccept,
        );

        $rightOnlyExample = $this->findExample(
            $leftDfa,
            $rightDfa,
            static fn (bool $leftAccept, bool $rightAccept): bool => !$leftAccept && $rightAccept,
        );

        return new EquivalenceResult(null === $leftOnlyExample && null === $rightOnlyExample, $leftOnlyExample, $rightOnlyExample);
    }

    /**
     * Whether preg_match() writes the same $matches for both patterns on
     * every subject: the same answer, the same match and the same groups,
     * named alike. It implies equivalent() in partial mode, where the same
     * subjects hold a match, but neither implies nor follows from
     * equivalent() in full mode, which compares the strings matched whole:
     * /a|ab/ and /ab|a/ match the same strings, yet on "ab" the first
     * matches "a" and the second "ab", as PCRE takes the first alternative
     * that leads to a match; /a|ab/ and /a/ write the same matches, yet
     * only the first matches "ab" whole. The counter-example is the
     * shortest subject the two treat differently.
     *
     * The match mode of the options does not apply: the question is what
     * preg_match() does.
     *
     * @throws ComplexityException When a pattern leaves the fragment the match solver reads, or a limit is reached
     */
    public function matchEquivalent(string $left, string $right, ?SolverOptions $options = null): MatchEquivalenceResult
    {
        return $this->matchEquivalentIn(MatchRun::Search, $left, $right, $options);
    }

    /**
     * matchEquivalent() for one way the engine runs a pattern: from offset 0,
     * from past the start of the subject, or the anchored retry that refuses
     * an empty match.
     *
     * @internal the check of a rewrite asks it for each call a pattern may go to
     *
     * @throws ComplexityException When a pattern leaves the fragment the match solver reads, or a limit is reached
     */
    public function matchEquivalentIn(MatchRun $run, string $left, string $right, ?SolverOptions $options = null): MatchEquivalenceResult
    {
        $options ??= new SolverOptions();
        $leftNfa = $this->priorityNfa($left, $options);
        $rightNfa = $this->priorityNfa($right, $options);

        if ($leftNfa->unicode !== $rightNfa->unicode) {
            throw new ComplexityException('Both patterns must read the subject the same way, as UTF-8 or as bytes, for the match solver to compare them.', 0, $left);
        }

        $counterExample = (new MatchExplorer($leftNfa, $rightNfa, $options->maxDfaStates, $left, $options->maxTransitionsProcessed, $run))->counterExample();

        // The sets the answer is computed from come from the running engine,
        // whatever PCRE2 is judged: the result carries the running release,
        // like every other question.
        return new MatchEquivalenceResult(null === $counterExample, $counterExample);
    }

    /**
     * The runs that prove two patterns write the same results in every
     * preg_*() call, or null when no run does: a pattern that reads the text
     * before where a search starts (a lookbehind, "\b", "\B", "^" under /m)
     * or that reports a match start of its own ("\K").
     *
     * A search from an offset reads the subject from there as one from 0
     * reads what follows, but for "^" and "\A", which never hold past the
     * start of the subject: a pattern with one needs that search too. A
     * pattern that may match empty needs the retry calls such as
     * preg_match_all() make after an empty match.
     *
     * Two patterns that read alike (see readAlike()) run alike everywhere:
     * no run is needed.
     *
     * @internal the check of a rewrite asks it for each call a pattern may go to
     *
     * @return list<MatchRun>|null
     */
    public function runsOfEveryCall(string $left, string $right): ?array
    {
        $translator = new HirTranslator($this->parser()->target());
        $leftAst = $this->parser()->parse($left);
        $rightAst = $this->parser()->parse($right);
        $hirs = [$translator->translate($leftAst), $translator->translate($rightAst)];

        if (self::readsAlike($leftAst, $rightAst, $hirs[0], $hirs[1])) {
            return [];
        }

        $startAnchored = false;
        $empty = false;
        foreach ($hirs as $hir) {
            $empty = $empty || false !== $hir->properties->nullable;
            $pending = [$hir];
            while (null !== $node = array_pop($pending)) {
                if ($node instanceof LookHir && \in_array($node->kind, [LookKind::Behind, LookKind::NegativeBehind], true)) {
                    return null;
                }

                if ($node instanceof AssertionHir) {
                    if (\in_array($node->kind, [AssertionKind::LineStart, AssertionKind::WordBoundary, AssertionKind::NotWordBoundary, AssertionKind::ResetMatchStart], true)) {
                        return null;
                    }

                    $startAnchored = $startAnchored || AssertionKind::SubjectStart === $node->kind;
                }

                // A condition stays as written: one that asserts anything
                // about where it stands is not followed.
                if ($node instanceof ConditionalHir && self::assertsPosition($node->condition)) {
                    return null;
                }

                array_push($pending, ...$node->children());
            }
        }

        $runs = [MatchRun::Search];
        if ($startAnchored) {
            $runs[] = MatchRun::SearchPastSubjectStart;
        }
        if ($empty) {
            $runs[] = MatchRun::EmptyMatchRetry;
            if ($startAnchored) {
                $runs[] = MatchRun::EmptyMatchRetryPastSubjectStart;
            }
        }

        return $runs;
    }

    /**
     * Whether two patterns read alike: the same normal form, positions
     * aside, the same options before it, and the same flags but for an "s"
     * or an "m" that form already applied. [0-9] and \d, a{1} and a, (?:a)
     * and a read alike. Such patterns run alike in every call, and wherever
     * their text is spliced into a larger pattern.
     *
     * @internal the lint asks it of a rewrite it would splice into a larger pattern
     */
    public function readAlike(string $left, string $right): bool
    {
        $translator = new HirTranslator($this->parser()->target());
        $leftAst = $this->parser()->parse($left);
        $rightAst = $this->parser()->parse($right);

        return self::readsAlike($leftAst, $rightAst, $translator->translate($leftAst), $translator->translate($rightAst));
    }

    /**
     * Whether two patterns differ only in parts that run alike wherever
     * they stand: the rest of both normal forms is the same, and each part
     * that changed matches the strings the other matches, none of which
     * begins another. From any position such a part then ends in one place
     * at most, the same on both sides, whatever the pattern around it does
     * after or before it: /(?<!x)(?:_|%)/ and /(?<!x)[_%]/ run alike in
     * every call, where the strings matched whole would prove nothing.
     *
     * A part holds characters, classes, alternatives and repetitions only:
     * no group, anchor, lookaround, backreference, "\K" or verb. /(?:a|ab)/
     * and /a/ write the same $matches, yet a "c" after them backtracks into
     * the first and finds "abc" in "xabc": "a" begins "ab", and the part is
     * not proven. A pattern a subroutine call may enter, a part inside a
     * lookbehind that matches strings of more than one length, or a pattern
     * that holds a verb or a callout, is not read. The branches at the top
     * of a lookbehind are read together: there only the strings they match
     * count, /(?<=a|bc|d)/ and /(?<=[ad]|bc)/ hold alike, each branch of one
     * length. Two sequences of other counts are paired by the parts both
     * keep, when read whole they are not proven: /(?<!x)-----.*-----/i and
     * /(?<!x)-{5}.*-{5}/i differ in each run of dashes.
     *
     * @internal the check of a rewrite asks it where the match solver does not read the pattern
     *
     * @throws ComplexityException When a part outgrows the limits of the options
     */
    public function rewritesAlikeInPlace(string $left, string $right, ?SolverOptions $options = null): bool
    {
        $leftAst = $this->parser()->parse($left);
        $rightAst = $this->parser()->parse($right);
        if (!self::sameSettings($leftAst, $rightAst)) {
            return false;
        }

        $translator = new HirTranslator($this->parser()->target());
        $leftHir = $translator->translate($leftAst);
        $rightHir = $translator->translate($rightAst);
        if (self::enters($leftHir) || self::enters($rightHir)) {
            return false;
        }

        $options ??= new SolverOptions();
        $full = new SolverOptions(
            matchMode: MatchMode::Full,
            maxNfaStates: $options->maxNfaStates,
            maxDfaStates: $options->maxDfaStates,
            minimizeDfa: $options->minimizeDfa,
            minimizationAlgorithm: $options->minimizationAlgorithm,
            maxTransitionsProcessed: $options->maxTransitionsProcessed,
            determinizationAlgorithm: $options->determinizationAlgorithm,
        );
        $unicode = $leftAst->isUnicode();

        // Runs of other counts are first read as one part each, then paired
        // by the parts both keep: either way the rest is the same.
        $sites = RewriteSites::between($leftHir, $rightHir);

        try {
            if ($this->sitesRunAlike($sites, $left, $right, $unicode, $full)) {
                return true;
            }
        } catch (ComplexityException) {
            // A run read whole may outgrow the limits its paired parts keep to.
        }

        $aligned = RewriteSites::between($leftHir, $rightHir, aligned: true);

        return RewriteSites::shape($aligned) !== RewriteSites::shape($sites) && $this->sitesRunAlike($aligned, $left, $right, $unicode, $full);
    }

    /**
     * Whether some string starting with the input is matched, under the match
     * mode of the options: a half-typed "2026" begins a date that
     * /^\d{4}-\d{2}-\d{2}$/ matches, where preg_match() can only say 0. In
     * UTF mode, an input ending in the middle of a character is viable when
     * one way to finish the character is.
     *
     * @throws ComplexityException When the pattern leaves the regular subset or a limit is reached
     */
    public function acceptsPrefix(string $regex, string $subject, ?SolverOptions $options = null): bool
    {
        $dfa = $this->buildDfa($regex, $options ?? new SolverOptions());

        return PrefixReader::begins($dfa, $subject, $this->parser()->parse($regex)->isUnicode());
    }

    /**
     * The set of strings the pattern matches, under the match mode of the
     * options: whether it is finite, how many strings of each length it
     * holds, each of them, and the strings it rejects.
     *
     * @throws ComplexityException When the pattern leaves the regular subset or a limit is reached
     */
    public function language(string $regex, ?SolverOptions $options = null): Language
    {
        return new Language($this->buildDfa($regex, $options ?? new SolverOptions()), $this->parser()->parse($regex)->isUnicode());
    }

    /**
     * Compiles a pattern to its DFA, and stores it in the cache when there is one,
     * so that later questions about the pattern reuse it.
     *
     * @throws ComplexityException When the pattern leaves the regular subset or a limit is reached
     */
    public function compile(string $regex, ?SolverOptions $options = null): Dfa
    {
        return $this->buildDfa($regex, $options ?? new SolverOptions());
    }

    /**
     * The key a compiled DFA is stored under.
     *
     * The PHP and PCRE2 judged decide what the pattern means: "{,2}" repeats
     * from PCRE2 10.43 and is text before. The character sets the DFA is
     * built from come from the running engine, whatever release is judged,
     * so its full version string enters the key too. The version of the code
     * that reads the pattern keeps a persistent cache from answering with a
     * DFA an older reading built.
     *
     * @param string $targetKey     The cache key of the PHP and PCRE2 target judged
     * @param string $engineVersion The running engine's full PCRE_VERSION string
     *
     * @internal
     */
    public static function dfaCacheKey(string $pattern, string $targetKey, SolverOptions $options, string $engineVersion): string
    {
        $parts = [
            $pattern,
            RegexParser::CACHE_VERSION,
            $targetKey,
            $engineVersion,
            $options->matchMode->value,
            $options->maxNfaStates,
            $options->maxDfaStates,
            $options->minimizeDfa ? '1' : '0',
            $options->minimizationAlgorithm->value,
            $options->determinizationAlgorithm->value,
            $options->maxTransitionsProcessed ?? 'null',
        ];

        return \hash('sha256', \implode('|', $parts));
    }

    /**
     * Whether each pair of parts runs alike wherever it stands.
     *
     * @param list<array{Hir, Hir, bool, bool}> $sites
     *
     * @throws ComplexityException When a part outgrows the limits of the options
     */
    private function sitesRunAlike(array $sites, string $left, string $right, bool $unicode, SolverOptions $full): bool
    {
        foreach ($sites as [$leftPart, $rightPart, $insideLookbehind, $lookbehindBodies]) {
            if (!self::plainPart($leftPart) || !self::plainPart($rightPart)) {
                return false;
            }

            // A lookbehind holds where some string one of its branches
            // matches ends: with no group in them, the order of the branches
            // tells nothing. Branches of one length each stand on every
            // release, and the same strings give them the same lengths.
            if ($lookbehindBodies && self::branchesOfOneLength($leftPart) && self::branchesOfOneLength($rightPart)
                && null === $this->findExample(self::partDfa($left, $leftPart, $unicode, $full), self::partDfa($right, $rightPart, $unicode, $full), static fn (bool $leftAccept, bool $rightAccept): bool => $leftAccept !== $rightAccept)) {
                continue;
            }

            // What a lookbehind may hold differs by release: a part of one
            // length on both sides leaves every branch the length it had.
            if ($insideLookbehind && !(self::oneLength($leftPart) && self::oneLength($rightPart))) {
                return false;
            }

            // One string each, or one character of a set each: no string
            // begins another, and the strings or the sets say it all.
            [$leftText, $rightText] = [$leftPart->properties->literal, $rightPart->properties->literal];
            if (null !== $leftText && null !== $rightText) {
                if ($leftText !== $rightText) {
                    return false;
                }

                continue;
            }

            [$leftSet, $rightSet] = [self::oneCharacterOf($leftPart), self::oneCharacterOf($rightPart)];
            if (null !== $leftSet && null !== $rightSet) {
                if (!$leftSet->subtract($rightSet)->isEmpty() || !$rightSet->subtract($leftSet)->isEmpty()) {
                    return false;
                }

                continue;
            }

            $leftDfa = self::partDfa($left, $leftPart, $unicode, $full);
            $rightDfa = self::partDfa($right, $rightPart, $unicode, $full);

            $differ = static fn (bool $leftAccept, bool $rightAccept): bool => $leftAccept !== $rightAccept;
            if (null !== $this->findExample($leftDfa, $rightDfa, $differ)) {
                return false;
            }

            // Strings of one length never begin one another.
            if (!self::oneLength($leftPart) && !$this->prefixFree($leftDfa)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The automaton of the strings a part matches whole.
     *
     * @throws ComplexityException When the part outgrows the limits of the options
     */
    private static function partDfa(string $pattern, Hir $part, bool $unicode, SolverOptions $options): Dfa
    {
        return (new DfaBuilder())->determinize((new HirToNfaTransformer($pattern, $unicode))->transform($part, $options), $options);
    }

    /**
     * Whether each branch of a lookbehind body, an alternation, or the body
     * as one branch, matches strings of one length.
     */
    private static function branchesOfOneLength(Hir $body): bool
    {
        foreach ($body instanceof AlternationHir ? $body->branches : [$body] as $branch) {
            if (!self::oneLength($branch)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether every string the part matches, as written, has one length.
     */
    private static function oneLength(Hir $part): bool
    {
        return $part->properties->minLength === $part->properties->maxLength;
    }

    /**
     * The characters a part matches when it matches one character, any of a
     * set, and nothing else; null otherwise.
     */
    private static function oneCharacterOf(Hir $part): ?CharSet
    {
        return 1 === $part->properties->minLength && 1 === $part->properties->maxLength ? $part->properties->first : null;
    }

    private static function readsAlike(RegexNode $leftAst, RegexNode $rightAst, Hir $leftHir, Hir $rightHir): bool
    {
        return self::sameSettings($leftAst, $rightAst) && RewriteSites::shape($leftHir) === RewriteSites::shape($rightHir);
    }

    /**
     * Whether two patterns open with the same options, carry the same flags
     * but for an "s" or an "m" their normal forms apply, and define the
     * same groups: the options a pattern opens with, (*UTF) or a newline
     * convention, are no part of the normal form, and neither is what a
     * "(?(DEFINE)...)" holds, which only a subroutine call runs.
     */
    private static function sameSettings(RegexNode $leftAst, RegexNode $rightAst): bool
    {
        if (StartOptions::of($leftAst->source ?? '') !== StartOptions::of($rightAst->source ?? '')) {
            return false;
        }

        $otherFlags = static fn (string $flags): string => count_chars(str_replace(['s', 'm'], '', $flags), 3);
        if ($otherFlags($leftAst->flags) !== $otherFlags($rightAst->flags)) {
            return false;
        }

        return RewriteSites::shape(self::defines($leftAst)) === RewriteSites::shape(self::defines($rightAst));
    }

    /**
     * @return list<DefineNode>
     */
    private static function defines(RegexNode $ast): array
    {
        $defines = [];
        NodeWalker::walk($ast, static function (NodeInterface $node) use (&$defines): ?TraversalAction {
            if ($node instanceof DefineNode) {
                $defines[] = $node;
            }

            return null;
        });

        return $defines;
    }

    /**
     * Whether the form holds a subroutine call, which may run any group
     * again elsewhere, or a verb or a callout, which act on how the engine
     * backtracks rather than on what it matches.
     */
    private static function enters(Hir $hir): bool
    {
        $pending = [$hir];
        while (null !== $node = array_pop($pending)) {
            if ($node instanceof OpaqueHir && ($node->node instanceof SubroutineNode || $node->node instanceof PcreVerbNode || $node->node instanceof CalloutNode)) {
                return true;
            }

            array_push($pending, ...$node->children());
        }

        return false;
    }

    /**
     * Whether a part holds characters, classes, alternatives and repetitions
     * that may give back only: the strings it matches say where it may end.
     */
    private static function plainPart(Hir $hir): bool
    {
        $pending = [$hir];
        while (null !== $node = array_pop($pending)) {
            $plain = $node instanceof LiteralHir || $node instanceof ClassHir || $node instanceof ConcatHir
                || $node instanceof AlternationHir || $node instanceof EmptyHir
                || ($node instanceof RepetitionHir && Greed::Possessive !== $node->greed);
            if (!$plain) {
                return false;
            }

            array_push($pending, ...$node->children());
        }

        return true;
    }

    /**
     * Whether no string the automaton accepts begins another it accepts:
     * no accepting state leads, in a step or more, to an accepting state.
     */
    private function prefixFree(Dfa $dfa): bool
    {
        $alphabet = $this->mergeAlphabetRanges($dfa, $dfa);
        $next = [];
        foreach ($dfa->states as $id => $state) {
            $next[$id] = [];
            foreach ($alphabet as [$start]) {
                $target = $state->transitionFor($start);
                if (null !== $target) {
                    $next[$id][$target] = true;
                }
            }
        }

        // The states an accepting state may be reached from, in no step or
        // more.
        $live = [];
        foreach ($dfa->states as $id => $state) {
            if ($state->isAccepting) {
                $live[$id] = true;
            }
        }
        do {
            $grown = false;
            foreach ($next as $id => $targets) {
                if (!isset($live[$id]) && [] !== array_intersect_key($targets, $live)) {
                    $live[$id] = true;
                    $grown = true;
                }
            }
        } while ($grown);

        foreach ($dfa->states as $id => $state) {
            if ($state->isAccepting && [] !== array_intersect_key($next[$id], $live)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the condition holds an anchor, an assertion or a lookbehind.
     */
    private static function assertsPosition(NodeInterface $condition): bool
    {
        $asserts = false;
        NodeWalker::walk($condition, static function (NodeInterface $node) use (&$asserts): ?TraversalAction {
            $asserts = $node instanceof AnchorNode || $node instanceof AssertionNode || $node instanceof KeepNode
                || ($node instanceof GroupNode && \in_array($node->type, [GroupType::LookbehindPositive, GroupType::LookbehindNegative], true));

            return $asserts ? TraversalAction::Stop : null;
        });

        return $asserts;
    }

    /**
     * @throws ComplexityException
     */
    private function priorityNfa(string $pattern, SolverOptions $options): PriorityNfa
    {
        $ast = $this->parser()->parse($pattern);

        // These flags change nothing the tree does not already say.
        $unsupported = array_diff(Bytes::split($ast->flags), ['i', 's', 'u', 'x', 'D', 'U', 'n', 'J', 'S', 'X', 'r']);
        if ([] !== array_filter($unsupported, static fn (string $flag): bool => '' !== $flag)) {
            throw new ComplexityException('Unsupported regex flags for the match solver: '.implode(', ', $unsupported).'.', 0, $pattern);
        }

        if ('CRLF' === StartOptions::newline($ast->source ?? '')) {
            throw new ComplexityException('Under (*CRLF) the dot reads a "\r" alone but not one before a "\n", which the match solver does not model.', 0, $pattern);
        }

        return (new PriorityNfaBuilder($options->maxNfaStates, $pattern))->build((new HirTranslator($this->parser()->target()))->translate($ast), $ast->isUnicode());
    }

    private function parser(): RegexParser
    {
        return $this->parser ?? RegexParser::create();
    }

    /**
     * @throws ComplexityException
     *
     * @return array{0: Dfa, 1: Dfa}
     */
    private function buildDfas(string $left, string $right, SolverOptions $options): array
    {
        if ($left === $right) {
            $dfa = $this->buildDfa($left, $options);

            return [$dfa, $dfa];
        }

        return [
            $this->buildDfa($left, $options),
            $this->buildDfa($right, $options),
        ];
    }

    /**
     * @throws ComplexityException
     */
    private function buildDfa(string $pattern, SolverOptions $options): Dfa
    {
        $cacheKey = null;
        if (null !== $this->dfaCache) {
            $cacheKey = $this->cacheKey($pattern, $options);
            $cached = $this->dfaCache->get($cacheKey);
            if (null !== $cached) {
                return $cached;
            }
        }

        $ast = $this->parser()->parse($pattern);
        $hir = (new RegularSubsetValidator())->assertSupported($ast, $pattern, $options, $this->parser()->target());

        $transformer = new HirToNfaTransformer($pattern, HirTranslator::unicodeOf($ast));
        $nfa = $transformer->transform($hir, $options);

        $dfa = (new DfaBuilder())->determinize($nfa, $options);

        if (null !== $this->dfaCache && null !== $cacheKey) {
            $this->dfaCache->set($cacheKey, $dfa);
        }

        return $dfa;
    }

    private function cacheKey(string $pattern, SolverOptions $options): string
    {
        return self::dfaCacheKey($pattern, $this->parser()->target()->cacheKey(), $options, \PCRE_VERSION);
    }

    /**
     * @param callable(bool, bool): bool $acceptPredicate
     */
    private function findExample(Dfa $left, Dfa $right, callable $acceptPredicate): ?string
    {
        $startLeft = $left->startState;
        $startRight = $right->startState;
        $rightStateCount = \count($right->states);
        $startKey = $this->pairKey($startLeft, $startRight, $rightStateCount);
        $alphabetRanges = $this->mergeAlphabetRanges($left, $right);

        // Both alphabets the bytes: a witness is a byte, not the UTF-8 of
        // its value; with a code point alphabet anywhere it is a character.
        $byteExample = $left->maxCodePoint <= self::MAX_BYTE && $right->maxCodePoint <= self::MAX_BYTE;

        if ($acceptPredicate($left->getState($startLeft)->isAccepting, $right->getState($startRight)->isAccepting)) {
            return '';
        }

        /** @var \SplQueue<array{int, int, int}> $queue */
        $queue = new \SplQueue();
        $queue->enqueue([$startLeft, $startRight, $startKey]);

        /** @var array<int, bool> $visited */
        $visited = [$startKey => true];
        /** @var array<int, array{0:int, 1:int}|null> $previous */
        $previous = [$startKey => null];

        while (!$queue->isEmpty()) {
            $item = $queue->dequeue();
            [$leftStateId, $rightStateId, $currentKey] = $item;
            $leftState = self::DEAD === $leftStateId ? null : $left->getState($leftStateId);
            $rightState = self::DEAD === $rightStateId ? null : $right->getState($rightStateId);

            foreach ($alphabetRanges as [$start]) {
                $symbol = $start;
                $nextLeft = null === $leftState ? self::DEAD : $leftState->transitionFor($symbol);
                $nextLeft ??= self::DEAD;
                $nextRight = null === $rightState ? self::DEAD : $rightState->transitionFor($symbol);
                $nextRight ??= self::DEAD;

                $nextKey = $this->pairKey($nextLeft, $nextRight, $rightStateCount);

                if (isset($visited[$nextKey])) {
                    continue;
                }

                $visited[$nextKey] = true;
                $previous[$nextKey] = [$currentKey, $symbol];

                $nextLeftState = self::DEAD === $nextLeft ? null : $left->getState($nextLeft);
                $nextRightState = self::DEAD === $nextRight ? null : $right->getState($nextRight);
                if ($acceptPredicate(null !== $nextLeftState && $nextLeftState->isAccepting, null !== $nextRightState && $nextRightState->isAccepting)) {
                    return $this->buildExample($nextKey, $previous, $byteExample);
                }

                $queue->enqueue([$nextLeft, $nextRight, $nextKey]);
            }
        }

        return null;
    }

    /**
     * One number per pair of states, the dead one counted in: it never
     * collides, the way a plain product would.
     */
    private function pairKey(int $left, int $right, int $rightStateCount): int
    {
        return ($left + 1) * ($rightStateCount + 1) + ($right + 1);
    }

    /**
     * @param array<int, array{0:int, 1:int}|null> $previous
     */
    private function buildExample(int $key, array $previous, bool $byteExample): string
    {
        $chars = [];
        $current = $key;
        while (null !== $previous[$current]) {
            [$prevKey, $char] = $previous[$current];
            $chars[] = $byteExample ? \chr($char) : (CodePointHelper::toString($char) ?? '');
            $current = $prevKey;
        }

        if ([] === $chars) {
            return '';
        }

        return implode('', \array_reverse($chars));
    }

    /**
     * @return array<int, AlphabetRange>
     */
    private function mergeAlphabetRanges(Dfa $left, Dfa $right): array
    {
        $min = \min($left->minCodePoint, $right->minCodePoint);
        $max = \max($left->maxCodePoint, $right->maxCodePoint);

        $boundaries = [
            $min => true,
            $max + 1 => true,
        ];

        foreach ([$left, $right] as $dfa) {
            $ranges = $dfa->alphabetRanges;
            if ([] === $ranges) {
                $ranges = [[$dfa->minCodePoint, $dfa->maxCodePoint]];
            }

            foreach ($ranges as [$start, $end]) {
                $boundaries[$start] = true;
                if ($end + 1 <= $max + 1) {
                    $boundaries[$end + 1] = true;
                }
            }
        }

        /** @var array<int> $points */
        $points = \array_keys($boundaries);
        \sort($points, \SORT_NUMERIC);

        $ranges = [];
        $count = \count($points);
        for ($i = 0; $i < $count - 1; $i++) {
            $start = $points[$i];
            $end = $points[$i + 1] - 1;

            if ($start > $max) {
                break;
            }

            $end = \min($end, $max);
            if ($start > $end) {
                continue;
            }

            // The Unicode alphabet has a hole where the surrogates would
            // be: a partition range may fall inside it, or start there and
            // run past it, and the probe of a range must name a character
            // a real subject can hold.
            if ($start >= 0xD800 && $end <= 0xDFFF) {
                continue;
            }
            if ($start >= 0xD800 && $start <= 0xDFFF) {
                $start = 0xE000;
            }

            $ranges[] = [$start, $end];
        }

        if ([] === $ranges) {
            $ranges[] = [$min, $max];
        }

        return $ranges;
    }
}
