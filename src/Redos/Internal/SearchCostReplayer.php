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

namespace PHPRegex\Redos\Internal;

use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Engine\PcreLimits;
use PHPRegex\Parser\Engine\PcreMatch;

/**
 * Replays a search-cost witness on the running engine, in steps rather than
 * time. pcre.backtrack_limit is PCRE2's match limit, counted afresh at each
 * start position of a search: the smallest limit under which one attempt
 * finishes is that attempt's step count. The attempt is pinned to an offset
 * with the "A" modifier and run in the interpreter, "(*NO_JIT)", with
 * "(*NO_AUTO_POSSESS)": PCRE2 makes most single loops possessive, which reads
 * the same characters but counts none of them.
 *
 * The witness replays when the search on it matches nowhere and an attempt
 * started further from the breaker takes at least one more step per run
 * word. The counter sees nothing inside an atomic or possessive repeat of a
 * single character set ("a++", "(?>a+)"): such a witness does not replay,
 * whatever it costs, and neither does a pattern that leaves no delimiter
 * for the counting verbs. A repeat of a longer word, "(?:ab)++" or
 * "(?>a+b)+", is counted. The subject holds 256 run words: a bounded repeat
 * above that would look unbounded to it, so the prover never brings a run
 * read through a bounded repeat it reads as unbounded.
 *
 * The JIT is not measured: under it some pattern and subject pairs crash PHP
 * (PCRE2 10.40 to 10.49), so every run goes through the engine, which turns
 * it off.
 *
 * Not final: a test replaces the replay to make it fail, as any engine
 * failure would; the prover catches the library's exceptions it throws.
 *
 * @internal
 */
class SearchCostReplayer
{
    /**
     * The run words of the subject the steps are counted on.
     */
    private const WORDS = 256;

    private const MAX_STEPS = 10_000_000;

    private const NO_JIT = '(*NO_JIT)';

    private const NO_AUTO_POSSESS = '(*NO_AUTO_POSSESS)';

    /**
     * Whether the engine, without its JIT, starts attempts inside the run
     * that each read what is left of it, and matches nowhere.
     */
    public function replays(string $pattern, string $prefix, string $run, string $breaker): bool
    {
        $counted = self::counted($pattern);
        if (null === $counted) {
            return false;
        }

        // A search that matches the witness fails none of its attempts there.
        $subject = $prefix.str_repeat($run, self::WORDS).$breaker;
        if (false !== self::counts($counted, $subject, 0, self::MAX_STEPS)->matched) {
            return false;
        }

        $quarter = intdiv(self::WORDS, 4);
        $far = self::steps($counted.'A', $subject, \strlen($prefix) + $quarter * \strlen($run));
        $near = self::steps($counted.'A', $subject, \strlen($prefix) + 2 * $quarter * \strlen($run));

        return $far - $near >= $quarter;
    }

    /**
     * The steps of the attempt pinned at the offset: one of the attempts of
     * a search that finished within the most steps, so it finishes too.
     */
    private static function steps(string $pinned, string $subject, int $offset): int
    {
        $low = 1;
        $high = self::MAX_STEPS;
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if (null === self::counts($pinned, $subject, $offset, $middle)->matched) {
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
        if (self::NO_JIT !== substr($prepared, 1, \strlen(self::NO_JIT))) {
            return null;
        }

        return substr_replace($prepared, self::NO_JIT.self::NO_AUTO_POSSESS, 1, \strlen(self::NO_JIT));
    }

    /**
     * The engine's answer for the attempts from the offset, under the step
     * limit and the recursion limit the ini sets; the engine leaves the ini
     * as it found it and keeps a warning from the output.
     */
    private static function counts(string $counted, string $subject, int $offset, int $limit): PcreMatch
    {
        $limits = new PcreLimits($limit, (int) \ini_get('pcre.recursion_limit'));

        return (new PcreEngine())->match($counted, $subject, $limits, $offset);
    }
}
