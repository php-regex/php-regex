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

class PcreFeature
{
    public const AlphabeticAssertionNames = 'alphabeticassertionnames';
    public const ScriptRuns = 'scriptruns';
    public const NonAsciiGroupNames = 'nonasciigroupnames';
    public const BadEscapeInClassStaysRefused = 'badescapeinclassstaysrefused';
    public const NamesReadWhole = 'namesreadwhole';
    public const AnyPropertyMatchesAnyCharacter = 'anypropertymatchesanycharacter';
    public const QuantifiedAccept = 'quantifiedaccept';
    public const PossessiveRepeatOnceIsAtomic = 'possessiverepeatonceisatomic';
    public const NonAtomicAssertionNames = 'nonatomicassertionnames';
    public const LookbehindInLookaheadMeasured = 'lookbehindinlookaheadmeasured';
    public const LookbehindLengthOverflowRefused = 'lookbehindlengthoverflowrefused';
    public const LongestLookbehindCountsItsBranchesOnly = 'longestlookbehindcountsitsbranchesonly';
    public const CasePairClassIsACaselessCharacter = 'casepairclassisacaselesscharacter';
    public const NonAtomicAssertionShortSyntax = 'nonatomicassertionshortsyntax';
    public const GroupHoldingItsOwnReferenceBacktracks = 'groupholdingitsownreferencebacktracks';
    public const RepeatedAssertionKeepsItsCount = 'repeatedassertionkeepsitscount';
    public const UcpFoldsPastAsciiWithoutUtf = 'ucpfoldspastasciiwithoututf';
    public const DefineSkippedInLookbehind = 'defineskippedinlookbehind';
    public const LargeOpenCountIsText = 'largeopencountistext';
    public const OverflowingBackslashNumberIsOctal = 'overflowingbackslashnumberisoctal';
    public const LoosePropertyNames = 'loosepropertynames';
    public const OpenAndPaddedRepeatCounts = 'openandpaddedrepeatcounts';
    public const PaddedBracedEscapes = 'paddedbracedescapes';
    public const VariableLengthLookbehind = 'variablelengthlookbehind';
    public const CaselessRestrictModifier = 'caselessrestrictmodifier';
    public const AsciiOptions = 'asciioptions';
    public const LongGroupNames = 'longgroupnames';
    public const ScanSubstring = 'scansubstring';
    public const ExtendedCharClass = 'extendedcharclass';
    public const CasingSettingVerbs = 'casingsettingverbs';
    public const HugeBackreferenceNumberIsReference = 'hugebackreferencenumberisreference';
    public const NumberTooBigPastWholeNumber = 'numbertoobigpastwholenumber';
    public const LimitValueErrorOnFaultingCharacter = 'limitvalueerroronfaultingcharacter';
    public const PosixItemErrorPastItsEnd = 'posixitemerrorpastitsend';
    public const MalformedPropertyName = 'malformedpropertyname';
    public const RangeFromTypeReadToItsEnd = 'rangefromtypereadtoitsend';
    public const EmptyQuoteSkippedAfterClassEscape = 'emptyquoteskippedafterclassescape';
    public const SpacedHyphenAfterClassEscapeMakesRange = 'spacedhyphenafterclassescapemakesrange';
    public const ClassBackslashKIsLetter = 'classbackslashkisletter';
    public const HexEscapeNeedsDigits = 'hexescapeneedsdigits';
    public const EmptyQuoteOpeningClassIsUnclosed = 'emptyquoteopeningclassisunclosed';
    public const ClassEscapeEndingRangeRefusedAsEscape = 'classescapeendingrangerefusedasescape';
    public const NamedCharacterBraceRefusedInClass = 'namedcharacterbracerefusedinclass';
    public const ClassesCompiledFromMergedRanges = 'classescompiledfrommergedranges';
    public const CaselessReferenceHoldsItsCaseRules = 'caselessreferenceholdsitscaserules';
    public const VersionConditionWholeNumbers = 'versionconditionwholenumbers';
    public const CallsReturnCaptureGroups = 'callsreturncapturegroups';
    public const GroupListErrors = 'grouplisterrors';
    public const GReferenceNumberReadBeforeClosing = 'greferencenumberreadbeforeclosing';
    public const NamedCodePointReadBeforeModeCheck = 'namedcodepointreadbeforemodecheck';
    public const CalloutConditionErrorAtItemStart = 'calloutconditionerroratitemstart';
    public const ErrorOffsetPastTheFault = 'erroroffsetpastthefault';
    public const AlphaNameAtPatternEndIsUnclosed = 'alphanameatpatternendisunclosed';
    public const VersionConditionLeftOpenIsVersionError = 'versionconditionleftopenisversionerror';
    public const BranchCountErrorOffTheConditionName = 'branchcounterrorofftheconditionname';
    public const SurrogateRefusedBeforeBrace = 'surrogaterefusedbeforebrace';
    public const TooLargeAtPatternStart = 'toolargeatpatternstart';
    public const ScanSubstringListsEachGroupOnce = 'scansubstringlistseachgrouponce';
    public const UnclosedBraceAtPatternEnd = 'unclosedbraceatpatternend';
    public const ExtendedClassBesideLookbehindCompiles = 'extendedclassbesidelookbehindcompiles';
    public const TooLargeAtGroupEndReportedAtPatternEnd = 'toolargeatgroupendreportedatpatternend';
    public const PossessiveReferenceRepeatedInPlace = 'possessivereferencerepeatedinplace';
    /**
     * The PCRE2 release the behaviour arrived in, as "10.45".
     */
public static function release($feature)
    {
        $releases = array (
  'alphabeticassertionnames' => '10.33',
  'scriptruns' => '10.33',
  'nonasciigroupnames' => '10.33',
  'badescapeinclassstaysrefused' => '10.33',
  'namesreadwhole' => '10.33',
  'anypropertymatchesanycharacter' => '10.33',
  'quantifiedaccept' => '10.34',
  'possessiverepeatonceisatomic' => '10.34',
  'nonatomicassertionnames' => '10.34',
  'lookbehindinlookaheadmeasured' => '10.34',
  'lookbehindlengthoverflowrefused' => '10.34',
  'longestlookbehindcountsitsbranchesonly' => '10.34',
  'casepairclassisacaselesscharacter' => '10.34',
  'nonatomicassertionshortsyntax' => '10.35',
  'groupholdingitsownreferencebacktracks' => '10.35',
  'repeatedassertionkeepsitscount' => '10.35',
  'ucpfoldspastasciiwithoututf' => '10.35',
  'defineskippedinlookbehind' => '10.35',
  'largeopencountistext' => '10.37',
  'overflowingbackslashnumberisoctal' => '10.37',
  'loosepropertynames' => '10.40',
  'openandpaddedrepeatcounts' => '10.43',
  'paddedbracedescapes' => '10.43',
  'variablelengthlookbehind' => '10.43',
  'caselessrestrictmodifier' => '10.43',
  'asciioptions' => '10.43',
  'longgroupnames' => '10.44',
  'scansubstring' => '10.45',
  'extendedcharclass' => '10.45',
  'casingsettingverbs' => '10.45',
  'hugebackreferencenumberisreference' => '10.45',
  'numbertoobigpastwholenumber' => '10.45',
  'limitvalueerroronfaultingcharacter' => '10.45',
  'posixitemerrorpastitsend' => '10.45',
  'malformedpropertyname' => '10.45',
  'rangefromtypereadtoitsend' => '10.45',
  'emptyquoteskippedafterclassescape' => '10.45',
  'spacedhyphenafterclassescapemakesrange' => '10.45',
  'classbackslashkisletter' => '10.45',
  'hexescapeneedsdigits' => '10.45',
  'emptyquoteopeningclassisunclosed' => '10.45',
  'classescapeendingrangerefusedasescape' => '10.45',
  'namedcharacterbracerefusedinclass' => '10.45',
  'classescompiledfrommergedranges' => '10.45',
  'caselessreferenceholdsitscaserules' => '10.45',
  'versionconditionwholenumbers' => '10.47',
  'callsreturncapturegroups' => '10.47',
  'grouplisterrors' => '10.47',
  'greferencenumberreadbeforeclosing' => '10.47',
  'namedcodepointreadbeforemodecheck' => '10.47',
  'calloutconditionerroratitemstart' => '10.47',
  'erroroffsetpastthefault' => '10.47',
  'alphanameatpatternendisunclosed' => '10.47',
  'versionconditionleftopenisversionerror' => '10.47',
  'branchcounterrorofftheconditionname' => '10.47',
  'surrogaterefusedbeforebrace' => '10.47',
  'toolargeatpatternstart' => '10.47',
  'scansubstringlistseachgrouponce' => '10.47',
  'unclosedbraceatpatternend' => '10.48',
  'extendedclassbesidelookbehindcompiles' => '10.48',
  'toolargeatgroupendreportedatpatternend' => '10.48',
  'possessivereferencerepeatedinplace' => '10.48',
);

        return $releases[$feature] ?? "0.0";
    }

    public static function from($value)
    {
        if (!\in_array($value, self::cases(), true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown value "%s" for %s.', $value, self::class));
        }

        return $value;
    }

    public static function tryFrom($value)
    {
        return \in_array($value, self::cases(), true) ? $value : null;
    }

    public static function cases()
    {
        return \array_values((new \ReflectionClass(self::class))->getConstants());
    }
}
