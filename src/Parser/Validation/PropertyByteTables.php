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

namespace PHPRegex\Parser\Validation;

/**
 * The characters below 256 each property matches in a class of PCRE2
 * 10.45 and later, the bits it sets in the map of that class, written by
 * tests/Tools/write_property_bytes.php from pcre2test of each release:
 * never edited by hand.
 *
 * A name is spelled as PCRE2 looks it up, in lower case, without spaces,
 * hyphens or underscores, a script after "sc:" or "scx:" (the name alone
 * reads as "scx:"), a Bidi class as "bidi" then the class, and a POSIX
 * name UCP reads as a property no name spells in brackets. A name not
 * listed matches no character below 256.
 *
 * @internal
 */
final class PropertyByteTables
{
    /**
     * The first release of each table, from the newest, with the Unicode
     * version it ships.
     */
    public const RELEASES = [
        '10.45' => '16.0.0',
    ];

    /**
     * Each table by its first release: the characters a property matches,
     * as runs "first-last" or one character, in hexadecimal, and the
     * names that match them.
     */
    public const TABLES = [
        '10.45' => [
            '00-08 0e-1b 7f-84 86-9f ad' => 'bidibn',
            '00-1f 7f-9f' => 'cc',
            '00-1f 7f-9f ad' => 'c',
            '00-40 5b-60 7b-a9 ab-b9 bb-bf d7 f7' => 'sc:common sc:zyyy scx:common scx:zyyy',
            '00-7f' => 'ascii',
            '00-ff' => 'any',
            '09 0b 1f' => 'bidis',
            '09-0d 20 85' => 'patternwhitespace patws',
            '09-0d 20 85 a0' => 'space whitespace wspace xps xsp',
            '0a 0d 1c-1e 85' => 'bidib',
            '0c 20' => 'bidiws',
            '20 a0' => 'z zs',
            '20-7e a0-ac ae-ff' => 'graphemebase grbase',
            '20-7e a0-ff' => '[:print:]',
            '21 2c 2e 3a-3b 3f' => 'term terminalpunctuation',
            '21 2e 3f' => 'sentenceterminal sterm',
            '21-22 26-2a 3b-40 5b-60 7b-7e a1 a6-a9 ab-ac ae-af b4 b6-b8 bb-bf d7 f7' => 'bidion',
            '21-23 25-27 2a 2c 2e-2f 3a-3b 3f-40 5c a1 a7 b6-b7 bf' => 'po',
            '21-23 25-2a 2c-2f 3a-3b 3f-40 5b-5d 5f 7b 7d a1 a7 ab b6-b7 bb bf' => 'p',
            '21-2f 3a-40 5b-5e 60 7b-7e a1-a7 a9 ab-ac ae b0-b1 b6 bb bf d7 f7' => 'patsyn patternsyntax',
            '21-2f 3a-40 5b-60 7b-7e a1 a7 ab b6-b7 bb bf' => '[:punct:]',
            '21-7e a1-ff' => '[:graph:]',
            '22 27 ab bb' => 'qmark quotationmark',
            '23 2a 30-39' => 'ecomp emojicomponent',
            '23 2a 30-39 a9 ae' => 'emoji',
            '23-25 a2-a5 b0-b1' => 'bidiet',
            '24 2b 3c-3e 5e 60 7c 7e a2-a6 a8-a9 ac ae-b1 b4 b8 d7 f7' => 's',
            '24 40 60 a0-ff' => 'xuc',
            '24 a2-a5' => 'sc',
            '27 2e 3a 5e 60 a8 ad af b4 b7-b8' => 'caseignorable ci',
            '28 5b 7b' => 'ps',
            '28-29 3c 3e 5b 5d 7b 7d ab bb' => 'bidim bidimirrored',
            '29 5d 7d' => 'pe',
            '2b 2d' => 'bidies',
            '2b 3c-3e 5e 7c 7e ac b1 d7 f7' => 'math',
            '2b 3c-3e 7c 7e ac b1 d7 f7' => 'sm',
            '2c 2e-2f 3a a0' => 'bidics',
            '2d' => 'dash pd',
            '30-39' => 'nd',
            '30-39 41-46 61-66' => '[:xdigit:] ahex asciihexdigit hex hexdigit',
            '30-39 41-5a 5f 61-7a aa b2-b3 b5 b9-ba bc-be c0-d6 d8-f6 f8-ff' => 'xwd',
            '30-39 41-5a 5f 61-7a aa b5 b7 ba c0-d6 d8-f6 f8-ff' => 'idc idcontinue xidc xidcontinue',
            '30-39 41-5a 61-7a aa b2-b3 b5 b9-ba bc-be c0-d6 d8-f6 f8-ff' => 'xan',
            '30-39 b2-b3 b9' => 'bidien',
            '30-39 b2-b3 b9 bc-be' => 'n',
            '41-5a 61-7a aa b5 ba c0-d6 d8-f6 f8-ff' => 'alpha alphabetic bidil cased ids idstart l xids xidstart',
            '41-5a 61-7a aa b7 ba c0-d6 d8-f6 f8-ff' => 'scx:latin scx:latn',
            '41-5a 61-7a aa ba c0-d6 d8-f6 f8-ff' => 'sc:latin sc:latn',
            '41-5a 61-7a b5 c0-d6 d8-f6 f8-ff' => 'changeswhencasemapped cwcm l& lc',
            '41-5a b5 c0-d6 d8-df' => 'changeswhencasefolded cwcf',
            '41-5a c0-d6 d8-de' => 'changeswhenlowercased cwl lu upper uppercase',
            '5e 60 a8 af b4 b7-b8' => 'dia diacritic',
            '5e 60 a8 af b4 b8' => 'sk',
            '5f' => 'pc',
            '61-7a aa b5 ba df-f6 f8-ff' => 'lower lowercase',
            '61-7a b5 df-f6 f8-ff' => 'changeswhentitlecased changeswhenuppercased cwt cwu ll',
            '69-6a' => 'sd softdotted',
            'a6 a9 ae b0' => 'so',
            'a9 ae' => 'extendedpictographic extpict',
            'aa ba' => 'lo',
            'ab' => 'pi',
            'ad' => 'cf defaultignorablecodepoint di',
            'b2-b3 b9' => 'idcompatmathcontinue',
            'b2-b3 b9 bc-be' => 'no',
            'b7' => 'ext extender scx:avestan scx:avst scx:cari scx:carian scx:copt scx:coptic scx:dupl scx:duployan'
                .' scx:elba scx:elbasan scx:geor scx:georgian scx:glag scx:glagolitic scx:gong scx:goth scx:gothic'
                .' scx:greek scx:grek scx:gunjalagondi scx:han scx:hani scx:lydi scx:lydian scx:mahajani scx:mahj'
                .' scx:oldpermic scx:perm scx:qaac scx:shavian scx:shaw',
            'bb' => 'pf',
        ],
    ];
}
