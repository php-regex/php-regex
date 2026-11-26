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

use PHPRegex\Parser\Engine\NoJit;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Engine\PcreLimits;
use PHPRegex\Parser\Engine\PcreMatch;
use PHPRegex\Redos\ConfirmationOptions;

/**
 * Replays a search-cost witness on the running engine, in steps rather than
 * time. pcre.backtrack_limit is PCRE2's match limit, counted afresh at each
 * start position of a search: the smallest limit under which one attempt
 * finishes is that attempt's step count. The attempt is pinned to an offset
 * with the "A" modifier and run in the interpreter, "(*NO_JIT)", with
 * "(*NO_AUTO_POSSESS)": PCRE2 makes most single loops possessive, which reads
 * the same characters but counts none of them.
 *
 * The witness replays when the search on it matches nowhere before the
 * breaker, and an attempt started further from the breaker takes at least
 * one more step per run word; the one of short matches, when each such
 * attempt matches a little past its start instead. The counter sees nothing
 * inside an atomic or possessive repeat of a single character set ("a++",
 * "(?>a+)"), and counts a repeat of a group, "(?:a)++" or "(?>a+b)+", at
 * each pass: the search proof hands over the pattern with each such
 * character in a group of its own, which changes no match. A pattern that
 * leaves no delimiter for the counting verbs does not replay, whatever it
 * costs. The subject holds 256 run words: a bounded repeat above that would
 * look unbounded to it, so the prover never brings a run read through a
 * bounded repeat it reads as unbounded.
 *
 * The JIT is not measured: under it some pattern and subject pairs crash PHP
 * (PCRE2 10.40 to 10.49), so every run goes through the engine, which turns
 * it off.
 *
 * @internal
 */
final class SearchCostReplayer implements SearchCostReplayerInterface
{
    /**
     * The run words of the subject the steps are counted on.
     */
    private const WORDS = 256;

    private const MAX_STEPS = 10_000_000;

    /**
     * The most run words a short match reads, as the search-cost proof
     * bounds it.
     */
    private const MAX_MATCH_WORDS = 4;

    private const NO_AUTO_POSSESS = '(*NO_AUTO_POSSESS)';

    private const NOTEMPTY_AT_START = '(*NOTEMPTY_ATSTART)';

    /**
     * Whether the engine, without its JIT, starts attempts inside the run
     * that each read what is left of it, and matches nowhere before the
     * breaker; null when the engine cannot count: the limits of the
     * options stop the search or a pinned attempt before it ends, or
     * ini_set() cannot set them.
     */
    #[\Override]
    public function replays(string $pattern, string $prefix, string $run, string $breaker, ?ConfirmationOptions $options = null): ?bool
    {
        $options ??= new ConfirmationOptions();
        $counted = self::counted($pattern);
        if (null === $counted) {
            return false;
        }

        // A search that matches the witness ends there, before the attempts
        // the run starts after that match. A match that starts in the
        // breaker or at the subject's end comes after every one of them,
        // when an attempt started there made it: "\K" may report there one
        // an earlier attempt made, so a pattern holding it matches nowhere.
        $subject = $prefix.str_repeat($run, self::WORDS).$breaker;
        $search = self::counts($counted, $subject, 0, self::MAX_STEPS, $options, \PREG_OFFSET_CAPTURE);
        if (null === $search->matched) {
            return null;
        }
        $found = $search->groups[0] ?? null;
        if ($search->matched && (str_contains($pattern, '\K') || !\is_array($found) || $found[1] < \strlen($subject) - \strlen($breaker))) {
            return false;
        }

        $quarter = intdiv(self::WORDS, 4);
        $far = self::steps($counted.'A', $subject, \strlen($prefix) + $quarter * \strlen($run), $options);
        $near = self::steps($counted.'A', $subject, \strlen($prefix) + 2 * $quarter * \strlen($run), $options);
        if (null === $far || null === $near) {
            return null;
        }

        return $far - $near >= $quarter;
    }

    /**
     * Whether the engine, without its JIT, stops the search started a
     * quarter and half-way through the run on an empty match within one run
     * word, as each search of the loop of preg_match_all() and its kin then
     * does, and whether the retry PHP makes there, pinned and refusing an
     * empty match at its start (PCRE2_NOTEMPTY_ATSTART | PCRE2_ANCHORED),
     * fails and takes one more step at least per run word left; null when
     * the engine cannot count. The loop calls the engine afresh at each
     * offset, so "\G" holds where the replay pins an attempt, as it does
     * there.
     */
    #[\Override]
    public function replaysEveryMatch(string $pattern, string $prefix, string $run, string $breaker, ?ConfirmationOptions $options = null): ?bool
    {
        $options ??= new ConfirmationOptions();
        $counted = self::counted($pattern);
        if (null === $counted) {
            return false;
        }

        $subject = $prefix.str_repeat($run, self::WORDS).$breaker;
        $quarter = intdiv(self::WORDS, 4);
        $retry = substr_replace($counted, self::NOTEMPTY_AT_START, 1, 0).'A';
        $steps = [];
        foreach ([1, 2] as $quarters) {
            $start = \strlen($prefix) + $quarters * $quarter * \strlen($run);
            $search = self::counts($counted, $subject, $start, self::MAX_STEPS, $options, \PREG_OFFSET_CAPTURE);
            if (null === $search->matched) {
                return null;
            }

            // The loop moves on past a match that reads the run; the empty
            // match may stand one character on, where a pattern refusing it
            // at the start of each call puts it.
            $found = $search->groups[0] ?? null;
            if (!$search->matched || !\is_array($found) || '' !== $found[0] || $found[1] > $start + \strlen($run)) {
                return false;
            }

            // A retry that matches moves the loop on; one the limits stop
            // cannot be counted either.
            if (true === self::counts($retry, $subject, $found[1], self::MAX_STEPS, $options)->matched) {
                return false;
            }

            $step = self::steps($retry, $subject, $found[1], $options);
            if (null === $step) {
                return null;
            }

            $steps[] = $step;
        }

        return $steps[0] - $steps[1] >= $quarter;
    }

    /**
     * Whether the engine, without its JIT, ends the attempt pinned a quarter
     * and half-way through the run on a match past its start within
     * MAX_MATCH_WORDS run words, as the call of the loop of
     * preg_match_all() and its kin started there does, and whether the
     * attempt takes one more step at least per run word left; null when the
     * engine cannot count. A match that "\K" leaves empty makes PHP retry
     * where it ends, refusing an empty match there: that retry must not
     * read past the same bound, so that the loop goes on inside the run.
     */
    #[\Override]
    public function replaysShortMatches(string $pattern, string $prefix, string $run, string $breaker, ?ConfirmationOptions $options = null): ?bool
    {
        $options ??= new ConfirmationOptions();
        $counted = self::counted($pattern);
        if (null === $counted) {
            return false;
        }

        $subject = $prefix.str_repeat($run, self::WORDS).$breaker;
        $quarter = intdiv(self::WORDS, 4);
        $pinned = $counted.'A';
        $retry = substr_replace($counted, self::NOTEMPTY_AT_START, 1, 0).'A';
        $bound = self::MAX_MATCH_WORDS * \strlen($run);
        $steps = [];
        foreach ([1, 2] as $quarters) {
            $start = \strlen($prefix) + $quarters * $quarter * \strlen($run);
            $step = self::steps($pinned, $subject, $start, $options);
            if (null === $step) {
                return null;
            }

            // Counted, the attempt finishes under the same limits: it
            // matches or fails.
            $match = self::matchFrom($pinned, $subject, $start, $options);
            if (!\is_array($match) || $match[0] <= $start || $match[0] > $start + $bound) {
                return false;
            }

            // The loop retries after a match "\K" leaves empty; one the
            // limits stop cannot be counted either.
            if ($match[1]) {
                $retried = self::matchFrom($retry, $subject, $match[0], $options);
                if (null === $retried) {
                    return null;
                }

                if (false !== $retried && $retried[0] > $match[0] + $bound) {
                    return false;
                }
            }

            $steps[] = $step;
        }

        return $steps[0] - $steps[1] >= $quarter;
    }

    /**
     * Where the match of the pattern from the offset ends, and whether it
     * is empty, as "\K" may leave one that reads a character; false when
     * there is none, null when the limits of the options stop the engine.
     *
     * @return array{int, bool}|false|null
     */
    private static function matchFrom(string $pattern, string $subject, int $offset, ConfirmationOptions $options): array|false|null
    {
        $found = self::counts($pattern, $subject, $offset, self::MAX_STEPS, $options, \PREG_OFFSET_CAPTURE);
        if (null === $found->matched) {
            return null;
        }

        $whole = $found->groups[0] ?? null;
        if (!\is_array($whole)) {
            return false;
        }

        $text = (string) $whole[0];

        return [$whole[1] + \strlen($text), '' === $text];
    }

    /**
     * The steps of the attempt pinned at the offset, one of the attempts of
     * the search that finished; null when it does not finish under the
     * limits of the options, which PCRE2 counts per attempt.
     */
    private static function steps(string $pinned, string $subject, int $offset, ConfirmationOptions $options): ?int
    {
        $low = 1;
        $high = min(self::MAX_STEPS, $options->backtrackLimit);
        if (null === self::counts($pinned, $subject, $offset, $high, $options)->matched) {
            return null;
        }

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if (null === self::counts($pinned, $subject, $offset, $middle, $options)->matched) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    /**
     * The pattern counted in the interpreter: "(*NO_JIT)(*NO_AUTO_POSSESS)"
     * after the opening delimiter, where the engine puts the first; null when
     * no delimiter is left for them.
     */
    private static function counted(string $pattern): ?string
    {
        $prepared = (new PcreEngine())->prepare($pattern);
        if (NoJit::VERB !== substr($prepared, 1, \strlen(NoJit::VERB))) {
            return null;
        }

        return substr_replace($prepared, NoJit::VERB.self::NO_AUTO_POSSESS, 1, \strlen(NoJit::VERB));
    }

    /**
     * The engine's answer for the attempts from the offset, under the step
     * limit capped by the caller's policy and its recursion limit. The engine
     * leaves the ini as it found it and keeps a warning from the output.
     *
     * @param 0|256|512|768 $flags the preg_match() flags: PREG_OFFSET_CAPTURE for where a match stands
     */
    private static function counts(string $counted, string $subject, int $offset, int $limit, ConfirmationOptions $options, int $flags = 0): PcreMatch
    {
        $limits = new PcreLimits(min($limit, $options->backtrackLimit), $options->recursionLimit);

        return (new PcreEngine())->match($counted, $subject, $flags, $offset, $limits);
    }
}
