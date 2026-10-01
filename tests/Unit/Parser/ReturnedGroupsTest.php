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

namespace PhpRegex\Tests\Unit\Parser;

use PhpRegex\Explain\Highlighter\HtmlHighlighter;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\Node\SubroutineNode;
use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * From PCRE2 10.47 a call may name the groups it returns, "(?1(2,<name>))":
 * their captures are kept after the call. Before, the list is refused.
 * Every offset below is pcre2test's (10.46, 10.47 and 10.49), and the
 * patterns come from testinput2 of the PCRE2 suite.
 */
final class ReturnedGroupsTest extends TestCase
{
    #[Test]
    #[DataProvider('provideCalls')]
    public function test_a_call_returning_groups_is_read_from_pcre2_10_47(string $pattern, int $offsetBefore): void
    {
        foreach (['10.47', '10.49'] as $release) {
            $regex = Regex::create(['cache' => null, 'pcre_version' => $release]);
            $result = $regex->validate($pattern);

            $this->assertTrue($result->isValid, \sprintf('%s on %s: %s', $pattern, $release, $result->error));
            $this->assertSame($pattern, $regex->parse($pattern)->accept(new PatternPrinter()), $pattern);
        }

        $before = Regex::create(['cache' => null, 'pcre_version' => '10.46'])->validate($pattern);
        $this->assertFalse($before->isValid, $pattern);
        $this->assertSame($offsetBefore, $before->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offsetBefore: int}>
     */
    public static function provideCalls(): iterable
    {
        yield 'one group' => ['pattern' => '/^(?1(2))\\2(?(DEFINE)(a(.)b(.)c))/', 'offsetBefore' => 4];
        yield 'quoted name' => ['pattern' => "/^((.)(?<id>.))(?1('id'))(.)/", 'offsetBefore' => 17];
        yield 'several groups' => ['pattern' => '/^(?1(3,2,2,3,2))(?1(3))(?(DEFINE)(a(.)b(.)c))/', 'offsetBefore' => 4];
        yield 'repeated call' => ['pattern' => '/^(?1(1,2)){2,4}(?(DEFINE)((..)#))xx#/', 'offsetBefore' => 4];
        yield 'whole pattern' => ['pattern' => '/<(?:[^<>]*?(?:(AB)[^<>]*|)(?:|(?R(1))))+>/', 'offsetBefore' => 33];
        yield 'call by name' => ['pattern' => '/(?(DEFINE)(?<w>(?<s>Sat)urday))(?&w(<s>)),\\k<s>/', 'offsetBefore' => 35];
        yield 'call by P' => ['pattern' => '/(?(DEFINE)(?<w>(?<s>Sat)urday))(?P>w(<s>))/', 'offsetBefore' => 36];
        yield 'relative back' => ['pattern' => '/()(?-1(-1))/', 'offsetBefore' => 6];
        yield 'relative forward' => ['pattern' => '/(?+1(1))()/', 'offsetBefore' => 4];
        yield 'groups repeated' => ['pattern' => '/()()()(?2(2,3,2,3,2))/', 'offsetBefore' => 9];
    }

    #[Test]
    public function test_the_tree_holds_the_groups_as_written(): void
    {
        $call = Regex::create(['cache' => null, 'pcre_version' => '10.47'])->parse("/()(?<n>)(?1(1,-1,<n>,'n'))/")->pattern;

        $this->assertInstanceOf(SequenceNode::class, $call);
        $this->assertInstanceOf(SubroutineNode::class, $call->children[2] ?? null);
        $this->assertSame(['1', '-1', '<n>', "'n'"], $call->children[2]->returnedGroups);
    }

    #[Test]
    public function test_a_call_to_group_zero_padded_is_the_whole_pattern(): void
    {
        // PHP compiles "(?00)" as "(?0)", with or without returned groups.
        $this->assertSame(1, preg_match('/a(?00)?b/', 'ab'));

        $this->assertTrue(Regex::create(['cache' => null])->validate('/a(?00)?b/')->isValid);
        $this->assertTrue(Regex::create(['cache' => null, 'pcre_version' => '10.47'])->validate('/a(?00(1))?b()/')->isValid);
    }

    #[Test]
    public function test_the_highlighted_pattern_keeps_the_list(): void
    {
        $highlighted = Regex::create(['cache' => null, 'pcre_version' => '10.47'])->highlight("/(a)(?<n>b)(?1(1,'n'))(?&n(<n>))/");

        $this->assertSame("(a)(?<n>b)(?1(1,'n'))(?&n(<n>))", preg_replace('/\e\[[\d;]*+m/', '', $highlighted));

        $pattern = '(?<=a)(?<!b)(?>c)(?<n>d)(?&n(<n>))(?P>n(<n>))';
        $html = Regex::create(['cache' => null, 'pcre_version' => '10.47'])->parse('/'.$pattern.'/')->accept(new HtmlHighlighter());
        $this->assertSame($pattern, html_entity_decode(strip_tags($html)));
    }

    #[Test]
    #[DataProvider('provideRefused')]
    public function test_a_list_pcre2_cannot_take_is_refused_where_it_stops(string $pattern, int $offset): void
    {
        $result = Regex::create(['cache' => null, 'pcre_version' => '10.49'])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame($offset, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideRefused(): iterable
    {
        // "expected capture group number or name"
        yield 'empty list at the end' => ['pattern' => '/(?1(/', 'offset' => 4];
        yield 'comma then the end' => ['pattern' => '/(?R(1,/', 'offset' => 6];
        yield 'space' => ['pattern' => '/(?1( 1))()/', 'offset' => 4];
        yield 'comma last' => ['pattern' => '/(?1(1,))()/', 'offset' => 6];
        yield 'comma first' => ['pattern' => '/(?1(,1))()/', 'offset' => 4];
        yield 'empty list' => ['pattern' => '/(?1()())/', 'offset' => 4];
        // "missing closing parenthesis"
        yield 'list never closed' => ['pattern' => '/(?R(1/', 'offset' => 5];
        yield 'call never closed' => ['pattern' => '/(?1(1)/', 'offset' => 6];
        yield 'text after the list' => ['pattern' => '/(?1(1)x)()/', 'offset' => 6];
        // Refused as it is read.
        yield 'group zero' => ['pattern' => '/(?1(1,0))()/', 'offset' => 7];
        yield 'relative zero' => ['pattern' => '/()(?1(+0))/', 'offset' => 8];
        yield 'back past the first group' => ['pattern' => '/()(?1(-2))/', 'offset' => 8];
        yield 'number too big' => ['pattern' => '/(?1(99999))()/', 'offset' => 9];
        // Refused once the pattern is read, where the group is named.
        yield 'missing group' => ['pattern' => '/(?1(5))()/', 'offset' => 4];
        yield 'missing group after another' => ['pattern' => '/(?1(1,5))()/', 'offset' => 6];
        yield 'missing group forward' => ['pattern' => '/(?1(1,+2))()/', 'offset' => 6];
        yield 'missing name' => ['pattern' => "/(?1(1,'nn'))(?<n>a)/", 'offset' => 7];
        yield 'missing call before its list' => ['pattern' => '/(?1(<name>))/', 'offset' => 3];
    }
}
