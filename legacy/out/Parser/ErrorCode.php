<?php

declare(strict_types=1);

namespace PHPRegex\Parser;

/**
 * Cached-instance twin of the ErrorCode enum: GroupType::Capturing()
 * returns one shared instance, so identity comparisons, ->value and
 * native type hints keep the enum's semantics on PHP 7.4.
 */
final class ErrorCode
{
    /** @var array<string, self> */
    private static $instances = [];

    /** @var string */
    public $name;

    /** @var string */
    public $value;

    private function __construct(string $name, string $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    public static function AssertionInvalid(): self
    {
        if (!isset(self::$instances['AssertionInvalid'])) {
            self::$instances['AssertionInvalid'] = new self('AssertionInvalid', 'regex.assertion.invalid');
        }

        return self::$instances['AssertionInvalid'];
    }

    public static function BackrefInvalidSyntax(): self
    {
        if (!isset(self::$instances['BackrefInvalidSyntax'])) {
            self::$instances['BackrefInvalidSyntax'] = new self('BackrefInvalidSyntax', 'regex.backref.invalid_syntax');
        }

        return self::$instances['BackrefInvalidSyntax'];
    }

    public static function BackrefMissingGroup(): self
    {
        if (!isset(self::$instances['BackrefMissingGroup'])) {
            self::$instances['BackrefMissingGroup'] = new self('BackrefMissingGroup', 'regex.backref.missing_group');
        }

        return self::$instances['BackrefMissingGroup'];
    }

    public static function BackrefMissingNamedGroup(): self
    {
        if (!isset(self::$instances['BackrefMissingNamedGroup'])) {
            self::$instances['BackrefMissingNamedGroup'] = new self('BackrefMissingNamedGroup', 'regex.backref.missing_named_group');
        }

        return self::$instances['BackrefMissingNamedGroup'];
    }

    public static function BackrefRelative(): self
    {
        if (!isset(self::$instances['BackrefRelative'])) {
            self::$instances['BackrefRelative'] = new self('BackrefRelative', 'regex.backref.relative');
        }

        return self::$instances['BackrefRelative'];
    }

    public static function BackrefZero(): self
    {
        if (!isset(self::$instances['BackrefZero'])) {
            self::$instances['BackrefZero'] = new self('BackrefZero', 'regex.backref.zero');
        }

        return self::$instances['BackrefZero'];
    }

    public static function CalloutInvalidDelimiter(): self
    {
        if (!isset(self::$instances['CalloutInvalidDelimiter'])) {
            self::$instances['CalloutInvalidDelimiter'] = new self('CalloutInvalidDelimiter', 'regex.callout.invalid_delimiter');
        }

        return self::$instances['CalloutInvalidDelimiter'];
    }

    public static function CalloutOutOfRange(): self
    {
        if (!isset(self::$instances['CalloutOutOfRange'])) {
            self::$instances['CalloutOutOfRange'] = new self('CalloutOutOfRange', 'regex.callout.out_of_range');
        }

        return self::$instances['CalloutOutOfRange'];
    }

    public static function CalloutUnclosed(): self
    {
        if (!isset(self::$instances['CalloutUnclosed'])) {
            self::$instances['CalloutUnclosed'] = new self('CalloutUnclosed', 'regex.callout.unclosed');
        }

        return self::$instances['CalloutUnclosed'];
    }

    public static function CalloutUnclosedString(): self
    {
        if (!isset(self::$instances['CalloutUnclosedString'])) {
            self::$instances['CalloutUnclosedString'] = new self('CalloutUnclosedString', 'regex.callout.unclosed_string');
        }

        return self::$instances['CalloutUnclosedString'];
    }

    public static function CharclassInvalidEscape(): self
    {
        if (!isset(self::$instances['CharclassInvalidEscape'])) {
            self::$instances['CharclassInvalidEscape'] = new self('CharclassInvalidEscape', 'regex.charclass.invalid_escape');
        }

        return self::$instances['CharclassInvalidEscape'];
    }

    public static function CharclassUnclosed(): self
    {
        if (!isset(self::$instances['CharclassUnclosed'])) {
            self::$instances['CharclassUnclosed'] = new self('CharclassUnclosed', 'regex.charclass.unclosed');
        }

        return self::$instances['CharclassUnclosed'];
    }

    public static function CommentUnclosed(): self
    {
        if (!isset(self::$instances['CommentUnclosed'])) {
            self::$instances['CommentUnclosed'] = new self('CommentUnclosed', 'regex.comment.unclosed');
        }

        return self::$instances['CommentUnclosed'];
    }

    public static function Complexity(): self
    {
        if (!isset(self::$instances['Complexity'])) {
            self::$instances['Complexity'] = new self('Complexity', 'regex.complexity');
        }

        return self::$instances['Complexity'];
    }

    public static function ConditionalAssertionExpected(): self
    {
        if (!isset(self::$instances['ConditionalAssertionExpected'])) {
            self::$instances['ConditionalAssertionExpected'] = new self('ConditionalAssertionExpected', 'regex.conditional.assertion_expected');
        }

        return self::$instances['ConditionalAssertionExpected'];
    }

    public static function ConditionalMissingGroup(): self
    {
        if (!isset(self::$instances['ConditionalMissingGroup'])) {
            self::$instances['ConditionalMissingGroup'] = new self('ConditionalMissingGroup', 'regex.conditional.missing_group');
        }

        return self::$instances['ConditionalMissingGroup'];
    }

    public static function ConditionalUnclosed(): self
    {
        if (!isset(self::$instances['ConditionalUnclosed'])) {
            self::$instances['ConditionalUnclosed'] = new self('ConditionalUnclosed', 'regex.conditional.unclosed');
        }

        return self::$instances['ConditionalUnclosed'];
    }

    public static function ConditionalVersionOperator(): self
    {
        if (!isset(self::$instances['ConditionalVersionOperator'])) {
            self::$instances['ConditionalVersionOperator'] = new self('ConditionalVersionOperator', 'regex.conditional.version_operator');
        }

        return self::$instances['ConditionalVersionOperator'];
    }

    public static function ConditionalVersionSyntax(): self
    {
        if (!isset(self::$instances['ConditionalVersionSyntax'])) {
            self::$instances['ConditionalVersionSyntax'] = new self('ConditionalVersionSyntax', 'regex.conditional.version_syntax');
        }

        return self::$instances['ConditionalVersionSyntax'];
    }

    public static function ConditionalInvalid(): self
    {
        if (!isset(self::$instances['ConditionalInvalid'])) {
            self::$instances['ConditionalInvalid'] = new self('ConditionalInvalid', 'regex.conditional.invalid');
        }

        return self::$instances['ConditionalInvalid'];
    }

    public static function ConditionalTooManyBranches(): self
    {
        if (!isset(self::$instances['ConditionalTooManyBranches'])) {
            self::$instances['ConditionalTooManyBranches'] = new self('ConditionalTooManyBranches', 'regex.conditional.too_many_branches');
        }

        return self::$instances['ConditionalTooManyBranches'];
    }

    public static function ControlCharInvalid(): self
    {
        if (!isset(self::$instances['ControlCharInvalid'])) {
            self::$instances['ControlCharInvalid'] = new self('ControlCharInvalid', 'regex.control_char.invalid');
        }

        return self::$instances['ControlCharInvalid'];
    }

    public static function DefineTooManyBranches(): self
    {
        if (!isset(self::$instances['DefineTooManyBranches'])) {
            self::$instances['DefineTooManyBranches'] = new self('DefineTooManyBranches', 'regex.define.too_many_branches');
        }

        return self::$instances['DefineTooManyBranches'];
    }

    public static function DelimiterInvalid(): self
    {
        if (!isset(self::$instances['DelimiterInvalid'])) {
            self::$instances['DelimiterInvalid'] = new self('DelimiterInvalid', 'regex.delimiter.invalid');
        }

        return self::$instances['DelimiterInvalid'];
    }

    public static function DelimiterUnclosed(): self
    {
        if (!isset(self::$instances['DelimiterUnclosed'])) {
            self::$instances['DelimiterUnclosed'] = new self('DelimiterUnclosed', 'regex.delimiter.unclosed');
        }

        return self::$instances['DelimiterUnclosed'];
    }

    public static function DelimiterUnescaped(): self
    {
        if (!isset(self::$instances['DelimiterUnescaped'])) {
            self::$instances['DelimiterUnescaped'] = new self('DelimiterUnescaped', 'regex.delimiter.unescaped');
        }

        return self::$instances['DelimiterUnescaped'];
    }

    public static function EncodingInvalidUtf8(): self
    {
        if (!isset(self::$instances['EncodingInvalidUtf8'])) {
            self::$instances['EncodingInvalidUtf8'] = new self('EncodingInvalidUtf8', 'regex.encoding.invalid_utf8');
        }

        return self::$instances['EncodingInvalidUtf8'];
    }

    public static function EscapeDigitsMissing(): self
    {
        if (!isset(self::$instances['EscapeDigitsMissing'])) {
            self::$instances['EscapeDigitsMissing'] = new self('EscapeDigitsMissing', 'regex.escape.digits_missing');
        }

        return self::$instances['EscapeDigitsMissing'];
    }

    public static function EscapeSingleByteInUtf(): self
    {
        if (!isset(self::$instances['EscapeSingleByteInUtf'])) {
            self::$instances['EscapeSingleByteInUtf'] = new self('EscapeSingleByteInUtf', 'regex.escape.single_byte_in_utf');
        }

        return self::$instances['EscapeSingleByteInUtf'];
    }

    public static function EscapeTrailingBackslash(): self
    {
        if (!isset(self::$instances['EscapeTrailingBackslash'])) {
            self::$instances['EscapeTrailingBackslash'] = new self('EscapeTrailingBackslash', 'regex.escape.trailing_backslash');
        }

        return self::$instances['EscapeTrailingBackslash'];
    }

    public static function EscapeUnrecognized(): self
    {
        if (!isset(self::$instances['EscapeUnrecognized'])) {
            self::$instances['EscapeUnrecognized'] = new self('EscapeUnrecognized', 'regex.escape.unrecognized');
        }

        return self::$instances['EscapeUnrecognized'];
    }

    public static function EscapeUnsupported(): self
    {
        if (!isset(self::$instances['EscapeUnsupported'])) {
            self::$instances['EscapeUnsupported'] = new self('EscapeUnsupported', 'regex.escape.unsupported');
        }

        return self::$instances['EscapeUnsupported'];
    }

    public static function ExtendedClassBesideLookbehind(): self
    {
        if (!isset(self::$instances['ExtendedClassBesideLookbehind'])) {
            self::$instances['ExtendedClassBesideLookbehind'] = new self('ExtendedClassBesideLookbehind', 'regex.extended_class.beside_lookbehind');
        }

        return self::$instances['ExtendedClassBesideLookbehind'];
    }

    public static function ExtendedClassBracketWithoutParen(): self
    {
        if (!isset(self::$instances['ExtendedClassBracketWithoutParen'])) {
            self::$instances['ExtendedClassBracketWithoutParen'] = new self('ExtendedClassBracketWithoutParen', 'regex.extended_class.bracket_without_paren');
        }

        return self::$instances['ExtendedClassBracketWithoutParen'];
    }

    public static function ExtendedClassEmptyExpression(): self
    {
        if (!isset(self::$instances['ExtendedClassEmptyExpression'])) {
            self::$instances['ExtendedClassEmptyExpression'] = new self('ExtendedClassEmptyExpression', 'regex.extended_class.empty_expression');
        }

        return self::$instances['ExtendedClassEmptyExpression'];
    }

    public static function ExtendedClassMissingOperand(): self
    {
        if (!isset(self::$instances['ExtendedClassMissingOperand'])) {
            self::$instances['ExtendedClassMissingOperand'] = new self('ExtendedClassMissingOperand', 'regex.extended_class.missing_operand');
        }

        return self::$instances['ExtendedClassMissingOperand'];
    }

    public static function ExtendedClassMissingOperator(): self
    {
        if (!isset(self::$instances['ExtendedClassMissingOperator'])) {
            self::$instances['ExtendedClassMissingOperator'] = new self('ExtendedClassMissingOperator', 'regex.extended_class.missing_operator');
        }

        return self::$instances['ExtendedClassMissingOperator'];
    }

    public static function ExtendedClassNestedTooDeep(): self
    {
        if (!isset(self::$instances['ExtendedClassNestedTooDeep'])) {
            self::$instances['ExtendedClassNestedTooDeep'] = new self('ExtendedClassNestedTooDeep', 'regex.extended_class.nested_too_deep');
        }

        return self::$instances['ExtendedClassNestedTooDeep'];
    }

    public static function ExtendedClassTooComplex(): self
    {
        if (!isset(self::$instances['ExtendedClassTooComplex'])) {
            self::$instances['ExtendedClassTooComplex'] = new self('ExtendedClassTooComplex', 'regex.extended_class.too_complex');
        }

        return self::$instances['ExtendedClassTooComplex'];
    }

    public static function ExtendedClassUnclosed(): self
    {
        if (!isset(self::$instances['ExtendedClassUnclosed'])) {
            self::$instances['ExtendedClassUnclosed'] = new self('ExtendedClassUnclosed', 'regex.extended_class.unclosed');
        }

        return self::$instances['ExtendedClassUnclosed'];
    }

    public static function ExtendedClassUnclosedParen(): self
    {
        if (!isset(self::$instances['ExtendedClassUnclosedParen'])) {
            self::$instances['ExtendedClassUnclosedParen'] = new self('ExtendedClassUnclosedParen', 'regex.extended_class.unclosed_paren');
        }

        return self::$instances['ExtendedClassUnclosedParen'];
    }

    public static function ExtendedClassUnexpectedCharacter(): self
    {
        if (!isset(self::$instances['ExtendedClassUnexpectedCharacter'])) {
            self::$instances['ExtendedClassUnexpectedCharacter'] = new self('ExtendedClassUnexpectedCharacter', 'regex.extended_class.unexpected_character');
        }

        return self::$instances['ExtendedClassUnexpectedCharacter'];
    }

    public static function ExtendedClassUnmatchedClose(): self
    {
        if (!isset(self::$instances['ExtendedClassUnmatchedClose'])) {
            self::$instances['ExtendedClassUnmatchedClose'] = new self('ExtendedClassUnmatchedClose', 'regex.extended_class.unmatched_close');
        }

        return self::$instances['ExtendedClassUnmatchedClose'];
    }

    public static function FlagRemovedE(): self
    {
        if (!isset(self::$instances['FlagRemovedE'])) {
            self::$instances['FlagRemovedE'] = new self('FlagRemovedE', 'regex.flag.removed_e');
        }

        return self::$instances['FlagRemovedE'];
    }

    public static function FlagUnknown(): self
    {
        if (!isset(self::$instances['FlagUnknown'])) {
            self::$instances['FlagUnknown'] = new self('FlagUnknown', 'regex.flag.unknown');
        }

        return self::$instances['FlagUnknown'];
    }

    public static function FormattingUnsafe(): self
    {
        if (!isset(self::$instances['FormattingUnsafe'])) {
            self::$instances['FormattingUnsafe'] = new self('FormattingUnsafe', 'regex.formatting.unsafe');
        }

        return self::$instances['FormattingUnsafe'];
    }

    public static function GenerateNoMatch(): self
    {
        if (!isset(self::$instances['GenerateNoMatch'])) {
            self::$instances['GenerateNoMatch'] = new self('GenerateNoMatch', 'regex.generate.no_match');
        }

        return self::$instances['GenerateNoMatch'];
    }

    public static function GroupDuplicateName(): self
    {
        if (!isset(self::$instances['GroupDuplicateName'])) {
            self::$instances['GroupDuplicateName'] = new self('GroupDuplicateName', 'regex.group.duplicate_name');
        }

        return self::$instances['GroupDuplicateName'];
    }

    public static function GroupNameConflict(): self
    {
        if (!isset(self::$instances['GroupNameConflict'])) {
            self::$instances['GroupNameConflict'] = new self('GroupNameConflict', 'regex.group.name_conflict');
        }

        return self::$instances['GroupNameConflict'];
    }

    public static function GroupNameExpected(): self
    {
        if (!isset(self::$instances['GroupNameExpected'])) {
            self::$instances['GroupNameExpected'] = new self('GroupNameExpected', 'regex.group.name_expected');
        }

        return self::$instances['GroupNameExpected'];
    }

    public static function GroupNameInvalid(): self
    {
        if (!isset(self::$instances['GroupNameInvalid'])) {
            self::$instances['GroupNameInvalid'] = new self('GroupNameInvalid', 'regex.group.name_invalid');
        }

        return self::$instances['GroupNameInvalid'];
    }

    public static function GroupNameTooLong(): self
    {
        if (!isset(self::$instances['GroupNameTooLong'])) {
            self::$instances['GroupNameTooLong'] = new self('GroupNameTooLong', 'regex.group.name_too_long');
        }

        return self::$instances['GroupNameTooLong'];
    }

    public static function GroupNameUnterminated(): self
    {
        if (!isset(self::$instances['GroupNameUnterminated'])) {
            self::$instances['GroupNameUnterminated'] = new self('GroupNameUnterminated', 'regex.group.name_unterminated');
        }

        return self::$instances['GroupNameUnterminated'];
    }

    public static function GroupNestedTooDeep(): self
    {
        if (!isset(self::$instances['GroupNestedTooDeep'])) {
            self::$instances['GroupNestedTooDeep'] = new self('GroupNestedTooDeep', 'regex.group.nested_too_deep');
        }

        return self::$instances['GroupNestedTooDeep'];
    }

    public static function GroupNumberTooBig(): self
    {
        if (!isset(self::$instances['GroupNumberTooBig'])) {
            self::$instances['GroupNumberTooBig'] = new self('GroupNumberTooBig', 'regex.group.number_too_big');
        }

        return self::$instances['GroupNumberTooBig'];
    }

    public static function GroupOptionHyphen(): self
    {
        if (!isset(self::$instances['GroupOptionHyphen'])) {
            self::$instances['GroupOptionHyphen'] = new self('GroupOptionHyphen', 'regex.group.option_hyphen');
        }

        return self::$instances['GroupOptionHyphen'];
    }

    public static function GroupSyntax(): self
    {
        if (!isset(self::$instances['GroupSyntax'])) {
            self::$instances['GroupSyntax'] = new self('GroupSyntax', 'regex.group.syntax');
        }

        return self::$instances['GroupSyntax'];
    }

    public static function GroupUnclosed(): self
    {
        if (!isset(self::$instances['GroupUnclosed'])) {
            self::$instances['GroupUnclosed'] = new self('GroupUnclosed', 'regex.group.unclosed');
        }

        return self::$instances['GroupUnclosed'];
    }

    public static function GroupUnmatchedClose(): self
    {
        if (!isset(self::$instances['GroupUnmatchedClose'])) {
            self::$instances['GroupUnmatchedClose'] = new self('GroupUnmatchedClose', 'regex.group.unmatched_close');
        }

        return self::$instances['GroupUnmatchedClose'];
    }

    public static function GroupListItemExpected(): self
    {
        if (!isset(self::$instances['GroupListItemExpected'])) {
            self::$instances['GroupListItemExpected'] = new self('GroupListItemExpected', 'regex.group_list.item_expected');
        }

        return self::$instances['GroupListItemExpected'];
    }

    public static function GroupListMissingGroup(): self
    {
        if (!isset(self::$instances['GroupListMissingGroup'])) {
            self::$instances['GroupListMissingGroup'] = new self('GroupListMissingGroup', 'regex.group_list.missing_group');
        }

        return self::$instances['GroupListMissingGroup'];
    }

    public static function GroupListRelativeZero(): self
    {
        if (!isset(self::$instances['GroupListRelativeZero'])) {
            self::$instances['GroupListRelativeZero'] = new self('GroupListRelativeZero', 'regex.group_list.relative_zero');
        }

        return self::$instances['GroupListRelativeZero'];
    }

    public static function InternalPcreFailure(): self
    {
        if (!isset(self::$instances['InternalPcreFailure'])) {
            self::$instances['InternalPcreFailure'] = new self('InternalPcreFailure', 'regex.internal.pcre_failure');
        }

        return self::$instances['InternalPcreFailure'];
    }

    public static function InternalUnexpectedState(): self
    {
        if (!isset(self::$instances['InternalUnexpectedState'])) {
            self::$instances['InternalUnexpectedState'] = new self('InternalUnexpectedState', 'regex.internal.unexpected_state');
        }

        return self::$instances['InternalUnexpectedState'];
    }

    public static function KeepInLookaround(): self
    {
        if (!isset(self::$instances['KeepInLookaround'])) {
            self::$instances['KeepInLookaround'] = new self('KeepInLookaround', 'regex.keep.in_lookaround');
        }

        return self::$instances['KeepInLookaround'];
    }

    public static function LookbehindTooComplex(): self
    {
        if (!isset(self::$instances['LookbehindTooComplex'])) {
            self::$instances['LookbehindTooComplex'] = new self('LookbehindTooComplex', 'regex.lookbehind.too_complex');
        }

        return self::$instances['LookbehindTooComplex'];
    }

    public static function LookbehindTooLong(): self
    {
        if (!isset(self::$instances['LookbehindTooLong'])) {
            self::$instances['LookbehindTooLong'] = new self('LookbehindTooLong', 'regex.lookbehind.too_long');
        }

        return self::$instances['LookbehindTooLong'];
    }

    public static function LookbehindUnbounded(): self
    {
        if (!isset(self::$instances['LookbehindUnbounded'])) {
            self::$instances['LookbehindUnbounded'] = new self('LookbehindUnbounded', 'regex.lookbehind.unbounded');
        }

        return self::$instances['LookbehindUnbounded'];
    }

    public static function LookbehindVariableLengthNotSupported(): self
    {
        if (!isset(self::$instances['LookbehindVariableLengthNotSupported'])) {
            self::$instances['LookbehindVariableLengthNotSupported'] = new self('LookbehindVariableLengthNotSupported', 'regex.lookbehind.variable_length_not_supported');
        }

        return self::$instances['LookbehindVariableLengthNotSupported'];
    }

    public static function NestingTooDeep(): self
    {
        if (!isset(self::$instances['NestingTooDeep'])) {
            self::$instances['NestingTooDeep'] = new self('NestingTooDeep', 'regex.nesting.too_deep');
        }

        return self::$instances['NestingTooDeep'];
    }

    public static function OctalInvalidDigit(): self
    {
        if (!isset(self::$instances['OctalInvalidDigit'])) {
            self::$instances['OctalInvalidDigit'] = new self('OctalInvalidDigit', 'regex.octal.invalid_digit');
        }

        return self::$instances['OctalInvalidDigit'];
    }

    public static function OctalMissingBrace(): self
    {
        if (!isset(self::$instances['OctalMissingBrace'])) {
            self::$instances['OctalMissingBrace'] = new self('OctalMissingBrace', 'regex.octal.missing_brace');
        }

        return self::$instances['OctalMissingBrace'];
    }

    public static function OctalOutOfRange(): self
    {
        if (!isset(self::$instances['OctalOutOfRange'])) {
            self::$instances['OctalOutOfRange'] = new self('OctalOutOfRange', 'regex.octal.out_of_range');
        }

        return self::$instances['OctalOutOfRange'];
    }

    public static function PatternEmpty(): self
    {
        if (!isset(self::$instances['PatternEmpty'])) {
            self::$instances['PatternEmpty'] = new self('PatternEmpty', 'regex.pattern.empty');
        }

        return self::$instances['PatternEmpty'];
    }

    public static function PatternNulByte(): self
    {
        if (!isset(self::$instances['PatternNulByte'])) {
            self::$instances['PatternNulByte'] = new self('PatternNulByte', 'regex.pattern.nul_byte');
        }

        return self::$instances['PatternNulByte'];
    }

    public static function PatternTooLarge(): self
    {
        if (!isset(self::$instances['PatternTooLarge'])) {
            self::$instances['PatternTooLarge'] = new self('PatternTooLarge', 'regex.pattern.too_large');
        }

        return self::$instances['PatternTooLarge'];
    }

    public static function PatternTooLong(): self
    {
        if (!isset(self::$instances['PatternTooLong'])) {
            self::$instances['PatternTooLong'] = new self('PatternTooLong', 'regex.pattern.too_long');
        }

        return self::$instances['PatternTooLong'];
    }

    public static function PcreRuntime(): self
    {
        if (!isset(self::$instances['PcreRuntime'])) {
            self::$instances['PcreRuntime'] = new self('PcreRuntime', 'regex.pcre.runtime');
        }

        return self::$instances['PcreRuntime'];
    }

    public static function PosixCollatingElement(): self
    {
        if (!isset(self::$instances['PosixCollatingElement'])) {
            self::$instances['PosixCollatingElement'] = new self('PosixCollatingElement', 'regex.posix.collating_element');
        }

        return self::$instances['PosixCollatingElement'];
    }

    public static function PosixInvalid(): self
    {
        if (!isset(self::$instances['PosixInvalid'])) {
            self::$instances['PosixInvalid'] = new self('PosixInvalid', 'regex.posix.invalid');
        }

        return self::$instances['PosixInvalid'];
    }

    public static function PosixOutsideClass(): self
    {
        if (!isset(self::$instances['PosixOutsideClass'])) {
            self::$instances['PosixOutsideClass'] = new self('PosixOutsideClass', 'regex.posix.outside_class');
        }

        return self::$instances['PosixOutsideClass'];
    }

    public static function QuantifierInvalidRange(): self
    {
        if (!isset(self::$instances['QuantifierInvalidRange'])) {
            self::$instances['QuantifierInvalidRange'] = new self('QuantifierInvalidRange', 'regex.quantifier.invalid_range');
        }

        return self::$instances['QuantifierInvalidRange'];
    }

    public static function QuantifierNothingToRepeat(): self
    {
        if (!isset(self::$instances['QuantifierNothingToRepeat'])) {
            self::$instances['QuantifierNothingToRepeat'] = new self('QuantifierNothingToRepeat', 'regex.quantifier.nothing_to_repeat');
        }

        return self::$instances['QuantifierNothingToRepeat'];
    }

    public static function QuantifierTooBig(): self
    {
        if (!isset(self::$instances['QuantifierTooBig'])) {
            self::$instances['QuantifierTooBig'] = new self('QuantifierTooBig', 'regex.quantifier.too_big');
        }

        return self::$instances['QuantifierTooBig'];
    }

    public static function RangeInvalidBounds(): self
    {
        if (!isset(self::$instances['RangeInvalidBounds'])) {
            self::$instances['RangeInvalidBounds'] = new self('RangeInvalidBounds', 'regex.range.invalid_bounds');
        }

        return self::$instances['RangeInvalidBounds'];
    }

    public static function RangeInvalidEnd(): self
    {
        if (!isset(self::$instances['RangeInvalidEnd'])) {
            self::$instances['RangeInvalidEnd'] = new self('RangeInvalidEnd', 'regex.range.invalid_end');
        }

        return self::$instances['RangeInvalidEnd'];
    }

    public static function RangeInvalidStart(): self
    {
        if (!isset(self::$instances['RangeInvalidStart'])) {
            self::$instances['RangeInvalidStart'] = new self('RangeInvalidStart', 'regex.range.invalid_start');
        }

        return self::$instances['RangeInvalidStart'];
    }

    public static function RangeReversed(): self
    {
        if (!isset(self::$instances['RangeReversed'])) {
            self::$instances['RangeReversed'] = new self('RangeReversed', 'regex.range.reversed');
        }

        return self::$instances['RangeReversed'];
    }

    public static function ScanSubstringMissingList(): self
    {
        if (!isset(self::$instances['ScanSubstringMissingList'])) {
            self::$instances['ScanSubstringMissingList'] = new self('ScanSubstringMissingList', 'regex.scan_substring.missing_list');
        }

        return self::$instances['ScanSubstringMissingList'];
    }

    public static function SubroutineInvalidSyntax(): self
    {
        if (!isset(self::$instances['SubroutineInvalidSyntax'])) {
            self::$instances['SubroutineInvalidSyntax'] = new self('SubroutineInvalidSyntax', 'regex.subroutine.invalid_syntax');
        }

        return self::$instances['SubroutineInvalidSyntax'];
    }

    public static function SubroutineMissingGroup(): self
    {
        if (!isset(self::$instances['SubroutineMissingGroup'])) {
            self::$instances['SubroutineMissingGroup'] = new self('SubroutineMissingGroup', 'regex.subroutine.missing_group');
        }

        return self::$instances['SubroutineMissingGroup'];
    }

    public static function SubroutineMissingNamedGroup(): self
    {
        if (!isset(self::$instances['SubroutineMissingNamedGroup'])) {
            self::$instances['SubroutineMissingNamedGroup'] = new self('SubroutineMissingNamedGroup', 'regex.subroutine.missing_named_group');
        }

        return self::$instances['SubroutineMissingNamedGroup'];
    }

    public static function SubroutineRecursion(): self
    {
        if (!isset(self::$instances['SubroutineRecursion'])) {
            self::$instances['SubroutineRecursion'] = new self('SubroutineRecursion', 'regex.subroutine.recursion');
        }

        return self::$instances['SubroutineRecursion'];
    }

    public static function SubroutineRelativeMissing(): self
    {
        if (!isset(self::$instances['SubroutineRelativeMissing'])) {
            self::$instances['SubroutineRelativeMissing'] = new self('SubroutineRelativeMissing', 'regex.subroutine.relative_missing');
        }

        return self::$instances['SubroutineRelativeMissing'];
    }

    public static function SubroutineRelativeZero(): self
    {
        if (!isset(self::$instances['SubroutineRelativeZero'])) {
            self::$instances['SubroutineRelativeZero'] = new self('SubroutineRelativeZero', 'regex.subroutine.relative_zero');
        }

        return self::$instances['SubroutineRelativeZero'];
    }

    public static function TokenUnexpected(): self
    {
        if (!isset(self::$instances['TokenUnexpected'])) {
            self::$instances['TokenUnexpected'] = new self('TokenUnexpected', 'regex.token.unexpected');
        }

        return self::$instances['TokenUnexpected'];
    }

    public static function TranspileUnsupported(): self
    {
        if (!isset(self::$instances['TranspileUnsupported'])) {
            self::$instances['TranspileUnsupported'] = new self('TranspileUnsupported', 'regex.transpile.unsupported');
        }

        return self::$instances['TranspileUnsupported'];
    }

    public static function UnicodeInvalidDigit(): self
    {
        if (!isset(self::$instances['UnicodeInvalidDigit'])) {
            self::$instances['UnicodeInvalidDigit'] = new self('UnicodeInvalidDigit', 'regex.unicode.invalid_digit');
        }

        return self::$instances['UnicodeInvalidDigit'];
    }

    public static function UnicodeOutOfRange(): self
    {
        if (!isset(self::$instances['UnicodeOutOfRange'])) {
            self::$instances['UnicodeOutOfRange'] = new self('UnicodeOutOfRange', 'regex.unicode.out_of_range');
        }

        return self::$instances['UnicodeOutOfRange'];
    }

    public static function UnicodePropertyInvalid(): self
    {
        if (!isset(self::$instances['UnicodePropertyInvalid'])) {
            self::$instances['UnicodePropertyInvalid'] = new self('UnicodePropertyInvalid', 'regex.unicode.property_invalid');
        }

        return self::$instances['UnicodePropertyInvalid'];
    }

    public static function UnicodePropertyMalformed(): self
    {
        if (!isset(self::$instances['UnicodePropertyMalformed'])) {
            self::$instances['UnicodePropertyMalformed'] = new self('UnicodePropertyMalformed', 'regex.unicode.property_malformed');
        }

        return self::$instances['UnicodePropertyMalformed'];
    }

    public static function UnicodeSurrogate(): self
    {
        if (!isset(self::$instances['UnicodeSurrogate'])) {
            self::$instances['UnicodeSurrogate'] = new self('UnicodeSurrogate', 'regex.unicode.surrogate');
        }

        return self::$instances['UnicodeSurrogate'];
    }

    public static function UnicodeNamedRequiresUtf(): self
    {
        if (!isset(self::$instances['UnicodeNamedRequiresUtf'])) {
            self::$instances['UnicodeNamedRequiresUtf'] = new self('UnicodeNamedRequiresUtf', 'regex.unicode_named.requires_utf');
        }

        return self::$instances['UnicodeNamedRequiresUtf'];
    }

    public static function VerbConflictingCasings(): self
    {
        if (!isset(self::$instances['VerbConflictingCasings'])) {
            self::$instances['VerbConflictingCasings'] = new self('VerbConflictingCasings', 'regex.verb.conflicting_casings');
        }

        return self::$instances['VerbConflictingCasings'];
    }

    public static function VerbInvalid(): self
    {
        if (!isset(self::$instances['VerbInvalid'])) {
            self::$instances['VerbInvalid'] = new self('VerbInvalid', 'regex.verb.invalid');
        }

        return self::$instances['VerbInvalid'];
    }

    public static function VerbLimitTooLarge(): self
    {
        if (!isset(self::$instances['VerbLimitTooLarge'])) {
            self::$instances['VerbLimitTooLarge'] = new self('VerbLimitTooLarge', 'regex.verb.limit_too_large');
        }

        return self::$instances['VerbLimitTooLarge'];
    }

    public static function VerbMarkNameMissing(): self
    {
        if (!isset(self::$instances['VerbMarkNameMissing'])) {
            self::$instances['VerbMarkNameMissing'] = new self('VerbMarkNameMissing', 'regex.verb.mark_name_missing');
        }

        return self::$instances['VerbMarkNameMissing'];
    }

    public static function VerbMisplaced(): self
    {
        if (!isset(self::$instances['VerbMisplaced'])) {
            self::$instances['VerbMisplaced'] = new self('VerbMisplaced', 'regex.verb.misplaced');
        }

        return self::$instances['VerbMisplaced'];
    }

    public static function VerbNameTooLong(): self
    {
        if (!isset(self::$instances['VerbNameTooLong'])) {
            self::$instances['VerbNameTooLong'] = new self('VerbNameTooLong', 'regex.verb.name_too_long');
        }

        return self::$instances['VerbNameTooLong'];
    }

    public static function VerbTurkishCasingWithoutUtf(): self
    {
        if (!isset(self::$instances['VerbTurkishCasingWithoutUtf'])) {
            self::$instances['VerbTurkishCasingWithoutUtf'] = new self('VerbTurkishCasingWithoutUtf', 'regex.verb.turkish_casing_without_utf');
        }

        return self::$instances['VerbTurkishCasingWithoutUtf'];
    }

    public static function VerbUnclosed(): self
    {
        if (!isset(self::$instances['VerbUnclosed'])) {
            self::$instances['VerbUnclosed'] = new self('VerbUnclosed', 'regex.verb.unclosed');
        }

        return self::$instances['VerbUnclosed'];
    }

    /** @return list<self> */
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
        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unknown value %s for ErrorCode.', $value));
    }

    public static function tryFrom(string $value): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        return null;
    }
}
