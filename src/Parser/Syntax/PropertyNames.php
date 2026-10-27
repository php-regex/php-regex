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

use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Text\Ascii;

/**
 * The property names each PCRE2 release knows.
 *
 * Before 10.40, which PHP 8.1 bundles (10.39): one exact spelling each,
 * looked up as written. The table is pcre2_tables.c of PCRE2 10.39 (Unicode
 * 14); an older release lacks the scripts a later Unicode added, which
 * SCRIPTS_SINCE names with the release that added each.
 *
 * From 10.40, which matches names loosely, reads "bc:", "sc:" and "scx:",
 * the four-letter script codes, the Bidi classes and the binary properties,
 * and makes a bare script name mean its script extensions: the names of
 * each release are PropertyNameTables, read from its sources, so that a
 * release is judged by its own names whatever the engine that runs.
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
     * The scripts of the table an older release does not know, by the
     * release that added them (pcre2_tables.c of 10.32 to 10.39): 10.33
     * added "Unknown", 10.34 Unicode 12.1, 10.35 Unicode 13, 10.39 Unicode
     * 14; 10.36 to 10.38 added none.
     */
    private const SCRIPTS_SINCE = [
        'Unknown' => '10.33',
        'Elymaic' => '10.34', 'Nandinagari' => '10.34', 'Nyiakeng_Puachue_Hmong' => '10.34', 'Wancho' => '10.34',
        'Chorasmian' => '10.35', 'Dives_Akuru' => '10.35', 'Khitan_Small_Script' => '10.35', 'Yezidi' => '10.35',
        'Cypro_Minoan' => '10.39', 'Old_Uyghur' => '10.39', 'Tangsa' => '10.39', 'Toto' => '10.39', 'Vithkuqi' => '10.39',
    ];

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
     * What PCRE2 drops from a name from 10.40: spaces, "\t" to "\r", hyphens
     * and underscores.
     */
    private const LOOSE_IGNORED = " \t\n\v\f\r-_";

    /**
     * The names of PropertyNameTables, each with the release that added it
     * and whether it is a script.
     *
     * @var array<string, array{0: string, 1: bool}>|null
     */
    private static ?array $namesFrom1040 = null;

    /**
     * Whether the PCRE2 judged, 10.39 or older, knows the name, spelled as
     * written.
     */
    public static function knownBefore1040(string $name, PcreTarget $target): bool
    {
        if (\in_array($name, self::CATEGORIES, true)) {
            return true;
        }

        $since = self::SCRIPTS_SINCE[$name] ?? null;

        return \in_array($name, self::SCRIPTS, true) && (null === $since || $target->pcreAtLeast($since));
    }

    /**
     * The release that added the script, spelled as PCRE2 10.39 reads it,
     * for one an older release does not know; null for any other name.
     */
    public static function releaseAddingBefore1040(string $name): ?string
    {
        return self::SCRIPTS_SINCE[$name] ?? null;
    }

    /**
     * Whether the name, spelled as PCRE2 10.39 reads it, is a script.
     */
    public static function isScriptBefore1040(string $name): bool
    {
        return \in_array($name, self::SCRIPTS, true);
    }

    /**
     * The spelling the PCRE2 judged, 10.39 or older, knows for a name
     * written as 10.40 reads it, case, spaces, hyphens and underscores
     * aside; null when none matches.
     */
    public static function spellingBefore1040(string $name, PcreTarget $target): ?string
    {
        $loose = static fn (string $text): string => Ascii::lower(str_replace([' ', "\t", '-', '_'], '', $text));
        foreach ([...self::CATEGORIES, ...self::SCRIPTS] as $known) {
            if ($loose($known) === $loose($name) && self::knownBefore1040($known, $target)) {
                return $known;
            }
        }

        return null;
    }

    /**
     * The first release from PCRE2 10.40 that knows the property "\p" or
     * "\P" names, from what follows the letter: one letter, or the text
     * between the braces. Null when none does.
     *
     * The name is read as each release reads it: a "^" right after the
     * brace negates, and from 10.45 one after the characters dropped too;
     * spaces, "\t" to "\r", hyphens and underscores are dropped and the
     * letters lowered. Up to the first ":" or "=", "bc", "bidiclass", "sc",
     * "script", "scx" and "scriptextensions" name the table the rest is
     * looked up in, and "sc" and its kin take a script only; any other
     * table is unknown.
     */
    public static function firstReleaseKnowing(string $name, bool $braced): ?string
    {
        $names = self::namesFrom1040();
        $releases = array_unique([...array_column($names, 0), PcreFeature::MalformedPropertyName->release()]);
        usort($releases, static fn (string $a, string $b): int => version_compare($a, $b));

        foreach ($releases as $release) {
            if (self::knownTo(self::lookedUp($name, $braced, $release), $release, $names)) {
                return $release;
            }
        }

        return null;
    }

    /**
     * The name a release from 10.40 looks up.
     */
    private static function lookedUp(string $name, bool $braced, string $release): string
    {
        if (!$braced) {
            return Ascii::lower($name);
        }

        $caretAfterDropped = version_compare($release, PcreFeature::MalformedPropertyName->release(), '>=');
        $negated = str_starts_with($name, '^');
        $read = '';
        for ($at = $negated ? 1 : 0, $length = \strlen($name); $at < $length; $at++) {
            $char = $name[$at];
            if (str_contains(self::LOOSE_IGNORED, $char)) {
                continue;
            }

            if ($caretAfterDropped && !$negated && '' === $read && '^' === $char) {
                $negated = true;

                continue;
            }

            $read .= $char;
        }

        return Ascii::lower($read);
    }

    /**
     * @param array<string, array{0: string, 1: bool}> $names
     */
    private static function knownTo(string $name, string $release, array $names): bool
    {
        $separator = strcspn($name, ':=');
        $scriptOnly = false;
        if ($separator < \strlen($name)) {
            $value = substr($name, $separator + 1);
            [$name, $scriptOnly] = match (substr($name, 0, $separator)) {
                'bc', 'bidiclass' => ['bidi'.$value, false],
                'sc', 'script', 'scx', 'scriptextensions' => [$value, true],
                default => [null, false],
            };
        }

        $entry = null === $name ? null : ($names[$name] ?? null);

        return null !== $entry && version_compare($entry[0], $release, '<=') && (!$scriptOnly || $entry[1]);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    private static function namesFrom1040(): array
    {
        if (null !== self::$namesFrom1040) {
            return self::$namesFrom1040;
        }

        $names = [];
        foreach ([[PropertyNameTables::SCRIPTS, true], [PropertyNameTables::PROPERTIES, false]] as [$lists, $isScript]) {
            foreach ($lists as $release => $list) {
                foreach (explode(' ', $list) as $name) {
                    $names[$name] = [(string) $release, $isScript];
                }
            }
        }

        return self::$namesFrom1040 = $names;
    }
}
