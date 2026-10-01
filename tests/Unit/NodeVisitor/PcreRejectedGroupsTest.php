<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Parser\ErrorCode;
use PhpRegex\Tests\TestUtils\ValidatorErrorCodes;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Group, verb and condition forms PHP refuses to compile.
 *
 * Every rejected pattern comes from PCRE2's own test suite and is refused by
 * both PCRE2 10.40 and 10.48 with the error code in its case name. Every
 * accepted control compiles on the same engines. The offsets are PCRE2's
 * body offsets: the 10.48 one first, then the 10.40 one where the two
 * releases disagree.
 *
 * A form refused while the pattern is read keeps the reader's error code,
 * like "[abc" does; a form refused once the tree is built carries a
 * "regex." code. Each case name says which of the two it is.
 */
final class PcreRejectedGroupsTest extends TestCase
{
    private const LAYER_PARSER = 'parser';

    private const LAYER_VALIDATOR = 'validator';

    #[Test]
    #[DataProvider('provideRejectedPatterns')]
    public function test_validate_rejects_pattern_pcre_refuses(string $pattern): void
    {
        $result = Regex::create()->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s compiles in no PCRE2 release but was reported valid.', $pattern));
        $this->assertNotNull($result->error);
    }

    #[Test]
    #[DataProvider('provideRejectedPatternsWithLayer')]
    public function test_validate_rejects_pattern_pcre_refuses_with_the_code_of_its_layer(string $pattern, string $layer): void
    {
        $result = Regex::create()->validate($pattern);

        $this->assertFalse($result->isValid);

        // Every layer names the problem now; a refusal the AST validator
        // judges keeps the code it had before the codes became an enum.
        $this->assertInstanceOf(ErrorCode::class, $result->errorCode);

        if (self::LAYER_VALIDATOR === $layer) {
            $this->assertContains($result->errorCode->value, ValidatorErrorCodes::VALUES);
        }
    }

    /**
     * @param list<int> $offsets
     */
    #[Test]
    #[DataProvider('provideRejectedPatternsWithOffsets')]
    public function test_validate_rejects_pattern_pcre_refuses_at_pcre_offset(string $pattern, array $offsets): void
    {
        $result = Regex::create()->validate($pattern);

        $this->assertFalse($result->isValid);
        $this->assertContains(
            $result->offset,
            $offsets,
            \sprintf('%s reported at offset %s, PCRE2 reports %s.', $pattern, var_export($result->offset, true), implode(' or ', $offsets)),
        );
    }

    #[Test]
    public function test_validate_rejects_bad_version_inside_an_alpha_assertion_payload(): void
    {
        // preg_match() on PCRE2 10.48: "syntax error or number too big in
        // (?(VERSION condition at offset 23". Inside (*pla:...) the payload
        // is validated without its source, so only verdict and code are pinned.
        $result = Regex::create()->validate('/(*pla:(?(VERSION>=10.0.0)a|b))c/');

        $this->assertFalse($result->isValid);
        $this->assertSame(ErrorCode::from('regex.condition.version_syntax'), $result->errorCode);
    }

    #[Test]
    #[DataProvider('provideAcceptedControls')]
    public function test_validate_accepts_neighbouring_pattern_pcre_compiles(string $pattern): void
    {
        $result = Regex::create()->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s compiles in PCRE2 but was reported invalid: %s', $pattern, (string) $result->error));
        $this->assertNull($result->error);
    }

    /**
     * PCRE2 10.44 and later take a group name of up to 128 code units; 10.40
     * stops at 32 and refuses this one with error 148 at offset 131. The
     * limit follows the release the target reads, so on PHP 8.4 the longest
     * legal name still compiles once a name one unit longer is refused.
     */
    #[Test]
    public function test_validate_accepts_name_of_128_code_units_as_pcre2_10_48_does(): void
    {
        $pattern = "/(?'abcdefghijklmnopqrstuvwxyzABCDEFGabcdefghijklmnopqrstuvwxyzABCDEabcdefghijklmnopqrstuvwxyzABCDEabcdefghijklmnopqrstuvwxyzABCDEFG'justright)/";

        // PHP 8.4 bundles PCRE2 10.44, which took the limit to 128; a PHP
        // linked to an older PCRE2 refuses the name.
        $result = Regex::create(['php_version' => 80400])->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('A 128-unit name compiles in PCRE2 10.48 but was reported invalid: %s', (string) $result->error));
        $this->assertNull($result->error);
    }

    /**
     * Neighbours of the refused forms that both releases compile, yet
     * validate() refuses today. Each fails on the same code path as the form
     * next to it: a verb name ends at the first ")" (122), a branch reset may
     * give one number the same name twice (165), and "(?C" takes any of the
     * string delimiters PCRE2 lists (182).
     */
    #[Test]
    #[DataProvider('provideNeighbouringFormsRefusedToday')]
    public function test_validate_accepts_neighbouring_form_pcre_compiles(string $pattern): void
    {
        $result = Regex::create()->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s compiles in PCRE2 but was reported invalid: %s', $pattern, (string) $result->error));
        $this->assertNull($result->error);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRejectedPatterns(): iterable
    {
        foreach (self::rejectedPatterns() as $name => $case) {
            yield $name => ['pattern' => $case['pattern']];
        }
    }

    /**
     * @return iterable<string, array{pattern: string, layer: string}>
     */
    public static function provideRejectedPatternsWithLayer(): iterable
    {
        foreach (self::rejectedPatterns() as $name => $case) {
            yield $name => ['pattern' => $case['pattern'], 'layer' => $case['layer']];
        }
    }

    /**
     * @return iterable<string, array{pattern: string, offsets: list<int>}>
     */
    public static function provideRejectedPatternsWithOffsets(): iterable
    {
        foreach (self::rejectedPatterns() as $name => $case) {
            yield $name => ['pattern' => $case['pattern'], 'offsets' => $case['offsets']];
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideAcceptedControls(): iterable
    {
        yield '165 family, single-branch branch reset: /(?|a)/' => ['pattern' => '/(?|a)/'];
        // A lookahead adds no length to a lookbehind, so \X inside one is allowed:
        // preg_match() compiles each of these on PCRE2 10.40 and 10.48.
        yield '125 family, \\X in a lookahead inside a lookbehind: /(?<=(?=\\X)a)b/' => ['pattern' => '/(?<=(?=\\X)a)b/'];
        yield '125 family, \\X in a negative lookahead: /(?<!(?!\\X)a)b/' => ['pattern' => '/(?<!(?!\\X)a)b/'];
        yield '125 family, \\X in an alpha lookahead: /(?<=(*pla:\\X)a)b/' => ['pattern' => '/(?<=(*pla:\\X)a)b/'];
        yield '125 family, \\X after a member: /(?<=a(?=b\\X))b/' => ['pattern' => '/(?<=a(?=b\\X))b/'];
        yield '125 family, \\X in a condition assertion: /(?<=(?(?=\\X)a|b))c/' => ['pattern' => '/(?<=(?(?=\\X)a|b))c/'];
        yield '125 family, \\X in a lookahead of a nested lookbehind: /(?<=x(?<=(?=\\X)a))b/' => ['pattern' => '/(?<=x(?<=(?=\\X)a))b/'];
        // Empty callout strings compile on PCRE2 10.40 and 10.48.
        yield '182 family, empty double-quoted callout: /(?C"")a/' => ['pattern' => '/(?C"")a/'];
        yield "182 family, empty single-quoted callout: /(?C'')a/" => ['pattern' => "/(?C'')a/"];
        yield '182 family, empty braced callout: /(?C{})a/' => ['pattern' => '/(?C{})a/'];
        yield '122 family, mark name then a group: /(*:ab)(d)c/' => ['pattern' => '/(*:ab)(d)c/'];
        yield '122 family, backslash in a mark name: /(*MARK:a\\tb)xxx/' => ['pattern' => '/(*MARK:a\\tb)xxx/'];
        yield '125 family, dot in a lookbehind under UTF: /a(?<=A.B)/u' => ['pattern' => '/a(?<=A.B)/u'];
        yield '125 family, property in a lookbehind under UTF: /a(?<=A\\pLB)/u' => ['pattern' => '/a(?<=A\\pLB)/u'];
        yield '125 family, code point in a lookbehind under UTF: /a(?<=A\\x{e9}B)/u' => ['pattern' => '/a(?<=A\\x{e9}B)/u'];
        yield '126 family, relative call back: /(a)x(?-1)y/' => ['pattern' => '/(a)x(?-1)y/'];
        yield '126 family, relative call forward: /x(?+1)(y)/' => ['pattern' => '/x(?+1)(y)/'];
        yield '126 family, whole-pattern recursion: /x(?0)?y/' => ['pattern' => '/x(?0)?y/'];
        yield '127 family, two branches after a reference: /(a)(?(1)a|b)/' => ['pattern' => '/(a)(?(1)a|b)/'];
        yield '127 family, grouped alternation as the no branch: /(a)(?(1)a|(?:b|c))/' => ['pattern' => '/(a)(?(1)a|(?:b|c))/'];
        yield '127 family, alternation inside the yes branch: /(a)(?(1)(a|b|c))/' => ['pattern' => '/(a)(?(1)(a|b|c))/'];
        yield '127 family, two branches after an assertion: /(?(?=a)a|b)/' => ['pattern' => '/(?(?=a)a|b)/'];
        yield '127 family, one branch after an assertion: /(?(?=a)a)/' => ['pattern' => '/(?(?=a)a)/'];
        yield '128 family, lookahead condition: /(?(?=a)xxx)/' => ['pattern' => '/(?(?=a)xxx)/'];
        yield '128 family, negative lookahead condition: /(?(?!a)xxx)/' => ['pattern' => '/(?(?!a)xxx)/'];
        yield '128 family, lookbehind condition: /(?(?<=a)xxx)/' => ['pattern' => '/(?(?<=a)xxx)/'];
        yield '128 family, (*pla:) condition: /(?(*pla:a)xxx)/' => ['pattern' => '/(?(*pla:a)xxx)/'];
        yield '128 family, (*positive_lookahead:) condition: /(?(*positive_lookahead:x)xxx)/' => ['pattern' => '/(?(*positive_lookahead:x)xxx)/'];
        yield '128 family, group reference condition: /(a)(?(1)xxx)/' => ['pattern' => '/(a)(?(1)xxx)/'];
        yield '141 family, (?P< form: /(?P<abc>x)(?P<xyz>y)/' => ['pattern' => '/(?P<abc>x)(?P<xyz>y)/'];
        yield '141 family, (?P= form: /(?P<abc>x)(?P=abc)/' => ['pattern' => '/(?P<abc>x)(?P=abc)/'];
        yield '141 family, (?\' form without P: /(?\'abc\'x)(?P<xyz>y)/' => ['pattern' => "/(?'abc'x)(?P<xyz>y)/"];
        yield '148 family, 32-unit quoted name: /(?\'abcdefgh...\'x)/' => ['pattern' => "/(?'abcdefghabcdefghabcdefghabcdefgh'x)/"];
        yield '148 family, 32-unit angle-bracketed name: /(?<abcdefgh...>x)/' => ['pattern' => '/(?<abcdefghabcdefghabcdefghabcdefgh>x)/'];
        yield '154 family, one-branch DEFINE: /^(?(DEFINE) abc ) /x' => ['pattern' => '/^(?(DEFINE) abc ) /x'];
        yield '154 family, grouped alternation in DEFINE: /^(?(DEFINE) (?:abc | xyz) ) /x' => ['pattern' => '/^(?(DEFINE) (?:abc | xyz) ) /x'];
        yield '154 family, alternation inside a defined group: /(?(DEFINE)(?<a>b|c))(?&a)/' => ['pattern' => '/(?(DEFINE)(?<a>b|c))(?&a)/'];
        yield '160 family, newline verb at the start: /(*CR)a(b)/' => ['pattern' => '/(*CR)a(b)/'];
        yield '160 family, two newline verbs at the start: /(*CR)(*LF)ab/' => ['pattern' => '/(*CR)(*LF)ab/'];
        yield '160 family, LIMIT_MATCH with a value: /(*LIMIT_MATCH=1)abc/' => ['pattern' => '/(*LIMIT_MATCH=1)abc/'];
        yield '160 family, LIMIT_MATCH with a value after CRLF: /(*CRLF)(*LIMIT_MATCH=10)abc/' => ['pattern' => '/(*CRLF)(*LIMIT_MATCH=10)abc/'];
        yield '162 family, quoted \\k name: /(?<ab>x)\\k\'ab\'/' => ['pattern' => "/(?<ab>x)\\k'ab'/"];
        yield '162 family, angle-bracketed \\k name: /(?<ab>x)\\k<ab>/' => ['pattern' => '/(?<ab>x)\\k<ab>/'];
        yield '162 family, braced \\k name: /(?<ab>x)\\k{ab}/' => ['pattern' => '/(?<ab>x)\\k{ab}/'];
        yield '165 family, named then unnamed in a branch reset: /(?|(?<a>A)|(B))/' => ['pattern' => '/(?|(?<a>A)|(B))/'];
        yield '165 family, unnamed then named in a branch reset: /(?|(A)|(?<b>B))/' => ['pattern' => '/(?|(A)|(?<b>B))/'];
        yield '165 family, same name under J: /(?|(?<n>f)|(?<n>b))/J' => ['pattern' => '/(?|(?<n>f)|(?<n>b))/J'];
        yield '166 family, MARK with a name: /a(*MARK:x)b/' => ['pattern' => '/a(*MARK:x)b/'];
        yield '166 family, short mark with a name: /abc(*:x)pqr/' => ['pattern' => '/abc(*:x)pqr/'];
        yield '166 family, PRUNE without a name: /a(*PRUNE)b/' => ['pattern' => '/a(*PRUNE)b/'];
        yield '166 family, PRUNE with an empty name: /a(*PRUNE:)b/' => ['pattern' => '/a(*PRUNE:)b/'];
        yield '166 family, THEN with an empty name: /a(*THEN:)b/' => ['pattern' => '/a(*THEN:)b/'];
        yield '166 family, SKIP with an empty name: /a(*SKIP:)b/' => ['pattern' => '/a(*SKIP:)b/'];
        yield '179 family, major and minor version: /(?(VERSION>=10.0)yes|no)/' => ['pattern' => '/(?(VERSION>=10.0)yes|no)/'];
        yield '179 family, equal version: /(?(VERSION=10.4)yes|no)/' => ['pattern' => '/(?(VERSION=10.4)yes|no)/'];
        yield '179 family, major version only: /(?(VERSION>=10)yes|no)/' => ['pattern' => '/(?(VERSION>=10)yes|no)/'];
        yield '182 family, double-quoted callout string: /(?C"ab")xx/' => ['pattern' => '/(?C"ab")xx/'];
        yield '182 family, numbered callout: /(?C1)xx/' => ['pattern' => '/(?C1)xx/'];
        yield '182 family, bare callout: /(?C)xx/' => ['pattern' => '/(?C)xx/'];
        yield '194 family, caret then a flag: /(?^x)AB/' => ['pattern' => '/(?^x)AB/'];
        yield '194 family, caret alone: /(?^)AB/' => ['pattern' => '/(?^)AB/'];
        yield '194 family, hyphen without caret: /(?x-i)AB/' => ['pattern' => '/(?x-i)AB/'];
        yield '194 family, caret in a scoped group: /(?^i:AB)/' => ['pattern' => '/(?^i:AB)/'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideNeighbouringFormsRefusedToday(): iterable
    {
        yield '122 family, mark name holding an open bracket: /(*:ab\\t(d)xxx/' => ['pattern' => '/(*:ab\\t(d)xxx/'];
        yield '122 family, mark name holding an open bracket, testinput1:5664' => ['pattern' => '/(*:m(m)(?&y)(?(DEFINE)(?<y>b))/'];
        yield '165 family, same name for the same number, testinput2:2947' => ['pattern' => '/(?|(?<a>A)|(?<a>B))/'];
        yield '182 family, single-quoted callout string: /(?C\'ab\')xx/' => ['pattern' => "/(?C'ab')xx/"];
        yield '182 family, braced callout string: /(?C{ab})xx/' => ['pattern' => '/(?C{ab})xx/'];
        yield '182 family, dollar-delimited callout string: /(?C$ab$)xx/' => ['pattern' => '/(?C$ab$)xx/'];
        yield '182 family, backtick-delimited callout string: /(?C`ab`)xx/' => ['pattern' => '/(?C`ab`)xx/'];
        yield '182 family, caret-delimited callout string: /(?C^ab^)xx/' => ['pattern' => '/(?C^ab^)xx/'];
        yield '182 family, percent-delimited callout string: /(?C%ab%)xx/' => ['pattern' => '/(?C%ab%)xx/'];
        yield '182 family, hash-delimited callout string: /(?C#ab#)xx/' => ['pattern' => '/(?C#ab#)xx/'];
    }

    /**
     * @return array<string, array{pattern: string, layer: string, offsets: list<int>}>
     */
    private static function rejectedPatterns(): array
    {
        $parser = self::LAYER_PARSER;
        $validator = self::LAYER_VALIDATOR;

        return [
            '122 unmatched ) after a mark name, parser: /(*:ab\\t(d\\)c)xxx/' => ['pattern' => '/(*:ab\\t(d\\)c)xxx/', 'layer' => $parser, 'offsets' => [13, 12]],
            '125 \\X in a lookbehind under UTF, validator: /a(?<=A\\XB)/u' => ['pattern' => '/a(?<=A\\XB)/u', 'layer' => $validator, 'offsets' => [1]],
            '126 relative call to zero back, validator: /x(?-0)y/' => ['pattern' => '/x(?-0)y/', 'layer' => $validator, 'offsets' => [5]],
            '126 relative call to zero forward, validator: /x(?+0)y/' => ['pattern' => '/x(?+0)y/', 'layer' => $validator, 'offsets' => [5]],
            '127 three branches after a reference, validator: /(a)(?(1)a|b|c)/' => ['pattern' => '/(a)(?(1)a|b|c)/', 'layer' => $validator, 'offsets' => [3]],
            '127 three branches after an assertion, validator: /(?(?=a)a|b|c)/' => ['pattern' => '/(?(?=a)a|b|c)/', 'layer' => $validator, 'offsets' => [0]],
            '128 (*ACCEPT) as a condition, parser: /(?(*ACCEPT)xxx)/' => ['pattern' => '/(?(*ACCEPT)xxx)/', 'layer' => $parser, 'offsets' => [3, 2]],
            '128 (*atomic:) as a condition, parser: /(?(*atomic:xx)xxx)/' => ['pattern' => '/(?(*atomic:xx)xxx)/', 'layer' => $parser, 'offsets' => [10]],
            '128 (*script_run:) as a condition, parser: /(?(*script_run:xxx)zzz)/' => ['pattern' => '/(?(*script_run:xxx)zzz)/', 'layer' => $parser, 'offsets' => [14]],
            '141 quote after (?P, parser: /(?P\'abc\'x)(?P<xyz>y)/' => ['pattern' => "/(?P'abc'x)(?P<xyz>y)/", 'layer' => $parser, 'offsets' => [4, 3]],
            '148 name of 129 code units, parser: testinput2:5064' => [
                'pattern' => "/(?'abcdefghijklmnopqrstuvwxyzABCDEFGabcdefghijklmnopqrstuvwxyzABCDEabcdefghijklmnopqrstuvwxyzABCDEabcdefghijklmnopqrstuvwxyzABCDEFGH'toolong)/",
                'layer' => $parser,
                'offsets' => [132],
            ],
            '154 two-branch DEFINE, validator: /^(?(DEFINE) abc | xyz ) /x' => ['pattern' => '/^(?(DEFINE) abc | xyz ) /x', 'layer' => $validator, 'offsets' => [4]],
            '160 newline verb not at the start, validator: /a(*CR)b/' => ['pattern' => '/a(*CR)b/', 'layer' => $validator, 'offsets' => [5]],
            // preg_match() on 10.48 and pcre2test 10.40: error 160 at offset 6.
            '160 start-of-pattern verb not at the start, validator: /a(*UTF)b/' => ['pattern' => '/a(*UTF)b/', 'layer' => $validator, 'offsets' => [6]],
            '160 LIMIT_MATCH without a value, validator: /(*LIMIT_MATCH=)abc/' => ['pattern' => '/(*LIMIT_MATCH=)abc/', 'layer' => $validator, 'offsets' => [14]],
            '160 LIMIT_MATCH without a value after CRLF, validator: /(*CRLF)(*LIMIT_MATCH=)abc/' => ['pattern' => '/(*CRLF)(*LIMIT_MATCH=)abc/', 'layer' => $validator, 'offsets' => [21]],
            '162 empty quoted \\k name, parser: /\\k\'\'/' => ['pattern' => "/\\k''/", 'layer' => $parser, 'offsets' => [3]],
            '162 empty angle-bracketed \\k name, parser: /\\k<>/' => ['pattern' => '/\\k<>/', 'layer' => $parser, 'offsets' => [3]],
            '162 empty braced \\k name, parser: /\\k{}/' => ['pattern' => '/\\k{}/', 'layer' => $parser, 'offsets' => [3]],
            '165 two names for one number in a branch reset, parser: /(?|(?<a>A)|(?<b>B))/' => ['pattern' => '/(?|(?<a>A)|(?<b>B))/', 'layer' => $parser, 'offsets' => [16]],
            '166 MARK without a name, validator: /a(*MARK)b/' => ['pattern' => '/a(*MARK)b/', 'layer' => $validator, 'offsets' => [7]],
            '166 MARK with an empty name, validator: /abc(*MARK:)pqr/' => ['pattern' => '/abc(*MARK:)pqr/', 'layer' => $validator, 'offsets' => [10]],
            '166 short mark with an empty name, validator: /abc(*:)pqr/' => ['pattern' => '/abc(*:)pqr/', 'layer' => $validator, 'offsets' => [6]],
            '169 bare \\k, parser: /\\k/' => ['pattern' => '/\\k/', 'layer' => $parser, 'offsets' => [2]],
            '169 \\k before a bare name, parser: /\\kabc/' => ['pattern' => '/\\kabc/', 'layer' => $parser, 'offsets' => [2]],
            '179 three-part version, validator: /(?(VERSION>=10.0.0)yes|no)/' => ['pattern' => '/(?(VERSION>=10.0.0)yes|no)/', 'layer' => $validator, 'offsets' => [17, 16]],
            '182 bare word after (?C, parser: /(?Cab)xx/' => ['pattern' => '/(?Cab)xx/', 'layer' => $parser, 'offsets' => [4, 3]],
            '194 hyphen after (?^ and a flag, parser: /(?^x-i)AB/' => ['pattern' => '/(?^x-i)AB/', 'layer' => $parser, 'offsets' => [5, 4]],
            '194 hyphen right after (?^, parser: /(?^-i)AB/' => ['pattern' => '/(?^-i)AB/', 'layer' => $parser, 'offsets' => [4, 3]],
        ];
    }
}
