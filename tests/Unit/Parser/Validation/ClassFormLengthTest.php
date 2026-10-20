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

use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ClassSetOperationNode;
use PHPRegex\Parser\Node\ClassSetOperator;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Validation\CompiledLength;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The length the first pass of each release counts for a substring scan,
 * an extended class "(?[...])" and a class of hundreds of members, its end
 * marker included: each row is the "code size" pcre2test of that release
 * prints for the pattern with the "memory" modifier, its source changed so
 * that the length it checks against the limit is the one kept ("u" read as
 * "utf" and "ucp"). Where a row names one release, the releases around it
 * that print the same are noted beside it.
 */
final class ClassFormLengthTest extends TestCase
{
    #[Test]
    #[DataProvider('provideLengths')]
    public function test_the_length_is_the_one_each_release_compiles(string $pattern, string $release, int $length): void
    {
        $this->assertSame($length, self::lengthOf($pattern, $release), \sprintf('%s on PCRE2 %s', $pattern, $release));
    }

    /**
     * The first count PCRE2 refuses and the one before it, on the release
     * the parser targets (pcre2test of each release, error 120): reported
     * at the end of the pattern up to 10.46, at offset 0 from 10.47.
     */
    #[Test]
    #[DataProvider('provideLimits')]
    public function test_the_verdict_is_the_one_each_release_gives_at_the_limit(string $template, string $flags, string $release, int $largest, int $offset): void
    {
        $parser = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => $release, 'runtime_pcre_validation' => false]);

        $compiles = $parser->validate('/'.\sprintf($template, $largest).'/'.$flags);
        $this->assertTrue($compiles->isValid, \sprintf('%s compiles on PCRE2 %s: %s', \sprintf($template, $largest), $release, (string) $compiles->error));

        $refused = $parser->validate('/'.\sprintf($template, $largest + 1).'/'.$flags);
        $this->assertSame(ErrorCode::PatternTooLarge, $refused->errorCode, \sprintf('%s on PCRE2 %s', \sprintf($template, $largest + 1), $release));
        $this->assertSame(-1 === $offset ? \strlen(\sprintf($template, $largest + 1)) : $offset, $refused->offset);
    }

    /**
     * Whether a counted extended class is a match of any character may
     * follow what a property holds below 256, or without UTF what the
     * locale holds past ASCII where its tables are not given; a name no
     * group bears is refused before any measure.
     */
    #[Test]
    #[DataProvider('provideLengthsNotKnown')]
    public function test_a_length_not_known_for_sure_is_left_out(string $pattern): void
    {
        $this->assertNull(self::lengthOf($pattern, '10.49'), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideLengthsNotKnown(): iterable
    {
        yield 'counted class that may hold every character but for a property' => ['pattern' => '/(?[ [^a] | !\\D ]){2}/u'];
        yield 'counted class that may hold every byte but for the locale' => ['pattern' => '/(?[ \\d ^ \\D ]){2}/'];
        yield 'substring scan of a name no group bears' => ['pattern' => '/(*scs:(<x>)a)/'];
        // Without UTF, what the locale holds past ASCII may complete a map.
        yield 'caseless classes of every byte, that of ASCII first' => ['pattern' => '/(?[ [\\x00-\\x7f] | [\\x80-\\xff] ])/i'];
        yield 'counted caseless class that may hold every byte but for the locale' => ['pattern' => '/(?[ [^\\x00-\\x7e] | [\\x00-\\x7e] ]){2}/i'];
        yield 'counted union of what may hold every character without UTF' => ['pattern' => '/(?[ [^a] | !\\p{Lu} ]){2}/i'];
        yield 'counted intersection with every character of what may hold every character' => ['pattern' => '/(?[ ( [^a] | !\\D ) & \\p{Any} ]){2}/u'];
    }

    /**
     * Under the tables of the locale, here those PCRE2 builds in, what a
     * class without UTF holds past ASCII is known (pcre2test 10.49, whose
     * tables they are).
     */
    #[Test]
    #[DataProvider('provideLengthsUnderTheTablesOfTheLocale')]
    public function test_the_length_is_known_under_the_tables_of_the_locale(string $pattern, int $length): void
    {
        $this->assertSame($length, self::lengthOf($pattern, '10.49', CharacterTables::builtIn()), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, length: int}>
     */
    public static function provideLengthsUnderTheTablesOfTheLocale(): iterable
    {
        yield 'counted class of every byte, a type and its negation' => ['pattern' => '/(?[ \\d ^ \\D ]){2}/', 'length' => 80];
        yield 'caseless classes of every byte, that of ASCII first' => ['pattern' => '/(?[ [\\x00-\\x7f] | [\\x80-\\xff] ])/i', 'length' => 77];
        yield 'counted caseless class of every byte' => ['pattern' => '/(?[ [^\\x00-\\x7e] | [\\x00-\\x7e] ]){2}/i', 'length' => 80];
    }

    /**
     * An extended class built without a pattern, as one read apart to find
     * the first error of its operands, does not tell a single item from one
     * in parentheses: it has no length; an operation has one.
     */
    #[Test]
    public function test_an_extended_class_without_its_text_has_a_length_only_for_an_operation(): void
    {
        $item = new ExtendedCharClassNode(new CharTypeNode('d', 4, 6), 0, 9);
        $operation = new ExtendedCharClassNode(new ClassSetOperationNode(ClassSetOperator::Union, new CharTypeNode('d', 4, 6), new CharTypeNode('w', 9, 11), '+', 4, 11), 0, 14);
        $length = new CompiledLength(static fn (): int => 0, '', false, false, '', new PcreTarget(80400, '10.49'));

        $this->assertNull($length->of($item));
        $this->assertSame(77, $length->of($operation));
    }

    /**
     * Up to 10.47 a class checks its lists against the limit as it is
     * compiled, with what its group counts before it, its header and its
     * properties; an operand of an extended class with what the class
     * counts before it too: on 10.47 a pattern that check refuses is
     * reported at its end, by a check that sets no offset, and any other
     * pattern too large at offset 0 (pcre2test 10.47).
     */
    #[Test]
    #[DataProvider('provideListsChecks')]
    public function test_a_class_whose_lists_pass_the_limit_is_reported_where_10_47_reports_it(string $pattern, int $offset): void
    {
        $result = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => '10.47', 'runtime_pcre_validation' => false])->validate($pattern);

        $this->assertSame(ErrorCode::PatternTooLarge, $result->errorCode);
        $this->assertSame(-1 === $offset ? \strlen($pattern) - 3 : $offset, $result->offset);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideListsChecks(): iterable
    {
        $lists = '[\\x{100}\\x{102}\\x{104}\\x{106}\\x{108}\\x{10a}]';
        // A counted group brings each pattern near the limit; an offset of
        // -1 stands for the end of the pattern.
        $near = static fn (int $letters): string => '/(?:a){8183}'.str_repeat('a', $letters);
        yield 'class of properties in lists, one letter short' => ['pattern' => $near(8).'['.str_repeat('\\p{L}', 10).substr($lists, 1).'/u', 'offset' => 0];
        yield 'class of properties in lists' => ['pattern' => $near(9).'['.str_repeat('\\p{L}', 10).substr($lists, 1).'/u', 'offset' => -1];
        yield 'class of properties in lists, one unit past' => ['pattern' => $near(8).'.['.str_repeat('\\p{L}', 10).substr($lists, 1).'/u', 'offset' => -1];
        yield 'class in lists inside a group' => ['pattern' => $near(24).'(?:'.$lists.')/u', 'offset' => 0];
        yield 'operand in lists, one letter short' => ['pattern' => $near(19).'(?[ \\p{N} + '.$lists.' ])/u', 'offset' => 0];
        yield 'operand in lists' => ['pattern' => $near(20).'(?[ \\p{N} + '.$lists.' ])/u', 'offset' => -1];
        yield 'second operand in lists, one letter short' => ['pattern' => $near(14).'(?[ '.$lists.' + \\p{N} - '.$lists.' ])/u', 'offset' => 0];
        yield 'second operand in lists' => ['pattern' => $near(15).'(?[ '.$lists.' + \\p{N} - '.$lists.' ])/u', 'offset' => -1];
        // Only an operand in lists is checked, as it is written.
        yield 'operand in lists before two others, one letter short' => ['pattern' => $near(23).'(?[ '.$lists.' + [a] + [b] ])/u', 'offset' => 0];
        yield 'operand in lists before two others' => ['pattern' => $near(24).'(?[ '.$lists.' + [a] + [b] ])/u', 'offset' => -1];
        yield 'operand of properties in lists, one letter short' => ['pattern' => $near(8).'(?[ ['.str_repeat('\\p{L}', 10).substr($lists, 1).' + [a] ])/u', 'offset' => 0];
        yield 'operand of properties in lists' => ['pattern' => $near(9).'(?[ ['.str_repeat('\\p{L}', 10).substr($lists, 1).' + [a] ])/u', 'offset' => -1];
    }

    /**
     * @return iterable<string, array{pattern: string, release: string, length: int}>
     */
    public static function provideLengths(): iterable
    {
        // A substring scan is a group that lists the captures it reads, a
        // number each. Up to 10.46 each item is written, a name several
        // groups bear as an index and a count; from 10.47 each group once,
        // in the order listed, and a name several groups bear only while one
        // of them is not listed yet.
        yield 'substring scan of one group, 10.45' => ['pattern' => '/(a)(*scs:(1)b)/', 'release' => '10.45', 'length' => 28];
        yield 'substring scan of one group, long spelling, 10.49' => ['pattern' => '/(a)(*scan_substring:(1)b)/', 'release' => '10.49', 'length' => 28];
        yield 'substring scan of no body, 10.48' => ['pattern' => '/(a)(*scs:(1))/', 'release' => '10.48', 'length' => 26];
        yield 'substring scan listing a group twice, 10.46' => ['pattern' => '/(a)(*scs:(1,1)b)/', 'release' => '10.46', 'length' => 31];
        yield 'substring scan listing a group twice, 10.47' => ['pattern' => '/(a)(*scs:(1,1)b)/', 'release' => '10.47', 'length' => 28];
        yield 'substring scan listing a group again after another, 10.45' => ['pattern' => '/(a)(b)(*scs:(1,2,1)c)/', 'release' => '10.45', 'length' => 44];
        yield 'substring scan listing a group again after another, 10.48' => ['pattern' => '/(a)(b)(*scs:(1,2,1)c)/', 'release' => '10.48', 'length' => 41];
        yield 'substring scan of the group before it, 10.49' => ['pattern' => '/(a)(*scs:(-1)b)/', 'release' => '10.49', 'length' => 28];
        yield 'substring scan of the group after it, 10.45' => ['pattern' => '/(*scs:(+1)b)(a)/', 'release' => '10.45', 'length' => 28];
        yield 'substring scan of a name and its number, 10.45' => ['pattern' => '/(?<n>a)(*scs:(<n>,1)b)/', 'release' => '10.45', 'length' => 31];
        yield 'substring scan of a name and its number, 10.47' => ['pattern' => '/(?<n>a)(*scs:(<n>,1)b)/', 'release' => '10.47', 'length' => 28];
        yield 'substring scan of a name in quotes, 10.49' => ['pattern' => "/(?<n>a)(*scs:('n')b)/", 'release' => '10.49', 'length' => 28];
        yield 'substring scan of a name two groups bear, 10.45' => ['pattern' => '/(?<n>a)(?<n>b)(*scs:(<n>)c)/J', 'release' => '10.45', 'length' => 40];
        yield 'substring scan of a name two groups bear, 10.49' => ['pattern' => '/(?<n>a)(?<n>b)(*scs:(<n>)c)/J', 'release' => '10.49', 'length' => 40];
        yield 'substring scan of a name two groups bear after their numbers, 10.46' => ['pattern' => '/(?<n>a)(?<n>b)(*scs:(1,2,<n>)c)/J', 'release' => '10.46', 'length' => 46];
        yield 'substring scan of a name two groups bear after their numbers, 10.47' => ['pattern' => '/(?<n>a)(?<n>b)(*scs:(1,2,<n>)c)/J', 'release' => '10.47', 'length' => 41];
        yield 'substring scan of a name two groups bear after one number, 10.48' => ['pattern' => '/(?<n>a)(?<n>b)(*scs:(1,<n>)c)/J', 'release' => '10.48', 'length' => 43];
        yield 'substring scan of a name two groups bear before a number, 10.49' => ['pattern' => '/(?<n>a)(?<n>b)(*scs:(<n>,1)c)/J', 'release' => '10.49', 'length' => 40];
        yield 'substring scan of a name two groups bear, listed twice, 10.45' => ['pattern' => '/(?<n>a)(?<n>b)(*scs:(<n>,<n>)c)/J', 'release' => '10.45', 'length' => 45];
        yield 'substring scan of a name two groups bear, listed twice, 10.49' => ['pattern' => '/(?<n>a)(?<n>b)(*scs:(<n>,<n>)c)/J', 'release' => '10.49', 'length' => 40];
        yield 'substring scan of a name two branches of one number bear, 10.49' => ['pattern' => '/(?|(?<n>a)|(?<n>b))(*scs:(<n>)c)/', 'release' => '10.49', 'length' => 47];
        yield 'substring scan of the group after it and that group, 10.45' => ['pattern' => '/(a)(*scs:(+1,2)b)(c)/', 'release' => '10.45', 'length' => 41];
        yield 'substring scan of the group after it and that group, 10.47' => ['pattern' => '/(a)(*scs:(+1,2)b)(c)/', 'release' => '10.47', 'length' => 38];
        yield 'substring scan of the group before it and that group, 10.48' => ['pattern' => '/(a)(*scs:(-1,1)b)/', 'release' => '10.48', 'length' => 28];
        yield 'substring scan of a name in quotes and its number, 10.49' => ['pattern' => "/(?<n>a)(*scs:('n',1)b)/", 'release' => '10.49', 'length' => 28];
        yield 'substring scan of a number and its zero-padded spelling, 10.47' => ['pattern' => '/(a)(*scs:(1,01)b)/', 'release' => '10.47', 'length' => 28];
        yield 'substring scan of two names, 10.46' => ['pattern' => '/(?<n>a)(?<m>b)(*scs:(<n>,<m>)c)/', 'release' => '10.46', 'length' => 41];
        yield 'substring scan of two branches, 10.49' => ['pattern' => '/(a)(*scs:(1)b|c)/', 'release' => '10.49', 'length' => 33];
        yield 'counted substring scan, 10.47' => ['pattern' => '/(a)(*scs:(1)b){2}/', 'release' => '10.47', 'length' => 39];
        yield 'optional substring scan, 10.45' => ['pattern' => '/(a)(*scs:(1)b)?/', 'release' => '10.45', 'length' => 29];
        // An open count repeats it as an assertion: once more than its least.
        yield 'substring scan counted once or more, 10.46' => ['pattern' => '/(a)(*scs:(1)b)+/', 'release' => '10.46', 'length' => 40];
        yield 'substring scan counted twice or more, 10.49' => ['pattern' => '/(a)(*scs:(1)b){2,}/', 'release' => '10.49', 'length' => 51];

        // An extended class of one item neither in brackets nor in
        // parentheses is the class of that item (10.45 to 10.49 alike).
        yield 'extended class of a type, 10.45' => ['pattern' => '/(?[ \\d ])/', 'release' => '10.45', 'length' => 40];
        yield 'extended class of a property, 10.49' => ['pattern' => '/(?[ \\p{L} ])/u', 'release' => '10.49', 'length' => 15];
        yield 'extended class of one character, 10.48' => ['pattern' => '/(?[ \\x{41} ])/', 'release' => '10.48', 'length' => 9];
        yield 'extended class of every character, 10.49' => ['pattern' => '/(?[ \\p{Any} ])/u', 'release' => '10.49', 'length' => 8];
        // Any other is its header, then each operand as a class without its
        // map, an operator where both sides are neither every character nor
        // none above 255, then a map where one is needed: its length is the
        // longer of what that pass counts and what the class is written as.
        yield 'extended class of a type in parentheses, 10.45' => ['pattern' => '/(?[ ( \\d ) ])/', 'release' => '10.45', 'length' => 44];
        yield 'extended class of a class, 10.49' => ['pattern' => '/(?[ [a-z] ])/i', 'release' => '10.49', 'length' => 44];
        yield 'extended class of a negated class, 10.46' => ['pattern' => '/(?[ [^a-z] ])/', 'release' => '10.46', 'length' => 44];
        yield 'extended class of a negated type, 10.47' => ['pattern' => '/(?[ !\\d ])/', 'release' => '10.47', 'length' => 44];
        yield 'union of two types, 10.45' => ['pattern' => '/(?[ \\d + \\w ])/', 'release' => '10.45', 'length' => 77];
        yield 'difference of two types, 10.49' => ['pattern' => '/(?[ \\d - \\w ])/', 'release' => '10.49', 'length' => 77];
        yield 'intersection of two types, 10.48' => ['pattern' => '/(?[ \\d & \\w ])/', 'release' => '10.48', 'length' => 77];
        yield 'symmetric difference of two types, 10.46' => ['pattern' => '/(?[ \\d ^ \\w ])/', 'release' => '10.46', 'length' => 77];
        yield 'union of two POSIX names, 10.47' => ['pattern' => '/(?[ [:alpha:] + [:digit:] ])/', 'release' => '10.47', 'length' => 77];
        yield 'union of two properties, 10.45' => ['pattern' => '/(?[ \\p{L} + \\p{N} ])/u', 'release' => '10.45', 'length' => 28];
        yield 'property less a class below 256, 10.49' => ['pattern' => '/(?[ \\p{L} - [a-z] ])/u', 'release' => '10.49', 'length' => 52];
        yield 'class below 256 and a range past it, 10.45' => ['pattern' => '/(?[ [a-z] + [\\x{100}-\\x{200}] ])/u', 'release' => '10.45', 'length' => 54];
        yield 'intersection of a class below 256 and a range past it, 10.48' => ['pattern' => '/(?[ [a-z] & [\\x{100}-\\x{200}] ])/u', 'release' => '10.48', 'length' => 54];
        yield 'counted intersection of a class below 256 and a range past it, 10.49' => ['pattern' => '/(?[ [a-z] & [\\x{100}-\\x{200}] ]){2}/u', 'release' => '10.49', 'length' => 59];
        yield 'symmetric difference of two characters past 255, 10.45' => ['pattern' => '/(?[ [\\x{100}] ^ [\\x{200}] ])/u', 'release' => '10.45', 'length' => 28];
        yield 'negated symmetric difference of two characters past 255, 10.49' => ['pattern' => '/(?[ !([\\x{100}] ^ [\\x{200}]) ])/u', 'release' => '10.49', 'length' => 29];
        yield 'negated difference of two characters past 255, 10.46' => ['pattern' => '/(?[ !([\\x{100}] - [\\x{200}]) ])/u', 'release' => '10.46', 'length' => 28];
        yield 'operations nested in parentheses, 10.47' => ['pattern' => '/(?[ ( [a] + [b] ) & !( [c] - \\x{100} ) ])/u', 'release' => '10.47', 'length' => 118];
        yield 'negated POSIX name and a type under UCP, 10.48' => ['pattern' => '/(?[ [:^alpha:] & \\d ])/u', 'release' => '10.48', 'length' => 28];
        yield 'horizontal spaces less one, 10.45' => ['pattern' => '/(?[ \\h - \\x{a0} ])/u', 'release' => '10.45', 'length' => 68];
        yield 'caseless class of a letter of three cases, 10.49' => ['pattern' => '/(?[ [k] ])/iu', 'release' => '10.49', 'length' => 48];
        yield 'caseless classes of Latin-1 letters, 10.46' => ['pattern' => '/(?[ [é] + [ÿ] ])/iu', 'release' => '10.46', 'length' => 52];
        // A class of no byte adds no map; one of none past 255 drops what is
        // joined to it.
        // A symmetric difference with every character or none turns the
        // other side over.
        yield 'symmetric difference of a type and its negation, 10.45' => ['pattern' => '/(?[ \\d ^ \\D ])/', 'release' => '10.45', 'length' => 77];
        yield 'symmetric difference of a negated type with itself, 10.49' => ['pattern' => '/(?[ \\D ^ \\D ])/', 'release' => '10.49', 'length' => 77];
        // A caseless operand past 255 takes its other cases from the Unicode
        // tables of the release.
        yield 'caseless operand past 255 first, 10.45' => ['pattern' => '/(?[ [\\x{100}] + \\d ])/iu', 'release' => '10.45', 'length' => 30];
        yield 'caseless operand past 255 second, 10.49' => ['pattern' => '/(?[ \\d + [\\x{100}] ])/iu', 'release' => '10.49', 'length' => 30];
        // An operation with every character or none keeps one side, or every
        // character or none, which count no operation.
        yield 'intersection with every character after, 10.45' => ['pattern' => '/(?[ \\p{L} & \\p{Any} ])/u', 'release' => '10.45', 'length' => 20];
        yield 'intersection with every character before, 10.49' => ['pattern' => '/(?[ \\p{Any} & \\p{L} ])/u', 'release' => '10.49', 'length' => 20];
        yield 'intersection with no character after, 10.46' => ['pattern' => '/(?[ \\p{L} & \\P{Any} ])/u', 'release' => '10.46', 'length' => 52];
        yield 'intersection with no character before, 10.47' => ['pattern' => '/(?[ \\P{Any} & \\p{L} ])/u', 'release' => '10.47', 'length' => 52];
        yield 'union with no character after, 10.48' => ['pattern' => '/(?[ \\p{L} | \\P{Any} ])/u', 'release' => '10.48', 'length' => 52];
        yield 'union with no character before, 10.45' => ['pattern' => '/(?[ \\P{Any} | \\p{L} ])/u', 'release' => '10.45', 'length' => 52];
        yield 'union with every character after, 10.49' => ['pattern' => '/(?[ \\p{L} | \\p{Any} ])/u', 'release' => '10.49', 'length' => 20];
        yield 'symmetric difference with no character after, 10.46' => ['pattern' => '/(?[ \\p{L} ^ \\P{Any} ])/u', 'release' => '10.46', 'length' => 52];
        yield 'symmetric difference with no character before, 10.47' => ['pattern' => '/(?[ \\P{Any} ^ \\p{L} ])/u', 'release' => '10.47', 'length' => 52];
        yield 'symmetric difference with every character after, 10.48' => ['pattern' => '/(?[ \\p{L} ^ \\p{Any} ])/u', 'release' => '10.48', 'length' => 20];
        yield 'symmetric difference with every character before, 10.45' => ['pattern' => '/(?[ \\p{Any} ^ \\p{L} ])/u', 'release' => '10.45', 'length' => 20];
        yield 'operation turned over by every character after, 10.49' => ['pattern' => '/(?[ ( \\p{L} ^ \\p{N} ) ^ \\p{Any} ])/u', 'release' => '10.49', 'length' => 30];
        yield 'operation turned over by every character before, 10.46' => ['pattern' => '/(?[ \\p{Any} ^ ( \\p{L} ^ \\p{N} ) ])/u', 'release' => '10.46', 'length' => 30];
        yield 'counted union of no character and a property, 10.47' => ['pattern' => '/(?[ ( \\p{Any} ^ \\p{Any} ) | \\p{L} ]){2}/u', 'release' => '10.47', 'length' => 26];
        yield 'counted symmetric difference of no character and every one, 10.48' => ['pattern' => '/(?[ \\P{Any} ^ \\p{Any} ]){2}/u', 'release' => '10.48', 'length' => 48];
        yield 'counted negated symmetric difference of every character with itself, 10.49' => ['pattern' => '/(?[ !( \\p{Any} ^ \\p{Any} ) ]){2}/u', 'release' => '10.49', 'length' => 80];
        yield 'symmetric difference of two classes of every byte, 10.45' => ['pattern' => '/(?[ [\\x00-\\xff] ^ [\\x00-\\xff] ])/', 'release' => '10.45', 'length' => 40];
        yield 'counted union of what is past ASCII and ASCII, 10.46' => ['pattern' => '/(?[ [^\\x00-\\x7f] | [\\x00-\\x7f] ]){2}/', 'release' => '10.46', 'length' => 80];
        yield 'type and its negation once to three times, 10.47' => ['pattern' => '/(?[ \\D | \\d ]){1,3}/', 'release' => '10.47', 'length' => 81];
        // An operation of two classes past 255 that hold a byte each is
        // written with their map, longer than what was counted.
        yield 'symmetric difference of two classes with a map, 10.48' => ['pattern' => '/(?[ [a\\x{100}] ^ [b\\x{200}] ])/u', 'release' => '10.48', 'length' => 60];
        yield 'negated symmetric difference of two classes with a map, 10.49' => ['pattern' => '/(?[ !( [a\\x{100}] ^ [b\\x{200}] ) ])/u', 'release' => '10.49', 'length' => 61];
        yield 'negated symmetric difference joined to no character, 10.45' => ['pattern' => '/(?[ !( \\p{L} ^ \\p{N} ) | \\P{Any} ])/u', 'release' => '10.45', 'length' => 62];
        yield 'negated intersection, 10.46' => ['pattern' => '/(?[ !( \\p{L} & \\p{Lu} ) ])/u', 'release' => '10.46', 'length' => 28];
        yield 'negated difference less a letter, 10.47' => ['pattern' => '/(?[ !( \\p{L} - [a] ) ])/u', 'release' => '10.47', 'length' => 52];
        yield 'counted negated difference less a letter, 10.45' => ['pattern' => '/(?[ !( \\p{L} - [a] ) ]){2}/u', 'release' => '10.45', 'length' => 57];
        yield 'counted negated symmetric difference of two letters, 10.49' => ['pattern' => '/(?[ !( [a] ^ [b] ) ]){2}/', 'release' => '10.49', 'length' => 82];
        yield 'negated union with a letter, 10.48' => ['pattern' => '/(?[ !( \\p{L} | [a] ) ])/u', 'release' => '10.48', 'length' => 52];
        yield 'type in parentheses written without spaces, 10.49' => ['pattern' => '/(?[(\\d)])/', 'release' => '10.49', 'length' => 44];
        yield 'class written caseless and not, 10.45' => ['pattern' => '/(?[ [k] ])(?i:(?[ [k] ]))/u', 'release' => '10.45', 'length' => 91];
        // Whether a map of what the locale or a property holds sets a
        // byte changes nothing: it counted 33 units.
        yield 'class of what the locale holds past ASCII and a property, 10.47' => ['pattern' => '/(?[ [^\\W\\x00-\\x7f] + \\p{L} ])/', 'release' => '10.47', 'length' => 52];
        yield 'class that may hold every character but for a property, 10.48' => ['pattern' => '/(?[ [^a] | !\\D ])/u', 'release' => '10.48', 'length' => 52];
        yield 'class of no byte joined to a letter, 10.45' => ['pattern' => '/(?[ [^\\d\\D] + [a] ])/', 'release' => '10.45', 'length' => 77];
        yield 'letter joined to the class of no character, 10.49' => ['pattern' => '/(?[ [a] + \\P{Any} ])/u', 'release' => '10.49', 'length' => 77];
        // Every character: a match of any character after the length that
        // pass counted, which a count repeats as it does a type.
        yield 'class of every byte, 10.45' => ['pattern' => '/(?[ [\\x00-\\xff] ])/', 'release' => '10.45', 'length' => 12];
        yield 'class of every byte in UTF mode, 10.48' => ['pattern' => '/(?[ [\\x00-\\xff] ])/u', 'release' => '10.48', 'length' => 44];
        yield 'union of two classes of every byte, 10.49' => ['pattern' => '/(?[ [\\x00-\\xff] | [\\x00-\\xff] ])/', 'release' => '10.49', 'length' => 13];
        yield 'type and its negation, 10.47' => ['pattern' => '/(?[ \\D | \\d ])/', 'release' => '10.47', 'length' => 77];
        yield 'counted type and its negation, 10.45' => ['pattern' => '/(?[ \\D | \\d ]){3}/', 'release' => '10.45', 'length' => 80];
        yield 'type and its negation, any number of times, 10.49' => ['pattern' => '/(?[ \\D | \\d ])*/', 'release' => '10.49', 'length' => 78];
        yield 'every character and a property, 10.46' => ['pattern' => '/(?[ \\p{Any} + \\p{L} ])/u', 'release' => '10.46', 'length' => 20];
        yield 'every character and a property, counted, 10.48' => ['pattern' => '/(?[ \\p{Any} + \\p{L} ]){2}/u', 'release' => '10.48', 'length' => 23];
        // A count repeats a class written as a map, or an extended class,
        // as it repeats any class.
        yield 'counted negated class, 10.45' => ['pattern' => '/(?[ [^a] ]){2}/', 'release' => '10.45', 'length' => 49];
        yield 'counted class, 10.49' => ['pattern' => '/(?[ [a] ]){2}/', 'release' => '10.49', 'length' => 49];
        yield 'counted property in parentheses, 10.46' => ['pattern' => '/(?[ ( \\p{L} ) ]){2}/u', 'release' => '10.46', 'length' => 24];
        yield 'counted union of two classes, 10.47' => ['pattern' => '/(?[ [a] + [b] ]){2}/', 'release' => '10.47', 'length' => 82];
        yield 'counted union of two maps of every byte together, 10.48' => ['pattern' => '/(?[ [\\x00-\\x7f] | [\\x80-\\xff] ]){2}/', 'release' => '10.48', 'length' => 82];
        yield 'counted union of two maps of every byte together in UTF mode, 10.49' => ['pattern' => '/(?[ [\\x00-\\x7f] | [\\x80-\\xff] ]){2}/u', 'release' => '10.49', 'length' => 82];
        yield 'counted union of a property and every byte, 10.45' => ['pattern' => '/(?[ \\p{L} | [\\x00-\\xff] ]){2}/u', 'release' => '10.45', 'length' => 57];
        yield 'counted class of every byte but one, 10.46' => ['pattern' => '/(?[ [\\x00-\\xff] - [a] ]){2}/', 'release' => '10.46', 'length' => 50];
        yield 'counted union of the last byte and every other, 10.47' => ['pattern' => '/(?[ [^\\x00-\\xfe] | [\\xff] ]){2}/', 'release' => '10.47', 'length' => 82];
        yield 'counted caseless union of the last byte and every other, 10.48' => ['pattern' => '/(?[ [^\\x00-\\xfe] + [\\xff] ]){2}/i', 'release' => '10.48', 'length' => 82];
        yield 'every character past 255 less a letter, 10.49' => ['pattern' => '/(?[ [\\x{100}-\\x{10ffff}] - [a] ])/u', 'release' => '10.49', 'length' => 77];
        // Six ranges past 255 or more go in lists kept beside the code.
        yield 'class in lists and a property, 10.45' => ['pattern' => '/(?[ [\\x{100}\\x{102}\\x{104}\\x{106}\\x{108}\\x{10a}] + \\p{N} ])/u', 'release' => '10.45', 'length' => 44];
        yield 'union of two classes in lists, 10.46' => ['pattern' => '/(?[ [\\x{100}\\x{102}\\x{104}\\x{106}\\x{108}\\x{10a}] + [\\x{200}\\x{202}\\x{204}\\x{206}\\x{208}\\x{20a}] ])/u', 'release' => '10.46', 'length' => 60];
        yield 'class in lists before an extended class in lists, 10.47' => ['pattern' => '/[\\x{300}\\x{302}\\x{304}\\x{306}\\x{308}\\x{30a}](?[ [\\x{100}\\x{102}\\x{104}\\x{106}\\x{108}\\x{10a}] + \\p{N} ])/u', 'release' => '10.47', 'length' => 68];
        yield 'class in lists less a character, 10.49' => ['pattern' => '/(?[ [\\x{100}\\x{102}\\x{104}\\x{106}\\x{108}\\x{10a}] - [\\x{102}] ])/u', 'release' => '10.49', 'length' => 44];

        // A class of hundreds of members is measured as one of a few.
        yield 'class of three thousand negated types, 10.32' => ['pattern' => '/['.str_repeat('\\D', 3000).']/', 'release' => '10.32', 'length' => 40];
        yield 'class of three thousand negated types under UCP, 10.48' => ['pattern' => '/['.str_repeat('\\D', 3000).']/u', 'release' => '10.48', 'length' => 9012];
        yield 'class of three hundred properties, 10.40' => ['pattern' => '/['.str_repeat('\\p{L}', 300).']/u', 'release' => '10.40', 'length' => 912];
        yield 'class of three hundred properties, 10.49' => ['pattern' => '/['.str_repeat('\\p{L}', 300).']/u', 'release' => '10.49', 'length' => 912];
        yield 'class of three hundred characters past 255, 10.32' => ['pattern' => '/['.self::wide(0x100, 300, 2).']/u', 'release' => '10.32', 'length' => 912];
        yield 'class of three hundred characters past 255, 10.44' => ['pattern' => '/['.self::wide(0x100, 300, 2).']/u', 'release' => '10.44', 'length' => 912];
        yield 'class of three hundred characters past 255, in lists, 10.45' => ['pattern' => '/['.self::wide(0x100, 300, 2).']/u', 'release' => '10.45', 'length' => 619];
        yield 'class of a letter and three hundred characters past 255, 10.35' => ['pattern' => '/[a'.self::wide(0x100, 300, 2).']/u', 'release' => '10.35', 'length' => 944];
        yield 'class of a letter and three hundred characters past 255, in lists, 10.48' => ['pattern' => '/[a'.self::wide(0x100, 300, 2).']/u', 'release' => '10.48', 'length' => 651];
        yield 'class of three hundred characters past 255 in a row, 10.39' => ['pattern' => '/['.self::wide(0x400, 300, 1).']/u', 'release' => '10.39', 'length' => 912];
        yield 'class of three hundred characters past 255 in a row, merged, 10.46' => ['pattern' => '/['.self::wide(0x400, 300, 1).']/u', 'release' => '10.46', 'length' => 17];
        yield 'class of a thousand characters past 4095, 10.42' => ['pattern' => '/['.self::wide(0x1000, 1000, 3).']/u', 'release' => '10.42', 'length' => 4012];
        yield 'class of a thousand characters past 4095, in lists, 10.47' => ['pattern' => '/['.self::wide(0x1000, 1000, 3).']/u', 'release' => '10.47', 'length' => 2019];
        yield 'class of four hundred letters, caseless, 10.34' => ['pattern' => '/['.str_repeat('ab', 200).']/i', 'release' => '10.34', 'length' => 40];
        yield 'class of three hundred Latin-1 letters, caseless, 10.43' => ['pattern' => '/['.str_repeat('é', 300).']/iu', 'release' => '10.43', 'length' => 40];
        yield 'class of three hundred ranges of letters, caseless, 10.33' => ['pattern' => '/['.str_repeat('a-z', 300).']/iu', 'release' => '10.33', 'length' => 2144];
        yield 'class of three hundred ranges of letters, caseless, 10.45' => ['pattern' => '/['.str_repeat('a-z', 300).']/iu', 'release' => '10.45', 'length' => 51];
        yield 'class of three hundred ranges past 255, 10.36' => ['pattern' => '/['.str_repeat('\\x{100}-\\x{200}', 300).']/u', 'release' => '10.36', 'length' => 1512];
        yield 'negated class of three hundred ranges past 255, 10.45' => ['pattern' => '/[^'.str_repeat('\\x{100}-\\x{200}', 300).']/u', 'release' => '10.45', 'length' => 17];
        yield 'class of three hundred horizontal spaces, 10.38' => ['pattern' => '/['.str_repeat('\\h', 300).']/u', 'release' => '10.38', 'length' => 8144];
        // From 10.45 a range written again, or held by another, adds nothing.
        yield 'ranges whose bounds read alike side by side, 10.45' => ['pattern' => '/[\\x{1}-\\x{59d8}\\x{c}-\\x{bb8}]/u', 'release' => '10.45', 'length' => 50];
        yield 'range past 255 and one of its starts, 10.47' => ['pattern' => '/[\\x{100}-\\x{5000}\\x{100}-\\x{200}]/u', 'release' => '10.47', 'length' => 18];
        yield 'range past 255 and one of its ends, 10.49' => ['pattern' => '/[\\x{100}-\\x{5000}\\x{4000}-\\x{5000}]/u', 'release' => '10.49', 'length' => 18];
        // Up to 10.44 each member adds the other cases of its letters.
        yield 'caseless ranges from one letter to two others, 10.44' => ['pattern' => '/[a-ca-z]/iu', 'release' => '10.44', 'length' => 51];
        yield 'caseless ranges from two letters to one, 10.44' => ['pattern' => '/[a-zs-z]/iu', 'release' => '10.44', 'length' => 54];
        yield 'caseless ranges whose bounds read alike side by side, 10.44' => ['pattern' => '/[\\x01-\\x7b\\x0b-\\x17]/iu', 'release' => '10.44', 'length' => 58];
        yield 'caseless class of three thousand Greek letters, 10.44' => ['pattern' => '/['.str_repeat('σς', 1500).']/iu', 'release' => '10.44', 'length' => 30012];
        yield 'caseless class of three thousand Greek letters, 10.45' => ['pattern' => '/['.str_repeat('σς', 1500).']/iu', 'release' => '10.45', 'length' => 20];
        yield 'class of three hundred horizontal spaces, 10.49' => ['pattern' => '/['.str_repeat('\\h', 300).']/u', 'release' => '10.49', 'length' => 63];
    }

    /**
     * @return iterable<string, array{template: string, flags: string, release: string, largest: int, offset: int}>
     */
    public static function provideLimits(): iterable
    {
        // An offset of -1 stands for the end of the refused pattern.
        yield 'substring scan listing one group three times, 10.45' => ['template' => '(a)(?:(*scs:(1,1,1)b)c){%d}', 'flags' => '', 'release' => '10.45', 'largest' => 2620, 'offset' => -1];
        yield 'substring scan listing one group three times, 10.47' => ['template' => '(a)(?:(*scs:(1,1,1)b)c){%d}', 'flags' => '', 'release' => '10.47', 'largest' => 3448, 'offset' => 0];
        yield 'extended class of two types, 10.45' => ['template' => '(?:(?[ \\d + \\w ])c){%d}', 'flags' => '', 'release' => '10.45', 'largest' => 840, 'offset' => -1];
        yield 'extended class of two types, 10.48' => ['template' => '(?:(?[ \\d + \\w ])c){%d}', 'flags' => '', 'release' => '10.48', 'largest' => 840, 'offset' => 0];
        yield 'extended class of two characters past 255, 10.46' => ['template' => '(?:(?[ [\\x{100}] ^ [\\x{200}] ])c){%d}', 'flags' => 'u', 'release' => '10.46', 'largest' => 2259, 'offset' => -1];
        yield 'extended class of two characters past 255, 10.49' => ['template' => '(?:(?[ [\\x{100}] ^ [\\x{200}] ])c){%d}', 'flags' => 'u', 'release' => '10.49', 'largest' => 2259, 'offset' => 0];
        yield 'class of three hundred characters past 255, 10.32' => ['template' => '(?:['.self::wide(0x100, 300, 2).']c){%d}', 'flags' => 'u', 'release' => '10.32', 'largest' => 71, 'offset' => -1];
        yield 'class of three hundred characters past 255, 10.44' => ['template' => '(?:['.self::wide(0x100, 300, 2).']c){%d}', 'flags' => 'u', 'release' => '10.44', 'largest' => 71, 'offset' => -1];
        yield 'class of three hundred characters past 255, in lists, 10.45' => ['template' => '(?:['.self::wide(0x100, 300, 2).']c){%d}', 'flags' => 'u', 'release' => '10.45', 'largest' => 4057, 'offset' => -1];
        yield 'class of three hundred characters past 255, in lists, 10.48' => ['template' => '(?:['.self::wide(0x100, 300, 2).']c){%d}', 'flags' => 'u', 'release' => '10.48', 'largest' => 4057, 'offset' => 0];
        yield 'class of three hundred negated types under UCP, 10.45' => ['template' => '(?:['.str_repeat('\\D', 300).']c){%d}', 'flags' => 'u', 'release' => '10.45', 'largest' => 71, 'offset' => -1];
        yield 'class of three hundred negated types under UCP, 10.48' => ['template' => '(?:['.str_repeat('\\D', 300).']c){%d}', 'flags' => 'u', 'release' => '10.48', 'largest' => 71, 'offset' => 0];
    }

    /**
     * Characters past 255 written as escapes: $count of them from $first,
     * $step apart.
     */
    private static function wide(int $first, int $count, int $step): string
    {
        $class = '';
        for ($i = 0; $i < $count; $i++) {
            $class .= \sprintf('\\x{%x}', $first + $i * $step);
        }

        return $class;
    }

    private static function lengthOf(string $pattern, string $release, ?CharacterTables $tables = null): ?int
    {
        $regex = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => $release, 'runtime_pcre_validation' => false])->parse($pattern);
        $source = (string) $regex->source;
        $utf = str_contains($regex->flags, 'u') || StartOptions::turnUtfOn($source);
        $ucp = str_contains($regex->flags, 'u') || \in_array('UCP', StartOptions::items($source), true);

        return (new CompiledLength(static fn (): int => 0, $source, $utf, $ucp, $regex->flags, new PcreTarget(80400, $release), $tables))->of($regex->pattern);
    }
}
