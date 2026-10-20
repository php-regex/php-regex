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

namespace PHPRegex\Tests\TestUtils;

/**
 * The error codes that may report each compile error PCRE2 names.
 *
 * A consumer switches on the code: the code must name the problem PCRE
 * reports. One message may cover several distinct problems (a missing group
 * reached by a backreference, a subroutine call or a condition), so a
 * message allows several codes; a message that names one construct never
 * allows the "(?"-syntax catch-all.
 *
 * The keys are the texts PHP 8.4 with PCRE2 10.49 prints between
 * "Compilation failed: " and " at offset N". A message listed with no code
 * is one whose code is still to be decided. A code that names what only
 * some releases report under its message is held to them in RELEASES:
 * codesFor() gives the codes a message allows on one release.
 */
final class PcreMessageCodes
{
    public const CODES = [
        // The wording of the older releases the suite runs on: PCRE2 10.40 to 10.44.
        'lookbehind assertion is not fixed length' => ['regex.lookbehind.unbounded', 'regex.lookbehind.variable_length_not_supported'],
        'assertion expected after (?( or (?(?C)' => ['regex.conditional.assertion_expected'],
        'digits missing in \\x{} or \\o{} or \\N{U+}' => ['regex.escape.digits_missing'],
        '\\ at end of pattern' => ['regex.escape.trailing_backslash'],
        'digit expected after (?+ or (?-' => ['regex.subroutine.invalid_syntax'],
        'subpattern name is too long (maximum 32 code units)' => ['regex.group.name_too_long'],

        // The wording of PCRE2 10.32, and of 10.33 for some.
        'invalid escape sequence in character class' => ['regex.charclass.invalid_escape'],
        'syntax error in subpattern name (missing terminator)' => ['regex.group.name_unterminated'],
        'group name must start with a non-digit' => ['regex.group.name_invalid'],
        'unknown property name after \\P or \\p' => ['regex.unicode.property_invalid'],
        'subpattern name is too long (maximum 32 characters)' => ['regex.group.name_too_long'],
        'group number is too big' => ['regex.group.number_too_big'],
        'conditional group contains more than two branches' => ['regex.conditional.too_many_branches'],
        'DEFINE group contains more than one branch' => ['regex.define.too_many_branches'],

        // PCRE2 10.45 to 10.47 fail on an operator of an extended class
        // their walk over the lookbehinds meets.
        'internal error: unknown meta code in check_lookbehinds()' => ['regex.extended_class.beside_lookbehind'],

        // The library refuses "\C" under "u" anywhere, on purpose.
        '\\C is not allowed in a lookbehind assertion in UTF-8 mode' => ['regex.escape.single_byte_in_utf'],

        // Verbs and alpha assertions.
        '(*MARK) must have an argument' => ['regex.verb.mark_name_missing'],
        // A start-of-pattern verb placed later, and a verb left open, are
        // both refused under this message.
        // A limit past 4294967289 is malformed too.
        '(*VERB) not recognized or malformed' => ['regex.verb.invalid', 'regex.verb.misplaced', 'regex.verb.unclosed', 'regex.verb.limit_too_large'],
        'name is too long in (*MARK), (*PRUNE), (*SKIP), or (*THEN)' => ['regex.verb.name_too_long'],
        '(*alpha_assertion) not recognized' => ['regex.verb.invalid'],

        // Group openers and inline options.
        'unrecognized character after (? or (?-' => ['regex.group.syntax'],
        'unrecognized character after (?P' => ['regex.group.syntax'],
        'invalid hyphen in option setting' => ['regex.group.option_hyphen'],
        'missing closing parenthesis' => ['regex.group.unclosed', 'regex.extended_class.unclosed_paren', 'regex.verb.unclosed'],
        'unmatched closing parenthesis' => ['regex.group.unmatched_close', 'regex.extended_class.unmatched_close'],
        'missing opening parenthesis' => ['regex.scan_substring.missing_list'],
        'subpattern number is too big' => ['regex.group.number_too_big'],
        // Undecided: "(?+" with no digit names a relative call, which has no
        // syntax code of its own; "(?R" followed by anything but ")".
        'digit expected after (?+' => ['regex.subroutine.invalid_syntax'],
        '(?R (recursive pattern call) must be followed by a closing parenthesis' => ['regex.subroutine.invalid_syntax'],

        // Group names.
        // In a condition, what cannot start a name is no condition at all:
        // "(?(+a)", "(?({2})", "(?( VERSION=10)".
        'subpattern name expected' => ['regex.group.name_expected', 'regex.conditional.invalid'],
        'subpattern name must start with a non-digit' => ['regex.group.name_invalid'],
        'syntax error in subpattern name (missing terminator?)' => ['regex.group.name_unterminated'],
        'subpattern name is too long (maximum 128 code units)' => ['regex.group.name_too_long'],
        'two named subpatterns have the same name (PCRE2_DUPNAMES not set)' => ['regex.group.duplicate_name'],
        'different names for subpatterns of the same number are not allowed' => ['regex.group.name_conflict'],

        // References: backreferences, subroutine calls, conditions and the
        // group lists of a scan substring or a call all name a group.
        'reference to non-existent subpattern' => [
            'regex.backref.missing_group',
            'regex.backref.missing_named_group',
            'regex.backref.relative',
            'regex.backref.zero',
            'regex.subroutine.missing_group',
            'regex.subroutine.missing_named_group',
            'regex.subroutine.relative_missing',
            'regex.subroutine.recursion',
            'regex.group_list.missing_group',
            'regex.conditional.missing_group',
        ],
        'a relative value of zero is not allowed' => ['regex.backref.zero', 'regex.subroutine.relative_zero', 'regex.group_list.relative_zero'],
        'syntax error in subpattern number (missing terminator?)' => ['regex.backref.invalid_syntax', 'regex.subroutine.invalid_syntax'],
        '\\g is not followed by a braced, angle-bracketed, or quoted name/number or by a plain number' => ['regex.backref.invalid_syntax'],
        '\\k is not followed by a braced, angle-bracketed, or quoted name' => ['regex.backref.invalid_syntax'],
        'expected capture group number or name' => ['regex.group_list.item_expected'],

        // Conditions.
        'atomic assertion expected after (?( or (?(?C)' => ['regex.conditional.assertion_expected'],
        // PCRE reports a list of groups left open, "(?1(1x))" or
        // "(*scs:(1x)a)", with the message of a condition.
        'missing closing parenthesis for condition' => ['regex.conditional.unclosed', 'regex.group.unclosed'],
        'conditional subpattern contains more than two branches' => ['regex.conditional.too_many_branches'],
        'DEFINE subpattern contains more than one branch' => ['regex.define.too_many_branches'],
        'syntax error or number too big in (?(VERSION condition' => ['regex.conditional.version_syntax', 'regex.conditional.version_operator'],

        // Callouts.
        'unrecognized string delimiter follows (?C' => ['regex.callout.invalid_delimiter'],
        'number after (?C is greater than 255' => ['regex.callout.out_of_range'],
        'missing terminating delimiter for callout with string argument' => ['regex.callout.unclosed_string'],
        'closing parenthesis for (?C expected' => ['regex.callout.unclosed'],

        // Quantifiers.
        'quantifier does not follow a repeatable item' => ['regex.quantifier.nothing_to_repeat'],
        'number too big in {} quantifier' => ['regex.quantifier.too_big'],
        'numbers out of order in {} quantifier' => ['regex.quantifier.invalid_range'],

        // Comments.
        'missing ) after (?# comment' => ['regex.comment.unclosed'],

        // Classes and POSIX names.
        'missing terminating ] for character class' => ['regex.charclass.unclosed', 'regex.extended_class.unclosed'],
        'escape sequence is invalid in character class' => ['regex.charclass.invalid_escape'],
        '\\N is not supported in a class' => ['regex.charclass.invalid_escape', 'regex.escape.unsupported'],
        'invalid range in character class' => ['regex.range.invalid_bounds'],
        'range out of order in character class' => ['regex.range.reversed'],
        'unknown POSIX class name' => ['regex.posix.invalid'],
        'POSIX collating elements are not supported' => ['regex.posix.collating_element'],
        'POSIX named classes are supported only within a class' => ['regex.posix.outside_class'],

        // Extended classes, PCRE2 10.45.
        'empty expression in extended character class' => ['regex.extended_class.empty_expression'],
        'extended character class nesting is too deep' => ['regex.extended_class.nested_too_deep'],
        'terminating ] with no following closing parenthesis in (?[...]' => ['regex.extended_class.bracket_without_paren'],
        'unexpected character in (?[...]) extended character class' => ['regex.extended_class.unexpected_character'],
        'unexpected expression in extended character class (no preceding operator)' => ['regex.extended_class.missing_operator'],
        'unexpected operator in extended character class (no preceding operand)' => ['regex.extended_class.missing_operand'],
        'expected operand after operator in extended character class' => ['regex.extended_class.missing_operand'],

        // Escapes.
        'unrecognized character follows \\' => ['regex.escape.unrecognized'],
        'PCRE2 does not support \\F, \\L, \\l, \\N{name}, \\U, or \\u' => ['regex.escape.unsupported'],
        '\\c at end of pattern' => ['regex.control_char.invalid'],
        '\\c must be followed by a printable ASCII character' => ['regex.control_char.invalid'],
        'digits missing after \\x or in \\x{} or \\o{} or \\N{U+}' => ['regex.escape.digits_missing'],
        'non-hex character in \\x{} (closing brace missing?)' => ['regex.unicode.invalid_digit'],
        'non-octal character in \\o{} (closing brace missing?)' => ['regex.octal.invalid_digit'],
        'missing opening brace after \\o' => ['regex.octal.missing_brace'],
        'octal value is greater than \\377 in 8-bit non-UTF-8 mode' => ['regex.octal.out_of_range'],
        'character code point value in \\x{} or \\o{} is too large' => ['regex.unicode.out_of_range', 'regex.octal.out_of_range'],
        'disallowed Unicode code point (>= 0xd800 && <= 0xdfff)' => ['regex.unicode.surrogate'],
        '\\N{U+dddd} is supported only in Unicode (UTF) mode' => ['regex.unicode_named.requires_utf'],
        'unknown property after \\P or \\p' => ['regex.unicode.property_invalid'],
        'malformed \\P or \\p sequence' => ['regex.unicode.property_malformed'],

        // Lookbehinds.
        'length of lookbehind assertion is not limited' => ['regex.lookbehind.unbounded'],
        'lookbehind assertion is too long' => ['regex.lookbehind.too_long'],
        'branch too long in variable-length lookbehind assertion' => ['regex.lookbehind.too_long'],
        'lookbehind is too complicated' => ['regex.lookbehind.too_complex'],
    ];

    /**
     * The codes of a message that name what only some releases report under
     * it: the first and the last of those releases, null for no bound.
     */
    public const RELEASES = [
        // The extended classes and the lists of groups arrived in 10.45; from
        // 10.47 an alphabetic name the pattern ends with misses its ")".
        'missing closing parenthesis' => ['regex.extended_class.unclosed_paren' => ['10.45', null], 'regex.verb.unclosed' => ['10.47', null]],
        'missing terminating ] for character class' => ['regex.extended_class.unclosed' => ['10.45', null]],
        'unmatched closing parenthesis' => ['regex.extended_class.unmatched_close' => ['10.45', null]],
        'reference to non-existent subpattern' => ['regex.group_list.missing_group' => ['10.45', null]],
        'a relative value of zero is not allowed' => ['regex.group_list.relative_zero' => ['10.45', null]],
    ];

    /**
     * The codes that may report the message on a PCRE2 release.
     *
     * @return list<string>
     */
    public static function codesFor(string $message, string $release): array
    {
        $codes = [];
        foreach (self::CODES[$message] ?? [] as $code) {
            [$first, $last] = self::RELEASES[$message][$code] ?? [null, null];
            if (self::within($release, $first, $last)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * The message and offset of PHP's "Compilation failed" warning, or the
     * whole warning when it is of another kind.
     *
     * @return array{message: string, offset: int|null}
     */
    public static function read(string $warning): array
    {
        $warning = (string) preg_replace('/^preg_\w+\(\): /', '', $warning);

        if (1 === preg_match('/^Compilation failed: (.*) at offset (\d+)$/s', $warning, $matches)) {
            return ['message' => $matches[1], 'offset' => (int) $matches[2]];
        }

        return ['message' => $warning, 'offset' => null];
    }

    /**
     * The warning the running PHP raises compiling the pattern, or null
     * when it compiles.
     */
    public static function warningOf(string $pattern): ?string
    {
        $warning = null;
        set_error_handler(static function (int $level, string $text) use (&$warning): bool {
            $warning = $text;

            return true;
        });

        try {
            $compiled = preg_match($pattern, '');
        } finally {
            restore_error_handler();
        }

        return false === $compiled ? ($warning ?? '') : null;
    }

    /**
     * Whether the release is one of $first to $last, null for no bound.
     */
    private static function within(string $release, ?string $first, ?string $last): bool
    {
        return (null === $first || version_compare($release, $first, '>=')) && (null === $last || version_compare($release, $last, '<='));
    }
}
