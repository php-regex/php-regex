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
    #[DataProvider('provideLengthsNotKnown')]
    public function test_a_length_not_known_for_sure_is_left_out(string $pattern): void
    {
        $this->assertNull(self::lengthOf($pattern), $pattern);
    }

    #[Test]
    public function test_a_pattern_past_the_limit_has_no_length(): void
    {
        $this->assertNull(self::lengthOf('/(?:a){8192}/'));
        $this->assertSame(65535, self::lengthOf('/(?:a){8191}/'));
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
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideLengthsNotKnown(): iterable
    {
        // Each branch of a lookbehind starts with the length to move back.
        yield 'lookbehind' => ['pattern' => '/(?<=a)b/'];
        // A caseless class past ASCII may hold the other cases of its letters.
        yield 'caseless class of a letter past ASCII and its other case' => ['pattern' => '/[éÉ]/iu'];
        yield 'class past 255 in UTF mode' => ['pattern' => '/[\x{100}a]/u'];
        yield 'class holding a type under UCP' => ['pattern' => '/[\d]/u'];
        yield 'class of every character' => ['pattern' => '/[\d\D]/'];
        yield 'class of every character in POSIX names and a range' => ['pattern' => '/[[:^ascii:][:cntrl:]\x20-\x7e]/'];
        yield 'class of every character in two vertical space types' => ['pattern' => '/[\v\V]/'];
        yield 'string callout' => ['pattern' => '/(?C"ab")/'];
        // A name may stand for several groups.
        yield 'reference by name' => ['pattern' => '/(?<n>a)\k<n>/'];
        // Rules that change the cases of "i", "k" and "s".
        yield 'caseless k under rules kept to ASCII' => ['pattern' => '/(*CASELESS_RESTRICT)k/iu'];
        yield 'class of i and its other case under Turkish rules' => ['pattern' => '/(*TURKISH_CASING)[iI]/u'];
        yield 'quoted caseless letters under rules kept to ASCII' => ['pattern' => '/(*CASELESS_RESTRICT)\\Qks\\E/iu'];
        yield 'counted class past 255 in UTF mode' => ['pattern' => '/[\\x{100}a]{2}/u'];
        // Without UTF, "é" is two bytes: a range that ends on it is read as
        // one ending on the first, then the second.
        yield 'range ending on a two-byte letter in byte mode' => ['pattern' => '/[é-\\xff]/'];
        yield 'class holding a property' => ['pattern' => '/[\\p{L}a]/'];
        // "(?!)" compiles to a failure, and so may a lookaround holding
        // nothing but options and comments.
        yield 'lookahead holding only options and a comment' => ['pattern' => '/(?=(?i)(?#c))/'];
        // A counted verb is wrapped in a group, a named accept is a mark too.
        yield 'counted accept' => ['pattern' => '/(*ACCEPT)?/'];
        yield 'accept with a name' => ['pattern' => '/(*ACCEPT:x)/'];
        // A script run and a conditional on a version are not counted.
        yield 'counted script run' => ['pattern' => '/(*sr:a){2}/'];
        // Without UTF, the cases of a byte past ASCII follow the locale PHP
        // builds its tables from.
        // Turkish tables make the other case of "i" a byte past ASCII.
        yield 'class of i and its other case' => ['pattern' => '/[iI]/'];
        yield 'class of every ASCII character in a POSIX name' => ['pattern' => '/[[:ascii:]]/'];
        // A character past 255 whose low byte is a letter's other case is no
        // case pair: the class holds it past the map (47 units).
        yield 'class of a letter and a character past 255 sharing its low byte' => ['pattern' => '/[a\\x{161}]/u'];
        yield 'class of two bytes past ASCII' => ['pattern' => '/[\\xe9\\xc9]/'];
        yield 'caseless class of every ASCII character and a byte past it' => ['pattern' => '/[\\x00-\\x7f\\xe9]/i'];
        // A class of thousands of types is not measured member by member.
        yield 'class of three thousand types' => ['pattern' => '/['.str_repeat('\\D', 3000).']/'];
        yield 'counted conditional on a version' => ['pattern' => '/(?(VERSION>=10.0)a){2}/'];
    }

    #[Test]
    #[DataProvider('provideWhereTheLimitIsPassed')]
    public function test_where_the_limit_is_passed(string $pattern, bool $insideABranch): void
    {
        $regex = RegexParser::create(['cache' => null, 'runtime_pcre_validation' => false])->parse($pattern);

        $this->assertSame($insideABranch, (new CompiledLength(static fn (): int => 0, (string) $regex->source, false, false, $regex->flags))->passedInsideABranch($regex->pattern), $pattern);
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
    }

    private static function lengthOf(string $pattern): ?int
    {
        $regex = RegexParser::create(['cache' => null, 'runtime_pcre_validation' => false])->parse($pattern);
        $source = (string) $regex->source;
        $utf = str_contains($regex->flags, 'u') || StartOptions::turnUtfOn($source);
        $ucp = str_contains($regex->flags, 'u') || \in_array('UCP', StartOptions::items($source), true);

        return (new CompiledLength(static fn (): int => 0, $source, $utf, $ucp, $regex->flags))->of($regex->pattern);
    }
}
