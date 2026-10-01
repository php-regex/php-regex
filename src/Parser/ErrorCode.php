<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Parser;

/**
 * What is wrong with a refused pattern, as a stable code a caller can match
 * on: every RegexException carries one, and so does a failed validation.
 * Each value reads "regex.<area>.<problem>", in snake_case.
 */
enum ErrorCode: string
{
    /**
     * An escape written as an assertion is not one PCRE knows.
     */
    case AssertionInvalid = 'regex.assertion.invalid';

    /**
     * A backreference is not written in a form PCRE reads.
     */
    case BackrefInvalidSyntax = 'regex.backref.invalid_syntax';

    /**
     * A backreference points to a group number the pattern does not have.
     */
    case BackrefMissingGroup = 'regex.backref.missing_group';

    /**
     * A backreference names a group the pattern does not have.
     */
    case BackrefMissingNamedGroup = 'regex.backref.missing_named_group';

    /**
     * A relative backreference points before the first group or past the last one.
     */
    case BackrefRelative = 'regex.backref.relative';

    /**
     * A backreference points to group 0, which is the whole match and no group.
     */
    case BackrefZero = 'regex.backref.zero';

    /**
     * A callout string opens with a character that is no string delimiter.
     */
    case CalloutInvalidDelimiter = 'regex.callout.invalid_delimiter';

    /**
     * A callout number is above 255.
     */
    case CalloutOutOfRange = 'regex.callout.out_of_range';

    /**
     * A callout argument is not followed by ")".
     */
    case CalloutUnclosed = 'regex.callout.unclosed';

    /**
     * A callout string is not closed by its delimiter.
     */
    case CalloutUnclosedString = 'regex.callout.unclosed_string';

    /**
     * An escape is not allowed inside a character class.
     */
    case CharclassInvalidEscape = 'regex.charclass.invalid_escape';

    /**
     * A character class is not closed by "]".
     */
    case CharclassUnclosed = 'regex.charclass.unclosed';

    /**
     * A "(?#" comment is not closed by ")".
     */
    case CommentUnclosed = 'regex.comment.unclosed';

    /**
     * The pattern is past the budget of the automata analysis.
     */
    case Complexity = 'regex.complexity';

    /**
     * A conditional group needs a lookaround assertion as its condition here.
     */
    case ConditionAssertionExpected = 'regex.condition.assertion_expected';

    /**
     * A condition names a group the pattern does not have.
     */
    case ConditionMissingGroup = 'regex.condition.missing_group';

    /**
     * The condition of a conditional group is not closed by ")".
     */
    case ConditionUnclosed = 'regex.condition.unclosed';

    /**
     * A VERSION condition compares with an operator other than "=" or ">=".
     */
    case ConditionVersionOperator = 'regex.condition.version_operator';

    /**
     * A VERSION condition does not name a version as major.minor.
     */
    case ConditionVersionSyntax = 'regex.condition.version_syntax';

    /**
     * A condition is neither a group reference, a lookaround, nor DEFINE.
     */
    case ConditionalInvalid = 'regex.conditional.invalid';

    /**
     * A conditional group has more than two branches.
     */
    case ConditionalTooManyBranches = 'regex.conditional.too_many_branches';

    /**
     * "\c" is not followed by a printable ASCII character.
     */
    case ControlCharInvalid = 'regex.control_char.invalid';

    /**
     * A "(?(DEFINE)...)" group has more than one branch.
     */
    case DefineTooManyBranches = 'regex.define.too_many_branches';

    /**
     * The pattern opens with an alphanumeric, backslash or NUL delimiter.
     */
    case DelimiterInvalid = 'regex.delimiter.invalid';

    /**
     * The pattern has no closing delimiter.
     */
    case DelimiterUnclosed = 'regex.delimiter.unclosed';

    /**
     * An unescaped delimiter ends the pattern early, and what follows reads as modifiers.
     */
    case DelimiterUnescaped = 'regex.delimiter.unescaped';

    /**
     * The pattern is not valid UTF-8 under the "u" modifier.
     */
    case EncodingInvalidUtf8 = 'regex.encoding.invalid_utf8';

    /**
     * An escape such as "\x{}", "\o{}" or "\N{U+}" holds no digit.
     */
    case EscapeDigitsMissing = 'regex.escape.digits_missing';

    /**
     * "\C" matches a single byte, which UTF mode does not allow.
     */
    case EscapeSingleByteInUtf = 'regex.escape.single_byte_in_utf';

    /**
     * A backslash ends the pattern with nothing to escape.
     */
    case EscapeTrailingBackslash = 'regex.escape.trailing_backslash';

    /**
     * A backslash is followed by a letter PCRE knows no escape for.
     */
    case EscapeUnrecognized = 'regex.escape.unrecognized';

    /**
     * The escape is not supported by PCRE, or not by the targeted PCRE release.
     */
    case EscapeUnsupported = 'regex.escape.unsupported';

    /**
     * The "]" closing an extended class is not followed by ")".
     */
    case ExtendedClassBracketWithoutParen = 'regex.extended_class.bracket_without_paren';

    /**
     * An extended class, or a parenthesis in it, holds no expression.
     */
    case ExtendedClassEmptyExpression = 'regex.extended_class.empty_expression';

    /**
     * An operator in an extended class has no operand before or after it.
     */
    case ExtendedClassMissingOperand = 'regex.extended_class.missing_operand';

    /**
     * Two operands in an extended class have no operator between them.
     */
    case ExtendedClassMissingOperator = 'regex.extended_class.missing_operator';

    /**
     * Parentheses in an extended class are nested deeper than PCRE allows.
     */
    case ExtendedClassNestedTooDeep = 'regex.extended_class.nested_too_deep';

    /**
     * An extended class runs out of the operation budget.
     */
    case ExtendedClassTooComplex = 'regex.extended_class.too_complex';

    /**
     * An extended class "(?[" is not closed by "])".
     */
    case ExtendedClassUnclosed = 'regex.extended_class.unclosed';

    /**
     * A parenthesis in an extended class is not closed by ")".
     */
    case ExtendedClassUnclosedParen = 'regex.extended_class.unclosed_paren';

    /**
     * An extended class holds a character that is no operand and no operator.
     */
    case ExtendedClassUnexpectedCharacter = 'regex.extended_class.unexpected_character';

    /**
     * A ")" in an extended class closes no open parenthesis.
     */
    case ExtendedClassUnmatchedClose = 'regex.extended_class.unmatched_close';

    /**
     * The "e" modifier was removed in PHP 7.0.
     */
    case FlagRemovedE = 'regex.flag.removed_e';

    /**
     * A modifier after the closing delimiter is not one PHP knows.
     */
    case FlagUnknown = 'regex.flag.unknown';

    /**
     * No sample the pattern matches was found.
     */
    case GenerateNoMatch = 'regex.generate.no_match';

    /**
     * Two groups share a name, which needs the "J" modifier or "(?J)".
     */
    case GroupDuplicateName = 'regex.group.duplicate_name';

    /**
     * Groups of the same number in a branch reset have different names.
     */
    case GroupNameConflict = 'regex.group.name_conflict';

    /**
     * A group name is expected where none is written.
     */
    case GroupNameExpected = 'regex.group.name_expected';

    /**
     * A group name holds a non-word character, or starts with a digit.
     */
    case GroupNameInvalid = 'regex.group.name_invalid';

    /**
     * A group name is longer than PCRE allows.
     */
    case GroupNameTooLong = 'regex.group.name_too_long';

    /**
     * A group name is not closed by its ">", "'" or "}".
     */
    case GroupNameUnterminated = 'regex.group.name_unterminated';

    /**
     * Groups are nested deeper than PCRE allows.
     */
    case GroupNestedTooDeep = 'regex.group.nested_too_deep';

    /**
     * A group number is above 65535.
     */
    case GroupNumberTooBig = 'regex.group.number_too_big';

    /**
     * An option setting has a hyphen PCRE does not take: a second one, or one after "(?^".
     */
    case GroupOptionHyphen = 'regex.group.option_hyphen';

    /**
     * A "(?" is followed by a character PCRE does not read there.
     */
    case GroupSyntax = 'regex.group.syntax';

    /**
     * A group is not closed by ")".
     */
    case GroupUnclosed = 'regex.group.unclosed';

    /**
     * A ")" closes no open group.
     */
    case GroupUnmatchedClose = 'regex.group.unmatched_close';

    /**
     * A list of groups, as "(*scs:(1,<n>)" or "(?1(2))" holds, has an item that is no group number or name.
     */
    case GroupListItemExpected = 'regex.group_list.item_expected';

    /**
     * A list of groups, as "(*scs:(1,<n>)" or "(?1(2))" holds, names a group the pattern does not have.
     */
    case GroupListMissingGroup = 'regex.group_list.missing_group';

    /**
     * A list of groups, as "(*scs:(1,<n>)" or "(?1(2))" holds, has the relative number zero.
     */
    case GroupListRelativeZero = 'regex.group_list.relative_zero';

    /**
     * PCRE failed while the library was reading the pattern.
     */
    case InternalPcreFailure = 'regex.internal.pcre_failure';

    /**
     * The library reached a state it does not expect, which is a bug to report.
     */
    case InternalUnexpectedState = 'regex.internal.unexpected_state';

    /**
     * "\K" is used inside a lookaround, which the targeted PHP refuses.
     */
    case KeepInLookaround = 'regex.keep.in_lookaround';

    /**
     * A lookbehind is too complicated for PCRE to measure.
     */
    case LookbehindTooComplex = 'regex.lookbehind.too_complex';

    /**
     * A lookbehind is longer than PCRE allows.
     */
    case LookbehindTooLong = 'regex.lookbehind.too_long';

    /**
     * A lookbehind can match text of unbounded length.
     */
    case LookbehindUnbounded = 'regex.lookbehind.unbounded';

    /**
     * A lookbehind of variable length needs a newer PCRE than the target.
     */
    case LookbehindVariableLengthNotSupported = 'regex.lookbehind.variable_length_not_supported';

    /**
     * The pattern nests deeper than the configured recursion limit.
     */
    case NestingTooDeep = 'regex.nesting.too_deep';

    /**
     * An octal escape holds a digit that is not octal.
     */
    case OctalInvalidDigit = 'regex.octal.invalid_digit';

    /**
     * "\o" is not followed by "{".
     */
    case OctalMissingBrace = 'regex.octal.missing_brace';

    /**
     * An octal escape names a code point past the allowed maximum.
     */
    case OctalOutOfRange = 'regex.octal.out_of_range';

    /**
     * The pattern is empty, or only whitespace.
     */
    case PatternEmpty = 'regex.pattern.empty';

    /**
     * The pattern compiles to more than PCRE's 64 KiB.
     */
    case PatternTooLarge = 'regex.pattern.too_large';

    /**
     * The pattern is longer than the configured maximum length.
     */
    case PatternTooLong = 'regex.pattern.too_long';

    /**
     * PHP refused to compile the pattern.
     */
    case PcreRuntime = 'regex.pcre.runtime';

    /**
     * A POSIX collating element such as "[.a.]" or "[=a=]" is not supported.
     */
    case PosixCollatingElement = 'regex.posix.collating_element';

    /**
     * A POSIX class name is not one PCRE knows.
     */
    case PosixInvalid = 'regex.posix.invalid';

    /**
     * A POSIX class is written outside a character class.
     */
    case PosixOutsideClass = 'regex.posix.outside_class';

    /**
     * A "{min,max}" quantifier has its numbers out of order.
     */
    case QuantifierInvalidRange = 'regex.quantifier.invalid_range';

    /**
     * A quantifier follows nothing it can repeat.
     */
    case QuantifierNothingToRepeat = 'regex.quantifier.nothing_to_repeat';

    /**
     * A quantifier number is above 65535.
     */
    case QuantifierTooBig = 'regex.quantifier.too_big';

    /**
     * A range in a character class runs from or to something that is not a character.
     */
    case RangeInvalidBounds = 'regex.range.invalid_bounds';

    /**
     * A range in a character class ends on more than one character.
     */
    case RangeInvalidEnd = 'regex.range.invalid_end';

    /**
     * A range in a character class starts on more than one character.
     */
    case RangeInvalidStart = 'regex.range.invalid_start';

    /**
     * A range in a character class runs from a higher code point to a lower one.
     */
    case RangeReversed = 'regex.range.reversed';

    /**
     * A scan substring assertion has no "(" to open its group list.
     */
    case ScanSubstringMissingList = 'regex.scan_substring.missing_list';

    /**
     * A subroutine call is not written in a form PCRE reads.
     */
    case SubroutineInvalidSyntax = 'regex.subroutine.invalid_syntax';

    /**
     * A subroutine call points to a group number the pattern does not have.
     */
    case SubroutineMissingGroup = 'regex.subroutine.missing_group';

    /**
     * A subroutine call names a group the pattern does not have.
     */
    case SubroutineMissingNamedGroup = 'regex.subroutine.missing_named_group';

    /**
     * A recursion condition points to a group the pattern does not have.
     */
    case SubroutineRecursion = 'regex.subroutine.recursion';

    /**
     * A relative subroutine call points before the first group or past the last one.
     */
    case SubroutineRelativeMissing = 'regex.subroutine.relative_missing';

    /**
     * A subroutine call holds the relative number zero.
     */
    case SubroutineRelativeZero = 'regex.subroutine.relative_zero';

    /**
     * A token stands where the pattern cannot take it.
     */
    case TokenUnexpected = 'regex.token.unexpected';

    /**
     * The pattern holds a construct the target dialect cannot express.
     */
    case TranspileUnsupported = 'regex.transpile.unsupported';

    /**
     * A braced escape holds a character that is not a hexadecimal digit.
     */
    case UnicodeInvalidDigit = 'regex.unicode.invalid_digit';

    /**
     * A code point is past U+10FFFF, or past 0xFF outside UTF mode.
     */
    case UnicodeOutOfRange = 'regex.unicode.out_of_range';

    /**
     * A Unicode property is unknown, or needs a newer PCRE than the target.
     */
    case UnicodePropertyInvalid = 'regex.unicode.property_invalid';

    /**
     * A "\p" or "\P" escape is not written in a form PCRE reads.
     */
    case UnicodePropertyMalformed = 'regex.unicode.property_malformed';

    /**
     * A code point is a surrogate, which UTF mode does not allow.
     */
    case UnicodeSurrogate = 'regex.unicode.surrogate';

    /**
     * "\N{U+hhhh}" needs the "u" modifier.
     */
    case UnicodeNamedRequiresUtf = 'regex.unicode_named.requires_utf';

    /**
     * "(*TURKISH_CASING)" and "(*CASELESS_RESTRICT)" are used together.
     */
    case VerbConflictingCasings = 'regex.verb.conflicting_casings';

    /**
     * A verb, or an alphabetic assertion, is unknown or malformed.
     */
    case VerbInvalid = 'regex.verb.invalid';

    /**
     * A "(*LIMIT_...)" value is larger than PCRE takes.
     */
    case VerbLimitTooLarge = 'regex.verb.limit_too_large';

    /**
     * "(*MARK)" has no name.
     */
    case VerbMarkNameMissing = 'regex.verb.mark_name_missing';

    /**
     * A start-of-pattern verb is written past the start of the pattern.
     */
    case VerbMisplaced = 'regex.verb.misplaced';

    /**
     * The name of a verb is longer than PCRE allows.
     */
    case VerbNameTooLong = 'regex.verb.name_too_long';

    /**
     * "(*TURKISH_CASING)" is used without UTF mode.
     */
    case VerbTurkishCasingWithoutUtf = 'regex.verb.turkish_casing_without_utf';

    /**
     * A verb such as "(*MARK:name" is not closed by ")".
     */
    case VerbUnclosed = 'regex.verb.unclosed';
}
