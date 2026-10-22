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

namespace PHPRegex\Parser;

class ErrorCode
{
    public const AssertionInvalid = 'regex.assertion.invalid';
    public const BackrefInvalidSyntax = 'regex.backref.invalid_syntax';
    public const BackrefMissingGroup = 'regex.backref.missing_group';
    public const BackrefMissingNamedGroup = 'regex.backref.missing_named_group';
    public const BackrefRelative = 'regex.backref.relative';
    public const BackrefZero = 'regex.backref.zero';
    public const CalloutInvalidDelimiter = 'regex.callout.invalid_delimiter';
    public const CalloutOutOfRange = 'regex.callout.out_of_range';
    public const CalloutUnclosed = 'regex.callout.unclosed';
    public const CalloutUnclosedString = 'regex.callout.unclosed_string';
    public const CharclassInvalidEscape = 'regex.charclass.invalid_escape';
    public const CharclassUnclosed = 'regex.charclass.unclosed';
    public const CommentUnclosed = 'regex.comment.unclosed';
    public const Complexity = 'regex.complexity';
    public const ConditionalAssertionExpected = 'regex.conditional.assertion_expected';
    public const ConditionalMissingGroup = 'regex.conditional.missing_group';
    public const ConditionalUnclosed = 'regex.conditional.unclosed';
    public const ConditionalVersionOperator = 'regex.conditional.version_operator';
    public const ConditionalVersionSyntax = 'regex.conditional.version_syntax';
    public const ConditionalInvalid = 'regex.conditional.invalid';
    public const ConditionalTooManyBranches = 'regex.conditional.too_many_branches';
    public const ControlCharInvalid = 'regex.control_char.invalid';
    public const DefineTooManyBranches = 'regex.define.too_many_branches';
    public const DelimiterInvalid = 'regex.delimiter.invalid';
    public const DelimiterUnclosed = 'regex.delimiter.unclosed';
    public const DelimiterUnescaped = 'regex.delimiter.unescaped';
    public const EncodingInvalidUtf8 = 'regex.encoding.invalid_utf8';
    public const EscapeDigitsMissing = 'regex.escape.digits_missing';
    public const EscapeSingleByteInUtf = 'regex.escape.single_byte_in_utf';
    public const EscapeTrailingBackslash = 'regex.escape.trailing_backslash';
    public const EscapeUnrecognized = 'regex.escape.unrecognized';
    public const EscapeUnsupported = 'regex.escape.unsupported';
    public const ExtendedClassBesideLookbehind = 'regex.extended_class.beside_lookbehind';
    public const ExtendedClassBracketWithoutParen = 'regex.extended_class.bracket_without_paren';
    public const ExtendedClassEmptyExpression = 'regex.extended_class.empty_expression';
    public const ExtendedClassMissingOperand = 'regex.extended_class.missing_operand';
    public const ExtendedClassMissingOperator = 'regex.extended_class.missing_operator';
    public const ExtendedClassNestedTooDeep = 'regex.extended_class.nested_too_deep';
    public const ExtendedClassTooComplex = 'regex.extended_class.too_complex';
    public const ExtendedClassUnclosed = 'regex.extended_class.unclosed';
    public const ExtendedClassUnclosedParen = 'regex.extended_class.unclosed_paren';
    public const ExtendedClassUnexpectedCharacter = 'regex.extended_class.unexpected_character';
    public const ExtendedClassUnmatchedClose = 'regex.extended_class.unmatched_close';
    public const FlagRemovedE = 'regex.flag.removed_e';
    public const FlagUnknown = 'regex.flag.unknown';
    public const FormattingUnsafe = 'regex.formatting.unsafe';
    public const GenerateNoMatch = 'regex.generate.no_match';
    public const GroupDuplicateName = 'regex.group.duplicate_name';
    public const GroupNameConflict = 'regex.group.name_conflict';
    public const GroupNameExpected = 'regex.group.name_expected';
    public const GroupNameInvalid = 'regex.group.name_invalid';
    public const GroupNameTooLong = 'regex.group.name_too_long';
    public const GroupNameUnterminated = 'regex.group.name_unterminated';
    public const GroupNestedTooDeep = 'regex.group.nested_too_deep';
    public const GroupNumberTooBig = 'regex.group.number_too_big';
    public const GroupOptionHyphen = 'regex.group.option_hyphen';
    public const GroupSyntax = 'regex.group.syntax';
    public const GroupUnclosed = 'regex.group.unclosed';
    public const GroupUnmatchedClose = 'regex.group.unmatched_close';
    public const GroupListItemExpected = 'regex.group_list.item_expected';
    public const GroupListMissingGroup = 'regex.group_list.missing_group';
    public const GroupListRelativeZero = 'regex.group_list.relative_zero';
    public const InternalPcreFailure = 'regex.internal.pcre_failure';
    public const InternalUnexpectedState = 'regex.internal.unexpected_state';
    public const KeepInLookaround = 'regex.keep.in_lookaround';
    public const LookbehindTooComplex = 'regex.lookbehind.too_complex';
    public const LookbehindTooLong = 'regex.lookbehind.too_long';
    public const LookbehindUnbounded = 'regex.lookbehind.unbounded';
    public const LookbehindVariableLengthNotSupported = 'regex.lookbehind.variable_length_not_supported';
    public const NestingTooDeep = 'regex.nesting.too_deep';
    public const OctalInvalidDigit = 'regex.octal.invalid_digit';
    public const OctalMissingBrace = 'regex.octal.missing_brace';
    public const OctalOutOfRange = 'regex.octal.out_of_range';
    public const PatternEmpty = 'regex.pattern.empty';
    public const PatternNulByte = 'regex.pattern.nul_byte';
    public const PatternTooLarge = 'regex.pattern.too_large';
    public const PatternTooLong = 'regex.pattern.too_long';
    public const PcreRuntime = 'regex.pcre.runtime';
    public const PosixCollatingElement = 'regex.posix.collating_element';
    public const PosixInvalid = 'regex.posix.invalid';
    public const PosixOutsideClass = 'regex.posix.outside_class';
    public const QuantifierInvalidRange = 'regex.quantifier.invalid_range';
    public const QuantifierNothingToRepeat = 'regex.quantifier.nothing_to_repeat';
    public const QuantifierTooBig = 'regex.quantifier.too_big';
    public const RangeInvalidBounds = 'regex.range.invalid_bounds';
    public const RangeInvalidEnd = 'regex.range.invalid_end';
    public const RangeInvalidStart = 'regex.range.invalid_start';
    public const RangeReversed = 'regex.range.reversed';
    public const ScanSubstringMissingList = 'regex.scan_substring.missing_list';
    public const SubroutineInvalidSyntax = 'regex.subroutine.invalid_syntax';
    public const SubroutineMissingGroup = 'regex.subroutine.missing_group';
    public const SubroutineMissingNamedGroup = 'regex.subroutine.missing_named_group';
    public const SubroutineRecursion = 'regex.subroutine.recursion';
    public const SubroutineRelativeMissing = 'regex.subroutine.relative_missing';
    public const SubroutineRelativeZero = 'regex.subroutine.relative_zero';
    public const TokenUnexpected = 'regex.token.unexpected';
    public const TranspileUnsupported = 'regex.transpile.unsupported';
    public const UnicodeInvalidDigit = 'regex.unicode.invalid_digit';
    public const UnicodeOutOfRange = 'regex.unicode.out_of_range';
    public const UnicodePropertyInvalid = 'regex.unicode.property_invalid';
    public const UnicodePropertyMalformed = 'regex.unicode.property_malformed';
    public const UnicodeSurrogate = 'regex.unicode.surrogate';
    public const UnicodeNamedRequiresUtf = 'regex.unicode_named.requires_utf';
    public const VerbConflictingCasings = 'regex.verb.conflicting_casings';
    public const VerbInvalid = 'regex.verb.invalid';
    public const VerbLimitTooLarge = 'regex.verb.limit_too_large';
    public const VerbMarkNameMissing = 'regex.verb.mark_name_missing';
    public const VerbMisplaced = 'regex.verb.misplaced';
    public const VerbNameTooLong = 'regex.verb.name_too_long';
    public const VerbTurkishCasingWithoutUtf = 'regex.verb.turkish_casing_without_utf';
    public const VerbUnclosed = 'regex.verb.unclosed';
}
