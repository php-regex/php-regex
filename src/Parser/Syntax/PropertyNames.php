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

namespace PHPRegex\Parser\Syntax;

use PHPRegex\Parser\Text\Ascii;

/**
 * The property names PCRE2 read before 10.40, which PHP 8.1 bundles (10.39):
 * one exact spelling each, looked up as written. 10.40 matches names
 * loosely, reads "sc:" and "scx:", the four-letter script codes, the Bidi
 * classes and the binary properties, and makes a bare script name mean its
 * script extensions. The table is pcre2_tables.c of PCRE2 10.39 (Unicode
 * 14); 10.37, which PHP 8.1.0 bundled, lacks five of its scripts and is
 * judged with it.
 *
 * @internal
 */
final class PropertyNames
{
    /**
     * PCRE2 10.39 reads at most this many characters of a braced name: past
     * them, without a "}", the "\p" is malformed.
     */
    public const MAX_NAME_LENGTH_BEFORE_10_40 = 31;

    /**
     * The general categories and PCRE's own properties.
     */
    private const CATEGORIES = [
        'Any', 'C', 'Cc', 'Cf', 'Cn', 'Co', 'Cs', 'L', 'L&', 'Ll', 'Lm', 'Lo', 'Lt', 'Lu', 'M', 'Mc', 'Me',
        'Mn', 'N', 'Nd', 'Nl', 'No', 'P', 'Pc', 'Pd', 'Pe', 'Pf', 'Pi', 'Po', 'Ps', 'S', 'Sc', 'Sk', 'Sm',
        'So', 'Xan', 'Xps', 'Xsp', 'Xuc', 'Xwd', 'Z', 'Zl', 'Zp', 'Zs',
    ];

    /**
     * The scripts.
     */
    private const SCRIPTS = [
        'Adlam', 'Ahom', 'Anatolian_Hieroglyphs', 'Arabic', 'Armenian', 'Avestan', 'Balinese', 'Bamum',
        'Bassa_Vah', 'Batak', 'Bengali', 'Bhaiksuki', 'Bopomofo', 'Brahmi', 'Braille', 'Buginese', 'Buhid',
        'Canadian_Aboriginal', 'Carian', 'Caucasian_Albanian', 'Chakma', 'Cham', 'Cherokee', 'Chorasmian',
        'Common', 'Coptic', 'Cuneiform', 'Cypriot', 'Cypro_Minoan', 'Cyrillic', 'Deseret', 'Devanagari',
        'Dives_Akuru', 'Dogra', 'Duployan', 'Egyptian_Hieroglyphs', 'Elbasan', 'Elymaic', 'Ethiopic',
        'Georgian', 'Glagolitic', 'Gothic', 'Grantha', 'Greek', 'Gujarati', 'Gunjala_Gondi', 'Gurmukhi',
        'Han', 'Hangul', 'Hanifi_Rohingya', 'Hanunoo', 'Hatran', 'Hebrew', 'Hiragana', 'Imperial_Aramaic',
        'Inherited', 'Inscriptional_Pahlavi', 'Inscriptional_Parthian', 'Javanese', 'Kaithi', 'Kannada',
        'Katakana', 'Kayah_Li', 'Kharoshthi', 'Khitan_Small_Script', 'Khmer', 'Khojki', 'Khudawadi', 'Lao',
        'Latin', 'Lepcha', 'Limbu', 'Linear_A', 'Linear_B', 'Lisu', 'Lycian', 'Lydian', 'Mahajani',
        'Makasar', 'Malayalam', 'Mandaic', 'Manichaean', 'Marchen', 'Masaram_Gondi', 'Medefaidrin',
        'Meetei_Mayek', 'Mende_Kikakui', 'Meroitic_Cursive', 'Meroitic_Hieroglyphs', 'Miao', 'Modi',
        'Mongolian', 'Mro', 'Multani', 'Myanmar', 'Nabataean', 'Nandinagari', 'New_Tai_Lue', 'Newa', 'Nko',
        'Nushu', 'Nyiakeng_Puachue_Hmong', 'Ogham', 'Ol_Chiki', 'Old_Hungarian', 'Old_Italic',
        'Old_North_Arabian', 'Old_Permic', 'Old_Persian', 'Old_Sogdian', 'Old_South_Arabian', 'Old_Turkic',
        'Old_Uyghur', 'Oriya', 'Osage', 'Osmanya', 'Pahawh_Hmong', 'Palmyrene', 'Pau_Cin_Hau', 'Phags_Pa',
        'Phoenician', 'Psalter_Pahlavi', 'Rejang', 'Runic', 'Samaritan', 'Saurashtra', 'Sharada', 'Shavian',
        'Siddham', 'SignWriting', 'Sinhala', 'Sogdian', 'Sora_Sompeng', 'Soyombo', 'Sundanese',
        'Syloti_Nagri', 'Syriac', 'Tagalog', 'Tagbanwa', 'Tai_Le', 'Tai_Tham', 'Tai_Viet', 'Takri', 'Tamil',
        'Tangsa', 'Tangut', 'Telugu', 'Thaana', 'Thai', 'Tibetan', 'Tifinagh', 'Tirhuta', 'Toto', 'Ugaritic',
        'Unknown', 'Vai', 'Vithkuqi', 'Wancho', 'Warang_Citi', 'Yezidi', 'Yi', 'Zanabazar_Square',
    ];

    /**
     * Whether PCRE2 10.39 knows the name, spelled as written.
     */
    public static function knownBefore1040(string $name): bool
    {
        return \in_array($name, self::CATEGORIES, true) || \in_array($name, self::SCRIPTS, true);
    }

    /**
     * Whether the name, spelled as PCRE2 10.39 reads it, is a script.
     */
    public static function isScriptBefore1040(string $name): bool
    {
        return \in_array($name, self::SCRIPTS, true);
    }

    /**
     * The spelling PCRE2 10.39 knows for a name written as 10.40 reads it,
     * case, spaces, hyphens and underscores aside; null when none matches.
     */
    public static function spellingBefore1040(string $name): ?string
    {
        $loose = static fn (string $text): string => Ascii::lower(str_replace([' ', "\t", '-', '_'], '', $text));
        foreach ([...self::CATEGORIES, ...self::SCRIPTS] as $known) {
            if ($loose($known) === $loose($name)) {
                return $known;
            }
        }

        return null;
    }
}
