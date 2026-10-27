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

/**
 * The property names each PCRE2 release from 10.40 knows, as PRIV(utt) of
 * its pcre2_ucptables.c lists them (pcre2_ucptables_inc.h from 10.47),
 * written by tests/Tools/write_property_names.php from the release
 * sources: never edited by hand.
 *
 * A name is spelled as PCRE2 looks it up: in lower case, without spaces,
 * hyphens or underscores, and a Bidi class as "bidi" then the class. No
 * release dropped a name or made a script another kind of property, so
 * each list holds the names its release added.
 *
 * @internal
 */
final class PropertyNameTables
{
    /**
     * The releases read, with the Unicode version of each.
     */
    public const RELEASES = [
        '10.40' => '14.0.0',
        '10.41' => '14.0.0',
        '10.42' => '14.0.0',
        '10.43' => '15.0.0',
        '10.44' => '15.0.0',
        '10.45' => '16.0.0',
        '10.46' => '16.0.0',
        '10.47' => '16.0.0',
        '10.48' => '17.0.0',
        '10.49' => '17.0.0',
    ];

    /**
     * The scripts, the names "sc:" and "scx:" take, by the release that
     * added them.
     */
    public const SCRIPTS = [
        '10.40' => 'adlam adlm aghb ahom anatolianhieroglyphs arab arabic armenian armi armn avestan avst bali balinese'
            .' bamu bamum bass bassavah batak batk beng bengali bhaiksuki bhks bopo bopomofo brah brahmi brai'
            .' braille bugi buginese buhd buhid cakm canadianaboriginal cans cari carian caucasianalbanian chakma'
            .' cham cher cherokee chorasmian chrs common copt coptic cpmn cprt cuneiform cypriot cyprominoan'
            .' cyrillic cyrl deseret deva devanagari diak divesakuru dogr dogra dsrt dupl duployan egyp'
            .' egyptianhieroglyphs elba elbasan elym elymaic ethi ethiopic geor georgian glag glagolitic gong gonm'
            .' goth gothic gran grantha greek grek gujarati gujr gunjalagondi gurmukhi guru han hang hangul hani'
            .' hanifirohingya hano hanunoo hatr hatran hebr hebrew hira hiragana hluw hmng hmnp hung'
            .' imperialaramaic inherited inscriptionalpahlavi inscriptionalparthian ital java javanese kaithi kali'
            .' kana kannada katakana kayahli khar kharoshthi khitansmallscript khmer khmr khoj khojki khudawadi'
            .' kits knda kthi lana lao laoo latin latn lepc lepcha limb limbu lina linb lineara linearb lisu lyci'
            .' lycian lydi lydian mahajani mahj maka makasar malayalam mand mandaic mani manichaean marc marchen'
            .' masaramgondi medefaidrin medf meeteimayek mend mendekikakui merc mero meroiticcursive'
            .' meroitichieroglyphs miao mlym modi mong mongolian mro mroo mtei mult multani myanmar mymr nabataean'
            .' nand nandinagari narb nbat newa newtailue nko nkoo nshu nushu nyiakengpuachuehmong ogam ogham'
            .' olchiki olck oldhungarian olditalic oldnortharabian oldpermic oldpersian oldsogdian oldsoutharabian'
            .' oldturkic olduyghur oriya orkh orya osage osge osma osmanya ougr pahawhhmong palm palmyrene pauc'
            .' paucinhau perm phag phagspa phli phlp phnx phoenician plrd prti psalterpahlavi qaac qaai rejang rjng'
            .' rohg runic runr samaritan samr sarb saur saurashtra sgnw sharada shavian shaw shrd sidd siddham'
            .' signwriting sind sinh sinhala sogd sogdian sogo sora sorasompeng soyo soyombo sund sundanese sylo'
            .' sylotinagri syrc syriac tagalog tagb tagbanwa taile taitham taiviet takr takri tale talu tamil taml'
            .' tang tangsa tangut tavt telu telugu tfng tglg thaa thaana thai tibetan tibt tifinagh tirh tirhuta'
            .' tnsa toto ugar ugaritic unknown vai vaii vith vithkuqi wancho wara warangciti wcho xpeo xsux yezi'
            .' yezidi yi yiii zanabazarsquare zanb zinh zyyy zzzz',
        '10.43' => 'kawi nagm nagmundari',
        '10.45' => 'gara garay gukh gurungkhema kiratrai krai olonal onao sunu sunuwar todhri todr tulutigalari tutg',
        '10.48' => 'berf beriaerfe sidetic sidt taiyo tayo tolongsiki tols',
    ];

    /**
     * The other names, by the release that added them: the general
     * categories, PCRE's own, the binary properties and the Bidi classes.
     */
    public const PROPERTIES = [
        '10.40' => 'ahex alpha alphabetic any ascii asciihexdigit bidial bidian bidib bidibn bidic bidicontrol bidics'
            .' bidien bidies bidiet bidifsi bidil bidilre bidilri bidilro bidim bidimirrored bidinsm bidion bidipdf'
            .' bidipdi bidir bidirle bidirli bidirlo bidis bidiws c cased caseignorable cc cf changeswhencasefolded'
            .' changeswhencasemapped changeswhenlowercased changeswhentitlecased changeswhenuppercased ci cn co cs'
            .' cwcf cwcm cwl cwt cwu dash defaultignorablecodepoint dep deprecated di dia diacritic ebase ecomp'
            .' emod emoji emojicomponent emojimodifier emojimodifierbase emojipresentation epres ext'
            .' extendedpictographic extender extpict graphemebase graphemeextend graphemelink grbase grext grlink'
            .' hex hexdigit idc idcontinue ideo ideographic ids idsb idsbinaryoperator idst idstart'
            .' idstrinaryoperator joinc joincontrol l l& lc ll lm lo loe logicalorderexception lower lowercase lt'
            .' lu m math mc me mn n nchar nd nl no noncharactercodepoint p patsyn patternsyntax patternwhitespace'
            .' patws pc pcm pd pe pf pi po prependedconcatenationmark ps qmark quotationmark radical'
            .' regionalindicator ri s sc sd sentenceterminal sk sm so softdotted space sterm term'
            .' terminalpunctuation uideo unifiedideograph upper uppercase variationselector vs whitespace wspace'
            .' xan xidc xidcontinue xids xidstart xps xsp xuc xwd z zl zp zs',
        '10.45' => 'idcompatmathcontinue idcompatmathstart idsu idsunaryoperator incb mcm modifiercombiningmark',
    ];
}
