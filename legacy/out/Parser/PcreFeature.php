<?php

declare(strict_types=1);

namespace PHPRegex\Parser;

/**
 * Cached-instance twin of the PcreFeature enum: GroupType::Capturing()
 * returns one shared instance, so identity comparisons, ->value and
 * native type hints keep the enum's semantics on PHP 7.4.
 */
final class PcreFeature
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

    public static function AlphabeticAssertionNames(): self
    {
        if (!isset(self::$instances['AlphabeticAssertionNames'])) {
            self::$instances['AlphabeticAssertionNames'] = new self('AlphabeticAssertionNames', 'AlphabeticAssertionNames');
        }

        return self::$instances['AlphabeticAssertionNames'];
    }

    public static function ScriptRuns(): self
    {
        if (!isset(self::$instances['ScriptRuns'])) {
            self::$instances['ScriptRuns'] = new self('ScriptRuns', 'ScriptRuns');
        }

        return self::$instances['ScriptRuns'];
    }

    public static function NonAsciiGroupNames(): self
    {
        if (!isset(self::$instances['NonAsciiGroupNames'])) {
            self::$instances['NonAsciiGroupNames'] = new self('NonAsciiGroupNames', 'NonAsciiGroupNames');
        }

        return self::$instances['NonAsciiGroupNames'];
    }

    public static function BadEscapeInClassStaysRefused(): self
    {
        if (!isset(self::$instances['BadEscapeInClassStaysRefused'])) {
            self::$instances['BadEscapeInClassStaysRefused'] = new self('BadEscapeInClassStaysRefused', 'BadEscapeInClassStaysRefused');
        }

        return self::$instances['BadEscapeInClassStaysRefused'];
    }

    public static function NamesReadWhole(): self
    {
        if (!isset(self::$instances['NamesReadWhole'])) {
            self::$instances['NamesReadWhole'] = new self('NamesReadWhole', 'NamesReadWhole');
        }

        return self::$instances['NamesReadWhole'];
    }

    public static function AnyPropertyMatchesAnyCharacter(): self
    {
        if (!isset(self::$instances['AnyPropertyMatchesAnyCharacter'])) {
            self::$instances['AnyPropertyMatchesAnyCharacter'] = new self('AnyPropertyMatchesAnyCharacter', 'AnyPropertyMatchesAnyCharacter');
        }

        return self::$instances['AnyPropertyMatchesAnyCharacter'];
    }

    public static function QuantifiedAccept(): self
    {
        if (!isset(self::$instances['QuantifiedAccept'])) {
            self::$instances['QuantifiedAccept'] = new self('QuantifiedAccept', 'QuantifiedAccept');
        }

        return self::$instances['QuantifiedAccept'];
    }

    public static function PossessiveRepeatOnceIsAtomic(): self
    {
        if (!isset(self::$instances['PossessiveRepeatOnceIsAtomic'])) {
            self::$instances['PossessiveRepeatOnceIsAtomic'] = new self('PossessiveRepeatOnceIsAtomic', 'PossessiveRepeatOnceIsAtomic');
        }

        return self::$instances['PossessiveRepeatOnceIsAtomic'];
    }

    public static function NonAtomicAssertionNames(): self
    {
        if (!isset(self::$instances['NonAtomicAssertionNames'])) {
            self::$instances['NonAtomicAssertionNames'] = new self('NonAtomicAssertionNames', 'NonAtomicAssertionNames');
        }

        return self::$instances['NonAtomicAssertionNames'];
    }

    public static function LookbehindInLookaheadMeasured(): self
    {
        if (!isset(self::$instances['LookbehindInLookaheadMeasured'])) {
            self::$instances['LookbehindInLookaheadMeasured'] = new self('LookbehindInLookaheadMeasured', 'LookbehindInLookaheadMeasured');
        }

        return self::$instances['LookbehindInLookaheadMeasured'];
    }

    public static function LookbehindLengthOverflowRefused(): self
    {
        if (!isset(self::$instances['LookbehindLengthOverflowRefused'])) {
            self::$instances['LookbehindLengthOverflowRefused'] = new self('LookbehindLengthOverflowRefused', 'LookbehindLengthOverflowRefused');
        }

        return self::$instances['LookbehindLengthOverflowRefused'];
    }

    public static function LongestLookbehindCountsItsBranchesOnly(): self
    {
        if (!isset(self::$instances['LongestLookbehindCountsItsBranchesOnly'])) {
            self::$instances['LongestLookbehindCountsItsBranchesOnly'] = new self('LongestLookbehindCountsItsBranchesOnly', 'LongestLookbehindCountsItsBranchesOnly');
        }

        return self::$instances['LongestLookbehindCountsItsBranchesOnly'];
    }

    public static function CasePairClassIsACaselessCharacter(): self
    {
        if (!isset(self::$instances['CasePairClassIsACaselessCharacter'])) {
            self::$instances['CasePairClassIsACaselessCharacter'] = new self('CasePairClassIsACaselessCharacter', 'CasePairClassIsACaselessCharacter');
        }

        return self::$instances['CasePairClassIsACaselessCharacter'];
    }

    public static function NonAtomicAssertionShortSyntax(): self
    {
        if (!isset(self::$instances['NonAtomicAssertionShortSyntax'])) {
            self::$instances['NonAtomicAssertionShortSyntax'] = new self('NonAtomicAssertionShortSyntax', 'NonAtomicAssertionShortSyntax');
        }

        return self::$instances['NonAtomicAssertionShortSyntax'];
    }

    public static function GroupHoldingItsOwnReferenceBacktracks(): self
    {
        if (!isset(self::$instances['GroupHoldingItsOwnReferenceBacktracks'])) {
            self::$instances['GroupHoldingItsOwnReferenceBacktracks'] = new self('GroupHoldingItsOwnReferenceBacktracks', 'GroupHoldingItsOwnReferenceBacktracks');
        }

        return self::$instances['GroupHoldingItsOwnReferenceBacktracks'];
    }

    public static function RepeatedAssertionKeepsItsCount(): self
    {
        if (!isset(self::$instances['RepeatedAssertionKeepsItsCount'])) {
            self::$instances['RepeatedAssertionKeepsItsCount'] = new self('RepeatedAssertionKeepsItsCount', 'RepeatedAssertionKeepsItsCount');
        }

        return self::$instances['RepeatedAssertionKeepsItsCount'];
    }

    public static function UcpFoldsPastAsciiWithoutUtf(): self
    {
        if (!isset(self::$instances['UcpFoldsPastAsciiWithoutUtf'])) {
            self::$instances['UcpFoldsPastAsciiWithoutUtf'] = new self('UcpFoldsPastAsciiWithoutUtf', 'UcpFoldsPastAsciiWithoutUtf');
        }

        return self::$instances['UcpFoldsPastAsciiWithoutUtf'];
    }

    public static function DefineSkippedInLookbehind(): self
    {
        if (!isset(self::$instances['DefineSkippedInLookbehind'])) {
            self::$instances['DefineSkippedInLookbehind'] = new self('DefineSkippedInLookbehind', 'DefineSkippedInLookbehind');
        }

        return self::$instances['DefineSkippedInLookbehind'];
    }

    public static function LargeOpenCountIsText(): self
    {
        if (!isset(self::$instances['LargeOpenCountIsText'])) {
            self::$instances['LargeOpenCountIsText'] = new self('LargeOpenCountIsText', 'LargeOpenCountIsText');
        }

        return self::$instances['LargeOpenCountIsText'];
    }

    public static function OverflowingBackslashNumberIsOctal(): self
    {
        if (!isset(self::$instances['OverflowingBackslashNumberIsOctal'])) {
            self::$instances['OverflowingBackslashNumberIsOctal'] = new self('OverflowingBackslashNumberIsOctal', 'OverflowingBackslashNumberIsOctal');
        }

        return self::$instances['OverflowingBackslashNumberIsOctal'];
    }

    public static function LoosePropertyNames(): self
    {
        if (!isset(self::$instances['LoosePropertyNames'])) {
            self::$instances['LoosePropertyNames'] = new self('LoosePropertyNames', 'LoosePropertyNames');
        }

        return self::$instances['LoosePropertyNames'];
    }

    public static function OpenAndPaddedRepeatCounts(): self
    {
        if (!isset(self::$instances['OpenAndPaddedRepeatCounts'])) {
            self::$instances['OpenAndPaddedRepeatCounts'] = new self('OpenAndPaddedRepeatCounts', 'OpenAndPaddedRepeatCounts');
        }

        return self::$instances['OpenAndPaddedRepeatCounts'];
    }

    public static function PaddedBracedEscapes(): self
    {
        if (!isset(self::$instances['PaddedBracedEscapes'])) {
            self::$instances['PaddedBracedEscapes'] = new self('PaddedBracedEscapes', 'PaddedBracedEscapes');
        }

        return self::$instances['PaddedBracedEscapes'];
    }

    public static function VariableLengthLookbehind(): self
    {
        if (!isset(self::$instances['VariableLengthLookbehind'])) {
            self::$instances['VariableLengthLookbehind'] = new self('VariableLengthLookbehind', 'VariableLengthLookbehind');
        }

        return self::$instances['VariableLengthLookbehind'];
    }

    public static function CaselessRestrictModifier(): self
    {
        if (!isset(self::$instances['CaselessRestrictModifier'])) {
            self::$instances['CaselessRestrictModifier'] = new self('CaselessRestrictModifier', 'CaselessRestrictModifier');
        }

        return self::$instances['CaselessRestrictModifier'];
    }

    public static function AsciiOptions(): self
    {
        if (!isset(self::$instances['AsciiOptions'])) {
            self::$instances['AsciiOptions'] = new self('AsciiOptions', 'AsciiOptions');
        }

        return self::$instances['AsciiOptions'];
    }

    public static function LongGroupNames(): self
    {
        if (!isset(self::$instances['LongGroupNames'])) {
            self::$instances['LongGroupNames'] = new self('LongGroupNames', 'LongGroupNames');
        }

        return self::$instances['LongGroupNames'];
    }

    public static function ScanSubstring(): self
    {
        if (!isset(self::$instances['ScanSubstring'])) {
            self::$instances['ScanSubstring'] = new self('ScanSubstring', 'ScanSubstring');
        }

        return self::$instances['ScanSubstring'];
    }

    public static function ExtendedCharClass(): self
    {
        if (!isset(self::$instances['ExtendedCharClass'])) {
            self::$instances['ExtendedCharClass'] = new self('ExtendedCharClass', 'ExtendedCharClass');
        }

        return self::$instances['ExtendedCharClass'];
    }

    public static function CasingSettingVerbs(): self
    {
        if (!isset(self::$instances['CasingSettingVerbs'])) {
            self::$instances['CasingSettingVerbs'] = new self('CasingSettingVerbs', 'CasingSettingVerbs');
        }

        return self::$instances['CasingSettingVerbs'];
    }

    public static function HugeBackreferenceNumberIsReference(): self
    {
        if (!isset(self::$instances['HugeBackreferenceNumberIsReference'])) {
            self::$instances['HugeBackreferenceNumberIsReference'] = new self('HugeBackreferenceNumberIsReference', 'HugeBackreferenceNumberIsReference');
        }

        return self::$instances['HugeBackreferenceNumberIsReference'];
    }

    public static function NumberTooBigPastWholeNumber(): self
    {
        if (!isset(self::$instances['NumberTooBigPastWholeNumber'])) {
            self::$instances['NumberTooBigPastWholeNumber'] = new self('NumberTooBigPastWholeNumber', 'NumberTooBigPastWholeNumber');
        }

        return self::$instances['NumberTooBigPastWholeNumber'];
    }

    public static function LimitValueErrorOnFaultingCharacter(): self
    {
        if (!isset(self::$instances['LimitValueErrorOnFaultingCharacter'])) {
            self::$instances['LimitValueErrorOnFaultingCharacter'] = new self('LimitValueErrorOnFaultingCharacter', 'LimitValueErrorOnFaultingCharacter');
        }

        return self::$instances['LimitValueErrorOnFaultingCharacter'];
    }

    public static function PosixItemErrorPastItsEnd(): self
    {
        if (!isset(self::$instances['PosixItemErrorPastItsEnd'])) {
            self::$instances['PosixItemErrorPastItsEnd'] = new self('PosixItemErrorPastItsEnd', 'PosixItemErrorPastItsEnd');
        }

        return self::$instances['PosixItemErrorPastItsEnd'];
    }

    public static function MalformedPropertyName(): self
    {
        if (!isset(self::$instances['MalformedPropertyName'])) {
            self::$instances['MalformedPropertyName'] = new self('MalformedPropertyName', 'MalformedPropertyName');
        }

        return self::$instances['MalformedPropertyName'];
    }

    public static function RangeFromTypeReadToItsEnd(): self
    {
        if (!isset(self::$instances['RangeFromTypeReadToItsEnd'])) {
            self::$instances['RangeFromTypeReadToItsEnd'] = new self('RangeFromTypeReadToItsEnd', 'RangeFromTypeReadToItsEnd');
        }

        return self::$instances['RangeFromTypeReadToItsEnd'];
    }

    public static function EmptyQuoteSkippedAfterClassEscape(): self
    {
        if (!isset(self::$instances['EmptyQuoteSkippedAfterClassEscape'])) {
            self::$instances['EmptyQuoteSkippedAfterClassEscape'] = new self('EmptyQuoteSkippedAfterClassEscape', 'EmptyQuoteSkippedAfterClassEscape');
        }

        return self::$instances['EmptyQuoteSkippedAfterClassEscape'];
    }

    public static function SpacedHyphenAfterClassEscapeMakesRange(): self
    {
        if (!isset(self::$instances['SpacedHyphenAfterClassEscapeMakesRange'])) {
            self::$instances['SpacedHyphenAfterClassEscapeMakesRange'] = new self('SpacedHyphenAfterClassEscapeMakesRange', 'SpacedHyphenAfterClassEscapeMakesRange');
        }

        return self::$instances['SpacedHyphenAfterClassEscapeMakesRange'];
    }

    public static function ClassBackslashKIsLetter(): self
    {
        if (!isset(self::$instances['ClassBackslashKIsLetter'])) {
            self::$instances['ClassBackslashKIsLetter'] = new self('ClassBackslashKIsLetter', 'ClassBackslashKIsLetter');
        }

        return self::$instances['ClassBackslashKIsLetter'];
    }

    public static function HexEscapeNeedsDigits(): self
    {
        if (!isset(self::$instances['HexEscapeNeedsDigits'])) {
            self::$instances['HexEscapeNeedsDigits'] = new self('HexEscapeNeedsDigits', 'HexEscapeNeedsDigits');
        }

        return self::$instances['HexEscapeNeedsDigits'];
    }

    public static function EmptyQuoteOpeningClassIsUnclosed(): self
    {
        if (!isset(self::$instances['EmptyQuoteOpeningClassIsUnclosed'])) {
            self::$instances['EmptyQuoteOpeningClassIsUnclosed'] = new self('EmptyQuoteOpeningClassIsUnclosed', 'EmptyQuoteOpeningClassIsUnclosed');
        }

        return self::$instances['EmptyQuoteOpeningClassIsUnclosed'];
    }

    public static function ClassEscapeEndingRangeRefusedAsEscape(): self
    {
        if (!isset(self::$instances['ClassEscapeEndingRangeRefusedAsEscape'])) {
            self::$instances['ClassEscapeEndingRangeRefusedAsEscape'] = new self('ClassEscapeEndingRangeRefusedAsEscape', 'ClassEscapeEndingRangeRefusedAsEscape');
        }

        return self::$instances['ClassEscapeEndingRangeRefusedAsEscape'];
    }

    public static function NamedCharacterBraceRefusedInClass(): self
    {
        if (!isset(self::$instances['NamedCharacterBraceRefusedInClass'])) {
            self::$instances['NamedCharacterBraceRefusedInClass'] = new self('NamedCharacterBraceRefusedInClass', 'NamedCharacterBraceRefusedInClass');
        }

        return self::$instances['NamedCharacterBraceRefusedInClass'];
    }

    public static function ClassesCompiledFromMergedRanges(): self
    {
        if (!isset(self::$instances['ClassesCompiledFromMergedRanges'])) {
            self::$instances['ClassesCompiledFromMergedRanges'] = new self('ClassesCompiledFromMergedRanges', 'ClassesCompiledFromMergedRanges');
        }

        return self::$instances['ClassesCompiledFromMergedRanges'];
    }

    public static function CaselessReferenceHoldsItsCaseRules(): self
    {
        if (!isset(self::$instances['CaselessReferenceHoldsItsCaseRules'])) {
            self::$instances['CaselessReferenceHoldsItsCaseRules'] = new self('CaselessReferenceHoldsItsCaseRules', 'CaselessReferenceHoldsItsCaseRules');
        }

        return self::$instances['CaselessReferenceHoldsItsCaseRules'];
    }

    public static function VersionConditionWholeNumbers(): self
    {
        if (!isset(self::$instances['VersionConditionWholeNumbers'])) {
            self::$instances['VersionConditionWholeNumbers'] = new self('VersionConditionWholeNumbers', 'VersionConditionWholeNumbers');
        }

        return self::$instances['VersionConditionWholeNumbers'];
    }

    public static function CallsReturnCaptureGroups(): self
    {
        if (!isset(self::$instances['CallsReturnCaptureGroups'])) {
            self::$instances['CallsReturnCaptureGroups'] = new self('CallsReturnCaptureGroups', 'CallsReturnCaptureGroups');
        }

        return self::$instances['CallsReturnCaptureGroups'];
    }

    public static function GroupListErrors(): self
    {
        if (!isset(self::$instances['GroupListErrors'])) {
            self::$instances['GroupListErrors'] = new self('GroupListErrors', 'GroupListErrors');
        }

        return self::$instances['GroupListErrors'];
    }

    public static function GReferenceNumberReadBeforeClosing(): self
    {
        if (!isset(self::$instances['GReferenceNumberReadBeforeClosing'])) {
            self::$instances['GReferenceNumberReadBeforeClosing'] = new self('GReferenceNumberReadBeforeClosing', 'GReferenceNumberReadBeforeClosing');
        }

        return self::$instances['GReferenceNumberReadBeforeClosing'];
    }

    public static function NamedCodePointReadBeforeModeCheck(): self
    {
        if (!isset(self::$instances['NamedCodePointReadBeforeModeCheck'])) {
            self::$instances['NamedCodePointReadBeforeModeCheck'] = new self('NamedCodePointReadBeforeModeCheck', 'NamedCodePointReadBeforeModeCheck');
        }

        return self::$instances['NamedCodePointReadBeforeModeCheck'];
    }

    public static function CalloutConditionErrorAtItemStart(): self
    {
        if (!isset(self::$instances['CalloutConditionErrorAtItemStart'])) {
            self::$instances['CalloutConditionErrorAtItemStart'] = new self('CalloutConditionErrorAtItemStart', 'CalloutConditionErrorAtItemStart');
        }

        return self::$instances['CalloutConditionErrorAtItemStart'];
    }

    public static function ErrorOffsetPastTheFault(): self
    {
        if (!isset(self::$instances['ErrorOffsetPastTheFault'])) {
            self::$instances['ErrorOffsetPastTheFault'] = new self('ErrorOffsetPastTheFault', 'ErrorOffsetPastTheFault');
        }

        return self::$instances['ErrorOffsetPastTheFault'];
    }

    public static function AlphaNameAtPatternEndIsUnclosed(): self
    {
        if (!isset(self::$instances['AlphaNameAtPatternEndIsUnclosed'])) {
            self::$instances['AlphaNameAtPatternEndIsUnclosed'] = new self('AlphaNameAtPatternEndIsUnclosed', 'AlphaNameAtPatternEndIsUnclosed');
        }

        return self::$instances['AlphaNameAtPatternEndIsUnclosed'];
    }

    public static function VersionConditionLeftOpenIsVersionError(): self
    {
        if (!isset(self::$instances['VersionConditionLeftOpenIsVersionError'])) {
            self::$instances['VersionConditionLeftOpenIsVersionError'] = new self('VersionConditionLeftOpenIsVersionError', 'VersionConditionLeftOpenIsVersionError');
        }

        return self::$instances['VersionConditionLeftOpenIsVersionError'];
    }

    public static function BranchCountErrorOffTheConditionName(): self
    {
        if (!isset(self::$instances['BranchCountErrorOffTheConditionName'])) {
            self::$instances['BranchCountErrorOffTheConditionName'] = new self('BranchCountErrorOffTheConditionName', 'BranchCountErrorOffTheConditionName');
        }

        return self::$instances['BranchCountErrorOffTheConditionName'];
    }

    public static function SurrogateRefusedBeforeBrace(): self
    {
        if (!isset(self::$instances['SurrogateRefusedBeforeBrace'])) {
            self::$instances['SurrogateRefusedBeforeBrace'] = new self('SurrogateRefusedBeforeBrace', 'SurrogateRefusedBeforeBrace');
        }

        return self::$instances['SurrogateRefusedBeforeBrace'];
    }

    public static function TooLargeAtPatternStart(): self
    {
        if (!isset(self::$instances['TooLargeAtPatternStart'])) {
            self::$instances['TooLargeAtPatternStart'] = new self('TooLargeAtPatternStart', 'TooLargeAtPatternStart');
        }

        return self::$instances['TooLargeAtPatternStart'];
    }

    public static function ScanSubstringListsEachGroupOnce(): self
    {
        if (!isset(self::$instances['ScanSubstringListsEachGroupOnce'])) {
            self::$instances['ScanSubstringListsEachGroupOnce'] = new self('ScanSubstringListsEachGroupOnce', 'ScanSubstringListsEachGroupOnce');
        }

        return self::$instances['ScanSubstringListsEachGroupOnce'];
    }

    public static function UnclosedBraceAtPatternEnd(): self
    {
        if (!isset(self::$instances['UnclosedBraceAtPatternEnd'])) {
            self::$instances['UnclosedBraceAtPatternEnd'] = new self('UnclosedBraceAtPatternEnd', 'UnclosedBraceAtPatternEnd');
        }

        return self::$instances['UnclosedBraceAtPatternEnd'];
    }

    public static function ExtendedClassBesideLookbehindCompiles(): self
    {
        if (!isset(self::$instances['ExtendedClassBesideLookbehindCompiles'])) {
            self::$instances['ExtendedClassBesideLookbehindCompiles'] = new self('ExtendedClassBesideLookbehindCompiles', 'ExtendedClassBesideLookbehindCompiles');
        }

        return self::$instances['ExtendedClassBesideLookbehindCompiles'];
    }

    public static function TooLargeAtGroupEndReportedAtPatternEnd(): self
    {
        if (!isset(self::$instances['TooLargeAtGroupEndReportedAtPatternEnd'])) {
            self::$instances['TooLargeAtGroupEndReportedAtPatternEnd'] = new self('TooLargeAtGroupEndReportedAtPatternEnd', 'TooLargeAtGroupEndReportedAtPatternEnd');
        }

        return self::$instances['TooLargeAtGroupEndReportedAtPatternEnd'];
    }

    public static function PossessiveReferenceRepeatedInPlace(): self
    {
        if (!isset(self::$instances['PossessiveReferenceRepeatedInPlace'])) {
            self::$instances['PossessiveReferenceRepeatedInPlace'] = new self('PossessiveReferenceRepeatedInPlace', 'PossessiveReferenceRepeatedInPlace');
        }

        return self::$instances['PossessiveReferenceRepeatedInPlace'];
    }

    /** @return list<self> */
    public static function cases(): array
    {
        return [
            self::AlphabeticAssertionNames(),
            self::ScriptRuns(),
            self::NonAsciiGroupNames(),
            self::BadEscapeInClassStaysRefused(),
            self::NamesReadWhole(),
            self::AnyPropertyMatchesAnyCharacter(),
            self::QuantifiedAccept(),
            self::PossessiveRepeatOnceIsAtomic(),
            self::NonAtomicAssertionNames(),
            self::LookbehindInLookaheadMeasured(),
            self::LookbehindLengthOverflowRefused(),
            self::LongestLookbehindCountsItsBranchesOnly(),
            self::CasePairClassIsACaselessCharacter(),
            self::NonAtomicAssertionShortSyntax(),
            self::GroupHoldingItsOwnReferenceBacktracks(),
            self::RepeatedAssertionKeepsItsCount(),
            self::UcpFoldsPastAsciiWithoutUtf(),
            self::DefineSkippedInLookbehind(),
            self::LargeOpenCountIsText(),
            self::OverflowingBackslashNumberIsOctal(),
            self::LoosePropertyNames(),
            self::OpenAndPaddedRepeatCounts(),
            self::PaddedBracedEscapes(),
            self::VariableLengthLookbehind(),
            self::CaselessRestrictModifier(),
            self::AsciiOptions(),
            self::LongGroupNames(),
            self::ScanSubstring(),
            self::ExtendedCharClass(),
            self::CasingSettingVerbs(),
            self::HugeBackreferenceNumberIsReference(),
            self::NumberTooBigPastWholeNumber(),
            self::LimitValueErrorOnFaultingCharacter(),
            self::PosixItemErrorPastItsEnd(),
            self::MalformedPropertyName(),
            self::RangeFromTypeReadToItsEnd(),
            self::EmptyQuoteSkippedAfterClassEscape(),
            self::SpacedHyphenAfterClassEscapeMakesRange(),
            self::ClassBackslashKIsLetter(),
            self::HexEscapeNeedsDigits(),
            self::EmptyQuoteOpeningClassIsUnclosed(),
            self::ClassEscapeEndingRangeRefusedAsEscape(),
            self::NamedCharacterBraceRefusedInClass(),
            self::ClassesCompiledFromMergedRanges(),
            self::CaselessReferenceHoldsItsCaseRules(),
            self::VersionConditionWholeNumbers(),
            self::CallsReturnCaptureGroups(),
            self::GroupListErrors(),
            self::GReferenceNumberReadBeforeClosing(),
            self::NamedCodePointReadBeforeModeCheck(),
            self::CalloutConditionErrorAtItemStart(),
            self::ErrorOffsetPastTheFault(),
            self::AlphaNameAtPatternEndIsUnclosed(),
            self::VersionConditionLeftOpenIsVersionError(),
            self::BranchCountErrorOffTheConditionName(),
            self::SurrogateRefusedBeforeBrace(),
            self::TooLargeAtPatternStart(),
            self::ScanSubstringListsEachGroupOnce(),
            self::UnclosedBraceAtPatternEnd(),
            self::ExtendedClassBesideLookbehindCompiles(),
            self::TooLargeAtGroupEndReportedAtPatternEnd(),
            self::PossessiveReferenceRepeatedInPlace(),
        ];
    }

    public function release(): string
    {
        $perCase = array (
  'AlphabeticAssertionNames' => '10.33',
  'ScriptRuns' => '10.33',
  'NonAsciiGroupNames' => '10.33',
  'BadEscapeInClassStaysRefused' => '10.33',
  'NamesReadWhole' => '10.33',
  'AnyPropertyMatchesAnyCharacter' => '10.33',
  'QuantifiedAccept' => '10.34',
  'PossessiveRepeatOnceIsAtomic' => '10.34',
  'NonAtomicAssertionNames' => '10.34',
  'LookbehindInLookaheadMeasured' => '10.34',
  'LookbehindLengthOverflowRefused' => '10.34',
  'LongestLookbehindCountsItsBranchesOnly' => '10.34',
  'CasePairClassIsACaselessCharacter' => '10.34',
  'NonAtomicAssertionShortSyntax' => '10.35',
  'GroupHoldingItsOwnReferenceBacktracks' => '10.35',
  'RepeatedAssertionKeepsItsCount' => '10.35',
  'UcpFoldsPastAsciiWithoutUtf' => '10.35',
  'DefineSkippedInLookbehind' => '10.35',
  'LargeOpenCountIsText' => '10.37',
  'OverflowingBackslashNumberIsOctal' => '10.37',
  'LoosePropertyNames' => '10.40',
  'OpenAndPaddedRepeatCounts' => '10.43',
  'PaddedBracedEscapes' => '10.43',
  'VariableLengthLookbehind' => '10.43',
  'CaselessRestrictModifier' => '10.43',
  'AsciiOptions' => '10.43',
  'LongGroupNames' => '10.44',
  'ScanSubstring' => '10.45',
  'ExtendedCharClass' => '10.45',
  'CasingSettingVerbs' => '10.45',
  'HugeBackreferenceNumberIsReference' => '10.45',
  'NumberTooBigPastWholeNumber' => '10.45',
  'LimitValueErrorOnFaultingCharacter' => '10.45',
  'PosixItemErrorPastItsEnd' => '10.45',
  'MalformedPropertyName' => '10.45',
  'RangeFromTypeReadToItsEnd' => '10.45',
  'EmptyQuoteSkippedAfterClassEscape' => '10.45',
  'SpacedHyphenAfterClassEscapeMakesRange' => '10.45',
  'ClassBackslashKIsLetter' => '10.45',
  'HexEscapeNeedsDigits' => '10.45',
  'EmptyQuoteOpeningClassIsUnclosed' => '10.45',
  'ClassEscapeEndingRangeRefusedAsEscape' => '10.45',
  'NamedCharacterBraceRefusedInClass' => '10.45',
  'ClassesCompiledFromMergedRanges' => '10.45',
  'CaselessReferenceHoldsItsCaseRules' => '10.45',
  'VersionConditionWholeNumbers' => '10.47',
  'CallsReturnCaptureGroups' => '10.47',
  'GroupListErrors' => '10.47',
  'GReferenceNumberReadBeforeClosing' => '10.47',
  'NamedCodePointReadBeforeModeCheck' => '10.47',
  'CalloutConditionErrorAtItemStart' => '10.47',
  'ErrorOffsetPastTheFault' => '10.47',
  'AlphaNameAtPatternEndIsUnclosed' => '10.47',
  'VersionConditionLeftOpenIsVersionError' => '10.47',
  'BranchCountErrorOffTheConditionName' => '10.47',
  'SurrogateRefusedBeforeBrace' => '10.47',
  'TooLargeAtPatternStart' => '10.47',
  'ScanSubstringListsEachGroupOnce' => '10.47',
  'UnclosedBraceAtPatternEnd' => '10.48',
  'ExtendedClassBesideLookbehindCompiles' => '10.48',
  'TooLargeAtGroupEndReportedAtPatternEnd' => '10.48',
  'PossessiveReferenceRepeatedInPlace' => '10.48',
);

        return $perCase[$this->name];
    }
}
