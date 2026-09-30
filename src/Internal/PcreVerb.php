<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Internal;

use RegexParser\ErrorCode;
use RegexParser\Node\GroupType;

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
final readonly class PcreVerb
{
    /**
     * PCRE2 10.32+ alphabetic assertion verbs and their group equivalents.
     */
    private const ASSERTIONS = [
        'positive_lookahead' => GroupType::T_GROUP_LOOKAHEAD_POSITIVE,
        'pla' => GroupType::T_GROUP_LOOKAHEAD_POSITIVE,
        'negative_lookahead' => GroupType::T_GROUP_LOOKAHEAD_NEGATIVE,
        'nla' => GroupType::T_GROUP_LOOKAHEAD_NEGATIVE,
        'positive_lookbehind' => GroupType::T_GROUP_LOOKBEHIND_POSITIVE,
        'plb' => GroupType::T_GROUP_LOOKBEHIND_POSITIVE,
        'negative_lookbehind' => GroupType::T_GROUP_LOOKBEHIND_NEGATIVE,
        'nlb' => GroupType::T_GROUP_LOOKBEHIND_NEGATIVE,
        'atomic' => GroupType::T_GROUP_ATOMIC,
    ];

    /**
     * The non-atomic assertions: a lookaround PCRE may backtrack into. Only
     * the positive ones exist.
     */
    private const NON_ATOMIC_ASSERTIONS = [
        'non_atomic_positive_lookahead' => GroupType::T_GROUP_LOOKAHEAD_POSITIVE,
        'napla' => GroupType::T_GROUP_LOOKAHEAD_POSITIVE,
        'non_atomic_positive_lookbehind' => GroupType::T_GROUP_LOOKBEHIND_POSITIVE,
        'naplb' => GroupType::T_GROUP_LOOKBEHIND_POSITIVE,
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
        public string $name,
        /**
         * The group an alphabetic assertion stands for, or null.
         */
        public ?GroupType $assertion = null,
        /**
         * The sub-pattern an assertion or a script run wraps, or null.
         */
        public ?string $payload = null,
        /**
         * The limit "(*LIMIT_MATCH=n)" sets, or null.
         */
        public ?int $matchLimit = null,
        /**
         * Where the payload starts, relative to the verb text.
         */
        public int $payloadOffset = 0,
        /**
         * Whether the assertion is non-atomic: "(*napla:...)", "(?*...)".
         */
        public bool $nonAtomic = false,
        /**
         * Whether the script run's body is atomic: "(*asr:...)".
         */
        public bool $atomicScriptRun = false,
        /**
         * "scs" or "scan_substring" as written, for a substring scan
         * "(*scs:(1)...)", PCRE2 10.45; null otherwise.
         */
        public ?string $scanSubstring = null,
    ) {}

    public static function read(string $verb): self
    {
        // "(?*...)" is the short spelling of "(*napla:...)"; the lexer hands
        // it over as the text after "(?".
        if (str_starts_with($verb, '*')) {
            return new self($verb, GroupType::T_GROUP_LOOKAHEAD_POSITIVE, substr($verb, 1), null, 1, true);
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
        if (preg_match('/^LIMIT_MATCH=(\d++)$/i', $verb, $matches)) {
            return new self($verb, null, null, (int) $matches[1]);
        }

        if (1 === preg_match('/^(scs|scan_substring):/', $verb, $scan)) {
            return new self($verb, scanSubstring: $scan[1]);
        }

        foreach (self::SCRIPT_RUN_PREFIXES as $prefix => $atomic) {
            if (!str_starts_with($verb, $prefix)) {
                continue;
            }

            $payload = substr($verb, \strlen($prefix));
            if ('' !== $payload) {
                return new self($verb, null, $payload, null, \strlen($prefix), atomicScriptRun: $atomic);
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
     * @param bool $pastTheDigit whether a name starting with a digit is
     *                           refused past the digit, as from PCRE2 10.47
     * @param bool $unicode      whether names are read in UTF mode
     *
     * @return array{0: int, 1: ErrorCode, 2: string}|null the offset, the
     *                                                     code and the message
     */
    public static function groupListFault(string $pattern, int $open, bool $pastTheDigit = true, bool $unicode = false): ?array
    {
        if ('(' !== ($pattern[$open] ?? '')) {
            return [$open, ErrorCode::ScanSubstringMissingList, \sprintf('Missing "(" to open the list of groups at position %d.', $open)];
        }

        $at = $open + 1;
        while (true) {
            $quote = $pattern[$at] ?? '';
            if ('<' === $quote || "'" === $quote) {
                $nameStart = $at + 1;
                if (1 === preg_match($unicode ? '/\G\p{Nd}/u' : '/\G[0-9]/', $pattern, $digit, 0, $nameStart)) {
                    $offset = $nameStart + ($pastTheDigit ? \strlen($digit[0]) : 0);

                    return [$offset, ErrorCode::GroupNameInvalid, \sprintf('A group name must not start with a digit, at position %d.', $offset)];
                }

                preg_match($unicode ? '/\G[_\p{L}\p{Nd}]*+/u' : '/\G\w*+/', $pattern, $name, 0, $nameStart);
                $nameEnd = $nameStart + \strlen($name[0] ?? '');
                if ($nameEnd === $nameStart) {
                    return [$nameStart, ErrorCode::GroupNameExpected, \sprintf('Group name expected at position %d.', $nameStart)];
                }

                if (('<' === $quote ? '>' : "'") !== ($pattern[$nameEnd] ?? '')) {
                    return [$nameEnd, ErrorCode::GroupNameUnterminated, \sprintf('Missing "%s" to close the group name at position %d.', '<' === $quote ? '>' : "'", $nameEnd)];
                }

                $at = $nameEnd + 1;
            } elseif (1 === preg_match('/\G[+-]?\d++/', $pattern, $number, 0, $at)) {
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
     * Where PCRE stops reading the value of "(*LIMIT_MATCH=n)" and the other
     * limits, the "(" at $start, when that value is malformed or unclosed;
     * null when it is well formed, or no limit starts there. With no digit,
     * PCRE stops after the "="; after digits, from PCRE2 10.45 on the first
     * other character, and before on the one after it.
     */
    public static function limitValueErrorOffset(string $pattern, int $start, bool $pcre1045): ?int
    {
        if (1 !== preg_match('/\G\(\*LIMIT_(?:MATCH|HEAP|DEPTH|RECURSION)=/', $pattern, $matches, 0, $start)) {
            return null;
        }

        $at = $start + \strlen($matches[0]);
        $digits = strspn($pattern, '0123456789', $at);
        if (0 === $digits) {
            return $at;
        }

        return ')' === ($pattern[$at + $digits] ?? '') ? null : $at + $digits + ($pcre1045 ? 0 : 1);
    }

    public function isScriptRun(): bool
    {
        return null === $this->assertion && null !== $this->payload;
    }
}
