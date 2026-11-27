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

namespace PHPRegex\Redos\Backtrack;

use PHPRegex\Parser\Analysis\LengthRangeCalculator;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Engine\NoJit;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Hir\Utf8;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\Bytes;
use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\Exception\ModelLimitException;
use PHPRegex\Redos\RedosSearchCost;
use PHPRegex\Redos\SearchCostCause;

/**
 * The cost of an unanchored search whose every attempt is proven linear,
 * read on the automaton the per-attempt proof built, on what is left of its
 * budget.
 *
 * The witness is a prefix, a run word, the word of a cycle of the
 * automaton, and a breaker. On the prefix, the run repeated n times, then
 * the breaker: an attempt started at any word of the run reads it from
 * there to its end (the word enters its own cycle from an attempt's start),
 * no attempt started in the prefix or the run succeeds, and every one fails
 * on the breaker, which holds the last code unit PCRE2 requires before it
 * tries an attempt, with a character after it when an attempt of the run
 * would match it at the end. The search then starts n attempts, each linear
 * in what is left of the run: quadratic. The attempts the breaker starts,
 * and the one at the subject's end, come after all of them, and may match:
 * "(?:\w+\.)?x" matches the "x" it requires, "(?:a+\d)?$" and "\s*$" the
 * empty string at the end. preg_match() then returns 1, once the attempts of
 * the run have each read to its end. The prefix is there when the first
 * attempt would match the bare run, as "^\s+" does in the trim regex
 * "/^\s+|\s+$/". The run word is one character, a cycle's word, or up to
 * four characters of different classes, so that a "\b" holds inside the
 * run. It starts with a character the interpreter starts an attempt at:
 * PCRE2 looks for the first code unit its compiler set before each
 * attempt, in the one case it flags it in, so a run word whose first
 * character holds none is passed over, and the class of that code unit is
 * written with the character it was read from.
 *
 * PCRE2 ties every attempt to the search start under "A", and when each
 * alternative opens with "^" (without m), "\A", "\G", or a ".*" under s
 * outside an atomic group (a class of every character too, without u, where
 * PCRE2 reads it as the dot): none is reported. A leading "^" under m or
 * ".*" without s starts attempts only after a newline of the pattern's
 * convention: reported only when the run word ends with one. The options a
 * pattern opens with read nothing; "(*NO_DOTSTAR_ANCHOR)" keeps PCRE2 from
 * tying a leading ".*" to the search start or to line starts, and
 * "(*NO_START_OPT)" from tying it to line starts. Under "(*NOTEMPTY)" no
 * attempt succeeds before it reads a character, so one that the empty
 * string would end reads on, as "(?:a+\d)?" does; under
 * "(*NOTEMPTY_ATSTART)" only the attempt at the search start is held so.
 * "\K" sets the start of the match it reports where it stands, so under
 * "(*NOTEMPTY)" no attempt succeeds right after it either, before it reads
 * a character more: "a+\K" gives back every "a+". Under
 * "(*NOTEMPTY_ATSTART)" a "\K" past the search start moves the empty match
 * past it, and the attempt succeeds. A possessive or atomic repeat of any
 * character moves to the end of the subject in one step, alone in a branch
 * of the atomic group or with "\K": no run is read through it.
 *
 * The model reads a bounded repeat above the unrolling cutoff as unbounded:
 * no run is read through it, the engine's attempts there stop at the bound.
 * A possessive one it reads as a possessive run, which leaves only before a
 * character it does not read, where the engine also leaves it at its bound:
 * no witness holds a character such a repeat reads.
 * It reads an atomic body whose branches each hold one run, "\K" around
 * it, and start with characters of their own as those runs, "(?>a+\K)" as
 * "(?>a+)\K". It does not keep a lookaround's condition and keeps any other
 * atomic body with every way through it: such a witness is reported only
 * when the engine replay confirms it, and the replay is asked only where
 * the pattern counts within its reach. A pinned replay cannot confirm a
 * pattern holding "\G", which holds at every pinned offset. The replay runs
 * the pattern with each character a repeat reads in a group of its own,
 * which the engine's step counter sees inside a possessive or atomic repeat
 * too: "(*NOTEMPTY)a++\K" and "(*NOTEMPTY)(?>a+\K)" read the rest of the
 * run at every attempt and give nothing back. The replay accepts a search
 * that matches only where the breaker starts or after it, unless "\K" may
 * move there the match of an earlier attempt.
 *
 * The witness of short matches reads each call's first attempt in the
 * order PCRE2 tries its paths, on the items of the automaton, which list
 * them in that order: each item where it comes first. A success ends the
 * items after it, which are never tried, and the items before it go on:
 * the match is the last success met, once they all fail or go round, as
 * the twenty-one characters of "a(?:a+\d|a{20})?", whose "a{20}" is tried
 * before the group is left out. The first call starts the subject, where
 * its attempt may fail inside the run though every later one matches, as
 * "\Ba(?:a+\d)?" does: its search goes on at the next character, unless
 * the A modifier ties it to its start. A path through a further round of
 * a bounded repeat read as unbounded, or through an atomic body kept
 * whole, may not be one the engine takes: such a witness is reported only
 * when the engine replay confirms it.
 *
 * @internal
 */
final class SearchCostProver
{
    /**
     * The most run words tried, and the most a cycle gives.
     */
    private const MAX_WORDS = 48;

    private const MAX_WORDS_PER_CYCLE = 8;

    /**
     * The most words of different classes tried, from the pairs and from
     * each two-step cycle: two or four characters.
     */
    private const MAX_MIXED_WORDS = 16;

    /**
     * The most one-character prefixes tried.
     */
    private const MAX_PREFIXES = 8;

    private const MAX_BREAKERS = 4;

    private const MAX_BREAKER_NODES = 64;

    private const MAX_BREAKER_LENGTH = 4;

    /**
     * The most run words a short match reads, as the engine replay bounds
     * it: a quarter of the run words it counts on.
     */
    private const MAX_MATCH_WORDS = 64;

    /**
     * The most a pattern may count for the engine replay to stand for a
     * run of any length: a quarter of the run words it counts on, as many
     * as lie before its first pinned attempt and between its two.
     */
    private const REPLAY_REACH = 64;

    /**
     * The attempts that refuse an empty match (pcre2api, PCRE2_NOTEMPTY and
     * PCRE2_NOTEMPTY_ATSTART): the one at the search start, the later ones.
     */
    private const EMPTY_REFUSED_AT_START = 1;

    private const EMPTY_REFUSED_LATER = 2;

    /**
     * Each attempt starts a search of its own, as in the loop of the
     * functions that look for every match, which calls the engine afresh
     * at each offset: "\G" holds there.
     */
    private const EVERY_CALL = 4;

    /**
     * The delimiters a group around a repeated character would end the
     * pattern at: its "?", ":" and ")".
     */
    private const HELD_BY_A_GROUP = '?:)';

    /**
     * The characters of "\h" and of "\v" (pcre2pattern, "Generic character
     * types"), whatever u.
     */
    private const HORIZONTAL_SPACES = [[0x09, 0x09], [0x20, 0x20], [0xA0, 0xA0], [0x1680, 0x1680], [0x180E, 0x180E], [0x2000, 0x200A], [0x202F, 0x202F], [0x205F, 0x205F], [0x3000, 0x3000]];

    private const VERTICAL_SPACES = [[0x0A, 0x0D], [0x85, 0x85], [0x2028, 0x2029]];

    /**
     * The characters a line starts after, by newline convention (pcre2pattern,
     * "Newline conventions"): what the dot without s leaves out, the line
     * feed of a CRLF pair.
     */
    private const LINE_ENDS = [
        'LF' => [[0x0A, 0x0A]],
        'CR' => [[0x0D, 0x0D]],
        'CRLF' => [[0x0A, 0x0A]],
        'ANYCRLF' => [[0x0A, 0x0A], [0x0D, 0x0D]],
        'ANY' => [[0x0A, 0x0D], [0x85, 0x85], [0x2028, 0x2029]],
        'NUL' => [[0x00, 0x00]],
    ];

    public function __construct(private readonly SearchCostReplayerInterface $replayer = new SearchCostReplayer()) {}

    /**
     * The search-cost witness of the pattern the proof found linear per
     * attempt, read from the automaton of its own search, replayed on the
     * engine when asked; null when none is found or the proof built no
     * search.
     *
     * A library failure inside the search proof says nothing of one
     * attempt: it gives no witness, and the per-attempt verdict the caller
     * holds stands. Any other error is a bug, and surfaces.
     *
     * @param PcreTarget|null $target the PCRE2 and the locale the pattern is read for, the
     *                                code unit it requires and the replay under the tables
     *                                of that locale; the running engine's when null
     */
    public function find(string $pattern, RegexNode $regex, ProofOutcome $proof, bool $replay, ?PcreTarget $target = null, ?ConfirmationOptions $options = null): ?RedosSearchCost
    {
        try {
            return $this->seek($pattern, $regex, $proof, $replay, $target ?? PcreTarget::runtime(), $options, false);
        } catch (ExceptionInterface) {
            return null;
        }
    }

    /**
     * The witness of a search quadratic only in the functions that look for
     * every match (preg_match_all(), preg_replace(), preg_split(), ...),
     * for the pattern whose own search find() gave none: the pattern
     * matches the empty string inside the run, where preg_match() stops,
     * and after each empty match PHP tries the same offset again with
     * PCRE2_NOTEMPTY_ATSTART | PCRE2_ANCHORED, which reads what is left of
     * the run then fails, before it moves one character on. Every offset
     * the loop stops at starts a call of its own: "\G" and the A modifier
     * hold at each, and so does a leading ".*", which PCRE2 tries at the
     * offset it is given; "^" without m and "\A" hold at the subject's
     * start alone. A call tied so tries that offset alone, and the loop
     * ends where the first one fails: its witness starts with an empty
     * match where the subject starts. The retry runs in PCRE2's interpreter whatever
     * pcre.jit, as the JIT takes no PCRE2_ANCHORED at match time. Under
     * "(*NOTEMPTY)" no match is empty, and PHP never retries.
     *
     * Without such a retry, the witness of short matches: preg_match()
     * stops at a short match inside the run, of 64 run words at most, which
     * the attempt finds after the paths it tries first read to the end of
     * the run and fail, as in "a(?:a+\d)?", "a*b|a" or "a{5}(?:a+\d)?", and
     * each call of the loop starts where the last match ended. Its
     * RedosSearchCost names SearchCostCause::ShortMatch.
     *
     * @param PcreTarget|null $target the PCRE2 and the locale the pattern is read for; the
     *                                running engine's when null
     */
    public function findEveryMatch(string $pattern, RegexNode $regex, ProofOutcome $proof, bool $replay, ?PcreTarget $target = null, ?ConfirmationOptions $options = null): ?RedosSearchCost
    {
        try {
            return $this->seek($pattern, $regex, $proof, $replay, $target ?? PcreTarget::runtime(), $options, true);
        } catch (ExceptionInterface) {
            return null;
        }
    }

    /**
     * The witness of a search quadratic in a loop that looks for every
     * match with preg_match() itself, for the pattern whose own search
     * find() gave none: the loop calls it again where each match ended,
     * one byte on after an empty one, and makes no retry there, as the
     * generator nette/utils' Strings::matchAll() returns under "lazy"
     * does. Each call pays the run when the paths its first attempt tries
     * before its match read to the end of the run, the match short or
     * empty: "(?:a+\d)?" reads the rest of the run at each position before
     * it settles on the empty string. A match found before those paths, as
     * the empty one of "(?:a+\d)??", costs that loop nothing, where PHP's
     * retry after it costs the functions that look for every match. The
     * RedosSearchCost names SearchCostCause::ShortMatch.
     *
     * A pattern holding "\K" is not read: the loop goes on from where the
     * reported match starts. Under u a call that ends on an empty match
     * before a character of several bytes starts the next one inside that
     * character, which PCRE2 refuses: no run of such a character is read.
     *
     * @param PcreTarget|null $target the PCRE2 and the locale the pattern is read for; the
     *                                running engine's when null
     */
    public function findMatchLoop(string $pattern, RegexNode $regex, ProofOutcome $proof, bool $replay, ?PcreTarget $target = null, ?ConfirmationOptions $options = null): ?RedosSearchCost
    {
        try {
            return $this->seek($pattern, $regex, $proof, $replay, $target ?? PcreTarget::runtime(), $options, true, true);
        } catch (ExceptionInterface) {
            return null;
        }
    }

    /**
     * @param bool $everyMatch whether the witness is the one of the loop of every match
     * @param bool $matchLoop  whether that loop calls preg_match() itself, with no retry after an empty
     *                         match
     */
    private function seek(string $pattern, RegexNode $regex, ProofOutcome $proof, bool $replay, PcreTarget $target, ?ConfirmationOptions $options, bool $everyMatch, bool $matchLoop = false): ?RedosSearchCost
    {
        $search = $proof->search;
        if (null === $search || (!$everyMatch && str_contains($regex->flags, 'A')) || ($matchLoop && self::holds($regex->pattern, static fn (NodeInterface $node): bool => $node instanceof KeepNode))) {
            return null;
        }

        [$automaton, $budget] = $search;
        $pnfa = $automaton->pnfa;
        $unicode = $pnfa->unicode;
        $sets = [];
        foreach ($pnfa->sets as $state => $set) {
            if (Pnfa::CHAR === $pnfa->kinds[$state]) {
                $sets[$pnfa->offsets[$state] ?? 0] ??= $set;
            }
        }

        // Whether a class may read the other case of its characters: "i"
        // set anywhere, or taken off.
        $caseless = str_contains($regex->flags, 'i')
            || self::holds($regex->pattern, static fn (NodeInterface $node): bool => $node instanceof GroupNode && GroupType::InlineFlags === $node->type && str_contains((string) $node->flags, 'i'));
        // Most patterns open with no option: nothing is read for them.
        $source = $regex->source ?? '';
        $startOptions = str_starts_with($source, '(*') ? StartOptions::items($source) : [];
        $lineEnds = CharSet::fromRanges(self::LINE_ENDS[[] === $startOptions ? 'LF' : StartOptions::newline($source)] ?? self::LINE_ENDS['LF']);
        $anchorsDotStar = !\in_array('NO_DOTSTAR_ANCHOR', $startOptions, true);
        $startsAtLines = $anchorsDotStar && !\in_array('NO_START_OPT', $startOptions, true);
        $start = self::start($regex->pattern, $sets, str_contains($regex->flags, 'm'), $unicode, $caseless, false, $anchorsDotStar, $startsAtLines ? $lineEnds : null, $everyMatch);
        if (null === $start || $start[0]) {
            return null;
        }

        $lineStart = $start[1];
        // A call of the loop of every match tries the offset it is given
        // alone under the A modifier, and where a search would be tied to
        // its start, as behind "\G": the loop ends where one fails.
        $tied = $everyMatch && (str_contains($regex->flags, 'A')
            || false !== (self::start($regex->pattern, $sets, str_contains($regex->flags, 'm'), $unicode, $caseless, false, $anchorsDotStar, $startsAtLines ? $lineEnds : null)[0] ?? null));
        $jumps = [];
        self::jumps($regex->pattern, $sets, $unicode, $caseless, $jumps);
        // "(*NOTEMPTY)" refuses an empty match at every attempt,
        // "(*NOTEMPTY_ATSTART)" at the one at the search start.
        // The retries of the loop of every match refuse it at each start.
        $refused = match (true) {
            \in_array('NOTEMPTY', $startOptions, true) => $everyMatch ? null : self::EMPTY_REFUSED_AT_START | self::EMPTY_REFUSED_LATER,
            $everyMatch => self::EMPTY_REFUSED_AT_START | self::EMPTY_REFUSED_LATER | self::EVERY_CALL,
            \in_array('NOTEMPTY_ATSTART', $startOptions, true) => self::EMPTY_REFUSED_AT_START,
            default => 0,
        };

        // Each call of the loop of every match refuses an empty match at its
        // start under either option.
        $emptyRefused = \in_array('NOTEMPTY', $startOptions, true) || \in_array('NOTEMPTY_ATSTART', $startOptions, true);
        $cause = $everyMatch ? SearchCostCause::EmptyMatchRetry : SearchCostCause::UnanchoredSearch;
        $exact = [] === $pnfa->marks;
        // The interpreter starts an attempt only where the subject holds
        // the first code unit the compiler set, unless the pattern turns
        // the start-up optimizations off.
        [$first, $required] = LastCodeUnit::firstAndRequired($regex, $target);
        $starts = self::starts($automaton, \in_array('NO_START_OPT', $startOptions, true) ? null : $first, $unicode);

        try {
            // Under "(*NOTEMPTY)" no match is empty, and PHP never retries;
            // nor does a loop that calls preg_match() itself.
            $found = null === $refused || $matchLoop ? null : $this->witness($automaton, $budget, $required, $lineStart ? $lineEnds : null, $jumps, $pnfa->readsFinalNewline, $refused, $starts);
            // preg_match() stops at an empty match inside the run, where the
            // loop of every match retries: without one, every call pays the
            // run.
            if ($everyMatch && null !== $found && !self::matchesEmptyInside($automaton, $found['run'])) {
                $found = null;
            }

            // The loop reaches the run only when its first call, tied to
            // the subject's start, ends there on an empty match too.
            if ($tied && null !== $found && !self::matchesEmptyBefore($automaton, $automaton->initials[0]['items'], [...$found['prefix'], ...$found['run']][0])) {
                $found = null;
            }

            // Or preg_match() stops at a short match, which the attempt
            // finds after it read the run, and the loop starts a call where
            // that match ends.
            if ($everyMatch && null === $found) {
                $found = $this->shortMatches($automaton, $budget, $jumps, $pnfa->readsFinalNewline, $emptyRefused, $starts, !$tied, $matchLoop);
                $cause = SearchCostCause::ShortMatch;
                $exact = $exact && null !== $found && $found['exact'];
            }
        } catch (ModelLimitException) {
            return null;
        }

        if (null === $found) {
            return null;
        }

        // No witness holds a character a possessive bounded repeat reads,
        // where the model reads that repeat without its bound.
        $characters = [...$found['prefix'], ...$found['run']];
        $found['breakers'] = array_values(array_filter(
            $found['breakers'],
            static fn (array $breaker): bool => !self::crossesABoundedPossessiveRun($pnfa, [...$characters, ...$breaker]),
        ));
        if ([] === $found['breakers']) {
            return null;
        }

        // An atomic body kept with every way through it is read as the
        // engine reads it on a witness none of whose characters it reads:
        // its states never take one. A witness one of them reads is not
        // read exactly.
        $untouched = array_values(array_filter(
            $found['breakers'],
            static fn (array $breaker): bool => !self::readsAnApproximatedState($pnfa, [...$characters, ...$breaker]),
        ));
        if ([] === $untouched) {
            $exact = false;
        } else {
            $found['breakers'] = $untouched;
        }

        $prefix = Utf8::encode($found['prefix'], $unicode);
        $run = Utf8::encode($found['run'], $unicode);
        $breakers = array_map(static fn (array $breaker): string => Utf8::encode($breaker, $unicode), $found['breakers']);

        // A witness the model does not read exactly stands on its replay,
        // which vouches for nothing the pattern counts past its reach.
        if (!$exact && (!$replay || !self::countsWithinTheReplay($regex->pattern, $unicode, 1, false))) {
            return null;
        }

        if (!$replay) {
            return new RedosSearchCost(2, $cause, $prefix, $run, $breakers[0], $unicode);
        }

        // Replayed under the tables of the target's locale, as the pattern
        // runs there.
        // "\G" holds where a replay pins an attempt, as it does at each call
        // of the loop of every match, never at a later attempt of a search.
        $pinnable = $everyMatch || !self::holds($regex->pattern, static fn (NodeInterface $node): bool => $node instanceof AssertionNode && 'G' === $node->value);
        $counted = self::countingReads($pattern, $regex, $sets, $unicode, $caseless);
        $replayed = null;
        $measured = true;
        CharacterTables::under($target->locale, function () use ($pinnable, $breakers, $counted, $prefix, $run, $options, $cause, $matchLoop, &$replayed, &$measured): void {
            foreach ($pinnable ? $breakers : [] as $breaker) {
                $replays = match (true) {
                    $matchLoop => $this->replayer->replaysMatchLoop($counted, $prefix, $run, $breaker, $options),
                    default => match ($cause) {
                        SearchCostCause::ShortMatch => $this->replayer->replaysShortMatches($counted, $prefix, $run, $breaker, $options),
                        SearchCostCause::EmptyMatchRetry => $this->replayer->replaysEveryMatch($counted, $prefix, $run, $breaker, $options),
                        SearchCostCause::UnanchoredSearch => $this->replayer->replays($counted, $prefix, $run, $breaker, $options),
                    },
                };
                if (true === $replays) {
                    $replayed = $breaker;

                    return;
                }
                $measured = $measured && null !== $replays;
            }
        });

        if (null !== $replayed) {
            return new RedosSearchCost(2, $cause, $prefix, $run, $replayed, $unicode, true);
        }

        // Under limits too low to count, the replay neither confirms nor
        // refutes the witness: "replayed" is null, as for a skipped replay.
        return $exact ? new RedosSearchCost(2, $cause, $prefix, $run, $breakers[0], $unicode, $measured ? false : null) : null;
    }

    /**
     * The pattern with each character a repeat reads put in a group of its
     * own, "a++" read as "(?:a)++": the engine's step counter sees nothing
     * inside a possessive or atomic repeat of one character set, which
     * reads the run without a backtracking frame, and counts each pass
     * through a repeated group, once per character. The group changes no
     * match, and a repeat the counter already sees takes as many steps
     * through it. A character of a set of every character that PCRE2 may
     * read as its any character stays as written: a possessive or atomic
     * repeat of it moves to the end of the subject in one step, which the
     * group would count as a read of each character. A character "\Q"
     * quotes, which "\E" follows where a repeat does, takes its group
     * outside the quote, quoted inside it: "\Qa\E++" read as
     * "\Q\E(?:\Qa\E)\E++", the last "\E" then ending no quote. Where the
     * group's "?", ":" or ")" is the delimiter, the pattern moves to one its
     * body does not hold, and stays as written where it holds every one.
     *
     * @param array<int, CharSet> $sets the set the automaton reads for the atom at each offset
     */
    private static function countingReads(string $pattern, RegexNode $regex, array $sets, bool $unicode, bool $caseless): string
    {
        $parts = NoJit::split($pattern);
        if (null === $parts) {
            return $pattern;
        }

        [$open, $body, $close, $flags] = $parts;
        if (str_contains(self::HELD_BY_A_GROUP, $open)) {
            $moved = NoJit::delimiterOutside($body);
            if (null === $moved) {
                return $pattern;
            }

            [$open, $close] = [$moved, $moved];
        }

        $universe = CharSet::universe($unicode);
        $atoms = [];
        self::repeatedCharacters($regex->pattern, $sets, $universe, $unicode, $caseless, $atoms);
        krsort($atoms);
        foreach ($atoms as $start => [$end, $literal]) {
            $atom = substr($body, $start, $end - $start);
            // A quoted character is written as it is read, and ends where
            // "\E" ends the quote.
            $group = $atom === $literal && '\E' === substr($body, $end, 2) ? '\E(?:\Q'.$atom.'\E)' : '(?:'.$atom.')';
            $body = substr($body, 0, $start).$group.substr($body, $end);
        }

        return $open.$body.$close.$flags;
    }

    /**
     * Where the characters repeats read one at a time stand: the atom of
     * each repeat the automaton reads as one set, but a set of every
     * character PCRE2 may read as its any character.
     *
     * @param array<int, CharSet>                 $sets
     * @param array<int, array{int, string|null}> $atoms the end of each such atom, by its start, and the
     *                                                   character it is where it is a literal one
     *
     * @param-out array<int, array{int, string|null}> $atoms
     */
    private static function repeatedCharacters(NodeInterface $node, array $sets, CharSet $universe, bool $unicode, bool $caseless, array &$atoms): void
    {
        if ($node instanceof QuantifierNode) {
            $atom = $node->node;
            $set = $sets[$atom->getStartPosition()] ?? null;
            if (null !== $set && (!$universe->subtract($set)->isEmpty() || false === self::isAnyCharacter($atom, $unicode, $caseless))) {
                $atoms[$atom->getStartPosition()] = [$atom->getEndPosition(), $atom instanceof LiteralNode ? $atom->value : null];
            }
        }

        foreach ($node->getChildren() as $child) {
            self::repeatedCharacters($child, $sets, $universe, $unicode, $caseless, $atoms);
        }
    }

    /**
     * Whether the replay, which counts on SearchCostReplayer's 256 run
     * words at two attempts pinned 64 and 128 of them in, shows what the
     * engine does on a run of any length: nothing under the node tells
     * those attempts from the ones of a longer run. A repeat that counts
     * past REPLAY_REACH does, its count multiplied by those of the repeats
     * around it; so does a lookaround that must read further than that,
     * and one that goes round a loop of several characters, which holds at
     * one position in so many: "(?=a{200})" fails at both pinned attempts
     * and holds on a longer run, "(?=(?:a{5})*\z)" holds every fifth
     * character from the end.
     *
     * @param int  $times        the product of the counts of the repeats around the node
     * @param bool $inLookaround whether the node lies in a lookaround
     */
    private static function countsWithinTheReplay(NodeInterface $node, bool $unicode, int $times, bool $inLookaround): bool
    {
        if ($node instanceof QuantifierNode) {
            $bounds = $node->bounds;
            $times *= max(1, $bounds->max ?? $bounds->min ?? 1);
            if ($times > self::REPLAY_REACH || ($inLookaround && null === $bounds?->max && 1 !== $node->node->accept(new LengthRangeCalculator($unicode))[1])) {
                return false;
            }
        }

        if ($node instanceof GroupNode && \in_array($node->type, [GroupType::LookaheadPositive, GroupType::LookaheadNegative, GroupType::LookbehindPositive, GroupType::LookbehindNegative], true)) {
            if ($node->child->accept(new LengthRangeCalculator($unicode))[0] > self::REPLAY_REACH) {
                return false;
            }

            $inLookaround = true;
        }

        foreach ($node->getChildren() as $child) {
            if (!self::countsWithinTheReplay($child, $unicode, $times, $inLookaround)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a state of an atomic body kept with every way through it
     * reads one of the characters.
     *
     * @param list<int> $characters those of the prefix, the run and a breaker
     */
    private static function readsAnApproximatedState(Pnfa $pnfa, array $characters): bool
    {
        foreach ($pnfa->approximated as $state => $_) {
            foreach ($characters as $character) {
                if ($pnfa->sets[$state]->contains($character)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether the witness holds a character a possessive bounded repeat
     * reads, where the model reads that repeat as a possessive run without
     * a bound. The model leaves such a run only before a character it does
     * not read; the engine also leaves it at its bound, and may match from
     * there: "a{1,300}+aa*" matches a run of 302 "a" or more at once,
     * where the model sees no way out of "a{1,300}+" inside the run. A
     * repeat that gives back leaves through no such check, or through one
     * of the item after it, as "$" under m.
     *
     * @param list<int> $characters those of the prefix, the run and a breaker
     */
    private static function crossesABoundedPossessiveRun(Pnfa $pnfa, array $characters): bool
    {
        if ([] === $pnfa->abstractedLoops) {
            return false;
        }

        $universe = CharSet::universe($pnfa->unicode);
        foreach ($pnfa->kinds as $state => $kind) {
            // Where a loop is entered or left: a possessive run leaves by
            // a check that the next character is none of its own.
            if (Pnfa::EPSILON !== $kind || 2 !== \count($pnfa->targets[$state])) {
                continue;
            }

            [$enter, $leave] = $pnfa->targets[$state];
            if (Pnfa::ENTER !== $pnfa->kinds[$enter] || !isset($pnfa->abstractedLoops[$pnfa->loops[$enter]]) || Pnfa::PEEK !== $pnfa->kinds[$leave]) {
                continue;
            }

            $run = $universe->subtract($pnfa->sets[$leave]);
            $body = $pnfa->next[$enter];
            if (Pnfa::CHAR === $pnfa->kinds[$body] && $pnfa->sets[$body]->key() !== $run->key()) {
                continue;
            }

            foreach ($characters as $character) {
                if ($run->contains($character)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The character of each class an attempt may start at, where PCRE2's
     * compiler set a first code unit: the interpreter starts an attempt
     * only where the subject holds it, in the one case it looks for or in
     * either. The class's own character where that one holds the code
     * unit, and the character the code unit was read from for its class:
     * "\xFF" for the class of "\xDF" and "\xFF" under ru_RU.KOI8-R,
     * where PCRE2 looks for "\xFF" alone. A class left out starts no
     * attempt; null when an attempt starts at any character.
     *
     * @param array{int, ?int}|null $first the character of the first code unit, and its other case when
     *                                     PCRE2 looks for either
     *
     * @return array<int, int>|null
     */
    private static function starts(ItemAutomaton $automaton, ?array $first, bool $unicode): ?array
    {
        if (null === $first) {
            return null;
        }

        $units = [Utf8::character($first[0], $unicode)[0] => true];
        if (null !== $first[1]) {
            $units[Utf8::character($first[1], $unicode)[0]] = true;
        }

        $starts = [];
        foreach ($automaton->representatives as $class => $representative) {
            if (isset($units[Utf8::character($representative, $unicode)[0]])) {
                $starts[$class] = $representative;
            }
        }

        return $starts + [$automaton->classOf($first[0]) => $first[0]];
    }

    /**
     * The characters of the classes: of each the one an attempt starts at,
     * else the one that stands for the class.
     *
     * @param list<int>            $classes
     * @param array<int, int>|null $starts
     *
     * @return list<int>
     */
    private static function characters(ItemAutomaton $automaton, array $classes, ?array $starts): array
    {
        return array_map(static fn (int $class): int => $starts[$class] ?? $automaton->representatives[$class], $classes);
    }

    /**
     * Whether the run holds the code unit PCRE2 requires: the character
     * itself, or its other case when PCRE2 looks for either, as the tables
     * of the target's locale, or Unicode under u or "(*UCP)", give it. A
     * character that only shares a class with it, as ";" with "!" under
     * ".", or "A" with a case-sensitive "a" after "[aA]", is not it.
     *
     * @param list<int> $run
     */
    private static function holdsCodeUnit(array $run, int $required, ?int $otherCase): bool
    {
        foreach ($run as $character) {
            if ($character === $required || $character === $otherCase) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the node or one of its descendants passes the test.
     *
     * @param \Closure(NodeInterface): bool $test
     */
    private static function holds(NodeInterface $node, \Closure $test): bool
    {
        if ($test($node)) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::holds($child, $test)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether every attempt of the node is tied to the search start, and
     * whether it starts only at the subject's start or after a newline, read
     * as PCRE2 reads the first item of each alternative under the m option
     * in force there; null when the start is undecided. An option the
     * pattern opens with reads nothing: the proof leaves no other verb.
     *
     * @param array<int, CharSet> $sets           the set the automaton reads for the atom at each offset
     * @param bool                $anchorsDotStar whether PCRE2 ties a leading ".*" under s to the search start
     * @param CharSet|null        $lineEnds       the characters a line starts after, where PCRE2 starts a
     *                                            leading ".*" without s; null when it starts it anywhere
     * @param bool                $everyCall      whether each attempt starts a call of its own, as in the
     *                                            loop of every match: "\G" and a leading ".*" then tie
     *                                            nothing, PCRE2 tries them at the offset it is given
     *
     * @return array{bool, bool}|null
     */
    private static function start(NodeInterface $node, array $sets, bool $multiline, bool $unicode, bool $caseless, bool $atomic, bool $anchorsDotStar, ?CharSet $lineEnds, bool $everyCall = false): ?array
    {
        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                if (self::isOptionSetting($child)) {
                    $multiline = self::multilineUnder((string) $child->flags, $multiline);
                } elseif (!$child instanceof CommentNode && !$child instanceof PcreVerbNode && !$child instanceof LimitMatchNode && !($child instanceof LiteralNode && '' === $child->value)) {
                    return self::start($child, $sets, $multiline, $unicode, $caseless, $atomic, $anchorsDotStar, $lineEnds, $everyCall);
                }
            }

            return [false, false];
        }

        if ($node instanceof AlternationNode) {
            $anchored = true;
            $lineStart = true;
            foreach ($node->alternatives as $alternative) {
                $start = self::start($alternative, $sets, $multiline, $unicode, $caseless, $atomic, $anchorsDotStar, $lineEnds, $everyCall);
                if (null === $start) {
                    return null;
                }

                // "(?m)" inside a branch also holds in the branches after it.
                $multiline = self::multilineAfter($alternative, $multiline);

                $anchored = $anchored && $start[0];
                $lineStart = $lineStart && $start[1];
            }

            return [$anchored, $lineStart];
        }

        if ($node instanceof GroupNode) {
            return match ($node->type) {
                GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::BranchReset => self::start($node->child, $sets, $multiline, $unicode, $caseless, $atomic, $anchorsDotStar, $lineEnds, $everyCall),
                GroupType::InlineFlags => self::start($node->child, $sets, self::multilineUnder((string) $node->flags, $multiline), $unicode, $caseless, $atomic, $anchorsDotStar, $lineEnds, $everyCall),
                GroupType::Atomic => self::start($node->child, $sets, $multiline, $unicode, $caseless, true, $anchorsDotStar, $lineEnds, $everyCall),
                default => [false, false],
            };
        }

        if ($node instanceof AnchorNode || $node instanceof AssertionNode) {
            return match ($node->value) {
                // "^" without m holds where a line starts too; "\A" and "\G" are no line start for PCRE2.
                '^' => [!$multiline, true],
                'A' => [true, false],
                'G' => [!$everyCall, false],
                default => [false, false],
            };
        }

        if (!$node instanceof QuantifierNode) {
            return [false, false];
        }

        $bounds = $node->bounds;
        if (null !== $bounds && $bounds->min > 0) {
            return self::start($node->node, $sets, $multiline, $unicode, $caseless, $atomic, $anchorsDotStar, $lineEnds, $everyCall);
        }

        // A leading ".*": PCRE2 anchors it under s, and starts it after a
        // newline without s; inside an atomic group, or under
        // "(*NO_DOTSTAR_ANCHOR)", it does neither.
        if ($everyCall || $atomic || !$anchorsDotStar || null === $bounds || null !== $bounds->max) {
            return [false, false];
        }

        $set = $sets[$node->node->getStartPosition()] ?? null;
        $universe = CharSet::universe($unicode);
        if (null === $set) {
            return [false, false];
        }

        if ($universe->subtract($set)->isEmpty()) {
            $anchored = self::isAnyCharacter($node->node, $unicode, $caseless);

            return null === $anchored ? null : [$anchored, false];
        }

        $dot = $node->node instanceof DotNode || ($node->node instanceof CharTypeNode && 'N' === $node->node->value);

        return [false, $dot && null !== $lineEnds && $set->key() === $universe->subtract($lineEnds)->key()];
    }

    /**
     * Whether PCRE2 reads the atom, which matches every character, as its
     * any-character item, which it anchors when it leads the pattern
     * repeated and moves to the end of the subject in one step when
     * possessive: the dot under s, "\p{Any}", and a class without u. Under u
     * (UTF and UCP) a class reads every item into a bitmap below U+0100,
     * and above it only its characters, ranges, "\h", "\v", "[:ascii:]",
     * "[:blank:]" and their negations: it is that item when these cover
     * every code point from U+0100, the surrogates included, when it holds
     * "\p{Any}", or when it is the negation of "\P{Any}" alone (pcre2test
     * 10.49). "\d", "\s", "\w", their negations, the other POSIX classes
     * and the other properties count for nothing there, so "[\s\S]" is no
     * such class. Another negated class is undecided, and so is under i a
     * class whose gaps the other cases may fill: null.
     */
    private static function isAnyCharacter(NodeInterface $atom, bool $unicode, bool $caseless): ?bool
    {
        if ($atom instanceof DotNode || $atom instanceof UnicodePropNode) {
            return true;
        }

        if (!$atom instanceof CharClassNode) {
            return false;
        }

        if (!$unicode) {
            return true;
        }

        if ($atom->isNegated) {
            return $atom->expression instanceof UnicodePropNode && false === self::anyProperty($atom->expression) ? true : null;
        }

        $every = CharSet::range(0, 0x10FFFF);
        $covered = CharSet::empty();
        $undecided = false;
        foreach ($atom->expression instanceof AlternationNode ? $atom->expression->alternatives : [$atom->expression] as $part) {
            if ($part instanceof UnicodePropNode && true === self::anyProperty($part)) {
                return true;
            }

            $characters = self::classCharacters($part, $every);
            if (null === $characters) {
                $undecided = true;
            } else {
                $covered = $covered->union($characters);
            }
        }

        // Below U+0100 PCRE2 reads every item into a bitmap, which the set
        // covering every character fills.
        $gap = CharSet::range(0x100, 0x10FFFF)->subtract($covered);
        if ($gap->isEmpty()) {
            return true;
        }

        if ($undecided) {
            return null;
        }

        // The other cases may fill a gap under i, never one among the
        // surrogates, which have none.
        return $caseless && $gap->intersect(CharSet::range(0xD800, 0xDFFF))->isEmpty() ? null : false;
    }

    /**
     * Whether the property is "\p{Any}", false when it is its negation, its
     * name read loosely as PCRE2 reads it: case, white space, "-" and "_"
     * aside, each "^" before the name negating it, the one "\P" brings
     * included; null for another property.
     */
    private static function anyProperty(UnicodePropNode $node): ?bool
    {
        $negated = false;
        $name = '';
        foreach (Bytes::split(trim($node->prop, '{}')) as $character) {
            if ('^' === $character && '' === $name) {
                $negated = !$negated;
            } elseif (!str_contains(" \t\n\v\f\r-_", $character)) {
                $name .= Ascii::lower($character);
            }
        }

        return 'any' === $name ? !$negated : null;
    }

    /**
     * The code points a part of a class under u covers as PCRE2 reads it;
     * nothing for a property, null for a part not read here.
     */
    private static function classCharacters(NodeInterface $part, CharSet $every): ?CharSet
    {
        if ($part instanceof RangeNode) {
            $from = LastCodeUnit::literalCharacter($part->start, true);
            $to = LastCodeUnit::literalCharacter($part->end, true);

            return null === $from || null === $to ? null : CharSet::range($from, $to);
        }

        $character = LastCodeUnit::literalCharacter($part, true);
        if (null !== $character) {
            return CharSet::single($character);
        }

        if ($part instanceof UnicodePropNode) {
            return CharSet::empty();
        }

        // "[:ascii:]" stays a range and "[:blank:]" is "\h"; the other POSIX
        // classes are properties.
        if ($part instanceof PosixClassNode) {
            return match ($part->class) {
                'ascii' => CharSet::range(0, 0x7F),
                '^ascii' => CharSet::range(0x80, 0x10FFFF),
                'blank' => CharSet::fromRanges(self::HORIZONTAL_SPACES),
                '^blank' => $every->subtract(CharSet::fromRanges(self::HORIZONTAL_SPACES)),
                default => CharSet::empty(),
            };
        }

        if (!$part instanceof CharTypeNode) {
            return null;
        }

        return match ($part->value) {
            'h' => CharSet::fromRanges(self::HORIZONTAL_SPACES),
            'H' => $every->subtract(CharSet::fromRanges(self::HORIZONTAL_SPACES)),
            'v' => CharSet::fromRanges(self::VERTICAL_SPACES),
            'V' => $every->subtract(CharSet::fromRanges(self::VERTICAL_SPACES)),
            // Unicode properties under u.
            'd', 'D', 's', 'S', 'w', 'W' => CharSet::empty(),
            default => null,
        };
    }

    /**
     * Whether the node is an option setting such as "(?i)", which holds up
     * to the end of the group around it.
     *
     * @phpstan-assert-if-true GroupNode $node
     */
    private static function isOptionSetting(NodeInterface $node): bool
    {
        return $node instanceof GroupNode && GroupType::InlineFlags === $node->type && $node->child instanceof LiteralNode && '' === $node->child->value;
    }

    /**
     * Whether m holds after the node: an option setting changes it up to the
     * end of the group around it, the branches after it included.
     */
    private static function multilineAfter(NodeInterface $node, bool $multiline): bool
    {
        if (self::isOptionSetting($node)) {
            return self::multilineUnder((string) $node->flags, $multiline);
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                $multiline = self::multilineAfter($child, $multiline);
            }
        }

        return $multiline;
    }

    /**
     * Whether m holds under the options: "^" takes it off, and its last
     * letter sets it, or takes it off after "-".
     */
    private static function multilineUnder(string $options, bool $multiline): bool
    {
        if (str_starts_with($options, '^')) {
            $multiline = false;
        }

        $letter = strrpos($options, 'm');
        if (false === $letter) {
            return $multiline;
        }

        $minus = strpos($options, '-');

        return false === $minus || $letter < $minus;
    }

    /**
     * Where the possessive or atomic repeats of any character read: PCRE2
     * moves such a repeat to the end of the subject in one step, so a run
     * read through it costs nothing. A run another loop reads is not
     * concerned.
     *
     * @param array<int, CharSet> $sets
     * @param array<int, true>    $jumps the offsets of their characters
     *
     * @param-out array<int, true> $jumps
     */
    private static function jumps(NodeInterface $node, array $sets, bool $unicode, bool $caseless, array &$jumps): void
    {
        $atomic = $node instanceof GroupNode && GroupType::Atomic === $node->type;
        foreach ($atomic ? self::branchRepeats($node->child) : [$node] as $repeat) {
            if (!$repeat instanceof QuantifierNode || (!$atomic && QuantifierType::Possessive !== $repeat->type)) {
                continue;
            }

            $bounds = $repeat->bounds;
            $offset = $repeat->node->getStartPosition();
            $set = $sets[$offset] ?? null;
            if (null !== $bounds && null === $bounds->max && null !== $set && CharSet::universe($unicode)->subtract($set)->isEmpty()
                && false !== self::isAnyCharacter($repeat->node, $unicode, $caseless)) {
                $jumps[$offset] = true;
            }
        }

        foreach ($node->getChildren() as $child) {
            self::jumps($child, $sets, $unicode, $caseless, $jumps);
        }
    }

    /**
     * The repeat each branch of an atomic body holds alone, "\K" and
     * comments aside: once it has read the repeat the branch commits, and
     * nothing backtracks into it. A body other than such branches is kept
     * with every way through it, so no witness crosses it before the replay.
     *
     * @return list<NodeInterface>
     */
    private static function branchRepeats(NodeInterface $body): array
    {
        $body = self::unwrap($body);
        $repeats = [];
        foreach ($body instanceof AlternationNode ? $body->alternatives : [$body] as $branch) {
            $items = array_values(array_filter(
                self::branchItems($branch),
                static fn (NodeInterface $item): bool => !$item instanceof KeepNode && !$item instanceof CommentNode && !($item instanceof LiteralNode && '' === $item->value),
            ));
            if (1 === \count($items)) {
                $repeats[] = self::unwrap($items[0]);
            }
        }

        return $repeats;
    }

    /**
     * The items of a branch, without the groups and sequences around them.
     *
     * @return list<NodeInterface>
     */
    private static function branchItems(NodeInterface $node): array
    {
        $node = self::unwrap($node);
        if (!$node instanceof SequenceNode) {
            return [$node];
        }

        $items = [];
        foreach ($node->children as $child) {
            array_push($items, ...self::branchItems($child));
        }

        return $items;
    }

    private static function unwrap(NodeInterface $node): NodeInterface
    {
        while ($node instanceof GroupNode && \in_array($node->type, [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::BranchReset], true)) {
            $node = $node->child;
        }

        return $node;
    }

    /**
     * The first run word that enters its own cycle and that no attempt it
     * starts succeeds on, after the shortest prefix that
     * keeps the first attempt from succeeding, with the breakers found after
     * it, in characters.
     *
     * @param array{int, ?int}|null $required     the code unit PCRE2 requires, as a character, and its other case
     *                                            when PCRE2 looks for either
     * @param CharSet|null          $lineStart    the characters a line starts after, when attempts start
     *                                            only at line starts; null when they start anywhere
     * @param array<int, true>      $jumps        where the repeats that jump to the end read
     * @param bool                  $finalNewline whether "$" or "\Z" may hold before a final newline
     * @param int                   $refused      the attempts that refuse an empty match
     * @param array<int, int>|null  $starts       the character of each class an attempt starts at; null
     *                                            when one starts anywhere
     *
     * @throws ModelLimitException
     *
     * @return array{prefix: list<int>, run: list<int>, breakers: non-empty-list<list<int>>}|null
     */
    private function witness(ItemAutomaton $automaton, Budget $budget, ?array $required, ?CharSet $lineStart, array $jumps, bool $finalNewline, int $refused, ?array $starts): ?array
    {
        $prefixes = [[]];
        for ($class = 0; $class < $automaton->classCount() && \count($prefixes) <= self::MAX_PREFIXES; $class++) {
            $prefixes[] = [$class];
        }

        foreach ($this->runWords($automaton, $budget, $jumps) as $word) {
            $last = $automaton->representatives[$word[\count($word) - 1]];
            if (null !== $lineStart && !$lineStart->contains($last)) {
                continue;
            }

            // The search starts no attempt at a run word whose first
            // character holds no first code unit.
            $later = self::later($automaton, $last, $refused);
            if ((null !== $starts && !isset($starts[$word[0]])) || !$this->entersItself($automaton, $budget, $later, $word, $jumps)) {
                continue;
            }

            $run = self::characters($automaton, $word, $starts);
            $missing = null === $required || self::holdsCodeUnit($run, ...$required) ? [] : [$required[0]];
            // The shortest prefix after which the attempt at the run's start
            // reads the run too, and no attempt succeeds inside it.
            foreach ($prefixes as $prefix) {
                $first = [] === $prefix
                    ? self::attempt($automaton, $automaton->initials[0]['items'], $refused, self::EMPTY_REFUSED_AT_START)
                    : self::later($automaton, $automaton->representatives[$prefix[\count($prefix) - 1]], $refused);
                $inside = $this->entersItself($automaton, $budget, $first, $word, $jumps) ? $this->runEnd($automaton, $prefix, $word, $refused) : null;
                if (null === $inside) {
                    continue;
                }

                $breakers = $this->breakers($automaton, $budget, $inside, $missing, $finalNewline ? $last : null);
                if ([] !== $breakers) {
                    return [
                        'prefix' => self::characters($automaton, $prefix, $starts),
                        'run' => $run,
                        'breakers' => $breakers,
                    ];
                }

                break;
            }
        }

        return null;
    }

    /**
     * The first run word on which each call of the loop of every match
     * reads to the end of the run before it ends on a short match, with the
     * breakers found after it, in characters, and whether the model read
     * each call as the engine does.
     *
     * The first call starts the subject, each other one where the match
     * before it ended, inside the run. Its first attempt tries its paths in
     * the order PCRE2 does, and ends on the last success it meets, after
     * at least one character and within MAX_MATCH_WORDS run words: the
     * paths it tries before that success all fail, and one of them reads
     * to the end of the run. A match that "\K" leaves empty makes PHP retry
     * where it ends, refusing an empty match there: as the attempt succeeds
     * no earlier than one character on, the retry ends on the same match.
     * The breakers stop the paths tried before each match, at the run's
     * end, without a success.
     *
     * @param array<int, true>     $jumps
     * @param bool                 $emptyRefused whether each call refuses an empty match at its start
     * @param array<int, int>|null $starts       the character of each class an attempt starts at; null
     *                                           when one starts anywhere
     * @param bool                 $searches     whether a call goes on to the next character when its
     *                                           attempt fails: not where it is tied to its start
     * @param bool                 $emptyToo     whether a call may end on an empty match, the loop
     *                                           going one byte on with no retry
     *
     * @throws ModelLimitException
     *
     * @return array{prefix: list<int>, run: list<int>, breakers: non-empty-list<list<int>>, exact: bool}|null
     */
    private function shortMatches(ItemAutomaton $automaton, Budget $budget, array $jumps, bool $finalNewline, bool $emptyRefused, ?array $starts, bool $searches, bool $emptyToo = false): ?array
    {
        foreach ($this->runWords($automaton, $budget, $jumps) as $word) {
            $exact = true;
            $after = $this->callsEnd($automaton, $budget, $word, $jumps, $emptyRefused, $starts, $searches, $emptyToo, $exact);
            if (null === $after) {
                continue;
            }

            $last = $automaton->representatives[$word[\count($word) - 1]];
            $breakers = $this->breakers($automaton, $budget, $after, [], $finalNewline ? $last : null);
            if ([] !== $breakers) {
                return [
                    'prefix' => [],
                    'run' => self::characters($automaton, $word, $starts),
                    'breakers' => $breakers,
                    'exact' => $exact,
                ];
            }
        }

        return null;
    }

    /**
     * The items the paths each call tries before its match hold at the end
     * of the run, whatever its length; null when a call matches nothing
     * short, or when those paths succeed or fail before the run's end. The
     * calls start at the phases of the run word their matches end at.
     *
     * The first call starts the subject: where its attempt fails inside
     * the run, or is not started, its search goes on with an attempt at
     * the next character, which starts no call, once for each character of
     * the run word at most. Every other call must match where it starts,
     * and one of them where a run word starts, as the attempts the engine
     * replay pins do: "\Bba(?:(?:ba)+\d)?" is read on a run of "ba", not
     * of "ab", whose calls all start on the "b".
     *
     * @param non-empty-list<int>  $word
     * @param array<int, true>     $jumps
     * @param array<int, int>|null $starts   the character of each class an attempt starts at; null when
     *                                       one starts anywhere
     * @param bool                 $searches whether a call goes on to the next character when its attempt
     *                                       fails
     * @param bool                 $emptyToo whether a call may end on an empty match: the next one then
     *                                       starts one byte on, which under u is inside a character of
     *                                       several
     * @param bool                 $exact    whether every path the calls went through is one the model
     *                                       reads as the engine does
     *
     * @param-out bool $exact
     *
     * @throws ModelLimitException
     *
     * @return array<int, true>|null
     */
    private function callsEnd(ItemAutomaton $automaton, Budget $budget, array $word, array $jumps, bool $emptyRefused, ?array $starts, bool $searches, bool $emptyToo, bool &$exact): ?array
    {
        $length = \count($word);
        $calls = [[0, $automaton->initials[0]['items']]];
        $started = [];
        $after = [];
        for ($index = 0; $index < \count($calls); $index++) {
            [$phase, $items] = $calls[$index];
            $match = self::attemptMatch($automaton, $budget, $items, $word, $phase, $emptyRefused, $starts, $emptyToo, $exact);
            for ($moved = 0; 0 === $index && $searches && false === $match && $moved < $length; $moved++) {
                $items = $automaton->laterStart(self::contextOf($automaton, $automaton->representatives[$word[$phase]]));
                $phase = ($phase + 1) % $length;
                $match = self::attemptMatch($automaton, $budget, $items, $word, $phase, $emptyRefused, $starts, $emptyToo, $exact);
                $started[0] = false;
            }

            if (!\is_array($match)) {
                return null;
            }

            // The paths tried before the match read the character after it.
            $end = ($phase + $match[0]) % $length;
            $runEnd = $this->readsToTheEnd($automaton, $budget, $match[1], $word, ($end + 1) % $length, $jumps);
            if (null === $runEnd) {
                return null;
            }

            $after += $runEnd;
            if (0 === $match[0]) {
                // The loop goes one byte on: inside the character, under u,
                // when that one is written with several.
                if ($automaton->pnfa->unicode && self::characters($automaton, [$word[$end]], $starts)[0] > 0x7F) {
                    return null;
                }

                $end = ($end + 1) % $length;
            }

            if (true !== ($started[$end] ?? false)) {
                $started[$end] = true;
                $before = $automaton->representatives[$word[($end + $length - 1) % $length]];
                $calls[] = [$end, $automaton->searchStart(self::contextOf($automaton, $before))];
            }
        }

        // No call matched where a run word starts: the first one went on
        // from there, and no match ended there.
        if (false === ($started[0] ?? true)) {
            return null;
        }

        ksort($after);

        return $after;
    }

    /**
     * The match of the attempt started with the items at the phase of the
     * run word, as shortMatch() gives it; false where the engine starts no
     * attempt, the character holding no first code unit.
     *
     * @param list<int>            $items
     * @param non-empty-list<int>  $word
     * @param array<int, int>|null $starts
     *
     * @param-out bool $exact
     *
     * @throws ModelLimitException
     *
     * @return array{int, array<int, true>}|false|null
     */
    private static function attemptMatch(ItemAutomaton $automaton, Budget $budget, array $items, array $word, int $phase, bool $emptyRefused, ?array $starts, bool $emptyToo, bool &$exact): array|false|null
    {
        return null !== $starts && !isset($starts[$word[$phase]]) ? false : self::shortMatch($automaton, $budget, $items, $word, $phase, $emptyRefused, $emptyToo, $exact);
    }

    /**
     * How many characters the match of an attempt reads, and the items of
     * the paths the attempt tries before that match once they read the
     * character after it. False when the attempt fails inside the run;
     * null when it reads on with no match, matches empty where the loop
     * retries after such a match, or reads more than MAX_MATCH_WORDS run
     * words. The items are kept in the order PCRE2
     * tries them, each where it comes first: a success ends the items
     * after it, which are never tried, and those before it go on. The
     * match is the last success met once they all fail, or come back to
     * where they were; when they come back to a success, the match reads
     * the run, and the paths before it succeed inside the run.
     *
     * @param list<int>           $items the attempt's items at its start, in that order
     * @param non-empty-list<int> $word
     *
     * @param-out bool $exact
     *
     * @throws ModelLimitException
     *
     * @return array{int, array<int, true>}|false|null
     */
    private static function shortMatch(ItemAutomaton $automaton, Budget $budget, array $items, array $word, int $phase, bool $emptyRefused, bool $emptyToo, bool &$exact): array|false|null
    {
        $length = \count($word);
        $current = [];
        foreach ($items as $item) {
            if (!$emptyRefused || !$automaton->isFinal($item)) {
                $current[$item] ??= true;
            }
        }

        $match = null;
        // Whether a path met so far, or one the match ends, may not be one
        // the engine takes.
        $widened = false;
        $matchWidened = false;
        $seen = [];
        for ($read = 0; [] !== $current; $read++) {
            $at = ($phase + $read) % $length;
            $key = $at.':'.implode(',', array_keys($current));
            if (isset($seen[$key])) {
                break;
            }

            $seen[$key] = true;
            $next = [];
            foreach ($current as $item => $_) {
                $budget->step();
                if ($automaton->isFinal($item)) {
                    if (!$automaton->accepts($item, $word[$at])) {
                        continue;
                    }

                    $match = [$read, $next];
                    $matchWidened = $widened;

                    break;
                }

                if (!$automaton->reads($item, $word[$at])) {
                    continue;
                }

                foreach ($automaton->successors[$item] as $index => $successor) {
                    // A further round of a bounded repeat read as unbounded,
                    // or a way through an atomic body kept whole, may not be
                    // one the engine takes.
                    if (0 !== ($automaton->edges[$item][$index] & (ItemAutomaton::EDGE_ABSTRACTED | ItemAutomaton::EDGE_WIDENED))) {
                        $widened = true;
                    }

                    $next[$successor] ??= true;
                }
            }

            $current = $next;
        }

        if (null === $match) {
            return [] === $current ? false : null;
        }

        if ((0 === $match[0] && !$emptyToo) || $match[0] > self::MAX_MATCH_WORDS * $length) {
            return null;
        }

        $exact = $exact && !$matchWidened;

        return $match;
    }

    /**
     * The items the paths a call tried before its match hold at the end of
     * the run, whatever its length, when one of them reads to it along the
     * ways a run may take and none succeeds first; null otherwise.
     *
     * @param array<int, true>    $tried the items of those paths, the next character at the phase
     * @param non-empty-list<int> $word
     * @param array<int, true>    $jumps
     *
     * @throws ModelLimitException
     *
     * @return array<int, true>|null
     */
    private function readsToTheEnd(ItemAutomaton $automaton, Budget $budget, array $tried, array $word, int $phase, array $jumps): ?array
    {
        ksort($tried);
        $length = \count($word);

        // No path succeeds inside the run: the items at the end of each run
        // word, from the phase on, until they repeat.
        $set = $tried;
        $seen = [];
        $after = [];
        for ($at = $phase; true; $at = ($at + 1) % $length) {
            if (0 === $at) {
                $key = implode(',', array_keys($set));
                if (isset($seen[$key])) {
                    break;
                }

                $seen[$key] = true;
                $after += $set;
            }

            $set = $automaton->stepSet($set, $word[$at]);
            if (null === $set) {
                return null;
            }
        }

        // One of them reads to the end of any run.
        $exactly = $tried;
        foreach (0 === $phase ? [] : \array_slice($word, $phase) as $class) {
            $exactly = self::stepExactly($automaton, $exactly, $class, $jumps);
            if (null === $exactly || [] === $exactly) {
                return null;
            }
        }

        return $this->entersItself($automaton, $budget, $exactly, $word, $jumps) ? $after : null;
    }

    /**
     * The words of the shortest cycle through each reading item, as
     * classes: one class repeated when it fits every step, else each step's
     * first class, then each step's second, and so on; then words of up to
     * four characters that mix classes, so that a word boundary holds
     * inside the run: two classes the loops read, characters of different
     * kinds first, and a two-step cycle read twice with two choices. A
     * cycle goes through no repeat that jumps to the end and back into no
     * bounded repeat the model reads as unbounded. Whether the attempts
     * read a word again and again is entersItself()'s to say.
     *
     * @param array<int, true> $jumps
     *
     * @throws ModelLimitException
     *
     * @return list<non-empty-list<int>>
     */
    private function runWords(ItemAutomaton $automaton, Budget $budget, array $jumps): array
    {
        // The ways a run may go, read once; a cycle stays in the strongly
        // connected component of its items.
        $graph = [];
        for ($item = 0; $item < $automaton->count(); $item++) {
            if (!$automaton->isFinal($item) && !isset($jumps[$automaton->offsetOf($item)])) {
                $budget->step();
                $graph[$item] = array_values(array_filter(self::exactSuccessors($automaton, $item, $jumps), static fn (int $next): bool => !$automaton->isFinal($next)));
            }
        }

        $components = StronglyConnectedComponents::of($graph, $budget)->componentOf;
        $sizes = array_count_values($components);
        // Mixing classes matters only where an attempt's start depends on
        // the character before it: the classes any item on a cycle reads.
        $kinds = null !== $automaton->pnfa->wordSet || $automaton->pnfa->hasLineStart;
        $words = [];
        $mixed = [];
        $looped = [];
        foreach ($graph as $item => $successors) {
            if (1 === $sizes[$components[$item]] && !\in_array($item, $successors, true)) {
                continue;
            }

            foreach ($kinds ? self::choices($automaton, [$automaton->masks[$item]]) : [] as $classes) {
                $looped += array_fill_keys($classes, true);
            }

            if (\count($words) >= self::MAX_WORDS) {
                continue;
            }

            $labels = $this->cycle($automaton, $budget, $item, $graph, $components);
            if (null === $labels) {
                continue;
            }

            foreach (self::wordsOf($automaton, $labels) as $word) {
                $words[implode(',', $word)] ??= $word;
            }

            foreach (self::mixedWordsOf($automaton, $labels) as $word) {
                $mixed[implode(',', $word)] ??= $word;
            }
        }

        $pairs = [];
        foreach (self::pairsOf($automaton, array_keys($looped), $budget) as $word) {
            $pairs[implode(',', $word)] ??= $word;
        }

        return \array_slice([...array_values($words), ...array_values(array_diff_key($pairs + $mixed, $words))], 0, self::MAX_WORDS);
    }

    /**
     * Two-character words of two different classes: a word character then
     * another kind first, then two kinds, then the rest.
     *
     * @param list<int> $classes
     *
     * @throws ModelLimitException
     *
     * @return list<non-empty-list<int>>
     */
    private static function pairsOf(ItemAutomaton $automaton, array $classes, Budget $budget): array
    {
        sort($classes);
        $contexts = [];
        foreach ($classes as $class) {
            $budget->step();
            $contexts[$class] = self::contextOf($automaton, $automaton->representatives[$class]);
        }

        // Each context shares its ordered second choices. Build these linear
        // lists once, rather than storing the Cartesian product of classes.
        $seconds = [];
        foreach (array_unique($contexts) as $context) {
            foreach ($contexts as $class => $other) {
                $budget->step();
                $rank = match (true) {
                    ItemAutomaton::CONTEXT_WORD === $context && $context !== $other => 0,
                    $context !== $other => 1,
                    default => 2,
                };
                $seconds[$rank][$context][] = $class;
            }
        }

        $pairs = [];
        for ($rank = 0; $rank < 3; $rank++) {
            foreach ($contexts as $first => $context) {
                $budget->step();
                foreach ($seconds[$rank][$context] ?? [] as $second) {
                    $budget->step();
                    if ($first === $second) {
                        continue;
                    }

                    $pairs[] = [$first, $second];
                    if (\count($pairs) >= self::MAX_MIXED_WORDS) {
                        return $pairs;
                    }
                }
            }
        }

        return $pairs;
    }

    /**
     * The labels of the shortest cycle from the item back to itself, the
     * item's own first, inside its component.
     *
     * @param array<int, list<int>> $graph
     * @param array<int, int>       $components
     *
     * @throws ModelLimitException
     *
     * @return non-empty-list<string>|null
     */
    private function cycle(ItemAutomaton $automaton, Budget $budget, int $item, array $graph, array $components): ?array
    {
        $parents = [$item => $item];
        $queue = [$item];
        for ($head = 0; $head < \count($queue); $head++) {
            $budget->step();
            $current = $queue[$head];
            foreach ($graph[$current] as $next) {
                if ($components[$next] !== $components[$item]) {
                    continue;
                }

                if ($next === $item) {
                    $path = [];
                    for ($at = $current; $at !== $item; $at = $parents[$at]) {
                        $path[] = $automaton->masks[$at];
                    }

                    return [$automaton->masks[$item], ...array_reverse($path)];
                }

                if (!isset($parents[$next])) {
                    $parents[$next] = $current;
                    $queue[] = $next;
                }
            }
        }

        return null;
    }

    /**
     * The successors of a reading item a run may go on to: not back into a
     * bounded repeat read as unbounded, not into a repeat that jumps to the
     * end.
     *
     * @param array<int, true> $jumps
     *
     * @return list<int>
     */
    private static function exactSuccessors(ItemAutomaton $automaton, int $item, array $jumps): array
    {
        $successors = [];
        foreach ($automaton->successors[$item] as $index => $next) {
            if (0 !== ($automaton->edges[$item][$index] & ItemAutomaton::EDGE_ABSTRACTED)
                || (!$automaton->isFinal($next) && isset($jumps[$automaton->offsetOf($next)]))) {
                continue;
            }

            $successors[] = $next;
        }

        return $successors;
    }

    /**
     * @param non-empty-list<string> $labels
     *
     * @return list<non-empty-list<int>>
     */
    private static function wordsOf(ItemAutomaton $automaton, array $labels): array
    {
        $common = $labels[0];
        foreach ($labels as $label) {
            $common &= $label;
        }

        $words = [];
        if (!ItemAutomaton::isEmpty($common)) {
            for ($class = 0; $class < $automaton->classCount() && \count($words) < self::MAX_WORDS_PER_CYCLE; $class++) {
                if (ItemAutomaton::has($common, $class)) {
                    $words[] = array_fill(0, \count($labels), $class);
                }
            }

            return $words;
        }

        $choices = self::choices($automaton, $labels);
        for ($rank = 0; \count($words) < self::MAX_WORDS_PER_CYCLE; $rank++) {
            $word = [];
            $fresh = false;
            foreach ($choices as $classes) {
                $word[] = $classes[min($rank, \count($classes) - 1)];
                $fresh = $fresh || $rank < \count($classes);
            }

            if (!$fresh || [] === $word) {
                break;
            }

            $words[] = $word;
        }

        return $words;
    }

    /**
     * Words of a two-step cycle read twice, four characters, whose two
     * halves differ.
     *
     * @param non-empty-list<string> $labels
     *
     * @return list<non-empty-list<int>>
     */
    private static function mixedWordsOf(ItemAutomaton $automaton, array $labels): array
    {
        if (2 !== \count($labels)) {
            return [];
        }

        $choices = self::choices($automaton, $labels);
        if (2 !== \count($choices)) {
            return [];
        }

        $halves = [];
        foreach ($choices[0] as $first) {
            foreach ($choices[1] as $second) {
                $halves[] = [$first, $second];
            }
        }

        $halves = \array_slice($halves, 0, self::MAX_MIXED_WORDS);
        $words = [];
        foreach ($halves as $first) {
            foreach ($halves as $second) {
                if ($first !== $second && \count($words) < self::MAX_MIXED_WORDS) {
                    $words[] = [...$first, ...$second];
                }
            }
        }

        return $words;
    }

    /**
     * The classes each label reads, in order.
     *
     * @param non-empty-list<string> $labels
     *
     * @return list<non-empty-list<int>>
     */
    private static function choices(ItemAutomaton $automaton, array $labels): array
    {
        $choices = [];
        foreach ($labels as $label) {
            $classes = [];
            for ($class = 0; $class < $automaton->classCount(); $class++) {
                if (ItemAutomaton::has($label, $class)) {
                    $classes[] = $class;
                }
            }

            if ([] !== $classes) {
                $choices[] = $classes;
            }
        }

        return $choices;
    }

    /**
     * Whether an attempt started with the items reads the run word again
     * and again without ever failing nor succeeding: it reads to the end of
     * any run. It reads along the ways a run may take: not back into a
     * bounded repeat read as unbounded, not through a repeat that jumps to
     * the end.
     *
     * @param array<int, true>    $current the attempt's items at its start
     * @param non-empty-list<int> $word
     * @param array<int, true>    $jumps
     *
     * @throws ModelLimitException
     */
    private function entersItself(ItemAutomaton $automaton, Budget $budget, array $current, array $word, array $jumps): bool
    {
        // The sets are finite: they repeat, or the budget stops the search.
        $seen = [];
        while (true) {
            $key = implode(',', array_keys($current));
            if (isset($seen[$key])) {
                return true;
            }

            $seen[$key] = true;
            $budget->step(1 + \count($current));
            foreach ($word as $class) {
                $current = self::stepExactly($automaton, $current, $class, $jumps);
                if (null === $current || [] === $current) {
                    return false;
                }
            }
        }
    }

    /**
     * The items after the set reads the class along the ways a run may
     * take, or null when one of its final items succeeds before it.
     *
     * @param array<int, true> $items
     * @param array<int, true> $jumps
     *
     * @throws ModelLimitException
     *
     * @return array<int, true>|null
     */
    private static function stepExactly(ItemAutomaton $automaton, array $items, int $class, array $jumps): ?array
    {
        $next = [];
        foreach ($items as $item => $_) {
            if ($automaton->isFinal($item)) {
                if ($automaton->accepts($item, $class)) {
                    return null;
                }

                continue;
            }

            if ($automaton->reads($item, $class)) {
                foreach (self::exactSuccessors($automaton, $item, $jumps) as $successor) {
                    $next[$successor] = true;
                }
            }
        }

        ksort($next);

        return $next;
    }

    /**
     * The items of every attempt of the search at the end of the run, an
     * attempt started at each character of the prefix and of the run, the
     * one started at its end aside, whatever the run's length (one word at
     * least); null when one of them succeeds before the run ends.
     *
     * @param list<int>           $prefix  classes
     * @param non-empty-list<int> $word
     * @param int                 $refused the attempts that refuse an empty match
     *
     * @throws ModelLimitException
     *
     * @return array<int, true>|null
     */
    private function runEnd(ItemAutomaton $automaton, array $prefix, array $word, int $refused): ?array
    {
        $later = [];
        foreach ($word as $index => $class) {
            $later[$index + 1 === \count($word) ? 0 : $index + 1] = self::later($automaton, $automaton->representatives[$class], $refused);
        }

        $current = self::attempt($automaton, $automaton->initials[0]['items'], $refused, self::EMPTY_REFUSED_AT_START);
        foreach ($prefix as $class) {
            $current = $automaton->stepSet($current, $class);
            if (null === $current) {
                return null;
            }
        }

        if ([] !== $prefix) {
            $current += self::later($automaton, $automaton->representatives[$prefix[\count($prefix) - 1]], $refused);
        }

        $seen = [];
        $inside = [];
        while (true) {
            foreach ($word as $index => $class) {
                if ($index > 0) {
                    $current += $later[$index];
                }

                $current = $automaton->stepSet($current, $class);
                if (null === $current) {
                    return null;
                }
            }

            // The run ends here at this length: the attempt it starts there
            // is not yet one of those it started.
            $inside += $current;
            $current += $later[0];
            ksort($current);
            $key = implode(',', array_keys($current));
            if (isset($seen[$key])) {
                ksort($inside);

                return $inside;
            }

            $seen[$key] = true;
        }
    }

    /**
     * The breakers, shortest first: characters after which every attempt of
     * the run has failed, then the code unit PCRE2 requires when the run
     * does not hold it, and one more character when an attempt of the run
     * would match that code unit at the end. An attempt the breaker starts
     * may match it, as the second alternative of "/a+b|b$/" matches the "b"
     * of "!b", or the empty string at the subject's end, as "$" does: the
     * search tries those attempts after every attempt of the run. When "$"
     * or "\Z" may hold before a final newline, a witness ending with a
     * newline gets one more character: the model reads such a newline into
     * the anchor, where a pattern item may read it instead.
     *
     * @param array<int, true> $inside  the items of the attempts the run started, at its end
     * @param list<int>        $missing characters
     * @param int|null         $runEnd  the run's last character, when the witness must not end with a newline
     *
     * @throws ModelLimitException
     *
     * @return list<list<int>>
     */
    private function breakers(ItemAutomaton $automaton, Budget $budget, array $inside, array $missing, ?int $runEnd): array
    {
        $found = [];
        // The code unit with a character after it only when it fails last.
        foreach ([] === $missing ? [false] : [false, true] as $trailing) {
            $queue = [[$inside, []]];
            $seen = [implode(',', array_keys($inside)) => true];
            for ($head = 0; $head < \count($queue) && $head < self::MAX_BREAKER_NODES && \count($found) < self::MAX_BREAKERS; $head++) {
                $budget->step();
                [$current, $path] = $queue[$head];
                $this->collect($automaton, $inside, $path, $missing, $runEnd, $trailing, $found);
                if (\count($path) >= self::MAX_BREAKER_LENGTH) {
                    continue;
                }

                for ($class = 0; $class < $automaton->classCount() && \count($found) < self::MAX_BREAKERS; $class++) {
                    $next = $automaton->stepSet($current, $class);
                    if (null === $next) {
                        continue;
                    }

                    if ([] === $next) {
                        $this->collect($automaton, $inside, [...$path, $class], $missing, $runEnd, $trailing, $found);

                        continue;
                    }

                    $key = implode(',', array_keys($next));
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $queue[] = [$next, [...$path, $class]];
                    }
                }
            }

            if ([] !== $found) {
                break;
            }
        }

        return $found;
    }

    /**
     * Keeps the breaker the path and the missing code unit write when the
     * model rejects it; trailing, the first of a few with one more
     * character the model rejects.
     *
     * @param array<int, true> $inside   the items of the attempts the run started, at its end
     * @param list<int>        $path     classes
     * @param list<int>        $missing  characters
     * @param bool             $trailing whether a character comes after the missing code unit
     * @param list<list<int>>  $found
     *
     * @param-out list<list<int>> $found
     *
     * @throws ModelLimitException
     */
    private function collect(ItemAutomaton $automaton, array $inside, array $path, array $missing, ?int $runEnd, bool $trailing, array &$found): void
    {
        $breaker = [...array_map(static fn (int $class): int => $automaton->representatives[$class], $path), ...$missing];
        $newline = null !== $runEnd && 0x0A === ([] === $breaker ? $runEnd : $breaker[\count($breaker) - 1]);
        $candidates = $newline || $trailing ? [] : [$breaker];
        foreach ($newline || $trailing ? $automaton->representatives : [] as $character) {
            if ((null === $runEnd || 0x0A !== $character) && (!$trailing || \count($candidates) < self::MAX_BREAKERS)) {
                $candidates[] = [...$breaker, $character];
            }
        }

        foreach ($candidates as $candidate) {
            if (\in_array($candidate, $found, true)) {
                return;
            }

            if ($this->rejects($automaton, $inside, $candidate)) {
                $found[] = $candidate;

                return;
            }
        }
    }

    /**
     * Whether every attempt of the run fails on the breaker. The attempts
     * the breaker starts, and the one at the subject's end, are tried after
     * them, once each has read to the end of the run: they may succeed.
     *
     * @param array<int, true> $inside  the items of the attempts the run started, at its end
     * @param list<int>        $breaker characters
     *
     * @throws ModelLimitException
     */
    private function rejects(ItemAutomaton $automaton, array $inside, array $breaker): bool
    {
        $current = $inside;
        foreach ($breaker as $character) {
            $current = $automaton->stepSet($current, $automaton->classOf($character));
            if (null === $current) {
                return false;
            }
        }

        return !$automaton->acceptsAtEnd($current);
    }

    /**
     * The context the character leaves for an attempt started after it.
     */
    private static function contextOf(ItemAutomaton $automaton, int $character): int
    {
        $pnfa = $automaton->pnfa;

        return match (true) {
            null !== $pnfa->wordSet && $pnfa->wordSet->contains($character) => ItemAutomaton::CONTEXT_WORD,
            $pnfa->hasLineStart && 0x0A === $character => ItemAutomaton::CONTEXT_NEWLINE,
            default => ItemAutomaton::CONTEXT_OTHER,
        };
    }

    /**
     * The items of an attempt started after the character, as a set: a
     * later attempt of the search, or the start of a call of its own in the
     * loop of every match.
     *
     * @param int $refused the attempts that refuse an empty match, and whether each starts a call
     *
     * @throws ModelLimitException
     *
     * @return array<int, true>
     */
    private static function later(ItemAutomaton $automaton, int $character, int $refused): array
    {
        $context = self::contextOf($automaton, $character);
        $items = 0 === ($refused & self::EVERY_CALL) ? $automaton->laterStart($context) : $automaton->searchStart($context);

        return self::attempt($automaton, $items, $refused, self::EMPTY_REFUSED_LATER);
    }

    /**
     * Whether an attempt started inside the run, after the run character
     * before it, may succeed on the empty string before the run character
     * after it, as a call of the loop of every match starts it: an empty
     * match at the subject's end alone does not stop preg_match() before
     * the run.
     *
     * @param list<int> $run characters
     */
    private static function matchesEmptyInside(ItemAutomaton $automaton, array $run): bool
    {
        foreach ($run as $index => $character) {
            $before = $run[(0 === $index ? \count($run) : $index) - 1];
            if (self::matchesEmptyBefore($automaton, $automaton->searchStart(self::contextOf($automaton, $before)), $character)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an attempt started with the items may succeed on the empty
     * string before the character.
     *
     * @param list<int> $items
     */
    private static function matchesEmptyBefore(ItemAutomaton $automaton, array $items, int $character): bool
    {
        $class = $automaton->classOf($character);
        foreach ($items as $item) {
            if ($automaton->isFinal($item) && $automaton->accepts($item, $class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The items an attempt starts with, as a set; without the final ones
     * when the attempt is among those that refuse an empty match: they
     * succeed before it reads anything.
     *
     * @param list<int> $items
     * @param int       $refused the attempts that refuse an empty match
     * @param int       $which   the attempt: at the search start, or a later one
     *
     * @return array<int, true>
     */
    private static function attempt(ItemAutomaton $automaton, array $items, int $refused, int $which): array
    {
        $set = self::set($items);
        if (0 === ($refused & $which)) {
            return $set;
        }

        foreach ($set as $item => $_) {
            if ($automaton->isFinal($item)) {
                unset($set[$item]);
            }
        }

        return $set;
    }

    /**
     * @param list<int> $items
     *
     * @return array<int, true>
     */
    private static function set(array $items): array
    {
        $set = array_fill_keys($items, true);
        ksort($set);

        return $set;
    }
}
