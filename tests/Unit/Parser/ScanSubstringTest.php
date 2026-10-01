<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Parser;

use PhpRegex\Automata\Exception\ComplexityException;
use PhpRegex\Automata\Options\SolverOptions;
use PhpRegex\Automata\Transform\RegularSubsetValidator;
use PhpRegex\Explain\AsciiTreeRenderer;
use PhpRegex\Explain\RailroadSvgRenderer;
use PhpRegex\Optimizer\Modernizer;
use PhpRegex\Parser\Analysis\LengthRangeCalculator;
use PhpRegex\Parser\ErrorCode;
use PhpRegex\Parser\Internal\PcreVerb;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Redos\RedosAnalysis;
use PhpRegex\Toolkit\Regex;
use PhpRegex\Transpiler\TranspileException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "(*scan_substring:(1)...)", or "(*scs:", PCRE2 10.45: an assertion that
 * matches its body against what the listed groups captured. Before 10.45 the
 * name is unknown. Every pattern and offset below is from testinput2 of the
 * PCRE2 suite, run through pcre2test 10.44, 10.45 and 10.49.
 */
final class ScanSubstringTest extends TestCase
{
    #[Test]
    #[DataProvider('provideAssertions')]
    public function test_the_assertion_is_read_from_pcre2_10_45(string $pattern, int $offsetBefore): void
    {
        foreach (['10.45', '10.49'] as $release) {
            $regex = Regex::create(['cache' => null, 'pcre_version' => $release]);
            $result = $regex->validate($pattern);

            $this->assertTrue($result->isValid, \sprintf('%s on %s: %s', $pattern, $release, $result->error));
            $this->assertSame($pattern, $regex->parse($pattern)->accept(new PatternPrinter()), $pattern);
        }

        $before = Regex::create(['cache' => null, 'pcre_version' => '10.44'])->validate($pattern);
        $this->assertFalse($before->isValid, $pattern);
        $this->assertSame($offsetBefore, $before->offset, $pattern);
    }

    #[Test]
    #[DataProvider('provideRefused')]
    public function test_what_pcre2_refuses_is_refused_where_it_stops(string $pattern, int $offsetBefore, int $offset1045, int $offset1049): void
    {
        foreach (['10.44' => $offsetBefore, '10.45' => $offset1045, '10.49' => $offset1049] as $release => $offset) {
            $result = Regex::create(['cache' => null, 'pcre_version' => (string) $release])->validate($pattern);

            $this->assertFalse($result->isValid, \sprintf('%s on %s', $pattern, $release));
            $this->assertSame($offset, $result->offset, \sprintf('%s on %s', $pattern, $release));
        }
    }

    #[Test]
    public function test_a_list_that_goes_wrong_is_refused_where_pcre2_stops(): void
    {
        // pcre2test 10.45 and 10.49.
        foreach (['/(*scs:a)/' => 6, '/(a)(*scs:(1x/' => 11, '/(a)(*scs:(?/' => 10, '/(a)(*scs:(1/' => 11] as $pattern => $offset) {
            $this->assertSame($offset, Regex::create(['cache' => null, 'pcre_version' => '10.49'])->validate($pattern)->offset, $pattern);
        }
    }

    #[Test]
    public function test_the_assertion_is_written_back_everywhere(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.45']);
        $ast = $regex->parse("/(a)(*scs:(1,'n')b)(?<n>c)/");

        $this->assertStringContainsString("(*scs:(1,'n')", $ast->accept(new PatternPrinter(true)));
        $this->assertSame("(a)(*scs:(1,'n')b)(?<n>c)", preg_replace('/\e\[[\d;]*+m/', '', $regex->highlight("/(a)(*scs:(1,'n')b)(?<n>c)/")));

        // Rebuilt trees keep the list.
        $this->assertStringContainsString("(*scs:(1,'n')", $regex->optimize("/(a)(*scs:(1,'n')b)(?<n>c)/")->optimized);
        $python = $regex->parse('/(?P<n>a)(*scs:(1)b)/')->accept(new Modernizer());
        $this->assertInstanceOf(RegexNode::class, $python);
        $this->assertSame('/(?<n>a)(*scs:(1)b)/', $python->accept(new PatternPrinter()));
        $modernized = $ast->accept(new Modernizer());
        $this->assertInstanceOf(RegexNode::class, $modernized);
        $this->assertStringContainsString("(*scs:(1,'n')", $modernized->accept(new PatternPrinter()));
    }

    #[Test]
    public function test_every_analysis_reads_the_assertion(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.45']);
        $pattern = '/(a+)(*scs:(1)a|b)c|x(*scs:(1)y)/';
        $ast = $regex->parse($pattern);

        $this->assertStringContainsString('Substring scan of groups 1', $regex->explain($pattern));
        $this->assertStringContainsString('Substring Scan (of groups 1)', $regex->explain($pattern, 'html'));
        $tree = $ast->accept(new AsciiTreeRenderer());
        $diagram = $ast->accept(new RailroadSvgRenderer());
        $this->assertIsString($tree);
        $this->assertIsString($diagram);
        $this->assertStringContainsString('scan of groups 1', $tree);
        $this->assertStringContainsString('scan of groups 1', (string) $diagram);
        $this->assertSame($pattern, $regex->optimize($pattern)->original);
        $this->assertInstanceOf(RedosAnalysis::class, $regex->redos($pattern));
        $this->assertTrue($regex->analyze($pattern)->isValid);

        try {
            (new RegularSubsetValidator())->assertSupported($ast, $pattern, new SolverOptions());
            $this->fail('A substring scan is no regular language.');
        } catch (ComplexityException $error) {
            $this->assertNotSame('', $error->getMessage());
        }

        $this->assertNull(PcreVerb::groupListFault('(1,2)', 0));
        $this->assertSame([2, ErrorCode::GroupUnclosed], \array_slice(PcreVerb::groupListFault('(1x', 0) ?? [], 0, 2));
        $this->assertSame([1, ErrorCode::GroupListItemExpected], \array_slice(PcreVerb::groupListFault('(?', 0) ?? [], 0, 2));

        foreach (['javascript', 'python'] as $target) {
            try {
                $regex->transpile($pattern, $target);
                $this->fail($target.' has no substring scan.');
            } catch (TranspileException $error) {
                $this->assertStringContainsString('Substring scans are not supported', $error->getMessage());
            }
        }
    }

    #[Test]
    public function test_the_tree_holds_the_groups_and_the_body(): void
    {
        $pattern = Regex::create(['cache' => null, 'pcre_version' => '10.45'])->parse("/(a)(?<n>b)(*scs:(1,<n>,'n',-1)ab)/")->pattern;
        $this->assertInstanceOf(SequenceNode::class, $pattern);

        $assertion = $pattern->children[2];
        $this->assertInstanceOf(GroupNode::class, $assertion);
        $this->assertSame(GroupType::ScanSubstring, $assertion->type);
        $this->assertSame(['1', '<n>', "'n'", '-1'], $assertion->scannedGroups);
    }

    #[Test]
    public function test_the_assertion_matches_nothing_of_the_subject(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.45']);
        $pattern = '/(a+)(*scs:(1)a)b/';

        $this->assertSame([2, null], $regex->parse($pattern)->accept(new LengthRangeCalculator()));
        if (false !== @preg_match($pattern, '')) {
            $this->assertSame(1, preg_match($pattern, $regex->generate($pattern)));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, offsetBefore: int}>
     */
    public static function provideAssertions(): iterable
    {
        yield 'testinput2 case 1' => ['pattern' => '/(?=.{10}(.))(*scs:(1)(?2))x(\\K){0}/', 'offsetBefore' => 17];
        yield 'testinput2 case 2' => ['pattern' => '/(a)(*scs:(1)b)*c/', 'offsetBefore' => 8];
        yield 'testinput2 case 3' => ['pattern' => '/(a)(*scs:(1)b)*?c/', 'offsetBefore' => 8];
        yield 'testinput2 case 4' => ['pattern' => '/(a)(*scs:(1)b)*+c/', 'offsetBefore' => 8];
        yield 'testinput2 case 5' => ['pattern' => '/(a)(*scs:(1)b)+c/', 'offsetBefore' => 8];
        yield 'testinput2 case 6' => ['pattern' => '/(a)(*scs:(1)b)+?c/', 'offsetBefore' => 8];
        yield 'testinput2 case 7' => ['pattern' => '/(a)(*scs:(1)b)++c/', 'offsetBefore' => 8];
        yield 'testinput2 case 8' => ['pattern' => '/(a)(*scs:(1)b)?c/', 'offsetBefore' => 8];
        yield 'testinput2 case 9' => ['pattern' => '/(a)(*scs:(1)b)??c/', 'offsetBefore' => 8];
        yield 'testinput2 case 10' => ['pattern' => '/(a)(*scs:(1)b)?+c/', 'offsetBefore' => 8];
        yield 'testinput2 case 11' => ['pattern' => '/(a)(*scs:(1)b){3}c/', 'offsetBefore' => 8];
        yield 'testinput2 case 12' => ['pattern' => '/(a)(*scs:(1)b){3,5}?c/', 'offsetBefore' => 8];
        yield 'testinput2 case 13' => ['pattern' => '/(a)(*scs:(1)b){3,}+c/', 'offsetBefore' => 8];
        yield 'testinput2 case 14' => ['pattern' => '/([a-z]++)(*scs:(1)(stx)|(ne))(.)/', 'offsetBefore' => 14];
        yield 'testinput2 case 15' => ['pattern' => '/(?<XX>[a-z]++)##(*scan_substring:(\'XX\').*(..)$)\\2/', 'offsetBefore' => 32];
        yield 'testinput2 case 16' => ['pattern' => '/([a-z])([a-z]++)(#+)(*scs:(2)(ab.))/', 'offsetBefore' => 25];
        yield 'testinput2 case 17' => ['pattern' => '/(?:(?<YYY>[a-z]++)|(?<YYY>[0-9]++)|$)(*scan_substring:(\'YYY\')((?<START>.).*\\k<START>$))/J', 'offsetBefore' => 53];
        yield 'testinput2 case 18' => ['pattern' => '/([a-zA-Z]+)(*scs:(1).*?(?<ABC>[A-Z]+)(*scan_substring:(\'ABC\').*(.)\\3))#+/', 'offsetBefore' => 16];
        yield 'testinput2 case 19' => ['pattern' => '/([a-zA-Z]+)(*scs:(1)(xy|ab(*ACCEPT)cd))/', 'offsetBefore' => 16];
        yield 'testinput2 case 20' => ['pattern' => '/(?<AA>[a-zA-Z]+)(*scs:(\'AA\')(ab(*ACCEPT)cd|xy))/', 'offsetBefore' => 21];
        yield 'testinput2 case 21' => ['pattern' => '/([a-z]++)##(*scs:(1)(abc))?!/', 'offsetBefore' => 16];
        yield 'testinput2 case 22' => ['pattern' => '/([a-z]++)##(*scs:(1)(abc))??(?(2)!|:)/', 'offsetBefore' => 16];
        yield 'testinput2 case 23' => ['pattern' => '/([a-z]++)##(*scs:(1)(abc)|xyz){8}(?(2)!|:)/', 'offsetBefore' => 16];
        yield 'testinput2 case 24' => ['pattern' => '/[A-Z]{3}([A-Z]++)#(*scs:(1)(?<=BC)XY)#/', 'offsetBefore' => 23];
        yield 'testinput2 case 25' => ['pattern' => '/()(\\w++)=(*scs:(2)(?=abc))(\\w++)/', 'offsetBefore' => 14];
        yield 'testinput2 case 26' => ['pattern' => '/(\\d++)(*scs:(1)\\d+\\z)(\\w+)/', 'offsetBefore' => 11];
        yield 'testinput2 case 27' => ['pattern' => '/(\\d++)(*scs:(1)\\d+\\Z)(\\w+)/', 'offsetBefore' => 11];
        yield 'testinput2 case 28' => ['pattern' => '/(\\d++)(*scs:(1)\\d+$)(\\w+)/', 'offsetBefore' => 11];
        yield 'testinput2 case 29' => ['pattern' => '/([a-z]{2})[a-z](*scs:(1)(.*?))\\2$/', 'offsetBefore' => 20];
        yield 'testinput2 case 30' => ['pattern' => '/^(([a-z]([a-z]*+))(*scs:(2).(?=(?1)|$)\\3)|#){5}/', 'offsetBefore' => 23];
        yield 'testinput2 case 31' => ['pattern' => '/(*scs:(1)a)(a)|x/', 'offsetBefore' => 5];
        yield 'testinput2 case 32' => ['pattern' => '/(*scs:(<GOOD_NAME>)a)(?<GOOD_NAME>a)(?<GOOD_NAME>b)(?<GOOD_NAME>c)(?<GOOD_NAME>d)|x/J', 'offsetBefore' => 5];
        yield 'testinput2 case 33' => ['pattern' => '/(*scs:(1)a)?(a)/', 'offsetBefore' => 5];
        yield 'testinput2 case 34' => ['pattern' => '/(*scs:(1)a)??(a)/', 'offsetBefore' => 5];
        yield 'testinput2 case 35' => ['pattern' => '/x(?|(*scs:(1)(?<=(.)))|()){8}/', 'offsetBefore' => 9];
        yield 'testinput2 case 36' => ['pattern' => '/(a)(b)(*scs:(2)(*scs:(1)a(*PRUNE)x)).+|(.+)/', 'offsetBefore' => 11];
        yield 'testinput2 case 37' => ['pattern' => '/(a)(b)(*scs:(2)(*scs:(1)a(*PRUNE:markstr)x)).+|(.+)/', 'offsetBefore' => 11];
        yield 'testinput2 case 38' => ['pattern' => '/(a)(b)(*scs:(2)(*scs:(1)a(*PRUNE:markstr))).+|(.+)/', 'offsetBefore' => 11];
        yield 'testinput2 case 39' => ['pattern' => '/(a)(b)(*scs:(2)(*scs:(1)a(*COMMIT)x)).+|(.+)/', 'offsetBefore' => 11];
        yield 'testinput2 case 40' => ['pattern' => '/(a)(b)(*scs:(2)(*scs:(1)a(*COMMIT:markstr)x)).+|(.+)/', 'offsetBefore' => 11];
        yield 'testinput2 case 41' => ['pattern' => '/(a)(b)(*scs:(2)(*scs:(1)a(*COMMIT:markstr))).+|(.+)/', 'offsetBefore' => 11];
        yield 'testinput2 case 42' => ['pattern' => '/(abc)(def)(*scs:(1)(*scs:(2)de(*SKIP)x)).+|(.+)/', 'offsetBefore' => 15];
        yield 'testinput2 case 43' => ['pattern' => '/(abc)(def)(*scs:(2)(*scs:(1)(*SKIP)x)).+|(.+)/', 'offsetBefore' => 15];
        yield 'testinput2 case 44' => ['pattern' => '/(?<=(abc))(def)(*scs:(2)(*scs:(1)(*SKIP)x)).+|(ef.+)/', 'offsetBefore' => 20];
        yield 'testinput2 case 45' => ['pattern' => '/(abc)(def)(*scs:(2)(?:(*scs:(1)abc(*SKIP:notfound)x|abcd|(abc)))).+/', 'offsetBefore' => 15];
        yield 'testinput2 case 46' => ['pattern' => '/(abc)(def)(*MARK:markstr)(*scs:(2)(?:(*scs:(1)abc(*SKIP:markstr)x))).+|(.+)/', 'offsetBefore' => 30];
        yield 'testinput2 case 47' => ['pattern' => '/^([a-z]++)(?:((?6))|((?7))|((?8))|(#))(?(DEFINE)((*scs:(1)abc(*PRUNE)d))((*scs:(1)abc(*COMMIT)e))((*scs:(1)abc(*SKIP)f)))/', 'offsetBefore' => 54];
        yield 'testinput2 case 48' => ['pattern' => '/\\b(\\w++)(*scs:(1)^)/', 'offsetBefore' => 13];
        yield 'testinput2 case 49' => ['pattern' => '/(\\b\\w{3,}+\\b)(*scs:(1)\\W*+(?:((.)\\W*+(?2)\\W*+\\3|)|((.)\\W*+(?4)\\W*+\\5|\\W*+.\\W*+))\\W*+$)/i', 'offsetBefore' => 18];
        yield 'testinput2 case 50' => ['pattern' => '/(?:(?\'A\'a)|(?<A>b))(*scs:(\'A\')b)c/J', 'offsetBefore' => 24];
        yield 'testinput2 case 51' => ['pattern' => '/(xyz)(abc)(*scs:(-1)abc)(*scs:(-2)\\1)/', 'offsetBefore' => 15];
        yield 'testinput2 case 52' => ['pattern' => '/^([a-z]++)#(*scs:(1)a|ab|abc|abcd|abcde|abcdef|(abcdefg))\\2/', 'offsetBefore' => 16];
        yield 'testinput2 case 53' => ['pattern' => '/^([a-z]++)(*scs:(1)(a+)(*THEN)b|(a+)(*THEN)c|(aa))/', 'offsetBefore' => 15];
        yield 'testinput2 case 54' => ['pattern' => '/^([a-z]++)(*scs:(1)((a+)(*THEN)b)|(a+)(*THEN)c|(aa))/', 'offsetBefore' => 15];
        yield 'testinput2 case 55' => ['pattern' => '/^([a-z]++)(*scs:(1)((a+)(*THEN)b))?/', 'offsetBefore' => 15];
        yield 'testinput2 case 56' => ['pattern' => '/^([a-z]++)(*scs:(1)(abc|(a+)(*THEN)b))?/', 'offsetBefore' => 15];
        yield 'testinput2 case 57' => ['pattern' => '/^(?:(.){20,30}#|([a-z]++)(*scs:(1)(a+)(*THEN)b){20,30}#|(.){20,30}!)/', 'offsetBefore' => 30];
        yield 'testinput2 case 58' => ['pattern' => '/(?:(abc)|(?<PP>def)|ghi)(*scs:(1,\'PP\').(.))/', 'offsetBefore' => 29];
        yield 'testinput2 case 59' => ['pattern' => '/(?:(?<MM>abc)|(?<MM>def)|(ghi)|(?\'NN\'jkl)|mno)(*scs:(\'MM\',3,<NN>).(.))/J', 'offsetBefore' => 51];
        yield 'testinput2 case 60' => ['pattern' => '/f(?:(*scs:(+1,+2)(?<=(.)))|()){16}/', 'offsetBefore' => 9];
        yield 'testinput2 case 61' => ['pattern' => '/(?<AA>a)(*scan_substring:(1,\'AA\',1,<AA>)a)b/', 'offsetBefore' => 24];
        yield 'testinput2 case 62' => ['pattern' => '/()()()(?<=ab(*scs:(1,2,3))cd)xyz/', 'offsetBefore' => 17];
        yield 'testinput2 case 63' => ['pattern' => '/()()()(?<=ab(*ACCEPT)(*scs:(1,2,3))cd|efg)xyz/', 'offsetBefore' => 26];
        yield 'testinput2 case 64' => ['pattern' => '/(a)(*scs:(1)a(*ACCEPT))bbb/', 'offsetBefore' => 8];
        yield 'testinput2 case 65' => ['pattern' => '/(a)(b+)(*scs:(1)a(*ACCEPT))(\\2)/', 'offsetBefore' => 12];
        yield 'testinput2 case 66' => ['pattern' => '/(a)(b)(c)(d)(*scs:(4,3,1,2,2,1,3,3,4,4)x)/', 'offsetBefore' => 17];
        yield 'testinput2 case 67' => ['pattern' => '/(?<n>a)(?<n>b)(?<n>c)(d)(*scs:(2,3,1,1,<n>,<n>)abc)/J', 'offsetBefore' => 29];
        yield 'testinput2 case 68' => ['pattern' => '/(?<n>a)(?<n>b)(?<n>c)(d)(*scs:(2,1,1,<n>,<n>)abc)/J', 'offsetBefore' => 29];
        yield 'testinput2 case 69' => ['pattern' => '/(?<n>a)(?<n>b)(?<n>c)(d)(?<m>e)(?<m>f)(*scs:(<n>,5,3,2,1,4,1,4,<m>,6,<n>,<m>)abc)/J', 'offsetBefore' => 43];
    }

    /**
     * @return iterable<string, array{pattern: string, offsetBefore: int, offset1045: int, offset1049: int}>
     */
    public static function provideRefused(): iterable
    {
        // The body is judged as any part of the pattern, where it stands.
        yield 'unknown escape in the body' => ['pattern' => '/(a)(*scs:(1)\\q)/', 'offsetBefore' => 8, 'offset1045' => 13, 'offset1049' => 14];
        yield 'escape no class takes in the body' => ['pattern' => '/(a)(*scs:(1)[\\B])/', 'offsetBefore' => 8, 'offset1045' => 14, 'offset1049' => 15];
        yield 'range out of order in the body' => ['pattern' => '/(a)(*scs:(1)[z-a])/', 'offsetBefore' => 8, 'offset1045' => 15, 'offset1049' => 16];
        yield 'unknown escape in an extended class in the body' => ['pattern' => '/(a)(*scs:(1)(?[ \\q ]))/', 'offsetBefore' => 8, 'offset1045' => 17, 'offset1049' => 18];
        yield 'refused case 1' => ['pattern' => '/(*scs:/', 'offsetBefore' => 5, 'offset1045' => 6, 'offset1049' => 6];
        yield 'refused case 2' => ['pattern' => '/(*scan_substring:(/', 'offsetBefore' => 16, 'offset1045' => 18, 'offset1049' => 18];
        yield 'refused case 3' => ['pattern' => '/(*scs:(\'name\'/', 'offsetBefore' => 5, 'offset1045' => 13, 'offset1049' => 13];
        yield 'refused case 4' => ['pattern' => '/(*scs:(1)a|b)/', 'offsetBefore' => 5, 'offset1045' => 7, 'offset1049' => 7];
        yield 'refused case 5' => ['pattern' => '/(*scs:(0)a)/', 'offsetBefore' => 5, 'offset1045' => 8, 'offset1049' => 8];
        yield 'refused case 6' => ['pattern' => '/(*scan_substring:(1)a|b)/', 'offsetBefore' => 16, 'offset1045' => 18, 'offset1049' => 18];
        yield 'refused case 7' => ['pattern' => '/(*scs:(<name>)a|b)/', 'offsetBefore' => 5, 'offset1045' => 8, 'offset1049' => 8];
        yield 'refused case 8' => ['pattern' => '/(*scan_substring:(<name>)a|b)/', 'offsetBefore' => 16, 'offset1045' => 19, 'offset1049' => 19];
        yield 'refused case 9' => ['pattern' => '/()(*scs:(1)+a)/', 'offsetBefore' => 7, 'offset1045' => 11, 'offset1049' => 12];
        yield 'refused case 10' => ['pattern' => '/()(*scs:(1,1,1,1,1,1,1,1,2))/', 'offsetBefore' => 7, 'offset1045' => 25, 'offset1049' => 25];
        yield 'refused case 11' => ['pattern' => '/()()(*scs:(1,2,1,2,1,2,2,\'XYZ\'))/', 'offsetBefore' => 9, 'offset1045' => 26, 'offset1049' => 26];
        yield 'refused case 12' => ['pattern' => '/(\\w++)=(?(*scs:(1)(abc))pqr|xyz)(\\w++)/', 'offsetBefore' => 14, 'offset1045' => 14, 'offset1049' => 14];
    }

    /**
     * PCRE reads the list of groups item by item and names what stops it:
     * no "(", an item that is neither number nor name, a name that is
     * empty, starts with a digit or is not closed, or no "," or ")" after
     * an item. Codes and offsets from PHP 8.4 with PCRE2 10.49.
     */
    #[Test]
    #[DataProvider('provideGroupListFaults')]
    public function test_a_fault_in_the_group_list_is_reported_as_pcre_reads_it(string $pattern, ErrorCode $code, int $offset): void
    {
        $result = Regex::create(['cache' => null, 'pcre_version' => '10.49'])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame($code, $result->errorCode, \sprintf('%s: %s', $pattern, (string) $result->error));
        $this->assertSame($offset, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideGroupListFaults(): iterable
    {
        // The body never closes: the lexer reads the list.
        yield 'no list' => ['pattern' => '/(*scs:x/', 'code' => ErrorCode::ScanSubstringMissingList, 'offset' => 6];
        yield 'nothing after the name' => ['pattern' => '/(*scs:/', 'code' => ErrorCode::ScanSubstringMissingList, 'offset' => 6];
        yield 'list with no item' => ['pattern' => '/(*scs:(/', 'code' => ErrorCode::GroupListItemExpected, 'offset' => 7];
        yield 'no item after a comma' => ['pattern' => '/(*scs:(1,/', 'code' => ErrorCode::GroupListItemExpected, 'offset' => 9];
        yield 'letter as an item' => ['pattern' => '/(*scs:(a)/', 'code' => ErrorCode::GroupListItemExpected, 'offset' => 7];
        yield 'empty name' => ['pattern' => '/(*scs:(<>/', 'code' => ErrorCode::GroupNameExpected, 'offset' => 8];
        yield 'name left open' => ['pattern' => '/(*scs:(<a/', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 9];
        yield 'quoted name left open' => ['pattern' => "/(*scs:('a/", 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 9];
        yield 'list left open after a number' => ['pattern' => '/(*scs:(1/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 8];
        yield 'list left open after a name' => ['pattern' => '/(*scs:(<a>/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 10];
        yield 'letter after a number' => ['pattern' => '/(*scs:(1b/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 8];
        yield 'letter after a name' => ['pattern' => '/(*scs:(<a>b/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 10];
        yield 'list closed, body left open' => ['pattern' => '/(*scs:(1)a/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 10];

        // The body closes: the parser reads the list.
        yield 'empty name, body closed' => ['pattern' => '/(*scs:(<>)x)/', 'code' => ErrorCode::GroupNameExpected, 'offset' => 8];
        yield 'name starting with a digit' => ['pattern' => '/(*scs:(<1a>)x)/', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 9];
        yield 'letter as an item, body closed' => ['pattern' => '/(*scs:(a)x)/', 'code' => ErrorCode::GroupListItemExpected, 'offset' => 7];
        yield 'letter after a name, body closed' => ['pattern' => '/(*scs:(<a>b)x)/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 10];
        yield 'space after a name' => ['pattern' => '/(?<n>a)(*scs:(<n> )x)/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 17];

        // The groups a call returns, PCRE2 10.46, are read the same way.
        yield 'empty name in a call list' => ['pattern' => '/(a)(?1(<>))/', 'code' => ErrorCode::GroupNameExpected, 'offset' => 8];
        yield 'letter after a number in a call list' => ['pattern' => '/(a)(?1(1b))/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 8];
        yield 'letter in a call list' => ['pattern' => '/(a)(?1(x))/', 'code' => ErrorCode::GroupListItemExpected, 'offset' => 7];
    }

    /**
     * A listed name starting with a digit is refused past the digit from
     * PCRE2 10.47, on it before, as every group name is.
     */
    #[Test]
    public function test_a_listed_name_starting_with_a_digit_is_refused_where_the_release_reports_it(): void
    {
        foreach (['10.46' => 8, '10.49' => 9] as $release => $offset) {
            foreach (['/(*scs:(<1a>)x)/', '/(*scs:(<1a/'] as $pattern) {
                $result = Regex::create(['cache' => null, 'pcre_version' => (string) $release])->validate($pattern);

                $this->assertSame(ErrorCode::GroupNameInvalid, $result->errorCode, $pattern.' on '.$release);
                $this->assertSame($offset, $result->offset, $pattern.' on '.$release);
            }
        }
    }
}
