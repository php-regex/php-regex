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

/**
 * A behaviour PCRE2 changed, by the release it arrived in. A rule asks its
 * target whether it has the behaviour (PcreTarget::supports()), so what
 * changed when reads as one table.
 */
enum PcreFeature
{
    /**
     * The assertions and the atomic group take alphabetic names: "(*pla:", "(*positive_lookahead:", "(*nla:", "(*plb:", "(*nlb:", "(*atomic:" and their long forms.
     */
    case AlphabeticAssertionNames;

    /**
     * Script runs: "(*script_run:", "(*sr:", "(*atomic_script_run:" and "(*asr:".
     */
    case ScriptRuns;

    /**
     * Under UTF a group name may hold letters and decimal digits of any script, as "(?<nämed>a)".
     */
    case NonAsciiGroupNames;

    /**
     * Under PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL, as PHP 7.3 and 7.4 compile, an escape a class refuses once it has read it, as "[\B]", "[\N]" or the "\d" of "[a-\d]", stays refused, where it was read as its letter.
     */
    case BadEscapeInClassStaysRefused;

    /**
     * A name is read whole before it is judged: a verb name holds letters, digits and underscores, not letters only, and a name too long is refused where it ends, not at the character past the limit.
     */
    case NamesReadWhole;

    /**
     * "\p{Any}" compiles to a match of any character, one unit, where it was a property of three.
     */
    case AnyPropertyMatchesAnyCharacter;

    /**
     * "(*ACCEPT)" may be repeated, as "(*ACCEPT)??".
     */
    case QuantifiedAccept;

    /**
     * A possessive "{1}+" on a group or a subroutine call makes it atomic, where it was ignored as "{1}" is.
     */
    case PossessiveRepeatOnceIsAtomic;

    /**
     * The non-atomic lookarounds: "(*napla:", "(*naplb:" and their long forms.
     */
    case NonAtomicAssertionNames;

    /**
     * A lookbehind inside a lookahead met while a lookbehind is measured is measured too, where the lookahead was skipped whole.
     */
    case LookbehindInLookaheadMeasured;

    /**
     * A lookbehind branch whose length passes what a 32-bit integer holds is too long, where its length wrapped around.
     */
    case LookbehindLengthOverflowRefused;

    /**
     * The longest lookbehind reported is the longest branch of a lookbehind, where it was the longest branch measured for one, of a group or a called group too.
     */
    case LongestLookbehindCountsItsBranchesOnly;

    /**
     * A class of a character and its other case, as "[aA]", compiles to that character matched caselessly, where it was a class.
     */
    case CasePairClassIsACaselessCharacter;

    /**
     * "(?*" and "(?<*" spell the non-atomic lookarounds.
     */
    case NonAtomicAssertionShortSyntax;

    /**
     * A group that holds a reference to itself may be backtracked into, where it was atomic.
     */
    case GroupHoldingItsOwnReferenceBacktracks;

    /**
     * A repeated assertion runs as many times as its count asks, where it ran once.
     */
    case RepeatedAssertionKeepsItsCount;

    /**
     * Under "(*UCP)" without UTF, caseless matching folds the characters past ASCII by their Unicode properties.
     */
    case UcpFoldsPastAsciiWithoutUtf;

    /**
     * A "(?(DEFINE)...)" group in a lookbehind adds nothing to its length, where it was measured as a group with its first item skipped.
     */
    case DefineSkippedInLookbehind;

    /**
     * A count past 65535 in a brace that opens no quantifier, as "a{65536", is text instead of a count too big.
     */
    case LargeOpenCountIsText;

    /**
     * A backslash and digits past 214748363, as "\777777777777", reads as
     * an octal escape then text instead of a number too big: the engine
     * takes its three octal digits and lets the rest read as itself.
     */
    case OverflowingBackslashNumberIsOctal;

    /**
     * "\p{...}" names are matched loosely ("\p{greek}", "\p{Old Italic}") and take "sc:", "scx:", four-letter script codes, Bidi classes and binary properties; a bare script name means its script extensions.
     */
    case LoosePropertyNames;

    /**
     * "{,2}" and a count padded with spaces, as "{ 2 }", repeat instead of matching as text.
     */
    case OpenAndPaddedRepeatCounts;

    /**
     * Spaces are allowed inside braced escapes and references, as "\x{ 41 }" or "\g{ 1 }".
     */
    case PaddedBracedEscapes;

    /**
     * A lookbehind may hold branches of different lengths, up to 255 characters.
     */
    case VariableLengthLookbehind;

    /**
     * The "r" modifier and "(?r)": caseless matching restricted to one script.
     */
    case CaselessRestrictModifier;

    /**
     * "(?a)" and its "D", "S", "W", "P", "T" variants: ASCII-only classes.
     */
    case AsciiOptions;

    /**
     * Under UCP "\w" matches a non-spacing mark (\p{Mn}) and a connector punctuation (\p{Pc}) too, as "\u{301}".
     */
    case UcpWordTakesMarksAndConnectors;

    /**
     * A group name may be 128 code units long, not 32.
     */
    case LongGroupNames;

    /**
     * "(*scan_substring:(1)...)", or "(*scs:": a match read inside a capture.
     */
    case ScanSubstring;

    /**
     * Perl extended character classes, "(?[ ... ])".
     */
    case ExtendedCharClass;

    /**
     * "(*CASELESS_RESTRICT)" and "(*TURKISH_CASING)".
     */
    case CasingSettingVerbs;

    /**
     * "\8" or "\9" followed by eight digits or more is a reference, not a digit and text.
     */
    case HugeBackreferenceNumberIsReference;

    /**
     * A number past 65535 is reported past the whole number.
     */
    case NumberTooBigPastWholeNumber;

    /**
     * An error in a "(*LIMIT_x=" value is reported on the faulty character.
     */
    case LimitValueErrorOnFaultingCharacter;

    /**
     * An unknown POSIX class or collating element is reported past its end.
     */
    case PosixItemErrorPastItsEnd;

    /**
     * A character no property name holds makes "\p{...}" malformed at that character.
     */
    case MalformedPropertyName;

    /**
     * A range from a type, a POSIX class or a property is read to its end before it is refused.
     */
    case RangeFromTypeReadToItsEnd;

    /**
     * "[\w\E-a]": the empty quote is skipped, and the hyphen makes a range.
     */
    case EmptyQuoteSkippedAfterClassEscape;

    /**
     * Under "(?xx)", a space between a class escape and a hyphen no longer
     * keeps the hyphen a member: "[\d -z]" is the range "\d-z", which is refused.
     */
    case SpacedHyphenAfterClassEscapeMakesRange;

    /**
     * "\k" in a class is the letter k, not an invalid escape.
     */
    case ClassBackslashKIsLetter;

    /**
     * "\x" with no hexadecimal digit is an error, not a NUL.
     */
    case HexEscapeNeedsDigits;

    /**
     * "[\E" or "[\Q\E" at the end of the pattern is a class left open, not a trailing backslash.
     */
    case EmptyQuoteOpeningClassIsUnclosed;

    /**
     * An escape a class refuses, as "\N" or "\z", ending a range is refused as that escape, not as an invalid range.
     */
    case ClassEscapeEndingRangeRefusedAsEscape;

    /**
     * "\N{" in a class is refused as a character name whatever follows the brace, where a count after "\N" was read as one.
     */
    case NamedCharacterBraceRefusedInClass;

    /**
     * A class compiles from its ranges sorted and merged: the other cases a caseless class adds past the map are added once, "[\p{Any}]" or a class of every byte compiles to a match of any character and "\P{Any}" to a class of none, where each member added its own.
     */
    case ClassesCompiledFromMergedRanges;

    /**
     * A caseless back reference compiles to one unit more, which holds its case rules.
     */
    case CaselessReferenceHoldsItsCaseRules;

    /**
     * The minor of "(?(VERSION>=10.xx)" is read whole, not as two digits.
     */
    case VersionConditionWholeNumbers;

    /**
     * A call may return capture groups, as "(?1(2,<name>))".
     */
    case CallsReturnCaptureGroups;

    /**
     * A list of groups that opens no "(", or holds an item that is no number or name, is refused as such, where it was a missing ")" at the end of the pattern and a reference to no group elsewhere.
     */
    case GroupListErrors;

    /**
     * An unclosed "\g<3" or "\g{3" is refused after the number, not at "\g".
     */
    case GReferenceNumberReadBeforeClosing;

    /**
     * "\N{U+...}" without UTF is read to its brace before it is refused.
     */
    case NamedCodePointReadBeforeModeCheck;

    /**
     * An error in a callout condition is reported at the start of the item.
     */
    case CalloutConditionErrorAtItemStart;

    /**
     * Most syntax errors are reported past the faulty character rather than on it.
     */
    case ErrorOffsetPastTheFault;

    /**
     * An alphabetic name the pattern ends in, as "(*pla", is a missing ")", not an unknown assertion.
     */
    case AlphaNameAtPatternEndIsUnclosed;

    /**
     * A character after the major of "(?(VERSION=10z)" is a version error, not a condition left open.
     */
    case VersionConditionLeftOpenIsVersionError;

    /**
     * A conditional on a name that holds more than two branches is not reported on that name.
     */
    case BranchCountErrorOffTheConditionName;

    /**
     * Under UTF a surrogate written in braces, "\x{d800" or "\o{154000", is refused before the closing brace is looked for.
     */
    case SurrogateRefusedBeforeBrace;

    /**
     * A pattern too large to compile is reported at offset 0, not at its end.
     */
    case TooLargeAtPatternStart;

    /**
     * A substring scan compiles each group it lists once, and a name several groups bear only while one of them is not listed before it, where each item listed compiled.
     */
    case ScanSubstringListsEachGroupOnce;

    /**
     * A braced escape left open at the end of the pattern is reported at the end, not past it.
     */
    case UnclosedBraceAtPatternEnd;

    /**
     * A pattern that holds a lookbehind compiles an operator of an extended class outside it, and a lookbehind an extended class that nests a class or a parenthesis, where both failed.
     */
    case ExtendedClassBesideLookbehindCompiles;

    /**
     * A pattern found too large only where a group or the pattern ends is reported at the end of the pattern again.
     */
    case TooLargeAtGroupEndReportedAtPatternEnd;

    /**
     * A possessive count on a back reference compiles to a possessive repeat, where it wrapped the reference in an atomic group.
     */
    case PossessiveReferenceRepeatedInPlace;

    /**
     * The PCRE2 release the behaviour arrived in, as "10.45".
     */
    public function release(): string
    {
        return match ($this) {
            self::AlphabeticAssertionNames,
            self::ScriptRuns,
            self::NonAsciiGroupNames,
            self::BadEscapeInClassStaysRefused,
            self::NamesReadWhole,
            self::AnyPropertyMatchesAnyCharacter => '10.33',
            self::QuantifiedAccept,
            self::PossessiveRepeatOnceIsAtomic,
            self::NonAtomicAssertionNames,
            self::LookbehindInLookaheadMeasured,
            self::LookbehindLengthOverflowRefused,
            self::LongestLookbehindCountsItsBranchesOnly,
            self::CasePairClassIsACaselessCharacter => '10.34',
            self::NonAtomicAssertionShortSyntax,
            self::GroupHoldingItsOwnReferenceBacktracks,
            self::RepeatedAssertionKeepsItsCount,
            self::UcpFoldsPastAsciiWithoutUtf,
            self::DefineSkippedInLookbehind => '10.35',
            self::LargeOpenCountIsText,
            self::OverflowingBackslashNumberIsOctal => '10.37',
            self::LoosePropertyNames => '10.40',
            self::OpenAndPaddedRepeatCounts,
            self::PaddedBracedEscapes,
            self::VariableLengthLookbehind,
            self::CaselessRestrictModifier,
            self::AsciiOptions,
            self::UcpWordTakesMarksAndConnectors => '10.43',
            self::LongGroupNames => '10.44',
            self::ScanSubstring,
            self::ExtendedCharClass,
            self::CasingSettingVerbs,
            self::HugeBackreferenceNumberIsReference,
            self::NumberTooBigPastWholeNumber,
            self::LimitValueErrorOnFaultingCharacter,
            self::PosixItemErrorPastItsEnd,
            self::MalformedPropertyName,
            self::RangeFromTypeReadToItsEnd,
            self::EmptyQuoteSkippedAfterClassEscape,
            self::SpacedHyphenAfterClassEscapeMakesRange,
            self::ClassBackslashKIsLetter,
            self::HexEscapeNeedsDigits,
            self::EmptyQuoteOpeningClassIsUnclosed,
            self::ClassEscapeEndingRangeRefusedAsEscape,
            self::NamedCharacterBraceRefusedInClass,
            self::ClassesCompiledFromMergedRanges,
            self::CaselessReferenceHoldsItsCaseRules => '10.45',
            self::VersionConditionWholeNumbers,
            self::CallsReturnCaptureGroups,
            self::GroupListErrors,
            self::GReferenceNumberReadBeforeClosing,
            self::NamedCodePointReadBeforeModeCheck,
            self::CalloutConditionErrorAtItemStart,
            self::ErrorOffsetPastTheFault,
            self::AlphaNameAtPatternEndIsUnclosed,
            self::VersionConditionLeftOpenIsVersionError,
            self::BranchCountErrorOffTheConditionName,
            self::SurrogateRefusedBeforeBrace,
            self::TooLargeAtPatternStart,
            self::ScanSubstringListsEachGroupOnce => '10.47',
            self::UnclosedBraceAtPatternEnd,
            self::ExtendedClassBesideLookbehindCompiles,
            self::TooLargeAtGroupEndReportedAtPatternEnd,
            self::PossessiveReferenceRepeatedInPlace => '10.48',
        };
    }
}
