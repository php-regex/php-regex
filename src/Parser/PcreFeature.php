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
     * "(?*" and "(?<*" spell the non-atomic lookarounds.
     */
    case NonAtomicAssertionShortSyntax;

    /**
     * A count past 65535 in a brace that opens no quantifier, as "a{65536", is text instead of a count too big.
     */
    case LargeOpenCountIsText;

    /**
     * A backslash and digits past 214748363, as "\214748364", is an octal escape and text instead of a number too big.
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
     * "\N" ending a range in a class is refused as "\N", not as an invalid range.
     */
    case ClassNEndingRangeRefusedAsN;

    /**
     * The minor of "(?(VERSION>=10.xx)" is read whole, not as two digits.
     */
    case VersionConditionWholeNumbers;

    /**
     * A call may return capture groups, as "(?1(2,<name>))".
     */
    case CallsReturnCaptureGroups;

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
     * A braced escape left open at the end of the pattern is reported at the end, not past it.
     */
    case UnclosedBraceAtPatternEnd;

    /**
     * The PCRE2 release the behaviour arrived in, as "10.45".
     */
    public function release(): string
    {
        return match ($this) {
            self::AlphabeticAssertionNames,
            self::ScriptRuns,
            self::NonAsciiGroupNames => '10.33',
            self::QuantifiedAccept,
            self::PossessiveRepeatOnceIsAtomic,
            self::NonAtomicAssertionNames => '10.34',
            self::NonAtomicAssertionShortSyntax => '10.35',
            self::LargeOpenCountIsText,
            self::OverflowingBackslashNumberIsOctal => '10.37',
            self::LoosePropertyNames => '10.40',
            self::OpenAndPaddedRepeatCounts,
            self::PaddedBracedEscapes,
            self::VariableLengthLookbehind,
            self::CaselessRestrictModifier,
            self::AsciiOptions => '10.43',
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
            self::ClassNEndingRangeRefusedAsN => '10.45',
            self::VersionConditionWholeNumbers,
            self::CallsReturnCaptureGroups,
            self::GReferenceNumberReadBeforeClosing,
            self::NamedCodePointReadBeforeModeCheck,
            self::CalloutConditionErrorAtItemStart,
            self::ErrorOffsetPastTheFault,
            self::AlphaNameAtPatternEndIsUnclosed,
            self::VersionConditionLeftOpenIsVersionError,
            self::BranchCountErrorOffTheConditionName => '10.47',
            self::UnclosedBraceAtPatternEnd => '10.48',
        };
    }
}
