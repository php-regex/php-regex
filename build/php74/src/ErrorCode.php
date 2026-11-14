<?php

declare(strict_types=1);

namespace PHPRegex\Parser;

/**
 * The ErrorCode enum as a class of shared instances: ErrorCode::AssertionInvalid() returns
 * the same instance at each call.
 */
final class ErrorCode implements BackedEnum
{
    /** @var array<string, self> */
    private static array $instances = [];

    /** @var array<int|string, self>|null */
    private static ?array $byValue = null;

    public string $name;

    public string $value;

    private function __construct(string $name, string $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    public static function AssertionInvalid(): self
    {
        return self::$instances['AssertionInvalid'] ??= new self('AssertionInvalid', 'regex.assertion.invalid');
    }

    public static function BackrefInvalidSyntax(): self
    {
        return self::$instances['BackrefInvalidSyntax'] ??= new self('BackrefInvalidSyntax', 'regex.backref.invalid_syntax');
    }

    public static function BackrefMissingGroup(): self
    {
        return self::$instances['BackrefMissingGroup'] ??= new self('BackrefMissingGroup', 'regex.backref.missing_group');
    }

    public static function BackrefMissingNamedGroup(): self
    {
        return self::$instances['BackrefMissingNamedGroup'] ??= new self('BackrefMissingNamedGroup', 'regex.backref.missing_named_group');
    }

    public static function BackrefRelative(): self
    {
        return self::$instances['BackrefRelative'] ??= new self('BackrefRelative', 'regex.backref.relative');
    }

    public static function BackrefZero(): self
    {
        return self::$instances['BackrefZero'] ??= new self('BackrefZero', 'regex.backref.zero');
    }

    public static function CalloutInvalidDelimiter(): self
    {
        return self::$instances['CalloutInvalidDelimiter'] ??= new self('CalloutInvalidDelimiter', 'regex.callout.invalid_delimiter');
    }

    public static function CalloutOutOfRange(): self
    {
        return self::$instances['CalloutOutOfRange'] ??= new self('CalloutOutOfRange', 'regex.callout.out_of_range');
    }

    public static function CalloutUnclosed(): self
    {
        return self::$instances['CalloutUnclosed'] ??= new self('CalloutUnclosed', 'regex.callout.unclosed');
    }

    public static function CalloutUnclosedString(): self
    {
        return self::$instances['CalloutUnclosedString'] ??= new self('CalloutUnclosedString', 'regex.callout.unclosed_string');
    }

    public static function CharclassInvalidEscape(): self
    {
        return self::$instances['CharclassInvalidEscape'] ??= new self('CharclassInvalidEscape', 'regex.charclass.invalid_escape');
    }

    public static function CharclassUnclosed(): self
    {
        return self::$instances['CharclassUnclosed'] ??= new self('CharclassUnclosed', 'regex.charclass.unclosed');
    }

    public static function CommentUnclosed(): self
    {
        return self::$instances['CommentUnclosed'] ??= new self('CommentUnclosed', 'regex.comment.unclosed');
    }

    public static function Complexity(): self
    {
        return self::$instances['Complexity'] ??= new self('Complexity', 'regex.complexity');
    }

    public static function ConditionalAssertionExpected(): self
    {
        return self::$instances['ConditionalAssertionExpected'] ??= new self('ConditionalAssertionExpected', 'regex.conditional.assertion_expected');
    }

    public static function ConditionalMissingGroup(): self
    {
        return self::$instances['ConditionalMissingGroup'] ??= new self('ConditionalMissingGroup', 'regex.conditional.missing_group');
    }

    public static function ConditionalUnclosed(): self
    {
        return self::$instances['ConditionalUnclosed'] ??= new self('ConditionalUnclosed', 'regex.conditional.unclosed');
    }

    public static function ConditionalVersionOperator(): self
    {
        return self::$instances['ConditionalVersionOperator'] ??= new self('ConditionalVersionOperator', 'regex.conditional.version_operator');
    }

    public static function ConditionalVersionSyntax(): self
    {
        return self::$instances['ConditionalVersionSyntax'] ??= new self('ConditionalVersionSyntax', 'regex.conditional.version_syntax');
    }

    public static function ConditionalInvalid(): self
    {
        return self::$instances['ConditionalInvalid'] ??= new self('ConditionalInvalid', 'regex.conditional.invalid');
    }

    public static function ConditionalTooManyBranches(): self
    {
        return self::$instances['ConditionalTooManyBranches'] ??= new self('ConditionalTooManyBranches', 'regex.conditional.too_many_branches');
    }

    public static function ControlCharInvalid(): self
    {
        return self::$instances['ControlCharInvalid'] ??= new self('ControlCharInvalid', 'regex.control_char.invalid');
    }

    public static function DefineTooManyBranches(): self
    {
        return self::$instances['DefineTooManyBranches'] ??= new self('DefineTooManyBranches', 'regex.define.too_many_branches');
    }

    public static function DelimiterInvalid(): self
    {
        return self::$instances['DelimiterInvalid'] ??= new self('DelimiterInvalid', 'regex.delimiter.invalid');
    }

    public static function DelimiterUnclosed(): self
    {
        return self::$instances['DelimiterUnclosed'] ??= new self('DelimiterUnclosed', 'regex.delimiter.unclosed');
    }

    public static function DelimiterUnescaped(): self
    {
        return self::$instances['DelimiterUnescaped'] ??= new self('DelimiterUnescaped', 'regex.delimiter.unescaped');
    }

    public static function EncodingInvalidUtf8(): self
    {
        return self::$instances['EncodingInvalidUtf8'] ??= new self('EncodingInvalidUtf8', 'regex.encoding.invalid_utf8');
    }

    public static function EscapeDigitsMissing(): self
    {
        return self::$instances['EscapeDigitsMissing'] ??= new self('EscapeDigitsMissing', 'regex.escape.digits_missing');
    }

    public static function EscapeSingleByteInUtf(): self
    {
        return self::$instances['EscapeSingleByteInUtf'] ??= new self('EscapeSingleByteInUtf', 'regex.escape.single_byte_in_utf');
    }

    public static function EscapeTrailingBackslash(): self
    {
        return self::$instances['EscapeTrailingBackslash'] ??= new self('EscapeTrailingBackslash', 'regex.escape.trailing_backslash');
    }

    public static function EscapeUnrecognized(): self
    {
        return self::$instances['EscapeUnrecognized'] ??= new self('EscapeUnrecognized', 'regex.escape.unrecognized');
    }

    public static function EscapeUnsupported(): self
    {
        return self::$instances['EscapeUnsupported'] ??= new self('EscapeUnsupported', 'regex.escape.unsupported');
    }

    public static function ExtendedClassBesideLookbehind(): self
    {
        return self::$instances['ExtendedClassBesideLookbehind'] ??= new self('ExtendedClassBesideLookbehind', 'regex.extended_class.beside_lookbehind');
    }

    public static function ExtendedClassBracketWithoutParen(): self
    {
        return self::$instances['ExtendedClassBracketWithoutParen'] ??= new self('ExtendedClassBracketWithoutParen', 'regex.extended_class.bracket_without_paren');
    }

    public static function ExtendedClassEmptyExpression(): self
    {
        return self::$instances['ExtendedClassEmptyExpression'] ??= new self('ExtendedClassEmptyExpression', 'regex.extended_class.empty_expression');
    }

    public static function ExtendedClassMissingOperand(): self
    {
        return self::$instances['ExtendedClassMissingOperand'] ??= new self('ExtendedClassMissingOperand', 'regex.extended_class.missing_operand');
    }

    public static function ExtendedClassMissingOperator(): self
    {
        return self::$instances['ExtendedClassMissingOperator'] ??= new self('ExtendedClassMissingOperator', 'regex.extended_class.missing_operator');
    }

    public static function ExtendedClassNestedTooDeep(): self
    {
        return self::$instances['ExtendedClassNestedTooDeep'] ??= new self('ExtendedClassNestedTooDeep', 'regex.extended_class.nested_too_deep');
    }

    public static function ExtendedClassTooComplex(): self
    {
        return self::$instances['ExtendedClassTooComplex'] ??= new self('ExtendedClassTooComplex', 'regex.extended_class.too_complex');
    }

    public static function ExtendedClassUnclosed(): self
    {
        return self::$instances['ExtendedClassUnclosed'] ??= new self('ExtendedClassUnclosed', 'regex.extended_class.unclosed');
    }

    public static function ExtendedClassUnclosedParen(): self
    {
        return self::$instances['ExtendedClassUnclosedParen'] ??= new self('ExtendedClassUnclosedParen', 'regex.extended_class.unclosed_paren');
    }

    public static function ExtendedClassUnexpectedCharacter(): self
    {
        return self::$instances['ExtendedClassUnexpectedCharacter'] ??= new self('ExtendedClassUnexpectedCharacter', 'regex.extended_class.unexpected_character');
    }

    public static function ExtendedClassUnmatchedClose(): self
    {
        return self::$instances['ExtendedClassUnmatchedClose'] ??= new self('ExtendedClassUnmatchedClose', 'regex.extended_class.unmatched_close');
    }

    public static function FlagRemovedE(): self
    {
        return self::$instances['FlagRemovedE'] ??= new self('FlagRemovedE', 'regex.flag.removed_e');
    }

    public static function FlagUnknown(): self
    {
        return self::$instances['FlagUnknown'] ??= new self('FlagUnknown', 'regex.flag.unknown');
    }

    public static function FormattingUnsafe(): self
    {
        return self::$instances['FormattingUnsafe'] ??= new self('FormattingUnsafe', 'regex.formatting.unsafe');
    }

    public static function GenerateNoMatch(): self
    {
        return self::$instances['GenerateNoMatch'] ??= new self('GenerateNoMatch', 'regex.generate.no_match');
    }

    public static function GroupDuplicateName(): self
    {
        return self::$instances['GroupDuplicateName'] ??= new self('GroupDuplicateName', 'regex.group.duplicate_name');
    }

    public static function GroupNameConflict(): self
    {
        return self::$instances['GroupNameConflict'] ??= new self('GroupNameConflict', 'regex.group.name_conflict');
    }

    public static function GroupNameExpected(): self
    {
        return self::$instances['GroupNameExpected'] ??= new self('GroupNameExpected', 'regex.group.name_expected');
    }

    public static function GroupNameInvalid(): self
    {
        return self::$instances['GroupNameInvalid'] ??= new self('GroupNameInvalid', 'regex.group.name_invalid');
    }

    public static function GroupNameTooLong(): self
    {
        return self::$instances['GroupNameTooLong'] ??= new self('GroupNameTooLong', 'regex.group.name_too_long');
    }

    public static function GroupNameUnterminated(): self
    {
        return self::$instances['GroupNameUnterminated'] ??= new self('GroupNameUnterminated', 'regex.group.name_unterminated');
    }

    public static function GroupNestedTooDeep(): self
    {
        return self::$instances['GroupNestedTooDeep'] ??= new self('GroupNestedTooDeep', 'regex.group.nested_too_deep');
    }

    public static function GroupNumberTooBig(): self
    {
        return self::$instances['GroupNumberTooBig'] ??= new self('GroupNumberTooBig', 'regex.group.number_too_big');
    }

    public static function GroupOptionHyphen(): self
    {
        return self::$instances['GroupOptionHyphen'] ??= new self('GroupOptionHyphen', 'regex.group.option_hyphen');
    }

    public static function GroupSyntax(): self
    {
        return self::$instances['GroupSyntax'] ??= new self('GroupSyntax', 'regex.group.syntax');
    }

    public static function GroupUnclosed(): self
    {
        return self::$instances['GroupUnclosed'] ??= new self('GroupUnclosed', 'regex.group.unclosed');
    }

    public static function GroupUnmatchedClose(): self
    {
        return self::$instances['GroupUnmatchedClose'] ??= new self('GroupUnmatchedClose', 'regex.group.unmatched_close');
    }

    public static function GroupListItemExpected(): self
    {
        return self::$instances['GroupListItemExpected'] ??= new self('GroupListItemExpected', 'regex.group_list.item_expected');
    }

    public static function GroupListMissingGroup(): self
    {
        return self::$instances['GroupListMissingGroup'] ??= new self('GroupListMissingGroup', 'regex.group_list.missing_group');
    }

    public static function GroupListRelativeZero(): self
    {
        return self::$instances['GroupListRelativeZero'] ??= new self('GroupListRelativeZero', 'regex.group_list.relative_zero');
    }

    public static function InternalPcreFailure(): self
    {
        return self::$instances['InternalPcreFailure'] ??= new self('InternalPcreFailure', 'regex.internal.pcre_failure');
    }

    public static function InternalUnexpectedState(): self
    {
        return self::$instances['InternalUnexpectedState'] ??= new self('InternalUnexpectedState', 'regex.internal.unexpected_state');
    }

    public static function KeepInLookaround(): self
    {
        return self::$instances['KeepInLookaround'] ??= new self('KeepInLookaround', 'regex.keep.in_lookaround');
    }

    public static function LookbehindTooComplex(): self
    {
        return self::$instances['LookbehindTooComplex'] ??= new self('LookbehindTooComplex', 'regex.lookbehind.too_complex');
    }

    public static function LookbehindTooLong(): self
    {
        return self::$instances['LookbehindTooLong'] ??= new self('LookbehindTooLong', 'regex.lookbehind.too_long');
    }

    public static function LookbehindUnbounded(): self
    {
        return self::$instances['LookbehindUnbounded'] ??= new self('LookbehindUnbounded', 'regex.lookbehind.unbounded');
    }

    public static function LookbehindVariableLengthNotSupported(): self
    {
        return self::$instances['LookbehindVariableLengthNotSupported'] ??= new self('LookbehindVariableLengthNotSupported', 'regex.lookbehind.variable_length_not_supported');
    }

    public static function NestingTooDeep(): self
    {
        return self::$instances['NestingTooDeep'] ??= new self('NestingTooDeep', 'regex.nesting.too_deep');
    }

    public static function OctalInvalidDigit(): self
    {
        return self::$instances['OctalInvalidDigit'] ??= new self('OctalInvalidDigit', 'regex.octal.invalid_digit');
    }

    public static function OctalMissingBrace(): self
    {
        return self::$instances['OctalMissingBrace'] ??= new self('OctalMissingBrace', 'regex.octal.missing_brace');
    }

    public static function OctalOutOfRange(): self
    {
        return self::$instances['OctalOutOfRange'] ??= new self('OctalOutOfRange', 'regex.octal.out_of_range');
    }

    public static function PatternEmpty(): self
    {
        return self::$instances['PatternEmpty'] ??= new self('PatternEmpty', 'regex.pattern.empty');
    }

    public static function PatternNulByte(): self
    {
        return self::$instances['PatternNulByte'] ??= new self('PatternNulByte', 'regex.pattern.nul_byte');
    }

    public static function PatternTooLarge(): self
    {
        return self::$instances['PatternTooLarge'] ??= new self('PatternTooLarge', 'regex.pattern.too_large');
    }

    public static function PatternTooLong(): self
    {
        return self::$instances['PatternTooLong'] ??= new self('PatternTooLong', 'regex.pattern.too_long');
    }

    public static function PcreRuntime(): self
    {
        return self::$instances['PcreRuntime'] ??= new self('PcreRuntime', 'regex.pcre.runtime');
    }

    public static function PosixCollatingElement(): self
    {
        return self::$instances['PosixCollatingElement'] ??= new self('PosixCollatingElement', 'regex.posix.collating_element');
    }

    public static function PosixInvalid(): self
    {
        return self::$instances['PosixInvalid'] ??= new self('PosixInvalid', 'regex.posix.invalid');
    }

    public static function PosixOutsideClass(): self
    {
        return self::$instances['PosixOutsideClass'] ??= new self('PosixOutsideClass', 'regex.posix.outside_class');
    }

    public static function QuantifierInvalidRange(): self
    {
        return self::$instances['QuantifierInvalidRange'] ??= new self('QuantifierInvalidRange', 'regex.quantifier.invalid_range');
    }

    public static function QuantifierNothingToRepeat(): self
    {
        return self::$instances['QuantifierNothingToRepeat'] ??= new self('QuantifierNothingToRepeat', 'regex.quantifier.nothing_to_repeat');
    }

    public static function QuantifierTooBig(): self
    {
        return self::$instances['QuantifierTooBig'] ??= new self('QuantifierTooBig', 'regex.quantifier.too_big');
    }

    public static function RangeInvalidBounds(): self
    {
        return self::$instances['RangeInvalidBounds'] ??= new self('RangeInvalidBounds', 'regex.range.invalid_bounds');
    }

    public static function RangeInvalidEnd(): self
    {
        return self::$instances['RangeInvalidEnd'] ??= new self('RangeInvalidEnd', 'regex.range.invalid_end');
    }

    public static function RangeInvalidStart(): self
    {
        return self::$instances['RangeInvalidStart'] ??= new self('RangeInvalidStart', 'regex.range.invalid_start');
    }

    public static function RangeReversed(): self
    {
        return self::$instances['RangeReversed'] ??= new self('RangeReversed', 'regex.range.reversed');
    }

    public static function RenderingOutputTooLarge(): self
    {
        return self::$instances['RenderingOutputTooLarge'] ??= new self('RenderingOutputTooLarge', 'regex.rendering.output_too_large');
    }

    public static function ScanSubstringMissingList(): self
    {
        return self::$instances['ScanSubstringMissingList'] ??= new self('ScanSubstringMissingList', 'regex.scan_substring.missing_list');
    }

    public static function SubroutineInvalidSyntax(): self
    {
        return self::$instances['SubroutineInvalidSyntax'] ??= new self('SubroutineInvalidSyntax', 'regex.subroutine.invalid_syntax');
    }

    public static function SubroutineMissingGroup(): self
    {
        return self::$instances['SubroutineMissingGroup'] ??= new self('SubroutineMissingGroup', 'regex.subroutine.missing_group');
    }

    public static function SubroutineMissingNamedGroup(): self
    {
        return self::$instances['SubroutineMissingNamedGroup'] ??= new self('SubroutineMissingNamedGroup', 'regex.subroutine.missing_named_group');
    }

    public static function SubroutineRecursion(): self
    {
        return self::$instances['SubroutineRecursion'] ??= new self('SubroutineRecursion', 'regex.subroutine.recursion');
    }

    public static function SubroutineRelativeMissing(): self
    {
        return self::$instances['SubroutineRelativeMissing'] ??= new self('SubroutineRelativeMissing', 'regex.subroutine.relative_missing');
    }

    public static function SubroutineRelativeZero(): self
    {
        return self::$instances['SubroutineRelativeZero'] ??= new self('SubroutineRelativeZero', 'regex.subroutine.relative_zero');
    }

    public static function TokenUnexpected(): self
    {
        return self::$instances['TokenUnexpected'] ??= new self('TokenUnexpected', 'regex.token.unexpected');
    }

    public static function TranspileUnsupported(): self
    {
        return self::$instances['TranspileUnsupported'] ??= new self('TranspileUnsupported', 'regex.transpile.unsupported');
    }

    public static function UnicodeInvalidDigit(): self
    {
        return self::$instances['UnicodeInvalidDigit'] ??= new self('UnicodeInvalidDigit', 'regex.unicode.invalid_digit');
    }

    public static function UnicodeOutOfRange(): self
    {
        return self::$instances['UnicodeOutOfRange'] ??= new self('UnicodeOutOfRange', 'regex.unicode.out_of_range');
    }

    public static function UnicodePropertyInvalid(): self
    {
        return self::$instances['UnicodePropertyInvalid'] ??= new self('UnicodePropertyInvalid', 'regex.unicode.property_invalid');
    }

    public static function UnicodePropertyMalformed(): self
    {
        return self::$instances['UnicodePropertyMalformed'] ??= new self('UnicodePropertyMalformed', 'regex.unicode.property_malformed');
    }

    public static function UnicodeSurrogate(): self
    {
        return self::$instances['UnicodeSurrogate'] ??= new self('UnicodeSurrogate', 'regex.unicode.surrogate');
    }

    public static function UnicodeNamedRequiresUtf(): self
    {
        return self::$instances['UnicodeNamedRequiresUtf'] ??= new self('UnicodeNamedRequiresUtf', 'regex.unicode_named.requires_utf');
    }

    public static function VerbConflictingCasings(): self
    {
        return self::$instances['VerbConflictingCasings'] ??= new self('VerbConflictingCasings', 'regex.verb.conflicting_casings');
    }

    public static function VerbInvalid(): self
    {
        return self::$instances['VerbInvalid'] ??= new self('VerbInvalid', 'regex.verb.invalid');
    }

    public static function VerbLimitTooLarge(): self
    {
        return self::$instances['VerbLimitTooLarge'] ??= new self('VerbLimitTooLarge', 'regex.verb.limit_too_large');
    }

    public static function VerbMarkNameMissing(): self
    {
        return self::$instances['VerbMarkNameMissing'] ??= new self('VerbMarkNameMissing', 'regex.verb.mark_name_missing');
    }

    public static function VerbMisplaced(): self
    {
        return self::$instances['VerbMisplaced'] ??= new self('VerbMisplaced', 'regex.verb.misplaced');
    }

    public static function VerbNameTooLong(): self
    {
        return self::$instances['VerbNameTooLong'] ??= new self('VerbNameTooLong', 'regex.verb.name_too_long');
    }

    public static function VerbTurkishCasingWithoutUtf(): self
    {
        return self::$instances['VerbTurkishCasingWithoutUtf'] ??= new self('VerbTurkishCasingWithoutUtf', 'regex.verb.turkish_casing_without_utf');
    }

    public static function VerbUnclosed(): self
    {
        return self::$instances['VerbUnclosed'] ??= new self('VerbUnclosed', 'regex.verb.unclosed');
    }

    /**
     * @return list<self>
     */
    public static function cases(): array
    {
        return [
            self::AssertionInvalid(),
            self::BackrefInvalidSyntax(),
            self::BackrefMissingGroup(),
            self::BackrefMissingNamedGroup(),
            self::BackrefRelative(),
            self::BackrefZero(),
            self::CalloutInvalidDelimiter(),
            self::CalloutOutOfRange(),
            self::CalloutUnclosed(),
            self::CalloutUnclosedString(),
            self::CharclassInvalidEscape(),
            self::CharclassUnclosed(),
            self::CommentUnclosed(),
            self::Complexity(),
            self::ConditionalAssertionExpected(),
            self::ConditionalMissingGroup(),
            self::ConditionalUnclosed(),
            self::ConditionalVersionOperator(),
            self::ConditionalVersionSyntax(),
            self::ConditionalInvalid(),
            self::ConditionalTooManyBranches(),
            self::ControlCharInvalid(),
            self::DefineTooManyBranches(),
            self::DelimiterInvalid(),
            self::DelimiterUnclosed(),
            self::DelimiterUnescaped(),
            self::EncodingInvalidUtf8(),
            self::EscapeDigitsMissing(),
            self::EscapeSingleByteInUtf(),
            self::EscapeTrailingBackslash(),
            self::EscapeUnrecognized(),
            self::EscapeUnsupported(),
            self::ExtendedClassBesideLookbehind(),
            self::ExtendedClassBracketWithoutParen(),
            self::ExtendedClassEmptyExpression(),
            self::ExtendedClassMissingOperand(),
            self::ExtendedClassMissingOperator(),
            self::ExtendedClassNestedTooDeep(),
            self::ExtendedClassTooComplex(),
            self::ExtendedClassUnclosed(),
            self::ExtendedClassUnclosedParen(),
            self::ExtendedClassUnexpectedCharacter(),
            self::ExtendedClassUnmatchedClose(),
            self::FlagRemovedE(),
            self::FlagUnknown(),
            self::FormattingUnsafe(),
            self::GenerateNoMatch(),
            self::GroupDuplicateName(),
            self::GroupNameConflict(),
            self::GroupNameExpected(),
            self::GroupNameInvalid(),
            self::GroupNameTooLong(),
            self::GroupNameUnterminated(),
            self::GroupNestedTooDeep(),
            self::GroupNumberTooBig(),
            self::GroupOptionHyphen(),
            self::GroupSyntax(),
            self::GroupUnclosed(),
            self::GroupUnmatchedClose(),
            self::GroupListItemExpected(),
            self::GroupListMissingGroup(),
            self::GroupListRelativeZero(),
            self::InternalPcreFailure(),
            self::InternalUnexpectedState(),
            self::KeepInLookaround(),
            self::LookbehindTooComplex(),
            self::LookbehindTooLong(),
            self::LookbehindUnbounded(),
            self::LookbehindVariableLengthNotSupported(),
            self::NestingTooDeep(),
            self::OctalInvalidDigit(),
            self::OctalMissingBrace(),
            self::OctalOutOfRange(),
            self::PatternEmpty(),
            self::PatternNulByte(),
            self::PatternTooLarge(),
            self::PatternTooLong(),
            self::PcreRuntime(),
            self::PosixCollatingElement(),
            self::PosixInvalid(),
            self::PosixOutsideClass(),
            self::QuantifierInvalidRange(),
            self::QuantifierNothingToRepeat(),
            self::QuantifierTooBig(),
            self::RangeInvalidBounds(),
            self::RangeInvalidEnd(),
            self::RangeInvalidStart(),
            self::RangeReversed(),
            self::RenderingOutputTooLarge(),
            self::ScanSubstringMissingList(),
            self::SubroutineInvalidSyntax(),
            self::SubroutineMissingGroup(),
            self::SubroutineMissingNamedGroup(),
            self::SubroutineRecursion(),
            self::SubroutineRelativeMissing(),
            self::SubroutineRelativeZero(),
            self::TokenUnexpected(),
            self::TranspileUnsupported(),
            self::UnicodeInvalidDigit(),
            self::UnicodeOutOfRange(),
            self::UnicodePropertyInvalid(),
            self::UnicodePropertyMalformed(),
            self::UnicodeSurrogate(),
            self::UnicodeNamedRequiresUtf(),
            self::VerbConflictingCasings(),
            self::VerbInvalid(),
            self::VerbLimitTooLarge(),
            self::VerbMarkNameMissing(),
            self::VerbMisplaced(),
            self::VerbNameTooLong(),
            self::VerbTurkishCasingWithoutUtf(),
            self::VerbUnclosed(),
        ];
    }

    public static function from(string $value): self
    {
        $case = self::tryFrom($value);
        if (null === $case) {
            throw new \ValueError('"'.$value.'" is not a valid backing value for enum PHPRegex\\Parser\\ErrorCode');
        }

        return $case;
    }

    public static function tryFrom(string $value): ?self
    {
        if (null === self::$byValue) {
            self::$byValue = [];
            foreach (self::cases() as $case) {
                self::$byValue[$case->value] = $case;
            }
        }

        return self::$byValue[$value] ?? null;
    }
}
