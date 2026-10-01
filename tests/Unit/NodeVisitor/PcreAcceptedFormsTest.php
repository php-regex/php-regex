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
use PhpRegex\Tests\TestUtils\Pcre2CaseRunner;
use PhpRegex\Tests\TestUtils\Pcre2ConformanceTable;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Patterns PHP compiles that validate() must not refuse.
 *
 * Every suite case below comes from PCRE2's own test suite and is rebuilt
 * from the committed fixture, never retyped: preg_match() compiles each one
 * on PCRE2 10.48 and pcre2test compiles it on PCRE2 10.40.
 *
 * Every refused neighbour is refused by both releases, so accepting the
 * forms above cannot turn into accepting what PHP refuses next to them.
 */
final class PcreAcceptedFormsTest extends TestCase
{
    /**
     * Suite case ids, grouped by the construct validate() refused them for.
     */
    private const SUITE_CASES = [
        'lookbehind through a call or reference' => [
            'testinput1:4646', 'testinput1:4651', 'testinput1:4656', 'testinput1:6309', 'testinput1:6528',
            'testinput2:194', 'testinput2:2889', 'testinput2:2893', 'testinput2:5088', 'testinput2:5276',
            'testinput2:5284', 'testinput2:5289', 'testinput2:5347', 'testinput2:6426', 'testinput2:6429',
            'testinput2:6433',
        ],
        'quantified empty group' => [
            'testinput1:3280', 'testinput1:3486', 'testinput1:3491', 'testinput1:3700', 'testinput1:4010',
            'testinput1:4016', 'testinput2:2169', 'testinput2:2181', 'testinput2:2451', 'testinput2:2565',
            'testinput2:4613', 'testinput2:4615', 'testinput2:5116', 'testinput2:5118',
        ],
        'non-atomic assertion' => [
            'testinput2:6159', 'testinput2:6162', 'testinput2:6169', 'testinput2:6177', 'testinput2:6179',
            'testinput2:6183', 'testinput2:6189', 'testinput2:6191', 'testinput2:6193', 'testinput2:6196',
            'testinput2:6199', 'testinput2:6204', 'testinput2:6281', 'testinput2:6284',
        ],
        'named or relative condition' => [
            'testinput1:4458', 'testinput1:4673', 'testinput1:5715', 'testinput1:5921', 'testinput1:5928',
            'testinput2:2163', 'testinput2:2177', 'testinput2:2953', 'testinput2:2969',
        ],
        'unicode group name' => [
            'testinput4:2543', 'testinput4:2546', 'testinput4:2549', 'testinput4:2552', 'testinput4:2555',
            'testinput4:2558', 'testinput4:2561', 'testinput5:2147', 'testinput5:2816',
        ],
        'empty group or callout condition' => [
            'testinput1:1626', 'testinput2:3819', 'testinput2:4578', 'testinput2:4582', 'testinput2:4752',
            'testinput2:5391', 'testinput5:1654',
        ],
        'quantified accept or non-atomic assertion' => [
            'testinput2:6083', 'testinput2:6087', 'testinput2:6113', 'testinput2:6185', 'testinput2:6187',
        ],
        'range ending in \\E' => [
            'testinput1:3984', 'testinput1:4006', 'testinput2:1852',
        ],
        'recursion condition on a name' => [
            'testinput2:1901', 'testinput2:2976',
        ],
        'quantified \\N' => ['testinput2:927'],
        'doubled quote in a callout string' => ['testinput2:4556'],
        'lookbehind length limit' => ['testinput2:5056'],
        'non-atomic lookbehind' => ['testinput2:6173'],
    ];

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $suite = null;

    #[Test]
    #[DataProvider('provideSuiteCases')]
    public function test_validate_accepts_suite_pattern_pcre_compiles(string $id, string $pattern): void
    {
        $case = self::suiteCase($id);
        $this->assertSame('accept', $case['verdict'] ?? null, $id.' is no longer an accepted case on PCRE2 10.48.');
        $this->assertIsArray($case['floor'] ?? null);
        $this->assertSame('accept', $case['floor']['verdict'] ?? null, $id.' is no longer an accepted case on PCRE2 10.40.');

        $result = Regex::create()->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s (%s) compiles in PCRE2 10.40 and 10.48 but was reported invalid: %s', $pattern, $id, (string) $result->error));
        $this->assertNull($result->error);
    }

    #[Test]
    #[DataProvider('provideNeighbouringAcceptedForms')]
    public function test_validate_accepts_neighbouring_form_pcre_compiles(string $pattern): void
    {
        $result = Regex::create()->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s compiles in PCRE2 10.40 and 10.48 but was reported invalid: %s', $pattern, (string) $result->error));
        $this->assertNull($result->error);
    }

    #[Test]
    #[DataProvider('provideNeighbouringRejectedForms')]
    public function test_validate_rejects_neighbouring_form_pcre_refuses(string $pattern): void
    {
        $result = Regex::create()->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s compiles in no PCRE2 release but was reported valid.', $pattern));
        $this->assertNotNull($result->error);
    }

    /**
     * PCRE2 measures a lookbehind branch by branch, and once a branch reset
     * stops it reusing a measure it gives up past a budget: error 135,
     * "lookbehind is too complicated", at offset 9 on 10.40 and 10.48.
     * Without the branch reset the same body compiles (testinput2:5088).
     */
    #[Test]
    public function test_validate_rejects_lookbehind_too_complicated_to_measure_after_branch_reset(): void
    {
        $case = self::suiteCase('testinput2:5099');
        $this->assertSame('reject', $case['verdict'] ?? null);
        $this->assertSame(135, $case['pcre2Code'] ?? null);

        $result = Regex::create()->validate((new Pcre2CaseRunner())->phpPattern($case));

        $this->assertFalse($result->isValid);
        $this->assertSame(ErrorCode::LookbehindTooComplex, $result->errorCode);
        $this->assertSame(9, $result->offset);
    }

    /**
     * "(?(-" and "(?(+" start a relative group number; without a digit after
     * the sign PCRE2 wants a name there instead, and a sign is no name:
     * error 162, "subpattern name expected", right after the sign, on 10.40
     * and 10.48, even when a group bears the name that follows the sign.
     */
    #[Test]
    #[DataProvider('provideSignWithoutDigitsConditions')]
    public function test_validate_rejects_condition_sign_without_digits_at_pcre_offset(string $pattern, int $offset): void
    {
        $result = Regex::create()->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s compiles in no PCRE2 release but was reported valid.', $pattern));
        $this->assertSame($offset, $result->offset);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideSignWithoutDigitsConditions(): iterable
    {
        yield 'minus then a letter: /(?(-a)b)/' => ['pattern' => '/(?(-a)b)/', 'offset' => 3];
        yield 'plus then a letter: /(?(+a)b)/' => ['pattern' => '/(?(+a)b)/', 'offset' => 3];
        yield 'minus alone: /(?(-)b)/' => ['pattern' => '/(?(-)b)/', 'offset' => 3];
        yield 'plus alone: /(?(+)b)/' => ['pattern' => '/(?(+)b)/', 'offset' => 3];
        yield 'minus then the name of a group: /(?<a>x)(?(-a)b)/' => ['pattern' => '/(?<a>x)(?(-a)b)/', 'offset' => 10];
        yield 'plus then the name of a group: /(?<a>x)(?(+a)b)/' => ['pattern' => '/(?<a>x)(?(+a)b)/', 'offset' => 10];
    }

    /**
     * @return iterable<string, array{id: string, pattern: string}>
     */
    public static function provideSuiteCases(): iterable
    {
        $runner = new Pcre2CaseRunner();

        foreach (self::SUITE_CASES as $family => $ids) {
            foreach ($ids as $id) {
                $pattern = $runner->phpPattern(self::suiteCase($id));

                yield \sprintf('%s, %s: %s', $family, $id, str_replace("\n", '\n', $pattern)) => [
                    'id' => $id,
                    'pattern' => $pattern,
                ];
            }
        }
    }

    /**
     * Forms next to the suite cases that preg_match() compiles on PCRE2
     * 10.48 and pcre2test compiles on 10.40.
     *
     * The lookbehind limit is the one both releases share for a fixed-length
     * lookbehind: 65535 characters. "(?<!a{65535}b)x" is the first length
     * past it (error 187, "lookbehind assertion is too long"). A variable
     * length is another limit: 10.48 allows up to 255 per branch, 10.40
     * allows none, so no variable-length row is pinned as accepted here.
     *
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideNeighbouringAcceptedForms(): iterable
    {
        yield 'lookbehind through a call or reference, repeated lookahead: /(?<=(?=.)+)/' => ['pattern' => '/(?<=(?=.)+)/'];
        yield 'lookbehind through a group, alternation of one length: /(?<=(?:ab|cd))x/' => ['pattern' => '/(?<=(?:ab|cd))x/'];
        yield 'lookbehind through a group, alternation of one long length: /(?<=(?:a{300}|b{300}))x/' => ['pattern' => '/(?<=(?:a{300}|b{300}))x/'];
        yield 'lookbehind through a conditional of one length: /(a)?(?<=(?(1)ab|cd))x/' => ['pattern' => '/(a)?(?<=(?(1)ab|cd))x/'];
        yield 'lookbehind through a conditional of one long length: /(a)?(?<=(?(1)a{300}|b{300}))x/' => ['pattern' => '/(a)?(?<=(?(1)a{300}|b{300}))x/'];
        yield 'lookbehind through a conditional that calls a group: /(a)?(?<=(?(1)(?1)|b))x/' => ['pattern' => '/(a)?(?<=(?(1)(?1)|b))x/'];
        yield 'lookbehind through a conditional that references a group: /(a)?(?<=(?(1)\\1|b))x/' => ['pattern' => '/(a)?(?<=(?(1)\\1|b))x/'];
        yield 'lookbehind through a relative reference, braces: /(a)(?<=\\g{-1})x/' => ['pattern' => '/(a)(?<=\\g{-1})x/'];
        yield 'lookbehind through a relative reference, bare: /(a)(?<=\\g-1)x/' => ['pattern' => '/(a)(?<=\\g-1)x/'];
        yield 'lookbehind through a relative reference to the nearest group: /(a+)(b)(?<=\\g{-1})x/' => ['pattern' => '/(a+)(b)(?<=\\g{-1})x/'];
        yield 'lookbehind through a bare relative reference to the nearest group: /(a+)(b)(?<=\\g-1)x/' => ['pattern' => '/(a+)(b)(?<=\\g-1)x/'];
        yield 'lookbehind through a group after a branch reset: /(?|(a)|(b))(?<=(?:c|d))x/' => ['pattern' => '/(?|(a)|(b))(?<=(?:c|d))x/'];
        yield 'lookbehind through a call into a one-branch reset: /(?|(a))(?<=(?1))x/' => ['pattern' => '/(?|(a))(?<=(?1))x/'];
        yield 'quantified empty group, possessive atomic group: /(?>)++/' => ['pattern' => '/(?>)++/'];
        yield 'empty group or callout condition, empty option unset: /a(?-)b/' => ['pattern' => '/a(?-)b/'];
        yield 'empty group or callout condition, empty group at the end: /a(?)/' => ['pattern' => '/a(?)/'];
        yield 'quantified accept, at the start: /(*ACCEPT)?a/' => ['pattern' => '/(*ACCEPT)?a/'];
        yield 'non-atomic lookbehind, empty: /(?<*)/' => ['pattern' => '/(?<*)/'];
        yield 'lookbehind length limit, 256 characters: /(?<!a{256})x/' => ['pattern' => '/(?<!a{256})x/'];
        yield 'lookbehind length limit, positive, 256 characters: /(?<=a{256})x/' => ['pattern' => '/(?<=a{256})x/'];
        yield 'lookbehind length limit, positive, 65535 characters: /(?<=a{65535})x/' => ['pattern' => '/(?<=a{65535})x/'];
        yield 'lookbehind length limit, 65535 characters over two atoms: /(?<!a{65534}b)x/' => ['pattern' => '/(?<!a{65534}b)x/'];

        // "R" followed by anything but digits is a name, not a recursion condition.
        yield 'named condition, name starting with R: /(?<Rx>a)(?(Rx)a)/' => ['pattern' => '/(?<Rx>a)(?(Rx)a)/'];
        yield 'named condition, R and a number then a letter: /(?<R1a>a)(?(R1a)a)/' => ['pattern' => '/(?<R1a>a)(?(R1a)a)/'];
        yield 'named condition, R and a number then a letter, group after: /(?(R1a)a)(?<R1a>b)/' => ['pattern' => '/(?(R1a)a)(?<R1a>b)/'];
        // Group 0 is the whole pattern: "R0" asks whether any recursion runs, as "R" does.
        yield 'recursion condition on group 0: /(?(R0)a|b)/' => ['pattern' => '/(?(R0)a|b)/'];
        yield 'recursion condition on group 0, leading zero: /(?(R00)a|b)/' => ['pattern' => '/(?(R00)a|b)/'];
        yield 'recursion condition on group 0, many zeros: /x(?(R000000)a)/' => ['pattern' => '/x(?(R000000)a)/'];
    }

    /**
     * Refused by preg_match() on PCRE2 10.48 and by pcre2test on 10.40; the
     * comment on each family names the PCRE2 errors.
     *
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideNeighbouringRejectedForms(): iterable
    {
        // 125 "not fixed length" (10.40) / "not limited" (10.48); 115 "non-existent subpattern".
        yield 'lookbehind through a call, unbounded group after: /(?<=(?1))(a+)/' => ['pattern' => '/(?<=(?1))(a+)/'];
        yield 'lookbehind through a call, unbounded group before: /(a*)(?<=b(?1))/' => ['pattern' => '/(a*)(?<=b(?1))/'];
        yield 'lookbehind through a call, unbounded named group: /(?<X>a+)(?<=(?&X))/' => ['pattern' => '/(?<X>a+)(?<=(?&X))/'];
        yield 'lookbehind through a call, unbounded defined group: /(?<=X(?(DEFINE)(Y+))(?1))./' => ['pattern' => '/(?<=X(?(DEFINE)(Y+))(?1))./'];
        yield 'lookbehind through a call, whole-pattern recursion: /(?<=a(?R))/' => ['pattern' => '/(?<=a(?R))/'];
        yield 'lookbehind through a call, missing group: /(?<=b(?1))/' => ['pattern' => '/(?<=b(?1))/'];
        yield 'lookbehind through a reference, unbounded group: /(a+)(?<=\\1)/' => ['pattern' => '/(a+)(?<=\\1)/'];
        yield 'lookbehind through a lookahead, unbounded atom after it: /(?<=(?=.)*a+)/' => ['pattern' => '/(?<=(?=.)*a+)/'];
        yield 'lookbehind through a group, unbounded alternative: /(?<=(?:a|b+))x/' => ['pattern' => '/(?<=(?:a|b+))x/'];
        yield 'lookbehind through a conditional, unbounded branch: /(a)?(?<=(?(1)ab|c+))x/' => ['pattern' => '/(a)?(?<=(?(1)ab|c+))x/'];
        yield 'lookbehind through a call, group calling itself: /(?<=(?1))(a|b(?1))/' => ['pattern' => '/(?<=(?1))(a|b(?1))/'];
        yield 'lookbehind through a call, into the enclosing group: /(a(?<=b(?1)))/' => ['pattern' => '/(a(?<=b(?1)))/'];
        yield 'lookbehind through a reference, into a branch reset: /(?|(a))(?<=\\1)x/' => ['pattern' => '/(?|(a))(?<=\\1)x/'];
        yield 'lookbehind through a relative reference, unbounded group: /(a+)(?<=\\g{-1})x/' => ['pattern' => '/(a+)(?<=\\g{-1})x/'];
        yield 'lookbehind through a relative reference, unbounded nearest group: /(a)(b+)(?<=\\g{-1})x/' => ['pattern' => '/(a)(b+)(?<=\\g{-1})x/'];

        // 109 "quantifier does not follow a repeatable item"; 104 "numbers out of order".
        yield 'quantifier without target, after an option setting: /(?i){3,5}/' => ['pattern' => '/(?i){3,5}/'];
        yield 'quantifier without target, star after an option setting: /(?i)*/' => ['pattern' => '/(?i)*/'];
        yield 'quantifier without target, at the start: /?(?(1)b|a)/' => ['pattern' => '/?(?(1)b|a)/'];
        yield 'quantifier without target, star at the start: /*a/' => ['pattern' => '/*a/'];
        yield 'quantifier without target, in an empty branch: /(|*)/' => ['pattern' => '/(|*)/'];
        yield 'quantifier without target, on a quantifier: /a**/' => ['pattern' => '/a**/'];
        yield 'quantifier without target, braces on braces: /a{2}{3}/' => ['pattern' => '/a{2}{3}/'];
        yield 'quantifier without target, anchor: /^{3,5}/' => ['pattern' => '/^{3,5}/'];
        yield 'quantified empty group, reversed bounds: /(){3,2}/' => ['pattern' => '/(){3,2}/'];

        // 195 "(*alpha_assertion) not recognized"; 160 "(*VERB) not recognized"; 125; 114 "missing )".
        yield 'non-atomic assertion, unknown name: /(*naplx:a)/' => ['pattern' => '/(*naplx:a)/'];
        yield 'non-atomic assertion, upper case: /(*NAPLA:a)/' => ['pattern' => '/(*NAPLA:a)/'];
        yield 'non-atomic assertion, no negative form: /(*nanla:a)/' => ['pattern' => '/(*nanla:a)/'];
        yield 'non-atomic assertion, no negative long form: /(*non_atomic_negative_lookahead:a)/' => ['pattern' => '/(*non_atomic_negative_lookahead:a)/'];
        yield 'non-atomic assertion, missing colon: /(*napla)/' => ['pattern' => '/(*napla)/'];
        yield 'non-atomic assertion, unclosed: /(*napla:a/' => ['pattern' => '/(*napla:a/'];
        yield 'non-atomic assertion, short form unclosed: /(?*a/' => ['pattern' => '/(?*a/'];
        yield 'non-atomic lookbehind, unbounded: /(*naplb:a+)/' => ['pattern' => '/(*naplb:a+)/'];
        yield 'non-atomic lookbehind, short form unbounded: /(?<*a+)/' => ['pattern' => '/(?<*a+)/'];
        // 198 "atomic assertion expected after (?(" (10.40 gives 128 for the short form).
        yield 'non-atomic assertion as a condition: /(?(*napla:a)b|c)/' => ['pattern' => '/(?(*napla:a)b|c)/'];
        yield 'non-atomic lookbehind as a condition: /(?(*naplb:a)b|c)/' => ['pattern' => '/(?(*naplb:a)b|c)/'];
        yield 'non-atomic assertion as a condition, short form: /(?(?*a)b|c)/' => ['pattern' => '/(?(?*a)b|c)/'];

        // 115 "non-existent subpattern"; 142 "syntax error in subpattern name"; 144 "must start with a non-digit".
        yield 'named condition, angle brackets, missing group: /(?(<nonexistent>)a)/' => ['pattern' => '/(?(<nonexistent>)a)/'];
        yield "named condition, quotes, missing group: /(?('nonexistent')a)/" => ['pattern' => "/(?('nonexistent')a)/"];
        yield 'named condition, unterminated angle bracket: /(?(<ab)a)/' => ['pattern' => '/(?(<ab)a)/'];
        yield "named condition, mismatched terminator: /(?('ab>)a)/" => ['pattern' => "/(?('ab>)a)/"];
        yield "named condition, quote closing an angle bracket: /(?<ab>a)(?(<ab')b)/" => ['pattern' => "/(?<ab>a)(?(<ab')b)/"];
        yield 'named condition, digit first: /(?(<1a>)a)/' => ['pattern' => '/(?(<1a>)a)/'];
        yield 'relative condition, no group before: /(?(-1)a)/' => ['pattern' => '/(?(-1)a)/'];
        yield 'relative condition, too far back: /((?(-2)a))/' => ['pattern' => '/((?(-2)a))/'];
        yield 'relative condition, no group after: /(?(+1)X)/' => ['pattern' => '/(?(+1)X)/'];

        // 144 "must start with a non-digit"; 142 "syntax error in subpattern name"; 115.
        yield "unicode group name, ASCII digit first: /(?'1a'x)/u" => ['pattern' => "/(?'1a'x)/u"];
        yield "unicode group name, Arabic-Indic digit first: /(?'\u{663}a'x)/u" => ['pattern' => "/(?'\u{663}a'x)/u"];
        yield "unicode group name, space: /(?'a b'x)/u" => ['pattern' => "/(?'a b'x)/u"];
        yield "unicode group name, space under x: /(?'a b'x)/ux" => ['pattern' => "/(?'a b'x)/ux"];
        yield "unicode group name, currency symbol: /(?'a\u{20ac}'x)/u" => ['pattern' => "/(?'a\u{20ac}'x)/u"];
        yield "unicode group name, hyphen: /(?'a-b'x)/u" => ['pattern' => "/(?'a-b'x)/u"];
        yield "unicode group name, non-ASCII letter without u: /(?'AB\u{e1}C'...)/" => ['pattern' => "/(?'AB\u{e1}C'...)/"];
        yield "unicode group name, reference to an undefined name: /(?'ABC'...)\\g{AB\u{e1}C}/u" => ['pattern' => "/(?'ABC'...)\\g{AB\u{e1}C}/u"];

        // 111 "unrecognized character after (?"; 128 "assertion expected after (?(?C)";
        // 138 "number after (?C is greater than 255"; 181 "missing terminating delimiter"; 162 "name expected".
        yield 'empty group, unrecognized letter: /a(?b/' => ['pattern' => '/a(?b/'];
        yield 'empty condition: /(?()a)/' => ['pattern' => '/(?()a)/'];
        yield 'callout condition, no assertion after it: /(?(?C25)abcd|xyz)/' => ['pattern' => '/(?(?C25)abcd|xyz)/'];
        yield 'callout condition, bare callout and no assertion: /(?(?C)a)/' => ['pattern' => '/(?(?C)a)/'];
        yield 'callout condition, number past 255: /(?(?C256)(?=abc)a)/' => ['pattern' => '/(?(?C256)(?=abc)a)/'];
        yield 'callout condition, unterminated string: /(?(?C"abc)(?=a)a)/' => ['pattern' => '/(?(?C"abc)(?=a)a)/'];

        // 109: (*ACCEPT) is the only verb PCRE2 lets a quantifier follow.
        yield 'quantified verb, commit: /a(*COMMIT)?b/' => ['pattern' => '/a(*COMMIT)?b/'];
        yield 'quantified verb, fail: /a(*FAIL)+/' => ['pattern' => '/a(*FAIL)+/'];
        yield 'quantified verb, mark: /(*MARK:X)?a/' => ['pattern' => '/(*MARK:X)?a/'];
        yield 'quantified verb, prune: /a(*PRUNE)*b/' => ['pattern' => '/a(*PRUNE)*b/'];
        yield 'quantified verb, lazy skip: /a(*SKIP)??b/' => ['pattern' => '/a(*SKIP)??b/'];
        yield 'quantified verb, then with braces: /a(*THEN){2}b/' => ['pattern' => '/a(*THEN){2}b/'];

        // 108 "range out of order"; 150 "invalid range"; 106 "missing terminating ]".
        yield 'range ending in \\E, reversed: /^[z-\\Ea]/' => ['pattern' => '/^[z-\\Ea]/'];
        yield 'range ending in \\Q...\\E, reversed: /^[z-\\Qa\\E]/' => ['pattern' => '/^[z-\\Qa\\E]/'];
        yield 'range ending in \\E, class escape as end: /^[a-\\E\\d]/' => ['pattern' => '/^[a-\\E\\d]/'];
        yield 'range ending in \\E, unclosed class: /^[a-\\E/' => ['pattern' => '/^[a-\\E/'];
        yield 'range ending in \\Q\\E, unclosed class: /^[a-\\Q\\E/' => ['pattern' => '/^[a-\\Q\\E/'];

        // 115 "non-existent subpattern"; 144; 162 "name expected"; 142.
        yield 'recursion condition on a name, missing group: /(?(R&nonexistent)a)/' => ['pattern' => '/(?(R&nonexistent)a)/'];
        yield 'recursion condition on a name, digit first: /(?<A>a)(?(R&1)a)/' => ['pattern' => '/(?<A>a)(?(R&1)a)/'];
        yield 'recursion condition on a name, empty name: /(?<A>a)(?(R&)a)/' => ['pattern' => '/(?<A>a)(?(R&)a)/'];
        yield 'recursion condition on a name, unterminated: /(?<A>a)(?(R&A/' => ['pattern' => '/(?<A>a)(?(R&A/'];

        // 193 "\N{U+dddd} only in UTF mode"; 137 "\N{name} not supported"; 171 "\N in a class"; 104.
        yield '\\N, code point without u: /\\N{U+41}/' => ['pattern' => '/\\N{U+41}/'];
        yield '\\N, character name: /\\N{ab}/' => ['pattern' => '/\\N{ab}/'];
        yield '\\N, unterminated braces: /\\N{4/' => ['pattern' => '/\\N{4/'];
        yield '\\N, reversed quantifier: /\\N{2,1}/' => ['pattern' => '/\\N{2,1}/'];
        yield '\\N, in a class: /[\\N]/' => ['pattern' => '/[\\N]/'];

        // 139 "closing parenthesis for (?C expected"; 181 "missing terminating delimiter".
        yield 'callout string, single quote inside: /a(?C"a)b"c")/' => ['pattern' => '/a(?C"a)b"c")/'];
        yield 'callout string, doubled quote then unterminated: /a(?C"a)b""c)/' => ['pattern' => '/a(?C"a)b""c)/'];
        yield 'callout string, unterminated: /a(?C"a)/' => ['pattern' => '/a(?C"a)/'];

        // 187 "lookbehind assertion is too long"; 105 "number too big in {} quantifier";
        // 10.48 "branch too long in variable-length lookbehind", 10.40 125.
        yield 'lookbehind length limit, one past 65535: /(?<!a{65535}b)x/' => ['pattern' => '/(?<!a{65535}b)x/'];
        yield 'lookbehind length limit, twice 65535: /(?<!(?:a{65535}){2})x/' => ['pattern' => '/(?<!(?:a{65535}){2})x/'];
        yield 'lookbehind length limit, quantifier past 65535: /(?<!a{65536})x/' => ['pattern' => '/(?<!a{65536})x/'];
        yield 'lookbehind length limit, variable length past 255: /(?<!a{0,256})x/' => ['pattern' => '/(?<!a{0,256})x/'];
        yield 'lookbehind length limit, variable alternation past 255: /(?<=(?:a|b{300}))x/' => ['pattern' => '/(?<=(?:a|b{300}))x/'];

        // 125 "not fixed length" / "not limited"; 114 "missing closing parenthesis".
        yield 'non-atomic lookbehind, unbounded group: /(?<*(.)+)/' => ['pattern' => '/(?<*(.)+)/'];
        yield 'non-atomic lookbehind, unbounded branch: /(?<*(.)*|(.)...)(\\1|\\2)/' => ['pattern' => '/(?<*(.)*|(.)...)(\\1|\\2)/'];
        yield 'non-atomic lookbehind, unclosed: /(?<*(.)..|(.).../' => ['pattern' => '/(?<*(.)..|(.).../'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function suiteCase(string $id): array
    {
        if (null === self::$suite) {
            $json = file_get_contents(Pcre2ConformanceTable::SUITE_PATH);

            if (!\is_string($json)) {
                throw new \RuntimeException('The suite fixture cannot be read.');
            }

            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

            if (!\is_array($decoded)) {
                throw new \RuntimeException('The suite fixture is not a JSON object.');
            }

            $suite = [];

            foreach ($decoded as $file => $cases) {
                if ('meta' === $file || !\is_array($cases)) {
                    continue;
                }

                foreach ($cases as $case) {
                    if (!\is_array($case)) {
                        continue;
                    }

                    $caseId = $case['id'] ?? null;

                    if (\is_string($caseId)) {
                        /** @var array<string, mixed> $row */
                        $row = $case;
                        $suite[$caseId] = $row;
                    }
                }
            }

            self::$suite = $suite;
        }

        return self::$suite[$id] ?? throw new \RuntimeException($id.' is missing from the suite fixture.');
    }
}
