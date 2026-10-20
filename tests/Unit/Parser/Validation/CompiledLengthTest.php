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

namespace PHPRegex\Tests\Unit\Parser\Validation;

use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Validation\CompiledLength;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The length PCRE2 10.48 and later compile a pattern to, its end marker
 * included, item by item: each row is the "code size" pcre2test 10.48 and
 * 10.49 print for the pattern with the "memory" modifier ("u" read as "utf"
 * and "ucp"). An item whose length depends on what is not known for sure
 * has no length.
 */
final class CompiledLengthTest extends TestCase
{
    #[Test]
    #[DataProvider('provideLengths')]
    public function test_the_length_is_the_one_pcre2_compiles(string $pattern, int $length): void
    {
        $this->assertSame($length, self::lengthOf($pattern), $pattern);
    }

    #[Test]
    #[DataProvider('provideLengthsOnEachRelease')]
    public function test_the_length_is_the_one_each_release_compiles(string $pattern, string $release, int $length): void
    {
        $this->assertSame($length, self::lengthOf($pattern, $release), \sprintf('%s on PCRE2 %s', $pattern, $release));
    }

    #[Test]
    #[DataProvider('provideLengthsNotKnown')]
    public function test_a_length_not_known_for_sure_is_left_out(string $pattern): void
    {
        $this->assertNull(self::lengthOf($pattern), $pattern);
    }

    #[Test]
    #[DataProvider('provideLengthsNotKnownOnARelease')]
    public function test_a_length_not_known_on_a_release_is_left_out(string $pattern, string $release): void
    {
        $this->assertNull(self::lengthOf($pattern, $release), \sprintf('%s on PCRE2 %s', $pattern, $release));
    }

    /**
     * Up to 10.34 a DEFINE group inside a lookbehind is read by another
     * measure, and on 10.34 a second DEFINE group that opens on a group
     * moves where the measure of lookbehinds stops: the lookbehinds after
     * them are not known for sure.
     *
     * @return iterable<string, array{pattern: string, release: string}>
     */
    public static function provideLengthsNotKnownOnARelease(): iterable
    {
        yield 'lookbehind after a DEFINE group inside a lookbehind, 10.33' => ['pattern' => '/(?<=(?(DEFINE)(a))b)c(?<=d)/', 'release' => '10.33'];
        yield 'lookbehind after a DEFINE group inside a lookbehind, 10.34' => ['pattern' => '/(?<=(?(DEFINE)(a))b)c(?<=d)/', 'release' => '10.34'];
        yield 'lookbehind after two DEFINE groups that open on a group, 10.34' => ['pattern' => '/(c)(?(DEFINE)(?<=x)b)(?(DEFINE)(?<!y)c)(?<=a)/', 'release' => '10.34'];
        yield 'lookbehind after a DEFINE group that opens on a group after a comment, 10.34' => ['pattern' => '/(?<=(?(DEFINE)(?#c)(a))b)c(?<=d)/', 'release' => '10.34'];
    }

    #[Test]
    public function test_a_pattern_past_the_limit_has_no_length(): void
    {
        $this->assertNull(self::lengthOf('/(?:a){8192}/'));
        $this->assertSame(65535, self::lengthOf('/(?:a){8191}/'));
        // The end marker counts against the limit: 65536 units compile, one
        // more does not (pcre2test 10.49).
        $this->assertSame(65536, self::lengthOf('/.'.str_repeat('a', 32764).'/'));
        $this->assertNull(self::lengthOf('/'.str_repeat('a', 32765).'/'));
        // Past the limit only once its last branch is added.
        $this->assertNull(self::lengthOf('/a|(?:b){8191}/'));
    }

    /**
     * @return iterable<string, array{pattern: string, length: int}>
     */
    public static function provideLengths(): iterable
    {
        yield 'letter' => ['pattern' => '/a/', 'length' => 9];
        yield 'three letters' => ['pattern' => '/abc/', 'length' => 13];
        yield 'two-byte letter in UTF mode' => ['pattern' => '/é/u', 'length' => 10];
        yield 'three-byte character in UTF mode' => ['pattern' => '/€/u', 'length' => 11];
        yield 'two-byte letter in byte mode, two characters' => ['pattern' => '/é/', 'length' => 11];
        yield 'caseless letter' => ['pattern' => '/k/i', 'length' => 9];
        yield 'caseless letter of three cases in UTF mode' => ['pattern' => '/k/iu', 'length' => 10];
        yield 'caseless two-byte letter in UTF mode' => ['pattern' => '/é/iu', 'length' => 10];
        // A caseless letter of more than one other case is a property of
        // three units, any other the letter after its caseless opcode.
        yield 'caseless Kelvin sign in UTF mode' => ['pattern' => '/K/iu', 'length' => 10];
        yield 'caseless three-byte character in UTF mode' => ['pattern' => '/€/iu', 'length' => 11];
        yield 'counted caseless two-byte letter in UTF mode' => ['pattern' => '/é{2}/iu', 'length' => 12];
        yield 'counted caseless micro sign in UTF mode' => ['pattern' => '/µ{2}/iu', 'length' => 13];
        yield 'caseless three-byte character counted two times or more' => ['pattern' => '/€{2,}/iu', 'length' => 17];
        yield 'negated caseless class of the Kelvin sign in UTF mode' => ['pattern' => '/[^K]/iu', 'length' => 10];
        yield 'caseless micro sign under UCP without UTF, two bytes' => ['pattern' => '/(*UCP)µ/i', 'length' => 12];
        // The settings that open a pattern compile to nothing.
        yield 'opening settings' => ['pattern' => '/(*NO_JIT)(*LIMIT_MATCH=10)(*CRLF)a/', 'length' => 9];
        yield 'two-byte letter after the UTF setting' => ['pattern' => '/(*UTF)é/', 'length' => 10];
        yield 'hexadecimal escape' => ['pattern' => '/\\x41/', 'length' => 9];
        yield 'control escape' => ['pattern' => '/\\cA/', 'length' => 9];
        yield 'dot' => ['pattern' => '/./', 'length' => 8];
        yield 'anchors' => ['pattern' => '/^$/', 'length' => 9];
        yield 'assertions' => ['pattern' => '/\\b\\B\\A\\z\\Z\\G/', 'length' => 13];
        yield 'start of the match reset' => ['pattern' => '/\\K/', 'length' => 8];
        yield 'types' => ['pattern' => '/\\d\\w\\s/', 'length' => 10];
        yield 'types under UCP' => ['pattern' => '/\\d\\w\\s/u', 'length' => 16];
        yield 'other types' => ['pattern' => '/\\h\\v\\R\\X\\N/', 'length' => 12];
        yield 'property' => ['pattern' => '/\\p{L}/', 'length' => 10];
        yield 'caseless property' => ['pattern' => '/\\p{Lu}/i', 'length' => 10];
        // "\p{Any}" is a match of any character, "\P{Any}" a class of none.
        yield 'property of any character' => ['pattern' => '/\\p{Any}/', 'length' => 8];
        yield 'negated property of any character' => ['pattern' => '/\\P{Any}/', 'length' => 40];
        yield 'counted property of any character' => ['pattern' => '/\\p{Any}{2}/', 'length' => 11];
        yield 'class of one letter' => ['pattern' => '/[a]/', 'length' => 9];
        yield 'negated class of one letter' => ['pattern' => '/[^a]/', 'length' => 9];
        yield 'class of a letter and its other case' => ['pattern' => '/[aA]/', 'length' => 9];
        yield 'class of k and its other case, which has a third' => ['pattern' => '/[kK]/', 'length' => 40];
        yield 'class of two letters' => ['pattern' => '/[ab]/', 'length' => 40];
        yield 'range' => ['pattern' => '/[a-z]/', 'length' => 40];
        yield 'negated range' => ['pattern' => '/[^a-z]/', 'length' => 40];
        yield 'range of one letter' => ['pattern' => '/[a-a]/', 'length' => 9];
        yield 'class holding a type' => ['pattern' => '/[\\d]/', 'length' => 40];
        yield 'class holding a POSIX name' => ['pattern' => '/[[:alpha:]]/', 'length' => 40];
        yield 'class holding the space type' => ['pattern' => '/[\\s]/', 'length' => 40];
        yield 'class holding the word type' => ['pattern' => '/[\\w]/', 'length' => 40];
        yield 'class holding the horizontal space type' => ['pattern' => '/[\\h]/', 'length' => 40];
        yield 'class holding the vertical space type' => ['pattern' => '/[\\v]/', 'length' => 40];
        yield 'class holding the POSIX digits' => ['pattern' => '/[[:digit:]]/', 'length' => 40];
        yield 'class holding the POSIX spaces' => ['pattern' => '/[[:space:]]/', 'length' => 40];
        yield 'class holding the POSIX word characters' => ['pattern' => '/[[:word:]]/', 'length' => 40];
        yield 'class holding the POSIX letters and digits' => ['pattern' => '/[[:alnum:]]/', 'length' => 40];
        yield 'class holding the POSIX capitals' => ['pattern' => '/[[:upper:]]/', 'length' => 40];
        yield 'class holding the POSIX small letters' => ['pattern' => '/[[:lower:]]/', 'length' => 40];
        yield 'class holding the POSIX hexadecimal digits' => ['pattern' => '/[[:xdigit:]]/', 'length' => 40];
        yield 'class holding the POSIX blanks' => ['pattern' => '/[[:blank:]]/', 'length' => 40];
        yield 'class holding the POSIX controls' => ['pattern' => '/[[:cntrl:]]/', 'length' => 40];
        yield 'class holding the POSIX punctuation' => ['pattern' => '/[[:punct:]]/', 'length' => 40];
        yield 'class holding the POSIX visible characters' => ['pattern' => '/[[:graph:]]/', 'length' => 40];
        yield 'class holding the POSIX printable characters' => ['pattern' => '/[[:print:]]/', 'length' => 40];
        yield 'class holding the horizontal spaces and non-spaces' => ['pattern' => '/[\\h\\S]/', 'length' => 40];
        yield 'class of one two-byte letter in UTF mode' => ['pattern' => '/[é]/u', 'length' => 10];
        yield 'negated caseless class of k in UTF mode' => ['pattern' => '/[^k]/iu', 'length' => 10];
        yield 'caseless class of two letters in UTF mode' => ['pattern' => '/[ab]/iu', 'length' => 40];
        // Under a caseless UTF option the Kelvin sign and the long s join a
        // class that holds k or s, as characters past the map.
        yield 'caseless class of k and its other case in UTF mode' => ['pattern' => '/[kK]/iu', 'length' => 48];
        yield 'caseless class of s and another letter in UTF mode' => ['pattern' => '/[sx]/iu', 'length' => 47];
        yield 'caseless class of k and s in UTF mode' => ['pattern' => '/[ks]/iu', 'length' => 51];
        yield 'caseless range in UTF mode' => ['pattern' => '/[a-z]/iu', 'length' => 51];
        yield 'negated caseless class of k and another letter in UTF mode' => ['pattern' => '/[^kx]/iu', 'length' => 48];
        // The Kelvin sign and the long s join the class for a literal k or s,
        // not for a POSIX name or a type that matches them.
        yield 'caseless class of a POSIX name under UTF without UCP' => ['pattern' => '/(*UTF)[[:alpha:]]/i', 'length' => 40];
        yield 'caseless class of the word type under UTF without UCP' => ['pattern' => '/(*UTF)[\\w]/i', 'length' => 40];
        yield 'caseless class of a POSIX name and k under UTF without UCP' => ['pattern' => '/(*UTF)[[:alpha:]k]/i', 'length' => 48];
        yield 'caseless class of the word type and s under UTF without UCP' => ['pattern' => '/(*UTF)[\\ws]/i', 'length' => 47];
        yield 'counted caseless class of k and s in UTF mode' => ['pattern' => '/[ks]{2}/iu', 'length' => 56];
        yield 'counted letter' => ['pattern' => '/a{2}/', 'length' => 11];
        yield 'letter counted two to three times' => ['pattern' => '/a{2,3}/', 'length' => 13];
        yield 'letter counted two to five times' => ['pattern' => '/a{2,5}/', 'length' => 15];
        yield 'letter counted three times or more' => ['pattern' => '/a{3,}/', 'length' => 13];
        yield 'letter counted up to four times' => ['pattern' => '/a{0,4}/', 'length' => 11];
        yield 'letter counted one to three times' => ['pattern' => '/a{1,3}/', 'length' => 13];
        yield 'starred letter' => ['pattern' => '/a*/', 'length' => 9];
        yield 'lazy plus' => ['pattern' => '/a+?/', 'length' => 9];
        yield 'possessive question mark' => ['pattern' => '/a?+/', 'length' => 9];
        yield 'counted two-byte letter in UTF mode' => ['pattern' => '/é{2}/u', 'length' => 12];
        yield 'counted caseless k in UTF mode' => ['pattern' => '/k{2}/iu', 'length' => 13];
        yield 'counted class' => ['pattern' => '/[ab]{2}/', 'length' => 45];
        yield 'starred class' => ['pattern' => '/[ab]*/', 'length' => 41];
        yield 'counted class of one letter' => ['pattern' => '/[a]{3}/', 'length' => 11];
        yield 'counted type' => ['pattern' => '/\\d{2}/', 'length' => 11];
        yield 'counted type under UCP' => ['pattern' => '/\\d{2,5}/u', 'length' => 19];
        yield 'dot counted two times or more' => ['pattern' => '/.{2,}/', 'length' => 13];
        yield 'counted property' => ['pattern' => '/\\p{L}{2}/', 'length' => 13];
        yield 'group' => ['pattern' => '/(?:a)/', 'length' => 15];
        yield 'capture' => ['pattern' => '/(a)/', 'length' => 17];
        yield 'named capture' => ['pattern' => '/(?<n>a)/', 'length' => 17];
        yield 'atomic group' => ['pattern' => '/(?>a)/', 'length' => 15];
        yield '(?=a)' => ['pattern' => '/(?=a)/', 'length' => 15];
        yield 'branch reset' => ['pattern' => '/(?|a|b)/', 'length' => 20];
        yield 'group of options' => ['pattern' => '/(?i:a)/', 'length' => 15];
        yield 'options for the rest of the pattern' => ['pattern' => '/(?i)a/', 'length' => 9];
        yield 'three branches' => ['pattern' => '/a|b|c/', 'length' => 19];
        yield 'counted group of two branches' => ['pattern' => '/(?:a|b){3}/', 'length' => 46];
        yield 'group of two branches counted up to three times' => ['pattern' => '/(?:a|b){0,3}/', 'length' => 61];
        yield 'group of two branches counted two to four times' => ['pattern' => '/(?:a|b){2,4}/', 'length' => 67];
        yield 'group of two branches counted two times or more' => ['pattern' => '/(?:a|b){2,}/', 'length' => 33];
        yield 'starred group of two branches' => ['pattern' => '/(?:a|b)*/', 'length' => 21];
        yield 'possessive count of a group of two branches' => ['pattern' => '/(?:a|b){3}+/', 'length' => 52];
        yield 'possessive plus on a group of two branches' => ['pattern' => '/(?:a|b)++/', 'length' => 20];
        yield '(?=a|b){2,}' => ['pattern' => '/(?=a|b){2,}/', 'length' => 47];
        yield 'reference' => ['pattern' => '/(a)\\1/', 'length' => 20];
        yield 'counted reference' => ['pattern' => '/(a)\\1{2,}/', 'length' => 25];
        yield 'caseless reference' => ['pattern' => '/(a)\\1/i', 'length' => 21];
        yield 'call' => ['pattern' => '/(a)(?1)/', 'length' => 20];
        yield 'counted call' => ['pattern' => '/(a)(?1){2}/', 'length' => 23];
        yield 'call counted two to three times' => ['pattern' => '/(a)(?1){2,3}/', 'length' => 33];
        yield 'starred call' => ['pattern' => '/(a)(?1)*/', 'length' => 27];
        yield 'recursion' => ['pattern' => '/(?R)/', 'length' => 10];
        yield 'conditional' => ['pattern' => '/(a)(?(1)b|c)/', 'length' => 33];
        yield 'counted conditional' => ['pattern' => '/(a)(?(1)b|c){2}/', 'length' => 49];
        yield '(?(?=x)b|c)' => ['pattern' => '/(?(?=x)b|c)/', 'length' => 28];
        yield 'definitions' => ['pattern' => '/(?(DEFINE)a)/', 'length' => 16];
        yield 'script run' => ['pattern' => '/(*sr:a)/', 'length' => 15];
        yield 'fail verb' => ['pattern' => '/(*FAIL)/', 'length' => 8];
        // "(*ACCEPT)" closes each capture open around it, inside the
        // assertion it stands in if any.
        yield 'accept' => ['pattern' => '/(*ACCEPT)/', 'length' => 8];
        yield 'accept in a capture' => ['pattern' => '/(a(*ACCEPT))/', 'length' => 21];
        yield 'accept after a capture closed' => ['pattern' => '/((a)(*ACCEPT))/', 'length' => 29];
        yield 'accept in a lookahead in a capture' => ['pattern' => '/(a(?=b(*ACCEPT)))/', 'length' => 26];
        yield 'accept in a capture in a lookahead' => ['pattern' => '/(?=(a(*ACCEPT)))/', 'length' => 27];
        yield 'named mark' => ['pattern' => '/(*MARK:ab)/', 'length' => 12];
        yield 'callout' => ['pattern' => '/(?C1)/', 'length' => 13];
        yield 'comment' => ['pattern' => '/(?#x)/', 'length' => 7];
        // A count of one leaves the item as it is, but for a possessive one
        // on a group, which wraps it in an atomic group.
        yield 'letter counted once' => ['pattern' => '/a{1}/', 'length' => 9];
        yield 'group counted once' => ['pattern' => '/(?:a){1}/', 'length' => 15];
        yield 'group counted once, possessive' => ['pattern' => '/(?:a){1}+/', 'length' => 21];
        yield 'call counted once' => ['pattern' => '/(a)(?1){1}/', 'length' => 20];
        // A group of options whose body is only a space under "x" is a group,
        // not options for the rest of the pattern.
        yield 'group of options holding a space under x' => ['pattern' => '/(?i: )k/xu', 'length' => 15];
        yield 'group of options turning x on, holding a space' => ['pattern' => '/(?ix: )k/u', 'length' => 15];
        yield 'group of options resetting them, holding a space' => ['pattern' => '/(?^x: )k/iu', 'length' => 16];
        // "(?^)" turns the caseless option off for the rest of the group.
        yield 'caseless k before the options are reset' => ['pattern' => '/k(?^)k/iu', 'length' => 12];
        yield 'k after the caseless option is turned off' => ['pattern' => '/(?-i)k/iu', 'length' => 9];
        yield 'k after the caseless option is turned on' => ['pattern' => '/(?i)k/u', 'length' => 10];
        yield 'k after the caseless option is turned on and the ASCII rules off' => ['pattern' => '/(?i-r)k/u', 'length' => 10];
        yield 'k after the caseless option is turned on and x off' => ['pattern' => '/(?i-x)k/u', 'length' => 10];
        yield 'k after the options are reset and the caseless one turned on' => ['pattern' => '/(?^i)k/u', 'length' => 10];
        yield 'caseless k after another option is set' => ['pattern' => '/(?x)k/iu', 'length' => 10];
        // A group counted zero times is kept, behind a marker that skips it.
        yield 'group counted zero times' => ['pattern' => '/(?:a){0}/', 'length' => 16];
        yield 'other types under UCP' => ['pattern' => '/\\h\\v\\R\\X\\N/u', 'length' => 12];
        // A class takes "?", "*" and "+" in one unit, any other count in five.
        yield 'class counted at most once' => ['pattern' => '/[ab]{0,1}/', 'length' => 41];
        yield 'optional class' => ['pattern' => '/[ab]?/', 'length' => 41];
        yield 'class counted once or more' => ['pattern' => '/[ab]+/', 'length' => 41];
        yield 'class counted once or more, written with braces' => ['pattern' => '/[ab]{1,}/', 'length' => 41];
        yield 'class counted up to three times' => ['pattern' => '/[ab]{0,3}/', 'length' => 45];
        yield 'class counted two times or more' => ['pattern' => '/[ab]{2,}/', 'length' => 45];
        yield 'class counted one to two times' => ['pattern' => '/[ab]{1,2}/', 'length' => 45];
        yield 'optional reference' => ['pattern' => '/(a)\\1?/', 'length' => 21];
        yield 'reference counted up to three times' => ['pattern' => '/(a)\\1{0,3}/', 'length' => 25];
        yield 'type under UCP counted one to three times' => ['pattern' => '/\\d{1,3}/u', 'length' => 16];
        yield 'type counted one to three times' => ['pattern' => '/\\d{1,3}/', 'length' => 12];
        yield 'optional call' => ['pattern' => '/(a)(?1)?/', 'length' => 27];
        yield 'call counted once or more' => ['pattern' => '/(a)(?1)+/', 'length' => 26];
        yield 'call counted zero times' => ['pattern' => '/(a)(?1){0}/', 'length' => 27];
        yield 'call counted up to two times' => ['pattern' => '/(a)(?1){0,2}/', 'length' => 43];
        yield 'call counted three times or more' => ['pattern' => '/(a)(?1){3,}/', 'length' => 36];
        yield 'call counted two times or more' => ['pattern' => '/(a)(?1){2,}/', 'length' => 33];
        yield 'possessive count of a call' => ['pattern' => '/(a)(?1){2}+/', 'length' => 29];
        yield 'possessive star on a call' => ['pattern' => '/(a)(?1)*+/', 'length' => 27];
        yield 'call counted once, possessive' => ['pattern' => '/(a)(?1){1}+/', 'length' => 26];
        yield 'possessive plus on a call' => ['pattern' => '/(a)(?1)++/', 'length' => 26];
        yield 'call counted two times or more, possessive' => ['pattern' => '/(a)(?1){2,}+/', 'length' => 33];
        yield 'possessive range count of a call' => ['pattern' => '/(a)(?1){2,3}+/', 'length' => 39];
        yield 'call counted at most once' => ['pattern' => '/(a)(?1){0,1}/', 'length' => 27];
        yield 'call counted one to three times' => ['pattern' => '/(a)(?1){1,3}/', 'length' => 46];
        yield 'call counted once or more, written with braces' => ['pattern' => '/(a)(?1){1,}/', 'length' => 26];
        yield 'counted class of a letter and its other case' => ['pattern' => '/[aA]{2}/', 'length' => 11];
        yield 'counted class of a letter and its other case in UTF mode' => ['pattern' => '/[aA]{2}/u', 'length' => 11];
        yield 'class of a letter and its other case counted two to three times' => ['pattern' => '/[aA]{2,3}/', 'length' => 13];
        yield 'Kelvin sign in UTF mode' => ['pattern' => '/\\x{212A}/u', 'length' => 11];
        yield 'counted k in UTF mode' => ['pattern' => '/k{2}/u', 'length' => 11];
        yield 'counted Kelvin sign in UTF mode' => ['pattern' => '/\\x{212A}{2}/u', 'length' => 13];
        // Rules kept to ASCII leave the cases of a letter but "i", "k" and
        // "s" as they are.
        yield 'caseless H under rules kept to ASCII' => ['pattern' => '/(*CASELESS_RESTRICT)H/iu', 'length' => 9];
        yield 'caseless J under rules kept to ASCII' => ['pattern' => '/(*CASELESS_RESTRICT)J/iu', 'length' => 9];
        yield 'caseless h under rules kept to ASCII' => ['pattern' => '/(*CASELESS_RESTRICT)h/iu', 'length' => 9];
        yield 'caseless j under rules kept to ASCII' => ['pattern' => '/(*CASELESS_RESTRICT)j/iu', 'length' => 9];
        yield 'caseless i with ogonek under rules kept to ASCII' => ['pattern' => '/(*CASELESS_RESTRICT)\\x{12F}/iu', 'length' => 10];
        yield 'caseless ligature IJ under rules kept to ASCII' => ['pattern' => '/(*CASELESS_RESTRICT)\\x{132}/iu', 'length' => 10];
        yield 'caseless a under rules kept to ASCII' => ['pattern' => '/(*CASELESS_RESTRICT)a/iu', 'length' => 9];
        yield 'counted caseless a under rules kept to ASCII' => ['pattern' => '/(*CASELESS_RESTRICT)a{2}/iu', 'length' => 11];
        yield 'counted caseless h under rules kept to ASCII' => ['pattern' => '/(*CASELESS_RESTRICT)h{2}/iu', 'length' => 11];
        yield 'class of a letter and its other case under rules kept to ASCII' => ['pattern' => '/(*CASELESS_RESTRICT)[aA]/u', 'length' => 9];
        yield 'last byte' => ['pattern' => '/\\xff/', 'length' => 9];
        yield 'counted last byte' => ['pattern' => '/\\xff{2}/', 'length' => 11];
        yield 'quoted letter and two-byte letter in UTF mode' => ['pattern' => '/\\Qaé\\E/u', 'length' => 12];
        yield 'quoted three-byte character in UTF mode' => ['pattern' => '/\\Q€\\E/u', 'length' => 11];
        yield 'quoted letter and three-byte character in UTF mode' => ['pattern' => '/\\Qa€\\E/u', 'length' => 13];
        yield 'quoted two-byte letter in UTF mode' => ['pattern' => '/\\Qé\\E/u', 'length' => 10];
        // A negated type or POSIX name holds every byte its name does not.
        yield 'class holding the POSIX non-letters' => ['pattern' => '/[[:^alpha:]]/', 'length' => 40];
        yield 'class holding the POSIX non-controls' => ['pattern' => '/[[:^cntrl:]]/', 'length' => 40];
        yield 'class of every byte but 0' => ['pattern' => '/[\\D1-9]/', 'length' => 40];
        yield 'class of every byte but 9' => ['pattern' => '/[\\D0-8]/', 'length' => 40];
        yield 'class of the non-word characters and the digits' => ['pattern' => '/[\\W0-9]/', 'length' => 40];
        yield 'possessive star on a group' => ['pattern' => '/(?:a)*+/', 'length' => 16];
        yield 'group counted up to two times, possessive' => ['pattern' => '/(?:a){0,2}+/', 'length' => 37];
        yield 'conditional on a recursion' => ['pattern' => '/(?(R)b|c)/', 'length' => 23];
        yield 'conditional on a recursion into a group' => ['pattern' => '/(?(R2)b|c)()()/', 'length' => 39];
        yield 'counted conditional on a recursion' => ['pattern' => '/(?(R)b|c){2}/', 'length' => 39];
        yield 'counted conditional on an assertion' => ['pattern' => '/(?(?=x)b|c){2}/', 'length' => 49];
        yield 'conditional counted up to two times' => ['pattern' => '/(a)(?(1)b|c){0,2}/', 'length' => 57];
        yield 'starred conditional' => ['pattern' => '/(a)(?(1)b|c)*/', 'length' => 34];
        yield 'possessive star on a conditional' => ['pattern' => '/(a)(?(1)b|c)*+/', 'length' => 40];
        yield 'conditional counted two times or more, possessive' => ['pattern' => '/(a)(?(1)b|c){2,}+/', 'length' => 61];
        yield 'conditional on a group name' => ['pattern' => '/(?<n>a)(?(<n>)b|c)/', 'length' => 33];
        yield 'class of every character under UTF without UCP' => ['pattern' => '/(*UTF)[\\d\\D]/', 'length' => 8];
        yield 'atomic script run' => ['pattern' => '/(*asr:a)/', 'length' => 21];
        yield 'counted negated property of any character' => ['pattern' => '/\\P{Any}{2}/', 'length' => 45];
        yield 'class of a letter and a character past 255 in UTF mode' => ['pattern' => '/[a\\x{100}]/u', 'length' => 47];
        yield 'conditional on an assertion with one branch' => ['pattern' => '/(?(?=x)b)/', 'length' => 23];
        yield 'class of a range and a character past 255 in UTF mode' => ['pattern' => '/[a-c\\x{100}]/u', 'length' => 47];
        yield 'conditional on a group number with one branch' => ['pattern' => '/(a)(?(1)b)/', 'length' => 28];
        yield 'accept counted zero times' => ['pattern' => '/(*ACCEPT){0}/', 'length' => 15];
        yield 'accept counted once' => ['pattern' => '/(*ACCEPT){1}/', 'length' => 14];
        // A name resolves to the groups it stands for, as PCRE2 resolves it.
        yield 'reference by name' => ['pattern' => '/(?<n>a)\\k<n>/', 'length' => 20];
        yield 'caseless reference by name' => ['pattern' => '/(?<n>a)\\k<n>/i', 'length' => 21];
        yield 'counted reference by name' => ['pattern' => '/(?<n>a)\\k<n>{2}/', 'length' => 25];
        yield 'reference by name in a conditional of one branch' => ['pattern' => '/(?<n>a)(?(1)\\k<n>)/', 'length' => 29];
        yield 'condition on a name written before its group' => ['pattern' => '/(?(<n>)a|b)(?<n>c)/', 'length' => 33];
        yield 'condition on a name two groups hold' => ['pattern' => '/(?<n>a)(?<n>b)(?(<n>)c|d)/J', 'length' => 45];
        yield 'reference by a name two groups hold' => ['pattern' => '/(?<n>a)(?<n>b)\\k<n>/J', 'length' => 32];
        yield 'counted conditional on a version' => ['pattern' => '/(?(VERSION>=10.0)a){2}/', 'length' => 25];
        yield 'empty lookahead' => ['pattern' => '/(?=)/', 'length' => 13];
        yield 'empty negative lookahead' => ['pattern' => '/(?!)/', 'length' => 8];
        // A Latin-1 letter with a third case is no pair in UTF mode; one whose
        // other case is past 255 is.
        yield 'class of a ring a and its other case, which has a third' => ['pattern' => '/[åÅ]/u', 'length' => 40];
        yield 'class of the micro sign and its other case, which has a third' => ['pattern' => '/[µΜ]/u', 'length' => 47];
        yield 'class of the sharp s and its capital' => ['pattern' => '/[ßẞ]/u', 'length' => 10];
        yield 'class of y with diaeresis and its capital' => ['pattern' => '/[ÿŸ]/u', 'length' => 10];
        yield 'empty second branch written after a space under x' => ['pattern' => '/(a)(?(1)b |)/x', 'length' => 31];
        yield 'accept with a name in a capture' => ['pattern' => '/(a(*ACCEPT:x))/', 'length' => 25];
        yield 'accept with a name in two captures' => ['pattern' => '/((a)(*ACCEPT:xy))/', 'length' => 34];
        yield 'condition on a name two groups of one number hold' => ['pattern' => '/(?|(?<n>a)|(?<n>b))(?(<n>)c|d)/', 'length' => 52];
    }

    /**
     * The lengths that changed from one release to the next, each the
     * length pcre2test prints on that release, or, where the first pass
     * counts more than the code holds, the length that pass counted, which
     * is the one PCRE2 checks against the limit.
     *
     * @return iterable<string, array{pattern: string, release: string, length: int}>
     */
    public static function provideLengthsOnEachRelease(): iterable
    {
        // "\p{Any}" is a property up to 10.32; "\P{Any}" a negated property
        // up to 10.44, a class of none from 10.45.
        yield 'property of any character, 10.32' => ['pattern' => '/\\p{Any}/', 'release' => '10.32', 'length' => 10];
        yield 'property of any character, 10.33' => ['pattern' => '/\\p{Any}/', 'release' => '10.33', 'length' => 8];
        yield 'counted property of any character, 10.32' => ['pattern' => '/\\p{Any}{2}/', 'release' => '10.32', 'length' => 13];
        yield 'negated property of any character, 10.44' => ['pattern' => '/\\P{Any}/', 'release' => '10.44', 'length' => 10];
        yield 'counted negated property of any character, 10.44' => ['pattern' => '/\\P{Any}{2}/', 'release' => '10.44', 'length' => 13];
        yield 'counted negated property of any character, 10.45' => ['pattern' => '/\\P{Any}{2}/', 'release' => '10.45', 'length' => 45];
        // A class of a letter and its other case is a class up to 10.33.
        yield 'class of a letter and its other case, 10.33' => ['pattern' => '/[aA]/', 'release' => '10.33', 'length' => 40];
        yield 'class of a letter and its other case, 10.34' => ['pattern' => '/[aA]/', 'release' => '10.34', 'length' => 9];
        yield 'class of i and its other case, 10.33' => ['pattern' => '/[iI]/', 'release' => '10.33', 'length' => 40];
        yield 'class of a two-byte letter and its other case in UTF mode, 10.33' => ['pattern' => '/[éÉ]/u', 'release' => '10.33', 'length' => 40];
        // A caseless reference holds its case rules from 10.45.
        yield 'caseless reference, 10.44' => ['pattern' => '/(a)\\1/i', 'release' => '10.44', 'length' => 20];
        yield 'caseless reference, 10.45' => ['pattern' => '/(a)\\1/i', 'release' => '10.45', 'length' => 21];
        yield 'caseless reference by name, 10.44' => ['pattern' => '/(?<n>a)\\k<n>/i', 'release' => '10.44', 'length' => 20];
        // A possessive count on a reference wraps it in an atomic group up
        // to 10.47.
        yield 'possessive star on a reference, 10.47' => ['pattern' => '/(a)\\1*+/', 'release' => '10.47', 'length' => 27];
        yield 'possessive star on a reference, 10.48' => ['pattern' => '/(a)\\1*+/', 'release' => '10.48', 'length' => 21];
        yield 'possessive count on a caseless reference, 10.44' => ['pattern' => '/(a)\\1{2}+/i', 'release' => '10.44', 'length' => 31];
        yield 'possessive count on a caseless reference, 10.45' => ['pattern' => '/(a)\\1{2}+/i', 'release' => '10.45', 'length' => 32];
        // "{1}+" leaves a group or a call as it is up to 10.33.
        yield 'group counted once, possessive, 10.33' => ['pattern' => '/(?:a){1}+/', 'release' => '10.33', 'length' => 15];
        yield 'call counted once, possessive, 10.33' => ['pattern' => '/(a)(?1){1}+/', 'release' => '10.33', 'length' => 20];
        yield 'call counted once, possessive, 10.34' => ['pattern' => '/(a)(?1){1}+/', 'release' => '10.34', 'length' => 26];
        // Up to 10.34 an assertion counted at least once is compiled once,
        // one counted from zero as optional.
        yield 'counted lookahead, 10.34' => ['pattern' => '/(?=a){2}/', 'release' => '10.34', 'length' => 15];
        yield 'counted lookahead, 10.35' => ['pattern' => '/(?=a){2}/', 'release' => '10.35', 'length' => 23];
        yield 'lookahead counted up to three times, 10.34' => ['pattern' => '/(?=a){0,3}/', 'release' => '10.34', 'length' => 16];
        yield 'possessive count on a lookbehind, 10.34' => ['pattern' => '/(?<=a){2}+/', 'release' => '10.34', 'length' => 18];
        yield 'possessive count on a lookbehind, 10.35' => ['pattern' => '/(?<=a){2}+/', 'release' => '10.35', 'length' => 35];
        // Up to 10.44 the Kelvin sign and the long s join a caseless class
        // for each "k", "K", "s" or "S" its members hold; from 10.45 once.
        yield 'caseless class of k and its other case in UTF mode, 10.44' => ['pattern' => '/[kK]/iu', 'release' => '10.44', 'length' => 52];
        yield 'caseless class of k twice in UTF mode, 10.44' => ['pattern' => '/[kk]/iu', 'release' => '10.44', 'length' => 52];
        yield 'caseless class of k twice in UTF mode, 10.45' => ['pattern' => '/[kk]/iu', 'release' => '10.45', 'length' => 48];
        yield 'caseless ranges of both cases in UTF mode, 10.44' => ['pattern' => '/[a-zA-Z]/iu', 'release' => '10.44', 'length' => 58];
        yield 'caseless range from K to k in UTF mode, 10.44' => ['pattern' => '/[K-k]/iu', 'release' => '10.44', 'length' => 55];
        yield 'caseless range from K to k in UTF mode, 10.45' => ['pattern' => '/[K-k]/iu', 'release' => '10.45', 'length' => 51];
        // A class of every byte is a map up to 10.44, a match of any
        // character from 10.45; "[\p{Any}]" an extended class up to 10.44.
        yield 'class of every byte, 10.44' => ['pattern' => '/[\\x00-\\xff]/', 'release' => '10.44', 'length' => 40];
        yield 'class of every byte, 10.45' => ['pattern' => '/[\\x00-\\xff]/', 'release' => '10.45', 'length' => 8];
        yield 'class of every byte but none, 10.45' => ['pattern' => '/[^\\x00-\\xff]/', 'release' => '10.45', 'length' => 40];
        yield 'class of every byte in a type and a range, 10.44' => ['pattern' => '/[\\D0-9]/', 'release' => '10.44', 'length' => 40];
        yield 'class of any character, 10.44' => ['pattern' => '/[\\p{Any}]/', 'release' => '10.44', 'length' => 15];
        yield 'class of any character, 10.45' => ['pattern' => '/[\\p{Any}]/', 'release' => '10.45', 'length' => 8];
        yield 'negated class of any character, 10.44' => ['pattern' => '/[^\\p{Any}]/', 'release' => '10.44', 'length' => 15];
        yield 'negated class of any character, 10.45' => ['pattern' => '/[^\\p{Any}]/', 'release' => '10.45', 'length' => 40];
        // The first pass counts a repeated DEFINE group once per copy;
        // pcre2test prints 16, what the compiled pattern holds.
        yield 'counted definitions' => ['pattern' => '/(?(DEFINE)a){2}/', 'release' => '10.44', 'length' => 25];
        yield 'counted script run, 10.33' => ['pattern' => '/(*sr:a){2}/', 'release' => '10.33', 'length' => 23];
        yield 'atomic script run, 10.33' => ['pattern' => '/(*asr:a)/', 'release' => '10.33', 'length' => 21];
        yield 'counted accept, 10.34' => ['pattern' => '/(*ACCEPT)?/', 'release' => '10.34', 'length' => 15];
        yield 'accept counted twice, 10.44' => ['pattern' => '/(*ACCEPT){2}/', 'release' => '10.44', 'length' => 21];
        // Each branch of a lookbehind of some length starts with the length
        // to move back: one number, or from 10.43 two where it varies.
        yield 'lookbehind, 10.32' => ['pattern' => '/(?<=a)b/', 'release' => '10.32', 'length' => 20];
        yield 'lookbehind of two branches, 10.44' => ['pattern' => '/(?<=a|bc)b/', 'release' => '10.44', 'length' => 30];
        yield 'lookbehind of no length, 10.48' => ['pattern' => '/(?<=\\b)b/', 'release' => '10.48', 'length' => 16];
        yield 'lookbehind of a branch of varying length, 10.43' => ['pattern' => '/(?<=a|b?)c/', 'release' => '10.43', 'length' => 32];
        yield 'lookbehind of one branch of varying length, 10.49' => ['pattern' => '/(?<=a?)b/', 'release' => '10.49', 'length' => 22];
        // A string callout holds its delimiters, a doubled one twice in the
        // first pass (pcre2test prints 21).
        yield 'string callout' => ['pattern' => '/(?C"ab")/', 'release' => '10.40', 'length' => 20];
        yield 'string callout holding its delimiter' => ['pattern' => '/(?C"a""b")/', 'release' => '10.48', 'length' => 22];
        yield 'reference by name' => ['pattern' => '/(?<n>a)\\k<n>/', 'release' => '10.42', 'length' => 20];
        yield 'empty negative lookahead' => ['pattern' => '/(?!)/', 'release' => '10.44', 'length' => 8];
        yield 'empty lookahead' => ['pattern' => '/(?=)/', 'release' => '10.44', 'length' => 13];
        yield 'conditional of one branch' => ['pattern' => '/(a)(?(1)b)/', 'release' => '10.44', 'length' => 28];
        yield 'conditional of an empty second branch' => ['pattern' => '/(a)(?(1)b|)/', 'release' => '10.44', 'length' => 31];
        yield 'conditional on an assertion, of one branch' => ['pattern' => '/(?(?=a)b)/', 'release' => '10.44', 'length' => 23];
        yield 'accept with a name' => ['pattern' => '/(*ACCEPT:x)/', 'release' => '10.44', 'length' => 12];
        // "(?!)" holding only comments is a failure, a lookaround holding
        // options a group.
        yield 'negative lookahead holding only comments' => ['pattern' => '/(?!(?#c)(?#d))/', 'release' => '10.44', 'length' => 8];
        yield 'lookahead holding only options and a comment' => ['pattern' => '/(?=(?i)(?#c))/', 'release' => '10.48', 'length' => 13];
        // An extended class: its opcode, link and flags, the map when it
        // holds characters below 256, its items, the end of the list.
        yield 'class past 255 in UTF mode' => ['pattern' => '/[\\x{100}a]/u', 'release' => '10.40', 'length' => 47];
        yield 'class of a letter and a character past 255 sharing its low byte' => ['pattern' => '/[a\\x{161}]/u', 'release' => '10.48', 'length' => 47];
        yield 'counted class past 255 in UTF mode' => ['pattern' => '/[\\x{100}a]{2}/u', 'release' => '10.44', 'length' => 52];
        yield 'class holding a type under UCP' => ['pattern' => '/[\\d]/u', 'release' => '10.32', 'length' => 15];
        yield 'class holding a property' => ['pattern' => '/[\\p{L}a]/', 'release' => '10.49', 'length' => 47];
        yield 'class of a range past 255 in UTF mode' => ['pattern' => '/[\\x{100}-\\x{200}]/u', 'release' => '10.45', 'length' => 17];
        yield 'class of a property in UTF mode' => ['pattern' => '/[\\p{L}]/u', 'release' => '10.47', 'length' => 15];
        // Up to 10.44 the horizontal spaces past 255 are six items; from
        // 10.45 the six ranges go in lists beside the code (16 bytes).
        yield 'class of horizontal spaces in UTF mode, 10.44' => ['pattern' => '/[\\h]/u', 'release' => '10.44', 'length' => 71];
        yield 'class of horizontal spaces in UTF mode, 10.45' => ['pattern' => '/[\\h]/u', 'release' => '10.45', 'length' => 63];
        yield 'class of what is not a horizontal space in UTF mode, 10.44' => ['pattern' => '/[\\H]/u', 'release' => '10.44', 'length' => 93];
        yield 'class of what is not a horizontal space in UTF mode, 10.49' => ['pattern' => '/[\\H]/u', 'release' => '10.49', 'length' => 79];
        // A two-byte character with three cases is three units in a class
        // up to 10.44, merged with its neighbour into a range from 10.45.
        yield 'class of a titlecase letter and its small letter in UTF mode, 10.44' => ['pattern' => '/[ǅǆ]/u', 'release' => '10.44', 'length' => 18];
        yield 'class of a titlecase letter and its small letter in UTF mode, 10.45' => ['pattern' => '/[ǅǆ]/u', 'release' => '10.45', 'length' => 17];
        yield 'class of every character in two vertical space types' => ['pattern' => '/[\\v\\V]/', 'release' => '10.48', 'length' => 8];
        // A recursive back reference wraps its capture in an atomic group
        // up to 10.34.
        yield 'capture holding a reference to itself, 10.34' => ['pattern' => '/(a)(\\2)/', 'release' => '10.34', 'length' => 34];
        yield 'capture holding a reference to itself, 10.35' => ['pattern' => '/(a)(\\2)/', 'release' => '10.35', 'length' => 28];
        yield 'reference to a name two groups bear' => ['pattern' => '/(?<n>a)|(?<n>b)\\k<n>/J', 'release' => '10.44', 'length' => 35];
        yield 'caseless reference to a name two groups bear, 10.45' => ['pattern' => '/(?<n>a)|(?<n>b)\\k<n>/iJ', 'release' => '10.45', 'length' => 36];
        yield 'condition on a name two groups bear' => ['pattern' => '/(?<n>a)|(?<n>b)(?(<n>)c)/J', 'release' => '10.44', 'length' => 43];
        yield 'condition on a name' => ['pattern' => '/(?<n>a)(?(<n>)c)/', 'release' => '10.48', 'length' => 28];
        // In UTF mode the other cases of a Latin-1 letter are the same on
        // every release; four of them are past 255.
        yield 'caseless class of a Latin-1 letter and its other case in UTF mode, 10.33' => ['pattern' => '/[éÉ]/iu', 'release' => '10.33', 'length' => 40];
        yield 'caseless class of a Latin-1 letter and its other case in UTF mode, 10.48' => ['pattern' => '/[éÉ]/iu', 'release' => '10.48', 'length' => 10];
        yield 'caseless class of a Latin-1 range in UTF mode' => ['pattern' => '/[à-þ]/iu', 'release' => '10.44', 'length' => 48];
        yield 'caseless class of the micro sign in UTF mode' => ['pattern' => '/[µx]/iu', 'release' => '10.32', 'length' => 50];
        yield 'caseless class of the y with a diaeresis in UTF mode' => ['pattern' => '/[ÿx]/iu', 'release' => '10.49', 'length' => 47];
        // A character of more than one other case brings its whole set:
        // up to 10.44 each member the runs of its set, from 10.45 once.
        yield 'caseless class of a titlecase letter and its small letter, 10.44' => ['pattern' => '/[ǅǆ]/iu', 'release' => '10.44', 'length' => 28];
        yield 'caseless class of a titlecase letter and its small letter, 10.45' => ['pattern' => '/[ǅǆ]/iu', 'release' => '10.45', 'length' => 17];
        yield 'caseless class of two Greek mus, 10.44' => ['pattern' => '/[Μμ]/iu', 'release' => '10.44', 'length' => 56];
        yield 'caseless class of two Greek mus, 10.45' => ['pattern' => '/[Μμ]/iu', 'release' => '10.45', 'length' => 50];
        yield 'caseless class of the two small sigmas, 10.44' => ['pattern' => '/[ςσ]/iu', 'release' => '10.44', 'length' => 32];
        yield 'caseless class of the two small sigmas, 10.45' => ['pattern' => '/[ςσ]/iu', 'release' => '10.45', 'length' => 20];
        yield 'class of six characters past 255, 10.44' => ['pattern' => '/[\\x{100}\\x{102}\\x{104}\\x{106}\\x{108}\\x{10a}]/u', 'release' => '10.44', 'length' => 30];
        yield 'class of six characters past 255 in lists, 10.45' => ['pattern' => '/[\\x{100}\\x{102}\\x{104}\\x{106}\\x{108}\\x{10a}]/u', 'release' => '10.45', 'length' => 31];
        // Up to 10.34 the measure of lookbehinds skips the first item of a
        // DEFINE group, and 10.34 stops where a DEFINE group that opens on a
        // group ends: a lookbehind left unmeasured has no length to move
        // back.
        yield 'lookbehind a DEFINE group opens on, and one after, 10.32' => ['pattern' => '/(c)(?(DEFINE)(?<=x)b)(?<=a)/', 'release' => '10.32', 'length' => 45];
        yield 'lookbehind a DEFINE group opens on, and one after, 10.34' => ['pattern' => '/(c)(?(DEFINE)(?<=x)b)(?<=a)/', 'release' => '10.34', 'length' => 42];
        yield 'lookbehind a DEFINE group opens on, and one after, 10.35' => ['pattern' => '/(c)(?(DEFINE)(?<=x)b)(?<=a)/', 'release' => '10.35', 'length' => 48];
        yield 'lookbehind after a DEFINE group opening on a capture, 10.33' => ['pattern' => '/(c)(?(DEFINE)(b))(?<=q)r/', 'release' => '10.33', 'length' => 47];
        yield 'lookbehind after a DEFINE group opening on a capture, 10.34' => ['pattern' => '/(c)(?(DEFINE)(b))(?<=q)r/', 'release' => '10.34', 'length' => 44];
        yield 'lookbehind in the group around a DEFINE group opening on a capture, 10.34' => ['pattern' => '/(c)(?:(?(DEFINE)(b))|(?<=q)r)/', 'release' => '10.34', 'length' => 56];
        // A type and its negation hold every byte whatever the locale: a
        // match of any character from 10.45.
        yield 'class of a type and its negation, 10.44' => ['pattern' => '/[\\d\\D]/', 'release' => '10.44', 'length' => 40];
        yield 'class of a type and its negation, 10.45' => ['pattern' => '/[\\d\\D]/', 'release' => '10.45', 'length' => 8];
        yield 'counted class of a type and its negation in UTF mode, 10.49' => ['pattern' => '/(*UTF)[\\d\\D]{2}/', 'release' => '10.49', 'length' => 11];
        // Under UCP "[:blank:]" is "\\h", and a negated "[:ascii:]" holds every
        // character past 255.
        yield 'class of the POSIX blanks under UCP, 10.44' => ['pattern' => '/[[:blank:]]/u', 'release' => '10.44', 'length' => 71];
        yield 'class of the POSIX blanks under UCP, 10.45' => ['pattern' => '/[[:blank:]]/u', 'release' => '10.45', 'length' => 63];
        yield 'class of what is not ASCII under UCP, 10.44' => ['pattern' => '/[[:^ascii:]]/u', 'release' => '10.44', 'length' => 40];
        // Up to 10.44 a run of other cases inside the member adds nothing.
        yield 'caseless range of titlecase letters, 10.44' => ['pattern' => '/[Ǆ-Ǉ]/iu', 'release' => '10.44', 'length' => 32];
        yield 'caseless range of titlecase letters, 10.45' => ['pattern' => '/[Ǆ-Ǉ]/iu', 'release' => '10.45', 'length' => 17];
        yield 'caseless class of the y with a diaeresis in UTF mode, 10.44' => ['pattern' => '/[ÿx]/iu', 'release' => '10.44', 'length' => 47];
        yield 'negative lookahead' => ['pattern' => '/(?!ab)/', 'release' => '10.44', 'length' => 17];
        yield 'DEFINE group holding a comment, then a lookbehind, 10.34' => ['pattern' => '/(?(DEFINE)(?#c))(?<=a)/', 'release' => '10.34', 'length' => 25];
        // A condition on a version is one unit, true or false, and the first
        // pass repeats the conditional as any other.
        yield 'conditional on a version' => ['pattern' => '/(?(VERSION>=10.0)a|b)/', 'release' => '10.32', 'length' => 21];
        yield 'counted conditional on a version' => ['pattern' => '/(?(VERSION>=10.0)a|b){2}/', 'release' => '10.44', 'length' => 35];
        yield 'counted conditional on a false version' => ['pattern' => '/(?(VERSION>=99.0)a|b){2}/', 'release' => '10.49', 'length' => 35];
        yield 'possessive count on a conditional on a version' => ['pattern' => '/(?(VERSION>=10.0)a|b){2}+/', 'release' => '10.48', 'length' => 41];
        yield 'call returning a group, 10.47' => ['pattern' => '/(a)(b)(?1(2))/', 'release' => '10.47', 'length' => 33];
        yield 'counted call returning a group, 10.49' => ['pattern' => '/(a)(b)(?1(2)){2}/', 'release' => '10.49', 'length' => 39];
        yield 'starred call returning a group, 10.48' => ['pattern' => '/(a)(b)(?1(2))*/', 'release' => '10.48', 'length' => 40];
        yield 'conditional on a callout and an assertion' => ['pattern' => '/(?(?C1)(?=b)c|d)/', 'release' => '10.44', 'length' => 34];
        yield 'counted conditional on a callout and an assertion' => ['pattern' => '/(?(?C1)(?=b)c|d){2}/', 'release' => '10.33', 'length' => 61];
        yield 'empty script run' => ['pattern' => '/(*sr:)/', 'release' => '10.33', 'length' => 13];
        yield 'empty atomic script run' => ['pattern' => '/(*asr:)/', 'release' => '10.45', 'length' => 19];
        yield 'counted empty atomic script run' => ['pattern' => '/(*asr:){2}/', 'release' => '10.49', 'length' => 31];
        yield 'possessive star on an empty atomic script run' => ['pattern' => '/(*asr:)*+/', 'release' => '10.44', 'length' => 26];
        yield 'class holding a space, then under xx' => ['pattern' => '/[a ](?xx)[a ]/', 'release' => '10.48', 'length' => 42];
        yield 'class holding a space under xx, then not' => ['pattern' => '/(?xx)[a ](?-xx)[a ]/', 'release' => '10.48', 'length' => 42];
        yield 'caseless k under UCP without UTF, 10.34' => ['pattern' => '/(*UCP)k/i', 'release' => '10.34', 'length' => 9];
        yield 'caseless k under UCP without UTF, 10.35' => ['pattern' => '/(*UCP)k/i', 'release' => '10.35', 'length' => 10];
        yield 'caseless class of the micro sign in UTF mode, 10.48' => ['pattern' => '/[µx]/iu', 'release' => '10.48', 'length' => 50];
        yield 'caseless class of the a with a ring in UTF mode, 10.48' => ['pattern' => '/[Åx]/iu', 'release' => '10.48', 'length' => 48];
        yield 'condition on the recursion of a name' => ['pattern' => '/(?<n>a)(?(R&n)c)/', 'release' => '10.48', 'length' => 28];
        yield 'condition on the recursion of a name two groups bear' => ['pattern' => '/(?<n>a)|(?<n>b)(?(R&n)c)/J', 'release' => '10.34', 'length' => 43];
        yield 'DEFINE group holding two comments, then a lookbehind, 10.34' => ['pattern' => '/(?(DEFINE)(?#c)(?#d))(?<=a)/', 'release' => '10.34', 'length' => 25];
        // The lists are kept once, whatever the copies of the group.
        yield 'counted group holding a class in lists, 10.45' => ['pattern' => '/(?:[\\x{100}\\x{102}\\x{104}\\x{106}\\x{108}\\x{10a}]){2}/u', 'release' => '10.45', 'length' => 51];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideLengthsNotKnown(): iterable
    {
        // A class that holds every character past 255 writes no property,
        // whose characters below 256 join its map, which may then hold every
        // byte.
        yield 'class of what is not a blank or ASCII, and spaces, under UCP' => ['pattern' => '/[\\x{205f}[:^blank:]É[:space:][:^ascii:]9]/u'];
        // An inline "a" may hold the types of a class to ASCII.
        yield 'class holding a type under ASCII options' => ['pattern' => '/(?aD)[\\dx]/u'];
        yield 'class of every character in POSIX names and a range' => ['pattern' => '/[[:^ascii:][:cntrl:]\x20-\x7e]/'];
        // Without UTF, "é" is two bytes: a range that ends on it is read as
        // one ending on the first, then the second.
        yield 'range ending on a two-byte letter in byte mode' => ['pattern' => '/[é-\\xff]/'];
        // Without UTF, the cases of a byte past ASCII follow the locale PHP
        // builds its tables from.
        // Turkish tables make the other case of "i" a byte past ASCII.
        yield 'class of i and its other case' => ['pattern' => '/[iI]/'];
        yield 'class of every ASCII character in a POSIX name' => ['pattern' => '/[[:ascii:]]/'];
        yield 'class of two bytes past ASCII' => ['pattern' => '/[\\xe9\\xc9]/'];
        yield 'caseless class of every ASCII character and a byte past it' => ['pattern' => '/[\\x00-\\x7f\\xe9]/i'];
        // An inline "a" may hold the types to ASCII under UCP.
        yield 'type under UCP and an inline a' => ['pattern' => '/(?a)\\d/u'];
        yield 'counted type under UCP and an inline a' => ['pattern' => '/(?a)\\d{2}/u'];
        yield 'condition on a name no group holds' => ['pattern' => '/(?(<x>)a|b)/'];
    }

    /**
     * An item whose length is not known for sure counts at its floor, and
     * the pattern has a length only where that floor reaches the most the
     * item may compile to: its ceiling, none for an item of no ceiling.
     */
    #[Test]
    #[DataProvider('provideCeilings')]
    public function test_an_item_of_unknown_length_counts_up_to_its_ceiling(string $pattern, ?int $ceiling): void
    {
        $regex = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => '10.48', 'runtime_pcre_validation' => false])->parse($pattern);
        $source = (string) $regex->source;
        $utf = str_contains($regex->flags, 'u') || StartOptions::turnUtfOn($source);
        $ucp = str_contains($regex->flags, 'u') || \in_array('UCP', StartOptions::items($source), true);

        $known = [];
        foreach (range(0, 40) as $floor) {
            if (null !== (new CompiledLength(static fn (): int => $floor, $source, $utf, $ucp, $regex->flags, new PcreTarget(80400, '10.48')))->of($regex->pattern)) {
                $known[] = $floor;
            }
        }

        $this->assertSame(null === $ceiling ? [] : [$ceiling], $known, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, ceiling: int|null}>
     */
    public static function provideCeilings(): iterable
    {
        yield 'character past 255 without UTF' => ['pattern' => '/\\x{100}/', 'ceiling' => null];
        yield 'type under UCP and an inline a' => ['pattern' => '/(?a)\\d/u', 'ceiling' => null];
    }

    /**
     * PCRE2 counts an item before the count after it is read: one counted
     * zero times keeps its length against the limit, though it is dropped
     * from the compiled pattern (pcre2test 10.49: the first of these
     * compiles to 65534 units, the second is too large).
     */
    #[Test]
    public function test_an_item_counted_zero_times_keeps_its_length_against_the_limit(): void
    {
        $this->assertSame(65536, self::lengthOf('/.'.str_repeat('a', 32763).'b{0}/'));
        $this->assertNull(self::lengthOf('/'.str_repeat('a', 32764).'b{0}/'));
    }

    #[Test]
    #[DataProvider('provideWhereTheLimitIsPassed')]
    public function test_where_the_limit_is_passed(string $pattern, bool $insideABranch): void
    {
        $regex = RegexParser::create(['cache' => null, 'runtime_pcre_validation' => false])->parse($pattern);

        $this->assertSame($insideABranch, (new CompiledLength(static fn (): int => 0, (string) $regex->source, false, false, $regex->flags, new PcreTarget(80400, '10.48')))->passedInsideABranch($regex->pattern), $pattern);
    }

    /**
     * Each verified with pcre2test 10.48 and 10.49: offset 0 when the limit
     * is passed inside a branch, the end of the pattern when it is passed
     * where a group ends.
     *
     * @return iterable<string, array{pattern: string, insideABranch: bool}>
     */
    public static function provideWhereTheLimitIsPassed(): iterable
    {
        // An assertion as a condition is measured first, as a group, then as
        // the first item of the conditional.
        yield 'condition past the limit on its own' => ['pattern' => '/(?(?=(?:a){8192})b|c)/', 'insideABranch' => true];
        yield 'condition past the limit with the conditional' => ['pattern' => '/(?(?=(?:a){8191})b)/', 'insideABranch' => true];
        yield 'last branch past the limit with the branch before' => ['pattern' => '/a|(?:b){8191}/', 'insideABranch' => false];
        // A condition whose last branch passes the limit where it ends.
        yield 'condition past the limit where it ends' => ['pattern' => '/(?(?=a|(?:b){8191})c)/', 'insideABranch' => false];
        // A condition that takes the conditional past the limit before its
        // branch, which holds only options.
        yield 'conditional past the limit with its condition' => ['pattern' => '/(?(?=(?:a){8190}aaa)(?i))/', 'insideABranch' => true];
        yield 'only branch of a conditional past the limit' => ['pattern' => '/(a)(?(1)(?:b){8200})/', 'insideABranch' => true];
        yield 'lookbehind past the limit' => ['pattern' => '/(?<=(?:a){8200})/', 'insideABranch' => true];
        yield 'second branch of a condition past the limit with the first' => ['pattern' => '/(?(?=(?:a){4000}|(?:b){4200}|c)x)/', 'insideABranch' => true];
        // The end marker counts against the limit, before a group or a
        // branch is measured: 65536 units are not past it, 65537 are.
        yield 'pattern of one branch one unit past the limit' => ['pattern' => '/'.str_repeat('a', 32765).'/', 'insideABranch' => false];
        yield 'group of 65536 units, past the limit in the branch around it' => ['pattern' => '/(?:(?:a){8190}|abc.)/', 'insideABranch' => true];
        yield 'group of 65537 units, past the limit where it ends' => ['pattern' => '/(?:(?:a){8190}|abcd)/', 'insideABranch' => false];
        yield 'first branch at the limit with the end marker' => ['pattern' => '/(?:a){8190}abc|d/', 'insideABranch' => false];
        yield 'first branch one unit past the limit with the end marker' => ['pattern' => '/(?:a){8190}abc.|d/', 'insideABranch' => true];
        yield 'second of three branches past the limit with the first' => ['pattern' => '/(?:a){4000}|(?:b){4200}|c/', 'insideABranch' => true];
        // A group of branches whose length is known to the unit is not past
        // the limit where it ends.
        yield 'lookbehind of two branches before a repeat past the limit' => ['pattern' => '/(?<=a|b)(?:c){8200}/', 'insideABranch' => true];
        yield 'negative lookbehind of two branches before a repeat past the limit' => ['pattern' => '/(?<!a|b)(?:c){8200}/', 'insideABranch' => true];
        yield 'atomic script run of two branches before a repeat past the limit' => ['pattern' => '/(*asr:(?:a){8189}aa|b)(?:c){8200}/', 'insideABranch' => true];
    }

    private static function lengthOf(string $pattern, string $release = '10.48'): ?int
    {
        $regex = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => $release, 'runtime_pcre_validation' => false])->parse($pattern);
        $source = (string) $regex->source;
        $utf = str_contains($regex->flags, 'u') || StartOptions::turnUtfOn($source);
        $ucp = str_contains($regex->flags, 'u') || \in_array('UCP', StartOptions::items($source), true);

        return (new CompiledLength(static fn (): int => 0, $source, $utf, $ucp, $regex->flags, new PcreTarget(80400, $release)))->of($regex->pattern);
    }
}
