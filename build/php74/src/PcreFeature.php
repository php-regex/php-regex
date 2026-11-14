<?php

declare(strict_types=1);

namespace PHPRegex\Parser;

/**
 * The PcreFeature enum as a class of shared instances: PcreFeature::AlphabeticAssertionNames() returns
 * the same instance at each call.
 */
final class PcreFeature implements UnitEnum
{
    /** @var array<string, self> */
    private static array $instances = [];

    public string $name;

    private function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function AlphabeticAssertionNames(): self
    {
        return self::$instances['AlphabeticAssertionNames'] ??= new self('AlphabeticAssertionNames');
    }

    public static function ScriptRuns(): self
    {
        return self::$instances['ScriptRuns'] ??= new self('ScriptRuns');
    }

    public static function NonAsciiGroupNames(): self
    {
        return self::$instances['NonAsciiGroupNames'] ??= new self('NonAsciiGroupNames');
    }

    public static function BadEscapeInClassStaysRefused(): self
    {
        return self::$instances['BadEscapeInClassStaysRefused'] ??= new self('BadEscapeInClassStaysRefused');
    }

    public static function NamesReadWhole(): self
    {
        return self::$instances['NamesReadWhole'] ??= new self('NamesReadWhole');
    }

    public static function AnyPropertyMatchesAnyCharacter(): self
    {
        return self::$instances['AnyPropertyMatchesAnyCharacter'] ??= new self('AnyPropertyMatchesAnyCharacter');
    }

    public static function QuantifiedAccept(): self
    {
        return self::$instances['QuantifiedAccept'] ??= new self('QuantifiedAccept');
    }

    public static function PossessiveRepeatOnceIsAtomic(): self
    {
        return self::$instances['PossessiveRepeatOnceIsAtomic'] ??= new self('PossessiveRepeatOnceIsAtomic');
    }

    public static function NonAtomicAssertionNames(): self
    {
        return self::$instances['NonAtomicAssertionNames'] ??= new self('NonAtomicAssertionNames');
    }

    public static function LookbehindInLookaheadMeasured(): self
    {
        return self::$instances['LookbehindInLookaheadMeasured'] ??= new self('LookbehindInLookaheadMeasured');
    }

    public static function LookbehindLengthOverflowRefused(): self
    {
        return self::$instances['LookbehindLengthOverflowRefused'] ??= new self('LookbehindLengthOverflowRefused');
    }

    public static function LongestLookbehindCountsItsBranchesOnly(): self
    {
        return self::$instances['LongestLookbehindCountsItsBranchesOnly'] ??= new self('LongestLookbehindCountsItsBranchesOnly');
    }

    public static function CasePairClassIsACaselessCharacter(): self
    {
        return self::$instances['CasePairClassIsACaselessCharacter'] ??= new self('CasePairClassIsACaselessCharacter');
    }

    public static function NonAtomicAssertionShortSyntax(): self
    {
        return self::$instances['NonAtomicAssertionShortSyntax'] ??= new self('NonAtomicAssertionShortSyntax');
    }

    public static function GroupHoldingItsOwnReferenceBacktracks(): self
    {
        return self::$instances['GroupHoldingItsOwnReferenceBacktracks'] ??= new self('GroupHoldingItsOwnReferenceBacktracks');
    }

    public static function RepeatedAssertionKeepsItsCount(): self
    {
        return self::$instances['RepeatedAssertionKeepsItsCount'] ??= new self('RepeatedAssertionKeepsItsCount');
    }

    public static function UcpFoldsPastAsciiWithoutUtf(): self
    {
        return self::$instances['UcpFoldsPastAsciiWithoutUtf'] ??= new self('UcpFoldsPastAsciiWithoutUtf');
    }

    public static function DefineSkippedInLookbehind(): self
    {
        return self::$instances['DefineSkippedInLookbehind'] ??= new self('DefineSkippedInLookbehind');
    }

    public static function LargeOpenCountIsText(): self
    {
        return self::$instances['LargeOpenCountIsText'] ??= new self('LargeOpenCountIsText');
    }

    public static function OverflowingBackslashNumberIsOctal(): self
    {
        return self::$instances['OverflowingBackslashNumberIsOctal'] ??= new self('OverflowingBackslashNumberIsOctal');
    }

    public static function LoosePropertyNames(): self
    {
        return self::$instances['LoosePropertyNames'] ??= new self('LoosePropertyNames');
    }

    public static function OpenAndPaddedRepeatCounts(): self
    {
        return self::$instances['OpenAndPaddedRepeatCounts'] ??= new self('OpenAndPaddedRepeatCounts');
    }

    public static function PaddedBracedEscapes(): self
    {
        return self::$instances['PaddedBracedEscapes'] ??= new self('PaddedBracedEscapes');
    }

    public static function VariableLengthLookbehind(): self
    {
        return self::$instances['VariableLengthLookbehind'] ??= new self('VariableLengthLookbehind');
    }

    public static function CaselessRestrictModifier(): self
    {
        return self::$instances['CaselessRestrictModifier'] ??= new self('CaselessRestrictModifier');
    }

    public static function AsciiOptions(): self
    {
        return self::$instances['AsciiOptions'] ??= new self('AsciiOptions');
    }

    public static function UcpWordTakesMarksAndConnectors(): self
    {
        return self::$instances['UcpWordTakesMarksAndConnectors'] ??= new self('UcpWordTakesMarksAndConnectors');
    }

    public static function CallCapturesGivenBack(): self
    {
        return self::$instances['CallCapturesGivenBack'] ??= new self('CallCapturesGivenBack');
    }

    public static function LongGroupNames(): self
    {
        return self::$instances['LongGroupNames'] ??= new self('LongGroupNames');
    }

    public static function ScanSubstring(): self
    {
        return self::$instances['ScanSubstring'] ??= new self('ScanSubstring');
    }

    public static function ExtendedCharClass(): self
    {
        return self::$instances['ExtendedCharClass'] ??= new self('ExtendedCharClass');
    }

    public static function CasingSettingVerbs(): self
    {
        return self::$instances['CasingSettingVerbs'] ??= new self('CasingSettingVerbs');
    }

    public static function HugeBackreferenceNumberIsReference(): self
    {
        return self::$instances['HugeBackreferenceNumberIsReference'] ??= new self('HugeBackreferenceNumberIsReference');
    }

    public static function NumberTooBigPastWholeNumber(): self
    {
        return self::$instances['NumberTooBigPastWholeNumber'] ??= new self('NumberTooBigPastWholeNumber');
    }

    public static function LimitValueErrorOnFaultingCharacter(): self
    {
        return self::$instances['LimitValueErrorOnFaultingCharacter'] ??= new self('LimitValueErrorOnFaultingCharacter');
    }

    public static function PosixItemErrorPastItsEnd(): self
    {
        return self::$instances['PosixItemErrorPastItsEnd'] ??= new self('PosixItemErrorPastItsEnd');
    }

    public static function MalformedPropertyName(): self
    {
        return self::$instances['MalformedPropertyName'] ??= new self('MalformedPropertyName');
    }

    public static function RangeFromTypeReadToItsEnd(): self
    {
        return self::$instances['RangeFromTypeReadToItsEnd'] ??= new self('RangeFromTypeReadToItsEnd');
    }

    public static function EmptyQuoteSkippedAfterClassEscape(): self
    {
        return self::$instances['EmptyQuoteSkippedAfterClassEscape'] ??= new self('EmptyQuoteSkippedAfterClassEscape');
    }

    public static function SpacedHyphenAfterClassEscapeMakesRange(): self
    {
        return self::$instances['SpacedHyphenAfterClassEscapeMakesRange'] ??= new self('SpacedHyphenAfterClassEscapeMakesRange');
    }

    public static function ClassBackslashKIsLetter(): self
    {
        return self::$instances['ClassBackslashKIsLetter'] ??= new self('ClassBackslashKIsLetter');
    }

    public static function HexEscapeNeedsDigits(): self
    {
        return self::$instances['HexEscapeNeedsDigits'] ??= new self('HexEscapeNeedsDigits');
    }

    public static function EmptyQuoteOpeningClassIsUnclosed(): self
    {
        return self::$instances['EmptyQuoteOpeningClassIsUnclosed'] ??= new self('EmptyQuoteOpeningClassIsUnclosed');
    }

    public static function ClassEscapeEndingRangeRefusedAsEscape(): self
    {
        return self::$instances['ClassEscapeEndingRangeRefusedAsEscape'] ??= new self('ClassEscapeEndingRangeRefusedAsEscape');
    }

    public static function NamedCharacterBraceRefusedInClass(): self
    {
        return self::$instances['NamedCharacterBraceRefusedInClass'] ??= new self('NamedCharacterBraceRefusedInClass');
    }

    public static function ClassesCompiledFromMergedRanges(): self
    {
        return self::$instances['ClassesCompiledFromMergedRanges'] ??= new self('ClassesCompiledFromMergedRanges');
    }

    public static function CaselessReferenceHoldsItsCaseRules(): self
    {
        return self::$instances['CaselessReferenceHoldsItsCaseRules'] ??= new self('CaselessReferenceHoldsItsCaseRules');
    }

    public static function VersionConditionWholeNumbers(): self
    {
        return self::$instances['VersionConditionWholeNumbers'] ??= new self('VersionConditionWholeNumbers');
    }

    public static function CallsReturnCaptureGroups(): self
    {
        return self::$instances['CallsReturnCaptureGroups'] ??= new self('CallsReturnCaptureGroups');
    }

    public static function GroupListErrors(): self
    {
        return self::$instances['GroupListErrors'] ??= new self('GroupListErrors');
    }

    public static function GReferenceNumberReadBeforeClosing(): self
    {
        return self::$instances['GReferenceNumberReadBeforeClosing'] ??= new self('GReferenceNumberReadBeforeClosing');
    }

    public static function NamedCodePointReadBeforeModeCheck(): self
    {
        return self::$instances['NamedCodePointReadBeforeModeCheck'] ??= new self('NamedCodePointReadBeforeModeCheck');
    }

    public static function CalloutConditionErrorAtItemStart(): self
    {
        return self::$instances['CalloutConditionErrorAtItemStart'] ??= new self('CalloutConditionErrorAtItemStart');
    }

    public static function ErrorOffsetPastTheFault(): self
    {
        return self::$instances['ErrorOffsetPastTheFault'] ??= new self('ErrorOffsetPastTheFault');
    }

    public static function AlphaNameAtPatternEndIsUnclosed(): self
    {
        return self::$instances['AlphaNameAtPatternEndIsUnclosed'] ??= new self('AlphaNameAtPatternEndIsUnclosed');
    }

    public static function VersionConditionLeftOpenIsVersionError(): self
    {
        return self::$instances['VersionConditionLeftOpenIsVersionError'] ??= new self('VersionConditionLeftOpenIsVersionError');
    }

    public static function BranchCountErrorOffTheConditionName(): self
    {
        return self::$instances['BranchCountErrorOffTheConditionName'] ??= new self('BranchCountErrorOffTheConditionName');
    }

    public static function SurrogateRefusedBeforeBrace(): self
    {
        return self::$instances['SurrogateRefusedBeforeBrace'] ??= new self('SurrogateRefusedBeforeBrace');
    }

    public static function TooLargeAtPatternStart(): self
    {
        return self::$instances['TooLargeAtPatternStart'] ??= new self('TooLargeAtPatternStart');
    }

    public static function ScanSubstringListsEachGroupOnce(): self
    {
        return self::$instances['ScanSubstringListsEachGroupOnce'] ??= new self('ScanSubstringListsEachGroupOnce');
    }

    public static function UnclosedBraceAtPatternEnd(): self
    {
        return self::$instances['UnclosedBraceAtPatternEnd'] ??= new self('UnclosedBraceAtPatternEnd');
    }

    public static function ExtendedClassBesideLookbehindCompiles(): self
    {
        return self::$instances['ExtendedClassBesideLookbehindCompiles'] ??= new self('ExtendedClassBesideLookbehindCompiles');
    }

    public static function TooLargeAtGroupEndReportedAtPatternEnd(): self
    {
        return self::$instances['TooLargeAtGroupEndReportedAtPatternEnd'] ??= new self('TooLargeAtGroupEndReportedAtPatternEnd');
    }

    public static function PossessiveReferenceRepeatedInPlace(): self
    {
        return self::$instances['PossessiveReferenceRepeatedInPlace'] ??= new self('PossessiveReferenceRepeatedInPlace');
    }

    /**
     * @return list<self>
     */
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
            self::UcpWordTakesMarksAndConnectors(),
            self::CallCapturesGivenBack(),
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
        $perCase = [
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
            'UcpWordTakesMarksAndConnectors' => '10.43',
            'CallCapturesGivenBack' => '10.43',
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
        ];

        return $perCase[$this->name];
    }
}
