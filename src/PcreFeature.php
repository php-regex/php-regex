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

namespace RegexParser;

/**
 * A behaviour PCRE2 changed, by the release it arrived in. A rule asks its
 * target whether it has the behaviour (PcreTarget::supports()), so what
 * changed when reads as one table.
 */
enum PcreFeature
{
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
     * "\k" in a class is the letter k, not an invalid escape.
     */
    case ClassBackslashKIsLetter;

    /**
     * "\x" with no hexadecimal digit is an error, not a NUL.
     */
    case HexEscapeNeedsDigits;

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
     * A braced escape left open at the end of the pattern is reported at the end, not past it.
     */
    case UnclosedBraceAtPatternEnd;

    /**
     * The PCRE2 release the behaviour arrived in, as "10.45".
     */
    public function release(): string
    {
        return match ($this) {
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
            self::ClassBackslashKIsLetter,
            self::HexEscapeNeedsDigits => '10.45',
            self::VersionConditionWholeNumbers,
            self::CallsReturnCaptureGroups,
            self::GReferenceNumberReadBeforeClosing,
            self::NamedCodePointReadBeforeModeCheck,
            self::CalloutConditionErrorAtItemStart,
            self::ErrorOffsetPastTheFault => '10.47',
            self::UnclosedBraceAtPatternEnd => '10.48',
        };
    }
}
