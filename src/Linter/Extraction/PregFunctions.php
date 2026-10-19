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
}
