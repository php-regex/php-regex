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

namespace PHPRegex\Linter\Extraction;

use PHPRegex\Parser\Text\Ascii;

/**
 * The preg functions taking a pattern, as the extractors and the static
 * analysis rules read their calls.
 *
 * @internal
 */
final class PregFunctions
{
    /**
     * The parameters of each function, in order: PHP binds the arguments to
     * them by position, then by name. The pattern comes first in each.
     */
    public const PARAMETERS = [
        'preg_match' => ['pattern', 'subject', 'matches', 'flags', 'offset'],
        'preg_match_all' => ['pattern', 'subject', 'matches', 'flags', 'offset'],
        'preg_replace' => ['pattern', 'replacement', 'subject', 'limit', 'count'],
        'preg_replace_callback' => ['pattern', 'callback', 'subject', 'limit', 'count', 'flags'],
        'preg_replace_callback_array' => ['pattern', 'subject', 'limit', 'count', 'flags'],
        'preg_split' => ['pattern', 'subject', 'limit', 'flags'],
        'preg_grep' => ['pattern', 'array', 'flags'],
        'preg_filter' => ['pattern', 'replacement', 'subject', 'limit', 'count'],
    ];

    /**
     * The functions whose pattern may also be an array of patterns, each
     * element one.
     */
    public const PATTERN_LISTS = ['preg_replace', 'preg_replace_callback', 'preg_filter'];

    /**
     * The functions that look for every match: after each match they go
     * on from its end, and after an empty one PHP tries the same offset
     * again with PCRE2_NOTEMPTY_ATSTART | PCRE2_ANCHORED before it moves
     * one character on. preg_match() and preg_grep() stop at the first
     * match.
     */
    public const EVERY_MATCH = ['preg_match_all', 'preg_replace', 'preg_filter', 'preg_replace_callback', 'preg_replace_callback_array', 'preg_split'];

    /**
     * The loop of a call that looks for every match with preg_match()
     * itself: it calls it again where each match ended, one byte on after
     * an empty one, and makes no retry there, as the generator nette/utils'
     * Strings::matchAll() returns under lazy does. Named apart from the
     * functions of EVERY_MATCH, whose loop PHP runs: the cost of one is not
     * the cost of the other.
     */
    public const MATCH_LOOP = 'preg_match loop';

    /**
     * The modes of RegexIterator and RecursiveRegexIterator under which
     * accept() looks for every match, each with the function whose loop it
     * runs, without a limit (ext/spl/spl_iterators.c): ALL_MATCHES that of
     * preg_match_all(), SPLIT that of preg_split(), REPLACE that of
     * preg_replace(). MATCH, the default, and GET_MATCH stop at the first
     * match.
     */
    public const ITERATOR_LOOPS = [
        \RegexIterator::ALL_MATCHES => 'preg_match_all',
        \RegexIterator::SPLIT => 'preg_split',
        \RegexIterator::REPLACE => 'preg_replace',
    ];

    /**
     * The modes of RegexIterator, by name.
     */
    private const ITERATOR_MODES = [
        'ALL_MATCHES' => \RegexIterator::ALL_MATCHES,
        'SPLIT' => \RegexIterator::SPLIT,
        'REPLACE' => \RegexIterator::REPLACE,
        'GET_MATCH' => \RegexIterator::GET_MATCH,
        'MATCH' => \RegexIterator::MATCH,
    ];

    /**
     * The position and name of the parameter holding what the pattern runs
     * on, preg_grep()'s array included; null for a function taking no
     * pattern.
     *
     * @return array{int, string}|null
     */
    public static function subject(string $function): ?array
    {
        foreach (self::PARAMETERS[$function] ?? [] as $position => $name) {
            if ('subject' === $name || 'array' === $name) {
                return [$position, $name];
            }
        }

        return null;
    }

    /**
     * The position and name of the parameter that bounds how many matches
     * the function makes; null for one without.
     *
     * @return array{int, string}|null
     */
    public static function limit(string $function): ?array
    {
        $position = array_search('limit', self::PARAMETERS[$function] ?? [], true);

        return false === $position ? null : [$position, 'limit'];
    }

    /**
     * The value of a mode of RegexIterator, or of RecursiveRegexIterator,
     * which inherits them, the class fully qualified as PHP resolves it,
     * without a leading backslash; null for any other class or name. The
     * names are compared as PHP compares them: the class in any case, the
     * constant case included.
     */
    public static function iteratorConstant(string $class, string $constant): ?int
    {
        $class = Ascii::lower($class);

        return 'regexiterator' === $class || 'recursiveregexiterator' === $class ? self::ITERATOR_MODES[$constant] ?? null : null;
    }

    /**
     * Whether a limit the call gives bounds how many matches the function
     * makes: any limit of zero or more for the replacing functions, which
     * read it unsigned, -1 meaning none; for preg_split(), any but -1 and 0,
     * which mean none, as a limit of 1 or less splits nothing.
     */
    public static function bounds(string $function, int $limit): bool
    {
        return 'preg_split' === $function ? -1 !== $limit && 0 !== $limit : $limit >= 0;
    }
}
