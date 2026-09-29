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
