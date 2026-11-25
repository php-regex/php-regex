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
final class PatternFunction
{
    /**
     * The names a configured helper whose spec names no parameter takes
     * the pattern under ("App\grep#1"), compared as PHP does. Every other
     * function is read under its own parameter's name: $parameterNames.
     */
    public const PATTERN_PARAMETER_NAMES = [
        'pattern',
        'patterns',
        'regex',
    ];

    /**
     * Parameter names a call may pass the replacement under, compared as
     * PHP does.
     */
    public const REPLACEMENT_PARAMETER_NAMES = [
        'replacement',
    ];

    /**
     * A function, or a static method named on its class: preg_match(),
     * Preg::match().
     */
    public const KIND_CALL = 'call';

    /**
     * A method matched by its name on $this, self::, static:: or parent::.
     */
    public const KIND_NAMED_METHOD = 'named-method';

    /**
     * A constructor: new RegexIterator(...).
     */
    public const KIND_CONSTRUCTOR = 'constructor';

    /**
     * An attribute: #[Regex(...)].
     */
    public const KIND_ATTRIBUTE = 'attribute';

    /**
     * The names a call may pass the pattern argument under, sorted, each
     * once.
     *
     * @var list<string>
     */
    public readonly array $parameterNames;

    /**
     * The names of each argument of $moreArgumentIndexes, in their order,
     * each list as $parameterNames is.
     *
     * @var list<list<string>>
     */
    public readonly array $moreParameterNames;

    /**
     * @param string                                      $label               name used in reports, e.g. "preg_match" or "Preg::match"
     * @param int                                         $argumentIndex       zero-based position of the pattern argument
     * @param bool                                        $keysArePatterns     when the argument is an array literal, whether its keys
     *                                                                         hold the patterns (preg_replace_callback_array) rather
     *                                                                         than its values (preg_replace)
     * @param int|null                                    $replacementIndex    with $keysArePatterns, the position of a replacement that
     *                                                                         decides between keys and values, as nette/utils'
     *                                                                         Strings::replace() does: the keys hold the patterns only
     *                                                                         when the array's first key is a string and the
     *                                                                         replacement is no callable; the values otherwise
     * @param list<int>                                   $moreArgumentIndexes the other arguments that carry a pattern, when the
     *                                                                         declarations of one function mark several
     * @param list<string>                                $parameterNames      the names a call may pass the pattern argument under:
     *                                                                         several when declarations of one function name the
     *                                                                         position differently
     * @param list<list<string>>                          $moreParameterNames  the names of the arguments of $moreArgumentIndexes, in
     *                                                                         their order; "pattern" for one left out
     * @param string                                      $kind                how the call is written, one of the KIND_* constants
     * @param list<string>                                $optionPath          the keys leading to the pattern when the argument is an
     *                                                                         array literal: ['options', 'regexp'] for filter_var()
     * @param bool                                        $optionPathRequired  whether the pattern is only found under $optionPath; when
     *                                                                         false, an argument that is no array literal is the pattern
     * @param array{int, string, string}|null             $filterArgument      the position, the parameter name and the constant an
     *                                                                         argument must name for the call to carry a pattern:
     *                                                                         filter_var()'s FILTER_VALIDATE_REGEXP
     * @param array{int, string}|null                     $utf8Argument        the position and the name of a parameter which, as the
     *                                                                         literal true, appends "u" to the pattern, as
     *                                                                         nette/utils does; anything but true or false leaves
     *                                                                         the pattern unknown
     * @param string                                      $patternSuffix       what the call appends to every pattern it reads
     * @param string|null                                 $everyMatch          the preg function the call runs its patterns in when
     *                                                                         that function looks for every match (PregFunctions::
     *                                                                         EVERY_MATCH); null when it stops at the first one
     * @param array{int, string}|null                     $limitArgument       with $everyMatch, the position and the name of the
     *                                                                         parameter bounding how many matches it makes
     * @param array{int, string, array<int, string>}|null $loopArgument        the position and the name of a parameter that
     *                                                                         decides whether the call looks for every match, with,
     *                                                                         for each value that makes it do so, the preg function
     *                                                                         whose loop it runs, false and true read as 0 and 1 as
     *                                                                         PHP converts them. Left out, the call runs
     *                                                                         $everyMatch; any other value, or one the call does
     *                                                                         not spell out, is read as stopping at the first match
     * @param array{int, string}|null                     $callbackArgument    with $everyMatch preg_replace, the position and the
     *                                                                         name of the replacement which, as a closure, makes
     *                                                                         the call run preg_replace_callback() instead
     */
    public function __construct(
        public readonly string $label,
        public readonly int $argumentIndex = 0,
        public readonly bool $keysArePatterns = false,
        public readonly ?int $replacementIndex = null,
        public readonly array $moreArgumentIndexes = [],
        array $parameterNames = ['pattern'],
        array $moreParameterNames = [],
        public readonly string $kind = self::KIND_CALL,
        public readonly array $optionPath = [],
        public readonly bool $optionPathRequired = false,
        public readonly ?array $filterArgument = null,
        public readonly ?array $utf8Argument = null,
        public readonly string $patternSuffix = '',
        public readonly ?string $everyMatch = null,
        public readonly ?array $limitArgument = null,
        public readonly ?array $loopArgument = null,
        public readonly ?array $callbackArgument = null,
    ) {
        // Sorted and without duplicates: the order the declarations were
        // read in makes no other function.
        $this->parameterNames = self::sortedNames($parameterNames);
        $this->moreParameterNames = array_map(self::sortedNames(...), $moreParameterNames);
    }

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
     * The name a report gives the call: "preg_match()", "new
     * RegexIterator()", "#[Regex]".
     */
    public function source(): string
    {
        return match ($this->kind) {
            self::KIND_CONSTRUCTOR => 'new '.$this->label.'()',
            self::KIND_ATTRIBUTE => '#['.$this->label.']',
            default => $this->label.'()',
        };
    }

    /**
     * The same function, reading the values of an array argument.
     */
    public function readingValues(): self
    {
        return $this->with(keysArePatterns: false, replacementIndex: null);
    }

    /**
     * The same function, once its option path has led to an item of the
     * array written at the call: that item is the pattern itself, and is
     * not looked into for the path again.
     */
    public function readingTheItem(): self
    {
        return $this->with(optionPath: [], optionPathRequired: false);
    }

    /**
     * The same function, a limit the call gives bounding how many matches
     * it makes: its loop no longer goes on after every match.
     */
    public function bounded(): self
    {
        return $this->with(everyMatch: null);
    }

    /**
     * Whether a limit the call gives bounds how many matches it makes.
     */
    public function boundedBy(int $limit): bool
    {
        return null !== $this->everyMatch && PregFunctions::bounds($this->everyMatch, $limit);
    }

    /**
     * The same function, the argument that decides whether it looks for
     * every match holding a value: the function that value makes it run its
     * loop in, none for any other value, and none for a value the call does
     * not spell out (null).
     */
    public function decidedBy(?int $value): self
    {
        return $this->with(everyMatch: null === $value ? null : $this->loopArgument[2][$value] ?? null);
    }

    /**
     * The same function, its replacement a closure: the call runs
     * preg_replace_callback(), whose loop preg_replace() shares.
     */
    public function replacingThroughCallback(): self
    {
        return 'preg_replace' === $this->everyMatch ? $this->with(everyMatch: 'preg_replace_callback') : $this;
    }

    /**
     * The same function, appending a suffix to every pattern it reads.
     */
    public function withPatternSuffix(string $suffix): self
    {
        return $this->with(patternSuffix: $suffix);
    }

    /**
     * One function per argument that carries a pattern, the first one
     * itself.
     *
     * @return non-empty-list<self>
     */
    public function eachArgument(): array
    {
        if ([] === $this->moreArgumentIndexes) {
            return [$this];
        }

        $functions = [$this->with(moreArgumentIndexes: [], moreParameterNames: [])];
        foreach ($this->moreArgumentIndexes as $position => $index) {
            $functions[] = $this->with(
                argumentIndex: $index,
                moreArgumentIndexes: [],
                parameterNames: $this->moreParameterNames[$position] ?? ['pattern'],
                moreParameterNames: [],
            );
        }

        return $functions;
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function sortedNames(array $names): array
    {
        $names = array_unique($names);
        sort($names);

        return $names;
    }

    /**
     * @param list<int>|null          $moreArgumentIndexes
     * @param list<string>|null       $parameterNames
     * @param list<list<string>>|null $moreParameterNames
     * @param list<string>|null       $optionPath
     */
    private function with(
        ?int $argumentIndex = null,
        ?bool $keysArePatterns = null,
        int|false|null $replacementIndex = false,
        ?array $moreArgumentIndexes = null,
        ?array $parameterNames = null,
        ?array $moreParameterNames = null,
        ?string $patternSuffix = null,
        ?array $optionPath = null,
        ?bool $optionPathRequired = null,
        string|false|null $everyMatch = false,
    ): self {
        return new self(
            $this->label,
            $argumentIndex ?? $this->argumentIndex,
            $keysArePatterns ?? $this->keysArePatterns,
            false === $replacementIndex ? $this->replacementIndex : $replacementIndex,
            $moreArgumentIndexes ?? $this->moreArgumentIndexes,
            $parameterNames ?? $this->parameterNames,
            $moreParameterNames ?? $this->moreParameterNames,
            $this->kind,
            $optionPath ?? $this->optionPath,
            $optionPathRequired ?? $this->optionPathRequired,
            $this->filterArgument,
            $this->utf8Argument,
            $patternSuffix ?? $this->patternSuffix,
            false === $everyMatch ? $this->everyMatch : $everyMatch,
            $this->limitArgument,
            $this->loopArgument,
            $this->callbackArgument,
        );
    }
}
