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

namespace PHPRegex\Transpiler\Target\JavaScript;

use PHPRegex\Parser\Text\Ascii;

/**
 * The general categories and binary properties JavaScript names, keyed by
 * the loose form PCRE2 compares them in: PCRE2 reads "\p{ lu }" and
 * "\p{white space}", JavaScript only "\p{Lu}" and "\p{White_Space}". PCRE
 * spells the cased letters "L&", JavaScript "LC".
 *
 * @internal
 */
final class JavaScriptProperties
{
    private const CATEGORIES = [
        'C', 'Cc', 'Cf', 'Cn', 'Co', 'Cs', 'L', 'LC', 'Ll', 'Lm', 'Lo', 'Lt', 'Lu', 'M', 'Mc', 'Me', 'Mn',
        'N', 'Nd', 'Nl', 'No', 'P', 'Pc', 'Pd', 'Pe', 'Pf', 'Pi', 'Po', 'Ps', 'S', 'Sc', 'Sk', 'Sm', 'So',
        'Z', 'Zl', 'Zp', 'Zs',
    ];

    /**
     * Each binary property ECMAScript lists, with its short alias.
     */
    private const BINARY = [
        'ASCII' => 'ASCII', 'ASCII_Hex_Digit' => 'AHex', 'Alphabetic' => 'Alpha', 'Any' => 'Any', 'Assigned' => 'Assigned',
        'Bidi_Control' => 'Bidi_C', 'Bidi_Mirrored' => 'Bidi_M', 'Case_Ignorable' => 'CI', 'Cased' => 'Cased',
        'Changes_When_Casefolded' => 'CWCF', 'Changes_When_Casemapped' => 'CWCM', 'Changes_When_Lowercased' => 'CWL',
        'Changes_When_NFKC_Casefolded' => 'CWKCF', 'Changes_When_Titlecased' => 'CWT', 'Changes_When_Uppercased' => 'CWU',
        'Dash' => 'Dash', 'Default_Ignorable_Code_Point' => 'DI', 'Deprecated' => 'Dep', 'Diacritic' => 'Dia',
        'Emoji' => 'Emoji', 'Emoji_Component' => 'EComp', 'Emoji_Modifier' => 'EMod', 'Emoji_Modifier_Base' => 'EBase',
        'Emoji_Presentation' => 'EPres', 'Extended_Pictographic' => 'ExtPict', 'Extender' => 'Ext',
        'Grapheme_Base' => 'Gr_Base', 'Grapheme_Extend' => 'Gr_Ext', 'Hex_Digit' => 'Hex', 'IDS_Binary_Operator' => 'IDSB',
        'IDS_Trinary_Operator' => 'IDST', 'ID_Continue' => 'IDC', 'ID_Start' => 'IDS', 'Ideographic' => 'Ideo',
        'Join_Control' => 'Join_C', 'Logical_Order_Exception' => 'LOE', 'Lowercase' => 'Lower', 'Math' => 'Math',
        'Noncharacter_Code_Point' => 'NChar', 'Pattern_Syntax' => 'Pat_Syn', 'Pattern_White_Space' => 'Pat_WS',
        'Quotation_Mark' => 'QMark', 'Radical' => 'Radical', 'Regional_Indicator' => 'RI', 'Sentence_Terminal' => 'STerm',
        'Soft_Dotted' => 'SD', 'Terminal_Punctuation' => 'Term', 'Unified_Ideograph' => 'UIdeo', 'Uppercase' => 'Upper',
        'Variation_Selector' => 'VS', 'White_Space' => 'space', 'XID_Continue' => 'XIDC', 'XID_Start' => 'XIDS',
    ];

    /**
     * The long names of the general categories, and their other aliases.
     */
    private const CATEGORY_NAMES = [
        'Cased_Letter', 'Close_Punctuation', 'Combining_Mark', 'Connector_Punctuation', 'Control', 'Currency_Symbol',
        'Dash_Punctuation', 'Decimal_Number', 'Enclosing_Mark', 'Final_Punctuation', 'Format', 'Initial_Punctuation',
        'Letter', 'Letter_Number', 'Line_Separator', 'Lowercase_Letter', 'Mark', 'Math_Symbol', 'Modifier_Letter',
        'Modifier_Symbol', 'Nonspacing_Mark', 'Number', 'Open_Punctuation', 'Other', 'Other_Letter', 'Other_Number',
        'Other_Punctuation', 'Other_Symbol', 'Paragraph_Separator', 'Private_Use', 'Punctuation', 'Separator',
        'Space_Separator', 'Spacing_Mark', 'Surrogate', 'Symbol', 'Titlecase_Letter', 'Unassigned', 'Uppercase_Letter',
        'cntrl', 'digit', 'punct',
    ];

    /**
     * The properties of strings the v flag adds, which only "\p" takes.
     */
    private const STRING_PROPERTIES = [
        'Basic_Emoji', 'Emoji_Keycap_Sequence', 'RGI_Emoji', 'RGI_Emoji_Flag_Sequence', 'RGI_Emoji_Modifier_Sequence',
        'RGI_Emoji_Tag_Sequence', 'RGI_Emoji_ZWJ_Sequence',
    ];

    /**
     * Whether JavaScript under v reads "\p{name}", or "\P{name}" when
     * negated, as written: a general category, a binary property or, for
     * "\p" alone, a property of strings, by its exact name or alias; or
     * "gc=", "sc=", "scx=" or their long names with a value of their kind.
     */
    public static function readsAsWritten(string $name, bool $negated): bool
    {
        $separator = strcspn($name, '=');
        if ($separator === \strlen($name)) {
            return self::isCategory($name) || isset(self::BINARY[$name]) || \in_array($name, self::BINARY, true) || (!$negated && \in_array($name, self::STRING_PROPERTIES, true));
        }

        $value = substr($name, $separator + 1);

        return match (substr($name, 0, $separator)) {
            'General_Category', 'gc' => self::isCategory($value),
            'Script', 'sc', 'Script_Extensions', 'scx' => ScriptNames::isWritten($value),
            default => false,
        };
    }

    /**
     * The JavaScript name of a general category or a binary property, or
     * null when the name is neither.
     */
    public static function javaScriptName(string $name): ?string
    {
        $key = ScriptNames::looseKey($name);
        if ('l&' === $key) {
            return 'LC';
        }

        // White_Space has a second short name JavaScript does not take.
        if ('wspace' === $key) {
            return 'White_Space';
        }

        foreach (self::CATEGORIES as $category) {
            if (Ascii::lower($category) === $key) {
                return $category;
            }
        }

        foreach (self::BINARY as $property => $alias) {
            if (ScriptNames::looseKey($property) === $key || ScriptNames::looseKey($alias) === $key) {
                return $property;
            }
        }

        return null;
    }

    private static function isCategory(string $name): bool
    {
        return \in_array($name, self::CATEGORIES, true) || \in_array($name, self::CATEGORY_NAMES, true);
    }
}
