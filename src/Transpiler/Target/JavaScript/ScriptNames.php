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

/**
 * The Unicode scripts by the names PCRE2 reads, loosely: case, spaces,
 * hyphens and underscores aside, a long name or a four-letter one. Each
 * gives the name JavaScript takes in "\p{Script=…}": the scripts both
 * PCRE2 10.49 and V8 under Unicode 17 know.
 *
 * @internal
 */
final class ScriptNames
{
    private const NAMES = [
        'adlam' => 'Adlam', 'adlm' => 'Adlam', 'aghb' => 'Caucasian_Albanian', 'ahom' => 'Ahom',
        'anatolianhieroglyphs' => 'Anatolian_Hieroglyphs', 'arab' => 'Arabic', 'arabic' => 'Arabic',
        'armenian' => 'Armenian', 'armi' => 'Imperial_Aramaic', 'armn' => 'Armenian', 'avestan' => 'Avestan',
        'avst' => 'Avestan', 'bali' => 'Balinese', 'balinese' => 'Balinese', 'bamu' => 'Bamum', 'bamum' => 'Bamum',
        'bass' => 'Bassa_Vah', 'bassavah' => 'Bassa_Vah', 'batak' => 'Batak', 'batk' => 'Batak', 'beng' => 'Bengali',
        'bengali' => 'Bengali', 'berf' => 'Beria_Erfe', 'beriaerfe' => 'Beria_Erfe', 'bhaiksuki' => 'Bhaiksuki',
        'bhks' => 'Bhaiksuki', 'bopo' => 'Bopomofo', 'bopomofo' => 'Bopomofo', 'brah' => 'Brahmi',
        'brahmi' => 'Brahmi', 'brai' => 'Braille', 'braille' => 'Braille', 'bugi' => 'Buginese',
        'buginese' => 'Buginese', 'buhd' => 'Buhid', 'buhid' => 'Buhid', 'cakm' => 'Chakma',
        'canadianaboriginal' => 'Canadian_Aboriginal', 'cans' => 'Canadian_Aboriginal', 'cari' => 'Carian',
        'carian' => 'Carian', 'caucasianalbanian' => 'Caucasian_Albanian', 'chakma' => 'Chakma', 'cham' => 'Cham',
        'cher' => 'Cherokee', 'cherokee' => 'Cherokee', 'chorasmian' => 'Chorasmian', 'chrs' => 'Chorasmian',
        'common' => 'Common', 'copt' => 'Coptic', 'coptic' => 'Coptic', 'cpmn' => 'Cypro_Minoan',
        'cprt' => 'Cypriot', 'cuneiform' => 'Cuneiform', 'cypriot' => 'Cypriot', 'cyprominoan' => 'Cypro_Minoan',
        'cyrillic' => 'Cyrillic', 'cyrl' => 'Cyrillic', 'deseret' => 'Deseret', 'deva' => 'Devanagari',
        'devanagari' => 'Devanagari', 'diak' => 'Dives_Akuru', 'divesakuru' => 'Dives_Akuru', 'dogr' => 'Dogra',
        'dogra' => 'Dogra', 'dsrt' => 'Deseret', 'dupl' => 'Duployan', 'duployan' => 'Duployan',
        'egyp' => 'Egyptian_Hieroglyphs', 'egyptianhieroglyphs' => 'Egyptian_Hieroglyphs', 'elba' => 'Elbasan',
        'elbasan' => 'Elbasan', 'elym' => 'Elymaic', 'elymaic' => 'Elymaic', 'ethi' => 'Ethiopic',
        'ethiopic' => 'Ethiopic', 'gara' => 'Garay', 'garay' => 'Garay', 'geor' => 'Georgian',
        'georgian' => 'Georgian', 'glag' => 'Glagolitic', 'glagolitic' => 'Glagolitic', 'gong' => 'Gunjala_Gondi',
        'gonm' => 'Masaram_Gondi', 'goth' => 'Gothic', 'gothic' => 'Gothic', 'gran' => 'Grantha',
        'grantha' => 'Grantha', 'greek' => 'Greek', 'grek' => 'Greek', 'gujarati' => 'Gujarati',
        'gujr' => 'Gujarati', 'gukh' => 'Gurung_Khema', 'gunjalagondi' => 'Gunjala_Gondi', 'gurmukhi' => 'Gurmukhi',
        'guru' => 'Gurmukhi', 'gurungkhema' => 'Gurung_Khema', 'han' => 'Han', 'hang' => 'Hangul',
        'hangul' => 'Hangul', 'hani' => 'Han', 'hanifirohingya' => 'Hanifi_Rohingya', 'hano' => 'Hanunoo',
        'hanunoo' => 'Hanunoo', 'hatr' => 'Hatran', 'hatran' => 'Hatran', 'hebr' => 'Hebrew', 'hebrew' => 'Hebrew',
        'hira' => 'Hiragana', 'hiragana' => 'Hiragana', 'hluw' => 'Anatolian_Hieroglyphs', 'hmng' => 'Pahawh_Hmong',
        'hmnp' => 'Nyiakeng_Puachue_Hmong', 'hung' => 'Old_Hungarian', 'imperialaramaic' => 'Imperial_Aramaic',
        'inherited' => 'Inherited', 'inscriptionalpahlavi' => 'Inscriptional_Pahlavi',
        'inscriptionalparthian' => 'Inscriptional_Parthian', 'ital' => 'Old_Italic', 'java' => 'Javanese',
        'javanese' => 'Javanese', 'kaithi' => 'Kaithi', 'kali' => 'Kayah_Li', 'kana' => 'Katakana',
        'kannada' => 'Kannada', 'katakana' => 'Katakana', 'kawi' => 'Kawi', 'kayahli' => 'Kayah_Li',
        'khar' => 'Kharoshthi', 'kharoshthi' => 'Kharoshthi', 'khitansmallscript' => 'Khitan_Small_Script',
        'khmer' => 'Khmer', 'khmr' => 'Khmer', 'khoj' => 'Khojki', 'khojki' => 'Khojki', 'khudawadi' => 'Khudawadi',
        'kiratrai' => 'Kirat_Rai', 'kits' => 'Khitan_Small_Script', 'knda' => 'Kannada', 'krai' => 'Kirat_Rai',
        'kthi' => 'Kaithi', 'lana' => 'Tai_Tham', 'lao' => 'Lao', 'laoo' => 'Lao', 'latin' => 'Latin',
        'latn' => 'Latin', 'lepc' => 'Lepcha', 'lepcha' => 'Lepcha', 'limb' => 'Limbu', 'limbu' => 'Limbu',
        'lina' => 'Linear_A', 'linb' => 'Linear_B', 'lineara' => 'Linear_A', 'linearb' => 'Linear_B',
        'lisu' => 'Lisu', 'lyci' => 'Lycian', 'lycian' => 'Lycian', 'lydi' => 'Lydian', 'lydian' => 'Lydian',
        'mahajani' => 'Mahajani', 'mahj' => 'Mahajani', 'maka' => 'Makasar', 'makasar' => 'Makasar',
        'malayalam' => 'Malayalam', 'mand' => 'Mandaic', 'mandaic' => 'Mandaic', 'mani' => 'Manichaean',
        'manichaean' => 'Manichaean', 'marc' => 'Marchen', 'marchen' => 'Marchen', 'masaramgondi' => 'Masaram_Gondi',
        'medefaidrin' => 'Medefaidrin', 'medf' => 'Medefaidrin', 'meeteimayek' => 'Meetei_Mayek',
        'mend' => 'Mende_Kikakui', 'mendekikakui' => 'Mende_Kikakui', 'merc' => 'Meroitic_Cursive',
        'mero' => 'Meroitic_Hieroglyphs', 'meroiticcursive' => 'Meroitic_Cursive',
        'meroitichieroglyphs' => 'Meroitic_Hieroglyphs', 'miao' => 'Miao', 'mlym' => 'Malayalam', 'modi' => 'Modi',
        'mong' => 'Mongolian', 'mongolian' => 'Mongolian', 'mro' => 'Mro', 'mroo' => 'Mro', 'mtei' => 'Meetei_Mayek',
        'mult' => 'Multani', 'multani' => 'Multani', 'myanmar' => 'Myanmar', 'mymr' => 'Myanmar',
        'nabataean' => 'Nabataean', 'nagm' => 'Nag_Mundari', 'nagmundari' => 'Nag_Mundari', 'nand' => 'Nandinagari',
        'nandinagari' => 'Nandinagari', 'narb' => 'Old_North_Arabian', 'nbat' => 'Nabataean', 'newa' => 'Newa',
        'newtailue' => 'New_Tai_Lue', 'nko' => 'Nko', 'nkoo' => 'Nko', 'nshu' => 'Nushu', 'nushu' => 'Nushu',
        'nyiakengpuachuehmong' => 'Nyiakeng_Puachue_Hmong', 'ogam' => 'Ogham', 'ogham' => 'Ogham',
        'olchiki' => 'Ol_Chiki', 'olck' => 'Ol_Chiki', 'oldhungarian' => 'Old_Hungarian',
        'olditalic' => 'Old_Italic', 'oldnortharabian' => 'Old_North_Arabian', 'oldpermic' => 'Old_Permic',
        'oldpersian' => 'Old_Persian', 'oldsogdian' => 'Old_Sogdian', 'oldsoutharabian' => 'Old_South_Arabian',
        'oldturkic' => 'Old_Turkic', 'olduyghur' => 'Old_Uyghur', 'olonal' => 'Ol_Onal', 'onao' => 'Ol_Onal',
        'oriya' => 'Oriya', 'orkh' => 'Old_Turkic', 'orya' => 'Oriya', 'osage' => 'Osage', 'osge' => 'Osage',
        'osma' => 'Osmanya', 'osmanya' => 'Osmanya', 'ougr' => 'Old_Uyghur', 'pahawhhmong' => 'Pahawh_Hmong',
        'palm' => 'Palmyrene', 'palmyrene' => 'Palmyrene', 'pauc' => 'Pau_Cin_Hau', 'paucinhau' => 'Pau_Cin_Hau',
        'perm' => 'Old_Permic', 'phag' => 'Phags_Pa', 'phagspa' => 'Phags_Pa', 'phli' => 'Inscriptional_Pahlavi',
        'phlp' => 'Psalter_Pahlavi', 'phnx' => 'Phoenician', 'phoenician' => 'Phoenician', 'plrd' => 'Miao',
        'prti' => 'Inscriptional_Parthian', 'psalterpahlavi' => 'Psalter_Pahlavi', 'qaac' => 'Coptic',
        'qaai' => 'Inherited', 'rejang' => 'Rejang', 'rjng' => 'Rejang', 'rohg' => 'Hanifi_Rohingya',
        'runic' => 'Runic', 'runr' => 'Runic', 'samaritan' => 'Samaritan', 'samr' => 'Samaritan',
        'sarb' => 'Old_South_Arabian', 'saur' => 'Saurashtra', 'saurashtra' => 'Saurashtra', 'sgnw' => 'SignWriting',
        'sharada' => 'Sharada', 'shavian' => 'Shavian', 'shaw' => 'Shavian', 'shrd' => 'Sharada', 'sidd' => 'Siddham',
        'siddham' => 'Siddham', 'sidetic' => 'Sidetic', 'sidt' => 'Sidetic', 'signwriting' => 'SignWriting',
        'sind' => 'Khudawadi', 'sinh' => 'Sinhala', 'sinhala' => 'Sinhala', 'sogd' => 'Sogdian', 'sogdian' => 'Sogdian',
        'sogo' => 'Old_Sogdian', 'sora' => 'Sora_Sompeng', 'sorasompeng' => 'Sora_Sompeng', 'soyo' => 'Soyombo',
        'soyombo' => 'Soyombo', 'sund' => 'Sundanese', 'sundanese' => 'Sundanese', 'sunu' => 'Sunuwar',
        'sunuwar' => 'Sunuwar', 'sylo' => 'Syloti_Nagri', 'sylotinagri' => 'Syloti_Nagri', 'syrc' => 'Syriac',
        'syriac' => 'Syriac', 'tagalog' => 'Tagalog', 'tagb' => 'Tagbanwa', 'tagbanwa' => 'Tagbanwa',
        'taile' => 'Tai_Le', 'taitham' => 'Tai_Tham', 'taiviet' => 'Tai_Viet', 'taiyo' => 'Tai_Yo',
        'takr' => 'Takri', 'takri' => 'Takri', 'tale' => 'Tai_Le', 'talu' => 'New_Tai_Lue', 'tamil' => 'Tamil',
        'taml' => 'Tamil', 'tang' => 'Tangut', 'tangsa' => 'Tangsa', 'tangut' => 'Tangut', 'tavt' => 'Tai_Viet',
        'tayo' => 'Tai_Yo', 'telu' => 'Telugu', 'telugu' => 'Telugu', 'tfng' => 'Tifinagh', 'tglg' => 'Tagalog',
        'thaa' => 'Thaana', 'thaana' => 'Thaana', 'thai' => 'Thai', 'tibetan' => 'Tibetan', 'tibt' => 'Tibetan',
        'tifinagh' => 'Tifinagh', 'tirh' => 'Tirhuta', 'tirhuta' => 'Tirhuta', 'tnsa' => 'Tangsa',
        'todhri' => 'Todhri', 'todr' => 'Todhri', 'tolongsiki' => 'Tolong_Siki', 'tols' => 'Tolong_Siki',
        'toto' => 'Toto', 'tulutigalari' => 'Tulu_Tigalari', 'tutg' => 'Tulu_Tigalari', 'ugar' => 'Ugaritic',
        'ugaritic' => 'Ugaritic', 'unknown' => 'Unknown', 'vai' => 'Vai', 'vaii' => 'Vai', 'vith' => 'Vithkuqi',
        'vithkuqi' => 'Vithkuqi', 'wancho' => 'Wancho', 'wara' => 'Warang_Citi', 'warangciti' => 'Warang_Citi',
        'wcho' => 'Wancho', 'xpeo' => 'Old_Persian', 'xsux' => 'Cuneiform', 'yezi' => 'Yezidi', 'yezidi' => 'Yezidi',
        'yi' => 'Yi', 'yiii' => 'Yi', 'zanabazarsquare' => 'Zanabazar_Square', 'zanb' => 'Zanabazar_Square',
        'zinh' => 'Inherited', 'zyyy' => 'Common', 'zzzz' => 'Unknown',
    ];

    /**
     * The JavaScript name of the script, or null when the name is no script
     * PCRE2 and JavaScript share.
     */
    public static function javaScriptName(string $name): ?string
    {
        return self::NAMES[self::looseKey($name)] ?? null;
    }

    /**
     * A name as PCRE2 compares it: lowercase, without ASCII spaces, hyphens
     * or underscores.
     */
    public static function looseKey(string $name): string
    {
        return strtolower(str_replace([' ', "\t", "\n", "\r", "\f", "\v", '-', '_'], '', $name));
    }
}
