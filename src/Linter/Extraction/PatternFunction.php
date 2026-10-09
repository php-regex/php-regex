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
 * A call whose argument at a known position carries a regex pattern.
 *
 * Covers the native preg_* functions as well as the wrappers shipped by
 * userland libraries (composer/pcre, nette/utils, ...) and any project
 * specific helper declared through configuration.
 *
 * @internal
 */
final readonly class PatternFunction
{
    /**
     * Parameter names a call may pass the pattern under, compared in
     * lowercase.
     */
    public const PATTERN_PARAMETER_NAMES = [
        'pattern',
        'patterns',
        'regex',
    ];

    /**
     * Parameter names a call may pass the replacement under, compared in
     * lowercase.
     */
    public const REPLACEMENT_PARAMETER_NAMES = [
        'replacement',
    ];

    /**
     * @param string   $label            name used in reports, e.g. "preg_match" or "Preg::match"
     * @param int      $argumentIndex    zero-based position of the pattern argument
     * @param bool     $keysArePatterns  when the argument is an array literal, whether its keys
     *                                   hold the patterns (preg_replace_callback_array) rather
     *                                   than its values (preg_replace)
     * @param int|null $replacementIndex with $keysArePatterns, the position of a replacement that
     *                                   decides between keys and values, as nette/utils'
     *                                   Strings::replace() does: the keys hold the patterns only
     *                                   when the array's first key is a string and the
     *                                   replacement is no callable; the values otherwise
     */
    public function __construct(
        public string $label,
        public int $argumentIndex = 0,
        public bool $keysArePatterns = false,
        public ?int $replacementIndex = null,
    ) {}

    /**
     * Whether PHP stores this array key as a string, not as an int: '01'
     * and '-0' stay strings, '0' and '-1' do not.
     */
    public static function isStringKey(string $key): bool
    {
        // PHP turns a key into an int when it is an int written the
        // canonical way, and only then.
        return (string) (int) $key !== $key;
    }

    /**
     * The same function, reading the values of an array argument.
     */
    public function readingValues(): self
    {
        return new self($this->label, $this->argumentIndex);
    }
}
