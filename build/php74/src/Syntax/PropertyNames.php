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
     * The four-letter codes PCRE2 reads from 10.40 for the scripts PCRE2
     * 10.39 knows, each with the name 10.39 knows the script by, where the
     * code is not that name: PRIV(utt) of pcre2_ucptables.c gives each code
     * the script of its name. "Qaac" and "Qaai" are the private codes of
     * Coptic and Inherited.
     */
    private const SCRIPT_CODES = [
        'adlm' => 'Adlam', 'aghb' => 'Caucasian_Albanian', 'arab' => 'Arabic', 'armi' => 'Imperial_Aramaic',
        'armn' => 'Armenian', 'avst' => 'Avestan', 'bali' => 'Balinese', 'bamu' => 'Bamum',
        'bass' => 'Bassa_Vah', 'batk' => 'Batak', 'beng' => 'Bengali', 'bhks' => 'Bhaiksuki',
        'bopo' => 'Bopomofo', 'brah' => 'Brahmi', 'brai' => 'Braille', 'bugi' => 'Buginese',
        'buhd' => 'Buhid', 'cakm' => 'Chakma', 'cans' => 'Canadian_Aboriginal', 'cari' => 'Carian',
        'cher' => 'Cherokee', 'chrs' => 'Chorasmian', 'copt' => 'Coptic', 'cpmn' => 'Cypro_Minoan',
        'cprt' => 'Cypriot', 'cyrl' => 'Cyrillic', 'deva' => 'Devanagari', 'diak' => 'Dives_Akuru',
        'dogr' => 'Dogra', 'dsrt' => 'Deseret', 'dupl' => 'Duployan', 'egyp' => 'Egyptian_Hieroglyphs',
        'elba' => 'Elbasan', 'elym' => 'Elymaic', 'ethi' => 'Ethiopic', 'geor' => 'Georgian',
        'glag' => 'Glagolitic', 'gong' => 'Gunjala_Gondi', 'gonm' => 'Masaram_Gondi', 'goth' => 'Gothic',
        'gran' => 'Grantha', 'grek' => 'Greek', 'gujr' => 'Gujarati', 'guru' => 'Gurmukhi',
        'hang' => 'Hangul', 'hani' => 'Han', 'hano' => 'Hanunoo', 'hatr' => 'Hatran', 'hebr' => 'Hebrew',
        'hira' => 'Hiragana', 'hluw' => 'Anatolian_Hieroglyphs', 'hmng' => 'Pahawh_Hmong',
        'hmnp' => 'Nyiakeng_Puachue_Hmong', 'hung' => 'Old_Hungarian', 'ital' => 'Old_Italic',
        'java' => 'Javanese', 'kali' => 'Kayah_Li', 'kana' => 'Katakana', 'khar' => 'Kharoshthi',
        'khmr' => 'Khmer', 'khoj' => 'Khojki', 'kits' => 'Khitan_Small_Script', 'knda' => 'Kannada',
        'kthi' => 'Kaithi', 'lana' => 'Tai_Tham', 'laoo' => 'Lao', 'latn' => 'Latin', 'lepc' => 'Lepcha',
        'limb' => 'Limbu', 'lina' => 'Linear_A', 'linb' => 'Linear_B', 'lyci' => 'Lycian', 'lydi' => 'Lydian',
        'mahj' => 'Mahajani', 'maka' => 'Makasar', 'mand' => 'Mandaic', 'mani' => 'Manichaean',
        'marc' => 'Marchen', 'medf' => 'Medefaidrin', 'mend' => 'Mende_Kikakui', 'merc' => 'Meroitic_Cursive',
        'mero' => 'Meroitic_Hieroglyphs', 'mlym' => 'Malayalam', 'mong' => 'Mongolian', 'mroo' => 'Mro',
        'mtei' => 'Meetei_Mayek', 'mult' => 'Multani', 'mymr' => 'Myanmar',
        'nand' => 'Nandinagari', 'narb' => 'Old_North_Arabian', 'nbat' => 'Nabataean', 'nkoo' => 'Nko',
        'nshu' => 'Nushu', 'ogam' => 'Ogham', 'olck' => 'Ol_Chiki', 'orkh' => 'Old_Turkic', 'orya' => 'Oriya',
        'osge' => 'Osage', 'osma' => 'Osmanya', 'ougr' => 'Old_Uyghur', 'palm' => 'Palmyrene',
        'pauc' => 'Pau_Cin_Hau', 'perm' => 'Old_Permic', 'phag' => 'Phags_Pa',
        'phli' => 'Inscriptional_Pahlavi', 'phlp' => 'Psalter_Pahlavi', 'phnx' => 'Phoenician',
        'plrd' => 'Miao', 'prti' => 'Inscriptional_Parthian', 'qaac' => 'Coptic', 'qaai' => 'Inherited',
        'rjng' => 'Rejang', 'rohg' => 'Hanifi_Rohingya', 'runr' => 'Runic', 'samr' => 'Samaritan',
        'sarb' => 'Old_South_Arabian', 'saur' => 'Saurashtra', 'sgnw' => 'SignWriting', 'shaw' => 'Shavian',
        'shrd' => 'Sharada', 'sidd' => 'Siddham', 'sind' => 'Khudawadi', 'sinh' => 'Sinhala',
        'sogd' => 'Sogdian', 'sogo' => 'Old_Sogdian', 'sora' => 'Sora_Sompeng', 'soyo' => 'Soyombo',
        'sund' => 'Sundanese', 'sylo' => 'Syloti_Nagri', 'syrc' => 'Syriac', 'tagb' => 'Tagbanwa',
        'takr' => 'Takri', 'tale' => 'Tai_Le', 'talu' => 'New_Tai_Lue', 'taml' => 'Tamil', 'tang' => 'Tangut',
        'tavt' => 'Tai_Viet', 'telu' => 'Telugu', 'tfng' => 'Tifinagh', 'tglg' => 'Tagalog',
        'thaa' => 'Thaana', 'tibt' => 'Tibetan', 'tirh' => 'Tirhuta', 'tnsa' => 'Tangsa',
        'ugar' => 'Ugaritic', 'vaii' => 'Vai', 'vith' => 'Vithkuqi', 'wara' => 'Warang_Citi',
        'wcho' => 'Wancho', 'xpeo' => 'Old_Persian', 'xsux' => 'Cuneiform', 'yezi' => 'Yezidi',
        'yiii' => 'Yi', 'zanb' => 'Zanabazar_Square', 'zinh' => 'Inherited', 'zyyy' => 'Common',
        'zzzz' => 'Unknown',
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
        foreach (array_merge(self::CATEGORIES, self::SCRIPTS) as $known) {
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

        // The releases read, from the oldest.
        foreach (array_keys(PropertyNameTables::RELEASES) as $release) {
            if (self::knownTo(self::lookedUp($name, $braced, $release)[1], $release, $names)) {
                return $release;
            }
        }

        return null;
    }

    /**
     * How PCRE2 10.39 or older, the engine, reads what the property "\p"
     * names means to a release from 10.40, the target, from what follows
     * the letter: whether a "^" the target reads negates it, and the name
     * the engine knows it by, which a "\p" before 10.40 takes as written.
     * Null when the target does not know the name, or the engine has no
     * name for what it means: a bare script name, which is the script
     * extensions from 10.40, as "scx:" is, a Bidi class, a binary property,
     * a script the engine does not know.
     *
     * @return array{bool, string}|null
     */
    public static function readBefore1040(string $name, bool $braced, PcreTarget $target, PcreTarget $engine): ?array
    {
        $release = $target->judgedWith();
        [$negated, $read] = self::lookedUp($name, $braced, $release);
        if (!self::knownTo($read, $release, self::namesFrom1040())) {
            return null;
        }

        // A name the target knows: up to a ":" or "=", one of its tables.
        $separator = strcspn($read, ':=');
        if ($separator < \strlen($read)) {
            $known = \in_array(substr($read, 0, $separator), ['sc', 'script'], true) ? self::scriptBefore1040((string) substr($read, $separator + 1), $engine) : null;
        } else {
            $known = 'lc' === $read ? 'L&' : self::categoryBefore1040($read);
        }

        return null === $known ? null : [$negated, $known];
    }

    /**
     * The name the engine, before 10.40, knows a script by, from its name
     * or its code as 10.40 looks them up; null when it knows none.
     */
    private static function scriptBefore1040(string $value, PcreTarget $engine): ?string
    {
        $script = self::SCRIPT_CODES[$value] ?? null;
        foreach (null === $script ? self::SCRIPTS : [] as $known) {
            if (Ascii::lower(str_replace('_', '', $known)) === $value) {
                $script = $known;

                break;
            }
        }

        return null !== $script && self::knownBefore1040($script, $engine) ? $script : null;
    }

    /**
     * The general category or property of PCRE's own a name as 10.40 looks
     * it up is, as 10.39 spells it; null for any other name.
     */
    private static function categoryBefore1040(string $read): ?string
    {
        foreach (self::CATEGORIES as $known) {
            if (Ascii::lower($known) === $read) {
                return $known;
            }
        }

        return null;
    }

    /**
     * The name a release from 10.40 looks up, and whether a "^" it reads
     * negates it.
     *
     * @return array{bool, string}
     */
    private static function lookedUp(string $name, bool $braced, string $release): array
    {
        if (!$braced) {
            return [false, Ascii::lower($name)];
        }

        $caretAfterDropped = version_compare($release, PcreFeature::MalformedPropertyName()->release(), '>=');
        $negated = 0 === strncmp($name, '^', strlen('^'));
        $read = '';
        for ($at = $negated ? 1 : 0, $length = \strlen($name); $at < $length; ++$at) {
            $char = $name[$at];
            if (false !== strpos(self::LOOSE_IGNORED, $char)) {
                continue;
            }

            if ($caretAfterDropped && !$negated && '' === $read && '^' === $char) {
                $negated = true;

                continue;
            }

            $read .= $char;
        }

        return [$negated, Ascii::lower($read)];
    }

    /**
     * @param array<string, array{0: string, 1: bool}> $names
     */
    private static function knownTo(string $name, string $release, array $names): bool
    {
        $separator = strcspn($name, ':=');
        $scriptOnly = false;
        if ($separator < \strlen($name)) {
            $value = (string) substr($name, $separator + 1);
            $matchSubject339 = (string) substr($name, 0, $separator);
            switch (true) {
                case 'bc' === $matchSubject339:
                case 'bidiclass' === $matchSubject339:
                    [$name, $scriptOnly] = ['bidi'.$value, false];
                    break;
                case 'sc' === $matchSubject339:
                case 'script' === $matchSubject339:
                case 'scx' === $matchSubject339:
                case 'scriptextensions' === $matchSubject339:
                    [$name, $scriptOnly] = [$value, true];
                    break;
                default:
                    [$name, $scriptOnly] = [null, false];
                    break;
            }
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
