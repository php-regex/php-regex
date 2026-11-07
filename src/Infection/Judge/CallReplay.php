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

namespace PHPRegex\Infection\Judge;

use PHPRegex\Infection\Site\PatternCall;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Engine\PcreLimits;

/**
 * Runs the call as it is written on a subject, once with each pattern, on
 * the running engine: a subject is a kill string only when both runs
 * answer and the answers differ. The arguments the call writes as literals
 * are passed as written; a call whose offset, flags, limit or replacement
 * is only known at run time cannot be replayed.
 *
 * preg_replace_callback() is replayed as the matches it hands its callback,
 * which is all a test can tell apart without the callback; preg_grep() as
 * the one-element array it keeps.
 *
 * The runs are the user's patterns, never the library's own: the engine
 * runs them, without the JIT, under backtracking and recursion limits of
 * the replay's own, a warning kept from the error log, and the engine's
 * last error is cleared behind them.
 *
 * @internal
 */
final class CallReplay
{
    /**
     * The limits a replay runs under: low enough that a pattern that
     * backtracks without end gives up at once, and counted in steps, so the
     * answer is the same on every machine.
     */
    public const BACKTRACK_LIMIT = 100_000;

    public const RECURSION_LIMIT = 10_000;

    public function __construct(private readonly PcreEngine $engine = new PcreEngine()) {}

    /**
     * Whether the call can be replayed: every argument that shapes the
     * answer is absent or written as an integer, a sum of PREG_* flags
     * included, and the replacement as a string. A numeric string, which
     * PHP reads as a number outside strict types, is not.
     */
    public function replayable(PatternCall $call): bool
    {
        $shaping = match ($call->function) {
            'preg_match', 'preg_match_all' => ['flags', 'offset'],
            'preg_replace', 'preg_filter' => ['limit'],
            'preg_replace_callback', 'preg_split' => ['limit', 'flags'],
            default => [],
        };
        foreach ($shaping as $parameter) {
            if ($call->passes($parameter) && null === $call->integer($parameter)) {
                return false;
            }
        }

        return !\in_array($call->function, ['preg_replace', 'preg_filter'], true) || null !== $call->string('replacement');
    }

    /**
     * Whether the two patterns answer the call differently on the subject,
     * both answering.
     */
    public function differs(PatternCall $call, string $original, string $mutant, string $subject): bool
    {
        if (!$this->replayable($call)) {
            return false;
        }

        $left = $this->answer($call, $original, $subject);
        $right = $this->answer($call, $mutant, $subject);

        return null !== $left && null !== $right && $left !== $right;
    }

    /**
     * What the call answers, as the engine runs the user's patterns, or null
     * when it fails. preg_filter() answers null where preg_replace() answers
     * the subject untouched, and preg_grep() keeps an element exactly where
     * preg_match() given more than a pattern and a subject finds a match,
     * inverted or not: the same subjects tell two
     * patterns apart, and preg_match() also fails where preg_grep() answers
     * an empty array before PHP 8.6. The offsets PREG_SPLIT_OFFSET_CAPTURE
     * adds tell nothing more apart: the same pieces sit at the same offsets.
     */
    private function answer(PatternCall $call, string $pattern, string $subject): ?string
    {
        $function = match ($call->function) {
            'preg_filter' => 'preg_replace',
            'preg_grep' => 'preg_match',
            'preg_match', 'preg_match_all', 'preg_replace', 'preg_replace_callback' => $call->function,
            default => 'preg_split',
        };
        if ('preg_grep' === $call->function) {
            // Its flag only inverts which elements it keeps, and it retries
            // no empty match: preg_match() runs alike once it is given more
            // than the pattern and the subject.
            $arguments = ['offset' => 0];
        } else {
            // The arguments the call passes, and only those: preg_match()
            // given a pattern and a subject alone runs otherwise. Each one
            // is a literal, replayable() has read them.
            $arguments = ['matches' => \in_array($call->function, ['preg_match', 'preg_match_all'], true) && $call->passes('matches')];
            foreach (['flags', 'offset', 'limit'] as $parameter) {
                $value = $call->integer($parameter);
                if (null !== $value) {
                    $arguments[$parameter] = $value;
                }
            }
        }
        $replacement = $call->string('replacement');
        if (null !== $replacement) {
            $arguments['replacement'] = $replacement;
        }

        $answer = $this->engine->answer($function, $pattern, $subject, $arguments, new PcreLimits(self::BACKTRACK_LIMIT, self::RECURSION_LIMIT));
        if (null === $answer) {
            // A pattern that compiles and matches leaves no error behind.
            $this->engine->compile('//');
        }

        return $answer;
    }
}
