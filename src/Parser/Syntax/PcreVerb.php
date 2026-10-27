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

namespace PHPRegex\Parser\Syntax;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\SyntaxErrorException;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\Utf8;

/**
 * What "(*...)" holds.
 *
 * Most of these are backtracking verbs — (*FAIL), (*SKIP), (*MARK:name) —
 * but PCRE also spells three other things this way: the alphabetic form of a
 * lookaround, a script run, and the match limit. Telling them apart is
 * string work on the text between the parentheses.
 *
 * @internal
 */
final class PcreVerb
{
    /**
     * The letters of an alphabetic name, "(*pla:" or "(*atomic:".
     */
    public const LOWERCASE_NAME = 'abcdefghijklmnopqrstuvwxyz_';

    /**
     * The characters "\w" stands for outside UTF mode.
     */
    public const WORD_NAME = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_';

    /**
     * PCRE2 10.33+ alphabetic assertion verbs and their group equivalents.
     */
    private const ASSERTIONS = [
        'positive_lookahead' => GroupType::LookaheadPositive,
        'pla' => GroupType::LookaheadPositive,
        'negative_lookahead' => GroupType::LookaheadNegative,
        'nla' => GroupType::LookaheadNegative,
        'positive_lookbehind' => GroupType::LookbehindPositive,
        'plb' => GroupType::LookbehindPositive,
        'negative_lookbehind' => GroupType::LookbehindNegative,
        'nlb' => GroupType::LookbehindNegative,
        'atomic' => GroupType::Atomic,
    ];

    /**
     * The non-atomic assertions: a lookaround PCRE may backtrack into. Only
     * the positive ones exist.
     */
    private const NON_ATOMIC_ASSERTIONS = [
        'non_atomic_positive_lookahead' => GroupType::LookaheadPositive,
        'napla' => GroupType::LookaheadPositive,
        'non_atomic_positive_lookbehind' => GroupType::LookbehindPositive,
        'naplb' => GroupType::LookbehindPositive,
    ];

    /**
     * The spellings of a script run, and whether its body is atomic.
     */
    private const SCRIPT_RUN_PREFIXES = [
        'script_run:' => false,
        'sr:' => false,
        'atomic_script_run:' => true,
        'asr:' => true,
    ];

    private function __construct(
        /**
         * The verb as it should be recorded, which is not what was written
         * when the pattern used the "(*:name)" shorthand for a mark.
         */
        public readonly string $name,
        /**
         * The group an alphabetic assertion stands for, or null.
         */
        public readonly ?GroupType $assertion = null,
        /**
         * The sub-pattern an assertion or a script run wraps, or null.
         */
        public readonly ?string $payload = null,
        /**
         * The limit "(*LIMIT_MATCH=n)" sets, or null.
         */
        public readonly ?int $matchLimit = null,
        /**
         * Where the payload starts, relative to the verb text.
         */
        public readonly int $payloadOffset = 0,
        /**
         * Whether the assertion is non-atomic: "(*napla:...)", "(?*...)".
         */
        public readonly bool $nonAtomic = false,
        /**
         * Whether the script run's body is atomic: "(*asr:...)".
         */
        public readonly bool $atomicScriptRun = false,
        /**
         * "scs" or "scan_substring" as written, for a substring scan
         * "(*scs:(1)...)", PCRE2 10.45; null otherwise.
         */
        public readonly ?string $scanSubstring = null,
    ) {}

    public static function read(string $verb): self
    {
        // "(?*...)" is the short spelling of "(*napla:...)"; the lexer hands
        // it over as the text after "(?".
        if (str_starts_with($verb, '*')) {
            return new self($verb, GroupType::LookaheadPositive, substr($verb, 1), null, 1, true);
        }

        // "(*:name)" and "(*=name)" are shorthands for a mark.
        if ('' !== $verb && (str_starts_with($verb, ':') || str_starts_with($verb, '='))) {
            $verb = 'MARK'.$verb;
        }

        $colon = strpos($verb, ':');
        if (false !== $colon) {
            // PCRE only knows the lowercase spelling of these.
            $name = substr($verb, 0, $colon);
            $assertion = self::ASSERTIONS[$name] ?? null;
            if (null !== $assertion) {
                return new self($verb, $assertion, substr($verb, $colon + 1), null, $colon + 1);
            }

            $nonAtomic = self::NON_ATOMIC_ASSERTIONS[$name] ?? null;
            if (null !== $nonAtomic) {
                return new self($verb, $nonAtomic, substr($verb, $colon + 1), null, $colon + 1, true);
            }
        }

        $matches = [];
        if (1 === LibraryPcre::match('/^LIMIT_MATCH=(\d++)\z/i', $verb, $matches)) {
            return new self($verb, null, null, (int) $matches[1]);
        }

        if (1 === LibraryPcre::match('/^(scs|scan_substring):/', $verb, $scan)) {
            return new self($verb, scanSubstring: $scan[1]);
        }

        // A script run may hold nothing: PCRE compiles "(*sr:)".
        foreach (self::SCRIPT_RUN_PREFIXES as $prefix => $atomic) {
            if (str_starts_with($verb, $prefix)) {
                return new self($verb, null, substr($verb, \strlen($prefix)), null, \strlen($prefix), atomicScriptRun: $atomic);
            }
        }

        return new self($verb);
    }

    /**
     * What stops PCRE reading the list of groups "(1,<name>,'name')" that
     * opens at $open, read as PCRE reads it, item by item: no "(", an item
     * that is neither a number nor a name, a name that is empty, starts with
     * a digit or is not closed, or no "," or ")" after an item. Null when the
     * list closes. What the numbers name is left to the caller.
     *
     * @param PcreTarget $target  the release read: where it refuses a name
     *                            that starts with a digit, on the digit or
     *                            past it, and in UTF mode which letters and
     *                            digits a name holds
     * @param bool       $unicode whether the pattern is read in UTF mode
     *
     * @return array{0: int, 1: ErrorCode, 2: string}|null the offset, the
     *                                                     code and the message
     */
    public static function groupListFault(string $pattern, int $open, PcreTarget $target, bool $unicode = false): ?array
    {
        $unicodeTables = $unicode ? $target : null;
        if ('(' !== ($pattern[$open] ?? '')) {
            return [$open, ErrorCode::ScanSubstringMissingList, \sprintf('Missing "(" to open the list of groups at position %d.', $open)];
        }

        $at = $open + 1;
        while (true) {
            $quote = $pattern[$at] ?? '';
            if ('<' === $quote || "'" === $quote) {
                $nameStart = $at + 1;
                $digit = null !== $unicodeTables ? NameCharacters::digitAt($pattern, $nameStart, $unicodeTables) : (Ascii::isDigit($pattern[$nameStart] ?? '') ? 1 : 0);
                if ($digit > 0) {
                    $offset = $target->faultOffset($nameStart + $digit, $digit);

                    return [$offset, ErrorCode::GroupNameInvalid, \sprintf('A group name must not start with a digit, at position %d.', $offset)];
                }

                if (null !== $unicodeTables) {
                    $nameEnd = NameCharacters::end($pattern, $nameStart, $unicodeTables);
                } else {
                    LibraryPcre::match('/\G\w*+/', $pattern, $name, 0, $nameStart);
                    $nameEnd = $nameStart + \strlen($name[0] ?? '');
                }

                // Lists arrived in PCRE2 10.45, which takes names of 128
                // code units; it measures one before it looks for its quote.
                if ($nameEnd - $nameStart > GroupNameReader::MAX_NAME_LENGTH) {
                    return [$nameEnd, ErrorCode::GroupNameTooLong, \sprintf('Group name is too long at position %d: PCRE allows at most %d code units.', $nameEnd, GroupNameReader::MAX_NAME_LENGTH)];
                }

                if ($nameEnd === $nameStart) {
                    return [$nameStart, ErrorCode::GroupNameExpected, \sprintf('Group name expected at position %d.', $nameStart)];
                }

                if (('<' === $quote ? '>' : "'") !== ($pattern[$nameEnd] ?? '')) {
                    return [$nameEnd, ErrorCode::GroupNameUnterminated, \sprintf('Missing "%s" to close the group name at position %d.', '<' === $quote ? '>' : "'", $nameEnd)];
                }

                $at = $nameEnd + 1;
            } elseif (1 === LibraryPcre::match('/\G[+-]?\d++/', $pattern, $number, 0, $at)) {
                $at += \strlen($number[0]);
            } else {
                return [$at, ErrorCode::GroupListItemExpected, \sprintf('Expected a capture group number or name at position %d.', $at)];
            }

            $next = $pattern[$at] ?? '';
            if (')' === $next) {
                return null;
            }

            if (',' !== $next) {
                return [$at, ErrorCode::GroupUnclosed, \sprintf('Missing ")" to close the list of groups at position %d.', $at)];
            }

            $at++;
        }
    }

    /**
     * The fault of a list of groups as PCRE2 10.45 and 10.46 report it,
     * which have no error of their own for a list that opens no "(" or an
     * item that is no number or name: a missing ")" at the end of the
     * pattern, a reference to no group elsewhere.
     *
     * @param array{0: int, 1: ErrorCode, 2: string} $fault
     *
     * @return array{0: int, 1: ErrorCode, 2: string}
     */
    public static function groupListFaultBefore1047(array $fault, int $patternLength): array
    {
        if (ErrorCode::ScanSubstringMissingList !== $fault[1] && ErrorCode::GroupListItemExpected !== $fault[1]) {
            return $fault;
        }

        return [$fault[0], $fault[0] >= $patternLength ? ErrorCode::GroupUnclosed : ErrorCode::GroupListMissingGroup, $fault[2]];
    }

    /**
     * The name of the "(*name:" at $at, one or more of $letters then ":";
     * null when no such opener stands there.
     *
     * It is read without a regex: a match that must end in ":" has PCRE2
     * search the subject for one before it tries the match, and up to
     * PCRE2 10.44 the JIT searches to the end of the subject, at every "("
     * of a pattern with fewer ":" than groups.
     */
    public static function nameBeforeColonAt(string $pattern, int $at, string $letters): ?string
    {
        if ('(' !== ($pattern[$at] ?? '') || '*' !== ($pattern[$at + 1] ?? '')) {
            return null;
        }

        $length = strspn($pattern, $letters, $at + 2);

        return $length > 0 && ':' === ($pattern[$at + 2 + $length] ?? '') ? substr($pattern, $at + 2, $length) : null;
    }

    /**
     * Whether PCRE knows "(*name:": an assertion, a script run, or a verb
     * that takes a name, the mark's "(*:" included.
     */
    public static function takesArgument(string $name): bool
    {
        return isset(self::ASSERTIONS[$name])
            || isset(self::NON_ATOMIC_ASSERTIONS[$name])
            || isset(self::SCRIPT_RUN_PREFIXES[$name.':'])
            || \in_array($name, ['', 'MARK', 'PRUNE', 'SKIP', 'THEN', 'COMMIT', 'ACCEPT', 'FAIL', 'F'], true);
    }

    /**
     * Whether "(*name:" is a lookahead or a lookbehind, "(*pla:" and the
     * like, which PCRE takes as the condition of a conditional: not
     * "(*atomic:", nor "(*napla:" and the other non-atomic ones.
     */
    public static function isLookaround(string $name): bool
    {
        return GroupType::Atomic !== (self::ASSERTIONS[$name] ?? GroupType::Atomic);
    }

    /**
     * Whether "(*name:" is one of the alphabetic names PCRE2 10.33 gave the
     * assertions and the atomic group, "(*pla:" to "(*atomic:".
     */
    public static function isAlphabeticAssertion(string $name): bool
    {
        return isset(self::ASSERTIONS[$name]);
    }

    /**
     * Whether "(*name:" spells a non-atomic lookaround, "(*napla:" or one of
     * its kind, which PCRE2 10.34 added.
     */
    public static function isNonAtomicAssertion(string $name): bool
    {
        return isset(self::NON_ATOMIC_ASSERTIONS[$name]);
    }

    /**
     * Whether "(*name:" spells a script run, "(*sr:" or one of its kind,
     * which PCRE2 10.33 added.
     */
    public static function namesScriptRun(string $name): bool
    {
        return isset(self::SCRIPT_RUN_PREFIXES[$name.':']);
    }

    /**
     * The opener every PCRE2 release reads for an alphabetic assertion name,
     * "(?=" for "pla" or "positive_lookahead".
     */
    public static function classicOpener(string $name): string
    {
        return match (self::ASSERTIONS[$name] ?? null) {
            GroupType::LookaheadPositive => '(?=',
            GroupType::LookaheadNegative => '(?!',
            GroupType::LookbehindPositive => '(?<=',
            GroupType::LookbehindNegative => '(?<!',
            default => '(?>',
        };
    }

    /**
     * Where PCRE stops reading the value of "(*LIMIT_MATCH=n)" and the other
     * limits, the "(" at $start, when that value is malformed or unclosed;
     * null when it is well formed, or no limit starts there. With no digit,
     * PCRE stops after the "="; after digits, from PCRE2 10.45 on the first
     * other character, and before on the one after it.
     */
    public static function limitValueErrorOffset(string $pattern, int $start, PcreTarget $target): ?int
    {
        if (1 !== LibraryPcre::match('/\G\(\*LIMIT_(?:MATCH|HEAP|DEPTH|RECURSION)=/', $pattern, $matches, 0, $start)) {
            return null;
        }

        $at = $start + \strlen($matches[0]);
        $digits = strspn($pattern, '0123456789', $at);
        if (0 === $digits) {
            return $at;
        }

        return ')' === ($pattern[$at + $digits] ?? '') ? null : $at + $digits + ($target->supports(PcreFeature::LimitValueErrorOnFaultingCharacter) ? 0 : 1);
    }

    /**
     * Where PCRE reports a "(*" the pattern never closes, the "(" at $start:
     * where its name ends, or at the end of the pattern once a name it knows
     * takes ":". In UTF mode, $utf, a character past the name is whole.
     */
    public static function unclosedOffset(string $pattern, int $start, PcreTarget $target, bool $utf): int
    {
        // A limit is only read in the run of settings that opens the
        // pattern; elsewhere it is a verb PCRE does not know.
        LibraryPcre::match('/\A(?:\(\*[A-Z_]++(?:=\d*+)?\))*+/', $pattern, $settings);
        $limit = $start > \strlen($settings[0] ?? '') ? null : self::limitValueErrorOffset($pattern, $start, $target);
        if (null !== $limit) {
            return $limit;
        }

        // Before PCRE2 10.33 the name is a run of letters.
        LibraryPcre::match($target->supports(PcreFeature::NamesReadWhole) ? '/\G[A-Za-z0-9_]*+/' : '/\G[A-Za-z]*+/', $pattern, $matches, 0, $start + 2);
        $name = $matches[0] ?? '';
        $nameEnd = $start + 2 + \strlen($name);

        if (':' === ($pattern[$nameEnd] ?? '') && self::takesArgument($name)) {
            return \strlen($pattern);
        }

        // "(*" at the end is a "*" with nothing to repeat, and from PCRE2
        // 10.47 an alphabetic name, one that starts with a lowercase letter,
        // followed by no colon is refused past the character after it,
        // whole in UTF mode.
        $pastTheFault = $target->supports(PcreFeature::ErrorOffsetPastTheFault);
        if ('' === $name && $nameEnd >= \strlen($pattern)) {
            return $target->faultOffset($nameEnd);
        }

        if (!$pastTheFault || 1 !== LibraryPcre::match('/^[a-z]/', $name) || $nameEnd >= \strlen($pattern) || ':' === $pattern[$nameEnd]) {
            return $nameEnd;
        }

        return $nameEnd + ($utf ? Utf8::width($pattern, $nameEnd) : 1);
    }

    /**
     * What PCRE refuses in the name of a "(*" no ")" closes, the "(" at
     * $start, or null for a name it reads: it reads the name as it reads any
     * verb name, before it looks for the ")", and refuses a name longer than
     * a group name may be where it ends. Before PCRE2 10.33, which reads it
     * as a run of letters, a name that starts with a digit is refused on it,
     * and one past 32 letters at the 33rd.
     */
    public static function unclosedNameError(string $pattern, int $start, PcreTarget $target): ?ParserException
    {
        $nameStart = $start + 2;
        if (!$target->supports(PcreFeature::NamesReadWhole)) {
            if (Ascii::isDigit($pattern[$nameStart] ?? '')) {
                return SyntaxErrorException::withContext(
                    \sprintf('Invalid verb name at position %d: PCRE2 before 10.33 reads it as a group name, which must not start with a digit.', $nameStart),
                    ErrorCode::GroupNameInvalid,
                    $nameStart,
                    $pattern,
                );
            }

            if (strspn($pattern, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', $nameStart) > GroupNameReader::MAX_NAME_LENGTH_BEFORE_PCRE_1044) {
                $offset = $nameStart + GroupNameReader::MAX_NAME_LENGTH_BEFORE_PCRE_1044 + 1;

                return SyntaxErrorException::withContext(\sprintf('Verb name too long at position %d: PCRE2 before 10.33 takes at most 32 letters.', $offset), ErrorCode::GroupNameTooLong, $offset, $pattern);
            }

            return null;
        }

        $length = strspn($pattern, self::WORD_NAME, $nameStart);
        $longest = GroupNameReader::maxNameLength($target);
        if ($length > $longest) {
            return SyntaxErrorException::withContext(
                \sprintf('Verb name too long at position %d: PCRE takes at most %d characters.', $nameStart + $length, $longest),
                ErrorCode::GroupNameTooLong,
                $nameStart + $length,
                $pattern,
            );
        }

        return null;
    }

    /**
     * "(**" opens no verb: a verb name never starts with "*", and "(?*" is
     * the only short spelling of a lookahead. PCRE refuses it at the second
     * "*", at $position.
     */
    public static function doubleStarError(string $pattern, int $position): ParserException
    {
        return SyntaxErrorException::withContext(
            \sprintf('Unknown verb at position %d: "(**" opens no verb, PCRE wants a name after "(*".', $position),
            ErrorCode::VerbInvalid,
            $position,
            $pattern,
        );
    }

    public function isScriptRun(): bool
    {
        return null === $this->assertion && null !== $this->payload;
    }
}
