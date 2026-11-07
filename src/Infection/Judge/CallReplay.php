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
 * The runs are the user's patterns, never the library's own: they go
 * without the JIT, under backtracking and recursion limits of their own set
 * for the call and put back after it, a warning kept from the error log,
 * and the engine's last error cleared behind them.
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
     * answer is written as a literal, the replacement a string.
     */
    public function replayable(PatternCall $call): bool
    {
        $shaping = match ($call->function) {
            'preg_match', 'preg_match_all' => ['flags', 'offset'],
            'preg_replace', 'preg_filter' => ['limit'],
            'preg_replace_callback' => ['limit'],
            'preg_split' => ['limit', 'flags'],
            default => [],
        };
        foreach ($shaping as $parameter) {
            if (!$call->literal($parameter)) {
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
     * What the call answers, serialized, or null when it fails.
     */
    private function answer(PatternCall $call, string $pattern, string $subject): ?string
    {
        $prepared = $this->engine->prepare($pattern);
        $limits = ['pcre.backtrack_limit' => self::BACKTRACK_LIMIT, 'pcre.recursion_limit' => self::RECURSION_LIMIT];
        $previous = [];
        foreach ($limits as $name => $value) {
            $previous[$name] = ini_get($name);
            ini_set($name, $value);
        }
        set_error_handler(static fn (): bool => true);

        try {
            $answer = $this->run($call, $prepared, $subject);
        } finally {
            restore_error_handler();
            foreach ($previous as $name => $value) {
                if (false !== $value) {
                    ini_set($name, $value);
                }
            }
            if (\PREG_NO_ERROR !== preg_last_error()) {
                // An empty pattern compiles and matches: no error is left
                // behind.
                $this->engine->compile('//');
            }
        }

        return $answer;
    }

    private function run(PatternCall $call, string $pattern, string $subject): ?string
    {
        $flags = $call->integer('flags', 0) ?? 0;
        $offset = $call->integer('offset', 0) ?? 0;
        $limit = $call->integer('limit', -1) ?? -1;
        $captures = (0 !== ($flags & \PREG_OFFSET_CAPTURE) ? \PREG_OFFSET_CAPTURE : 0) | (0 !== ($flags & \PREG_UNMATCHED_AS_NULL) ? \PREG_UNMATCHED_AS_NULL : 0);

        // Each call answers false, or null, when the engine gives up.
        switch ($call->function) {
            case 'preg_match':
            case 'preg_match_all':
                // Without $matches the call answers how many matches it
                // found, nothing of what they hold.
                $found = 'preg_match' === $call->function
                    ? preg_match($pattern, $subject, $matches, $captures, $offset)
                    : preg_match_all($pattern, $subject, $matches, $captures, $offset);
                $answer = false === $found ? false : ($call->passes('matches') ? $matches : $found);

                break;
            case 'preg_replace':
            case 'preg_filter':
                // preg_filter() answers null where preg_replace() answers the
                // subject untouched: the same subjects tell two patterns apart.
                $answer = preg_replace($pattern, (string) $call->string('replacement'), $subject, $limit) ?? false;

                break;
            case 'preg_replace_callback':
                // What the callback receives, under the call's own flags, and
                // where each match it replaces stands.
                $answer = false === preg_match_all($pattern, $subject, $matches, \PREG_SET_ORDER | $captures)
                    || false === preg_match_all($pattern, $subject, $spans, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE)
                    ? false
                    : \array_slice(array_map(static fn (array $match, array $span): array => [$match, $span[0][1]], $matches, $spans), 0, $limit < 0 ? null : $limit);

                break;
            case 'preg_split':
                // The same pieces sit at the same offsets: PREG_SPLIT_OFFSET_CAPTURE
                // tells nothing more apart.
                $answer = preg_split($pattern, $subject, $limit, (0 !== ($flags & \PREG_SPLIT_NO_EMPTY) ? \PREG_SPLIT_NO_EMPTY : 0) | (0 !== ($flags & \PREG_SPLIT_DELIM_CAPTURE) ? \PREG_SPLIT_DELIM_CAPTURE : 0));

                break;
            default:
                // preg_grep() keeps an element exactly where preg_match()
                // finds a match, inverted or not: the same elements tell two
                // patterns apart. preg_match() also fails where preg_grep()
                // answers an empty array before PHP 8.6.
                $answer = preg_match($pattern, $subject);
        }

        return false === $answer ? null : serialize($answer);
    }
}
