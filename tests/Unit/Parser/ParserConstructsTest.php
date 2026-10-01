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

use PhpRegex\Parser\Exception\LexerException;
use PhpRegex\Parser\Exception\ParserException;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One case per PCRE construct the parser claims to read.
 *
 * Reading a construct is only half of it: what the parser understood is
 * checked by writing the pattern back out. A construct that is silently
 * dropped, or spelled differently on the way out, shows up here.
 */
final class ParserConstructsTest extends TestCase
{
    #[Test]
    #[DataProvider('provideConstructs')]
    public function test_a_construct_is_read_and_written_back(string $pattern, string $recompiled): void
    {
        $ast = Regex::create()->parse($pattern);

        $this->assertInstanceOf(RegexNode::class, $ast);
        $this->assertSame($recompiled, $ast->accept(new PatternPrinter()));
    }

    #[Test]
    #[DataProvider('provideConstructsPcreAccepts')]
    public function test_what_is_written_back_is_still_a_pattern_pcre_accepts(string $pattern): void
    {
        $recompiled = Regex::create()->parse($pattern)->accept(new PatternPrinter());

        $this->assertTrue(
            $this->compiles($recompiled),
            \sprintf('PCRE rejects "%s", recompiled from "%s".', $recompiled, $pattern),
        );
    }

    #[Test]
    public function test_a_recursion_condition_is_written_back_as_a_condition(): void
    {
        // "(?(R)" asks whether the pattern is recursing. Compiling it as a
        // subroutine call, "(?((?R))", gives a pattern PCRE refuses.
        $recompiled = Regex::create()->parse('/(?(R)yes|no)/')->accept(new PatternPrinter());

        $this->assertSame('/(?(R)yes|no)/', $recompiled);
    }

    /**
     * Rows that once sat in provideConstructs(), on a pattern PHP refuses.
     */
    #[Test]
    #[DataProvider('provideConstructsPhpRefuses')]
    public function test_a_construct_php_refuses_is_not_read(string $pattern): void
    {
        $this->expectException(LexerException::class);

        Regex::create()->parse($pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideConstructsPhpRefuses(): iterable
    {
        // preg_match() on PCRE2 10.48: "missing terminating ] for character class at offset 6".
        // A "]" right after an empty "\Q\E" is a member of the class, not its end.
        yield 'char class quote mode empty: /[\\Q\\E]/' => ['pattern' => '/[\\Q\\E]/'];
    }

    /**
     * Rows that once sat in provideConstructs(), on a pattern PHP refuses
     * while reading its groups.
     */
    #[Test]
    #[DataProvider('provideConstructsParserRefuses')]
    public function test_a_construct_php_refuses_is_refused_by_the_parser(string $pattern): void
    {
        $this->expectException(ParserException::class);

        Regex::create()->parse($pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideConstructsParserRefuses(): iterable
    {
        // preg_match() on PCRE2 10.40 to 10.48: "subpattern name expected at offset 3".
        // After "(?(", "?" opens the assertion: "(?(?=test)" is a condition, "(?((?=test))" is not.
        yield 'conditional with lookahead positive: /(?((?=test))yes|no)/' => ['pattern' => '/(?((?=test))yes|no)/'];
        yield 'conditional with lookahead negative: /(?((?!test))yes|no)/' => ['pattern' => '/(?((?!test))yes|no)/'];
        yield 'conditional with lookbehind positive: /(?((?<=test))yes|no)/' => ['pattern' => '/(?((?<=test))yes|no)/'];
        yield 'conditional with lookbehind negative: /(?((?<!test))yes|no)/' => ['pattern' => '/(?((?<!test))yes|no)/'];
        yield 'parser conditional with lookahead negative: /(?((?!x))yes|no)/' => ['pattern' => '/(?((?!x))yes|no)/'];
        // preg_match() on PCRE2 10.40 to 10.48: "subpattern name expected at offset 12" and 13.
        // A condition names its group bare, in "<>" or in quotes, never in "{}" or ">...<".
        yield 'conditional with curly brace name: /(?<foo>x)(?({foo})yes|no)/' => ['pattern' => '/(?<foo>x)(?({foo})yes|no)/'];
        yield 'parser conditional with curly brace name: /(?<name>x)(?({name})yes|no)/' => ['pattern' => '/(?<name>x)(?({name})yes|no)/'];
        yield 'parser conditional with angle bracket name: /(?<name>x)(?(>name<)yes|no)/' => ['pattern' => '/(?<name>x)(?(>name<)yes|no)/'];

        // preg_match() on PCRE2 10.48: "unrecognized character after (?P at offset 4"; 10.40 at 3.
        // "(?P" takes "<", ">" or "=": the quoted name is "(?'name'...)", without the "P".
        yield 'python named group single quotes: /(?P\'name\'test)/' => ['pattern' => '/(?P\'name\'test)/'];
        yield 'python named group double quotes: /(?P"name"test)/' => ['pattern' => '/(?P"name"test)/'];
        yield 'python style named groups with quotes: /(?P\'name\'a)/' => ['pattern' => '/(?P\'name\'a)/'];
        yield 'python style named groups with quotes: /(?P"name"a)/' => ['pattern' => '/(?P"name"a)/'];
    }

    /**
     * @return iterable<string, array{pattern: string, recompiled: string}>
     */
    public static function provideConstructs(): iterable
    {
        yield 'python named group angle brackets: /(?P<name>test)/' => [
            'pattern' => '/(?P<name>test)/',
            'recompiled' => '/(?P<name>test)/',
        ];

        yield 'python subroutine call: /(?P<name>test)(?P>name)/' => [
            'pattern' => '/(?P<name>test)(?P>name)/',
            'recompiled' => '/(?P<name>test)(?P>name)/',
        ];

        yield 'positive lookbehind: /(?<=test)abc/' => [
            'pattern' => '/(?<=test)abc/',
            'recompiled' => '/(?<=test)abc/',
        ];

        yield 'negative lookbehind: /(?<!test)abc/' => [
            'pattern' => '/(?<!test)abc/',
            'recompiled' => '/(?<!test)abc/',
        ];

        yield 'conditional with number: /(test)(?(1)yes|no)/' => [
            'pattern' => '/(test)(?(1)yes|no)/',
            'recompiled' => '/(test)(?(1)yes|no)/',
        ];

        yield 'conditional with named group: /(?<name>test)(?(<name>)yes|no)/' => [
            'pattern' => '/(?<name>test)(?(<name>)yes|no)/',
            'recompiled' => '/(?<name>test)(?(name)yes|no)/',
        ];

        yield 'conditional non define resets token stream: /(?(DEFINEX)yes|no)/' => [
            'pattern' => '/(?(DEFINEX)yes|no)/',
            'recompiled' => '/(?(DEFINEX)yes|no)/',
        ];

        yield 'conditional lookaround condition: /(?(?=a)yes|no)/' => [
            'pattern' => '/(?(?=a)yes|no)/',
            'recompiled' => '/(?(?=a)yes|no)/',
        ];

        yield 'conditional without else: /(test)(?(1)yes)/' => [
            'pattern' => '/(test)(?(1)yes)/',
            'recompiled' => '/(test)(?(1)yes)/',
        ];

        yield 'subroutine call by number: /(test)(?1)/' => [
            'pattern' => '/(test)(?1)/',
            'recompiled' => '/(test)(?1)/',
        ];

        yield 'subroutine call relative: /(test)(?-1)/' => [
            'pattern' => '/(test)(?-1)/',
            'recompiled' => '/(test)(?-1)/',
        ];

        yield 'subroutine call named: /(?<name>test)(?&name)/' => [
            'pattern' => '/(?<name>test)(?&name)/',
            'recompiled' => '/(?<name>test)(?&name)/',
        ];

        yield 'atomic group: /(?>test)/' => [
            'pattern' => '/(?>test)/',
            'recompiled' => '/(?>test)/',
        ];

        yield 'non capturing group: /(?:test)/' => [
            'pattern' => '/(?:test)/',
            'recompiled' => '/(?:test)/',
        ];

        yield 'group with flags i: /(?i:test)/' => [
            'pattern' => '/(?i:test)/',
            'recompiled' => '/(?i:test)/',
        ];

        yield 'group with multiple flags: /(?im:test)/' => [
            'pattern' => '/(?im:test)/',
            'recompiled' => '/(?im:test)/',
        ];

        yield 'group with negative flags: /(?-i:test)/' => [
            'pattern' => '/(?-i:test)/',
            'recompiled' => '/(?-i:test)/',
        ];

        yield 'group with mixed flags: /(?i-m:test)/' => [
            'pattern' => '/(?i-m:test)/',
            'recompiled' => '/(?i-m:test)/',
        ];

        yield 'char class with range: /[a-z]/' => [
            'pattern' => '/[a-z]/',
            'recompiled' => '/[a-z]/',
        ];

        yield 'char class with multiple ranges: /[a-zA-Z0-9]/' => [
            'pattern' => '/[a-zA-Z0-9]/',
            'recompiled' => '/[a-zA-Z0-9]/',
        ];

        yield 'negated char class: /[^a-z]/' => [
            'pattern' => '/[^a-z]/',
            'recompiled' => '/[^a-z]/',
        ];

        yield 'posix char class: /[[:alnum:]]/' => [
            'pattern' => '/[[:alnum:]]/',
            'recompiled' => '/[[:alnum:]]/',
        ];

        yield 'negated posix char class: /[[:^alnum:]]/' => [
            'pattern' => '/[[:^alnum:]]/',
            'recompiled' => '/[[:^alnum:]]/',
        ];

        yield 'octal legacy: /\\101/' => [
            'pattern' => '/\\101/',
            'recompiled' => '/\\101/',
        ];

        yield 'octal modern: /\\o{101}/' => [
            'pattern' => '/\\o{101}/',
            'recompiled' => '/\\o{101}/',
        ];

        yield 'unicode escape hex: /\\x41/' => [
            'pattern' => '/\\x41/',
            'recompiled' => '/\\x41/',
        ];

        yield 'unicode escape braces: /\\u{0041}/' => [
            'pattern' => '/\\u{0041}/',
            'recompiled' => '/\\u{0041}/',
        ];

        yield 'pcre verb fail: /(*FAIL)/' => [
            'pattern' => '/(*FAIL)/',
            'recompiled' => '/(*FAIL)/',
        ];

        yield 'pcre verb mark: /(*MARK:test)/' => [
            'pattern' => '/(*MARK:test)/',
            'recompiled' => '/(*MARK:test)/',
        ];

        yield 'keep: /test\\K/' => [
            'pattern' => '/test\\K/',
            'recompiled' => '/test\\K/',
        ];

        yield 'anchors: /^test/' => [
            'pattern' => '/^test/',
            'recompiled' => '/^test/',
        ];

        yield 'anchors: /test$/' => [
            'pattern' => '/test$/',
            'recompiled' => '/test$/',
        ];

        yield 'anchors: /^test$/' => [
            'pattern' => '/^test$/',
            'recompiled' => '/^test$/',
        ];

        yield 'assertions: /\\A/' => [
            'pattern' => '/\\A/',
            'recompiled' => '/\\A/',
        ];

        yield 'assertions: /\\z/' => [
            'pattern' => '/\\z/',
            'recompiled' => '/\\z/',
        ];

        yield 'assertions: /\\Z/' => [
            'pattern' => '/\\Z/',
            'recompiled' => '/\\Z/',
        ];

        yield 'assertions: /\\G/' => [
            'pattern' => '/\\G/',
            'recompiled' => '/\\G/',
        ];

        yield 'assertions: /\\b/' => [
            'pattern' => '/\\b/',
            'recompiled' => '/\\b/',
        ];

        yield 'assertions: /\\B/' => [
            'pattern' => '/\\B/',
            'recompiled' => '/\\B/',
        ];

        yield 'char types: /\\d/' => [
            'pattern' => '/\\d/',
            'recompiled' => '/\\d/',
        ];

        yield 'char types: /\\D/' => [
            'pattern' => '/\\D/',
            'recompiled' => '/\\D/',
        ];

        yield 'char types: /\\s/' => [
            'pattern' => '/\\s/',
            'recompiled' => '/\\s/',
        ];

        yield 'char types: /\\S/' => [
            'pattern' => '/\\S/',
            'recompiled' => '/\\S/',
        ];

        yield 'char types: /\\w/' => [
            'pattern' => '/\\w/',
            'recompiled' => '/\\w/',
        ];

        yield 'char types: /\\W/' => [
            'pattern' => '/\\W/',
            'recompiled' => '/\\W/',
        ];

        yield 'char types: /\\h/' => [
            'pattern' => '/\\h/',
            'recompiled' => '/\\h/',
        ];

        yield 'char types: /\\H/' => [
            'pattern' => '/\\H/',
            'recompiled' => '/\\H/',
        ];

        yield 'char types: /\\v/' => [
            'pattern' => '/\\v/',
            'recompiled' => '/\\v/',
        ];

        yield 'char types: /\\V/' => [
            'pattern' => '/\\V/',
            'recompiled' => '/\\V/',
        ];

        yield 'char types: /\\R/' => [
            'pattern' => '/\\R/',
            'recompiled' => '/\\R/',
        ];

        yield 'backref numbered: /(test)\\1/' => [
            'pattern' => '/(test)\\1/',
            'recompiled' => '/(test)\\1/',
        ];

        yield 'backref named k angle: /(?<name>test)\\k<name>/' => [
            'pattern' => '/(?<name>test)\\k<name>/',
            'recompiled' => '/(?<name>test)\\k<name>/',
        ];

        yield 'backref named k brace: /(?<name>test)\\k{name}/' => [
            'pattern' => '/(?<name>test)\\k{name}/',
            'recompiled' => '/(?<name>test)\\k{name}/',
        ];

        yield 'g reference number: /(test)\\g1/' => [
            'pattern' => '/(test)\\g1/',
            'recompiled' => '/(test)\\g1/',
        ];

        yield 'g reference relative: /(test)\\g-1/' => [
            'pattern' => '/(test)\\g-1/',
            'recompiled' => '/(test)\\g-1/',
        ];

        yield 'g reference angle: /(?<name>test)\\g<name>/' => [
            'pattern' => '/(?<name>test)\\g<name>/',
            'recompiled' => '/(?<name>test)\\g<name>/',
        ];

        // "\g{name}" is a back reference, "\g<name>" a call: preg_match() on
        // PCRE2 10.48 matches "ab" with /^(?<n>a|b)\g<n>$/ but not with
        // /^(?<n>a|b)\g{n}$/. The braced spelling is written back as is.
        yield 'g reference brace: /(?<name>test)\\g{name}/' => [
            'pattern' => '/(?<name>test)\\g{name}/',
            'recompiled' => '/(?<name>test)\\g{name}/',
        ];

        yield 'dot: /./' => [
            'pattern' => '/./',
            'recompiled' => '/./',
        ];

        yield 'quantifiers: /a*/' => [
            'pattern' => '/a*/',
            'recompiled' => '/a*/',
        ];

        yield 'quantifiers: /a+/' => [
            'pattern' => '/a+/',
            'recompiled' => '/a+/',
        ];

        yield 'quantifiers: /a?/' => [
            'pattern' => '/a?/',
            'recompiled' => '/a?/',
        ];

        yield 'quantifiers: /a{2}/' => [
            'pattern' => '/a{2}/',
            'recompiled' => '/a{2}/',
        ];

        yield 'quantifiers: /a{2,}/' => [
            'pattern' => '/a{2,}/',
            'recompiled' => '/a{2,}/',
        ];

        yield 'quantifiers: /a{2,5}/' => [
            'pattern' => '/a{2,5}/',
            'recompiled' => '/a{2,5}/',
        ];

        yield 'quantifiers: /a*?/' => [
            'pattern' => '/a*?/',
            'recompiled' => '/a*?/',
        ];

        yield 'quantifiers: /a+?/' => [
            'pattern' => '/a+?/',
            'recompiled' => '/a+?/',
        ];

        yield 'quantifiers: /a??/' => [
            'pattern' => '/a??/',
            'recompiled' => '/a??/',
        ];

        yield 'quantifiers: /a{2,5}?/' => [
            'pattern' => '/a{2,5}?/',
            'recompiled' => '/a{2,5}?/',
        ];

        yield 'quantifiers: /a*+/' => [
            'pattern' => '/a*+/',
            'recompiled' => '/a*+/',
        ];

        yield 'quantifiers: /a++/' => [
            'pattern' => '/a++/',
            'recompiled' => '/a++/',
        ];

        yield 'quantifiers: /a?+/' => [
            'pattern' => '/a?+/',
            'recompiled' => '/a?+/',
        ];

        yield 'quantifiers: /a{2,5}+/' => [
            'pattern' => '/a{2,5}+/',
            'recompiled' => '/a{2,5}+/',
        ];

        yield 'comment: /(?#this is a comment)test/' => [
            'pattern' => '/(?#this is a comment)test/',
            'recompiled' => '/(?#this is a comment)test/',
        ];

        yield 'alternation: /foo|bar|baz/' => [
            'pattern' => '/foo|bar|baz/',
            'recompiled' => '/foo|bar|baz/',
        ];

        yield 'empty alternation branches: /foo||bar/' => [
            'pattern' => '/foo||bar/',
            'recompiled' => '/foo||bar/',
        ];

        yield 'complex nested: /(?:(?<name>test)|(?P<other>foo)){2,5}/' => [
            'pattern' => '/(?:(?<name>test)|(?P<other>foo)){2,5}/',
            'recompiled' => '/(?:(?<name>test)|(?P<other>foo)){2,5}/',
        ];

        yield 'various delimiters: /test/' => [
            'pattern' => '/test/',
            'recompiled' => '/test/',
        ];

        yield 'various delimiters: #test#' => [
            'pattern' => '#test#',
            'recompiled' => '#test#',
        ];

        yield 'various delimiters: ~test~' => [
            'pattern' => '~test~',
            'recompiled' => '~test~',
        ];

        yield 'various delimiters: @test@' => [
            'pattern' => '@test@',
            'recompiled' => '@test@',
        ];

        yield 'various delimiters: !test!' => [
            'pattern' => '!test!',
            'recompiled' => '!test!',
        ];

        yield 'various delimiters: %test%' => [
            'pattern' => '%test%',
            'recompiled' => '%test%',
        ];

        yield 'various delimiters: {test}' => [
            'pattern' => '{test}',
            'recompiled' => '{test}',
        ];

        yield 'extract pattern and flags: /test/i' => [
            'pattern' => '/test/i',
            'recompiled' => '/test/i',
        ];

        yield 'extract pattern and flags: /test/im' => [
            'pattern' => '/test/im',
            'recompiled' => '/test/im',
        ];

        yield 'extract pattern and flags: /test/ims' => [
            'pattern' => '/test/ims',
            'recompiled' => '/test/ims',
        ];

        yield 'extract pattern and flags: /test/imsx' => [
            'pattern' => '/test/imsx',
            'recompiled' => '/test/imsx',
        ];

        yield 'extract pattern and flags: /test/imsxu' => [
            'pattern' => '/test/imsxu',
            'recompiled' => '/test/imsxu',
        ];

        yield 'extract pattern and flags: /test/imsxuD' => [
            'pattern' => '/test/imsxuD',
            'recompiled' => '/test/imsxuD',
        ];

        yield 'extract pattern and flags: /test/imsxuDU' => [
            'pattern' => '/test/imsxuDU',
            'recompiled' => '/test/imsxuDU',
        ];

        yield 'extract pattern and flags: /test/imsxuDUA' => [
            'pattern' => '/test/imsxuDUA',
            'recompiled' => '/test/imsxuDUA',
        ];

        yield 'extract pattern and flags: /test/imsxuDUAJ' => [
            'pattern' => '/test/imsxuDUAJ',
            'recompiled' => '/test/imsxuDUAJ',
        ];

        yield 'conditional with numeric reference: /(a)(?(1)yes|no)/' => [
            'pattern' => '/(a)(?(1)yes|no)/',
            'recompiled' => '/(a)(?(1)yes|no)/',
        ];

        yield 'conditional with multi digit numeric reference: /(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)(k)(l)(?(12)yes|no)/' => [
            'pattern' => '/(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)(k)(l)(?(12)yes|no)/',
            'recompiled' => '/(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)(k)(l)(?(12)yes|no)/',
        ];

        yield 'conditional with lookahead condition: /(?(?=test)yes|no)/' => [
            'pattern' => '/(?(?=test)yes|no)/',
            'recompiled' => '/(?(?=test)yes|no)/',
        ];

        yield 'conditional with negative lookahead condition: /(?(?!test)yes|no)/' => [
            'pattern' => '/(?(?!test)yes|no)/',
            'recompiled' => '/(?(?!test)yes|no)/',
        ];

        yield 'conditional with bare group name: /(?<foo>x)(?(foo)yes|no)/' => [
            'pattern' => '/(?<foo>x)(?(foo)yes|no)/',
            'recompiled' => '/(?<foo>x)(?(foo)yes|no)/',
        ];

        yield 'conditional with recursion check: /(?(R)yes|no)/' => [
            'pattern' => '/(?(R)yes|no)/',
            'recompiled' => '/(?(R)yes|no)/',
        ];

        yield 'conditional with angle bracket name: /(?<name>x)(?(<name>)yes|no)/' => [
            'pattern' => '/(?<name>x)(?(<name>)yes|no)/',
            'recompiled' => '/(?<name>x)(?(name)yes|no)/',
        ];

        yield 'quantifier with exact count: /a{5}/' => [
            'pattern' => '/a{5}/',
            'recompiled' => '/a{5}/',
        ];

        yield 'g reference variations: /(?<name>a)\\g<name>/' => [
            'pattern' => '/(?<name>a)\\g<name>/',
            'recompiled' => '/(?<name>a)\\g<name>/',
        ];

        // A back reference, not a call; see "g reference brace" above.
        yield 'g reference variations: /(?<name>a)\\g{name}/' => [
            'pattern' => '/(?<name>a)\\g{name}/',
            'recompiled' => '/(?<name>a)\\g{name}/',
        ];

        yield 'g reference variations: /(a)\\g1/' => [
            'pattern' => '/(a)\\g1/',
            'recompiled' => '/(a)\\g1/',
        ];

        yield 'g reference variations: /(a)\\g{1}/' => [
            'pattern' => '/(a)\\g{1}/',
            'recompiled' => '/(a)\\g{1}/',
        ];

        yield 'g reference variations: /(a)\\g{-1}/' => [
            'pattern' => '/(a)\\g{-1}/',
            'recompiled' => '/(a)\\g{-1}/',
        ];

        yield 'g reference variations: /(a)\\g{+1}/' => [
            'pattern' => '/(a)\\g{+1}/',
            'recompiled' => '/(a)\\g{+1}/',
        ];

        yield 'subroutine call p syntax: /(?<foo>a)(?P>foo)/' => [
            'pattern' => '/(?<foo>a)(?P>foo)/',
            'recompiled' => '/(?<foo>a)(?P>foo)/',
        ];

        yield 'char class negation: /[^abc]/' => [
            'pattern' => '/[^abc]/',
            'recompiled' => '/[^abc]/',
        ];

        yield 'char class with dash positions: /[-abc]/' => [
            'pattern' => '/[-abc]/',
            'recompiled' => '/[-abc]/',
        ];

        yield 'char class with dash positions: /[abc-]/' => [
            'pattern' => '/[abc-]/',
            'recompiled' => '/[abc-]/',
        ];

        yield 'empty alternation branches: /abc|/' => [
            'pattern' => '/abc|/',
            'recompiled' => '/abc|/',
        ];

        yield 'empty alternation branches: /|abc/' => [
            'pattern' => '/|abc/',
            'recompiled' => '/|abc/',
        ];

        yield 'empty alternation branches: /abc||def/' => [
            'pattern' => '/abc||def/',
            'recompiled' => '/abc||def/',
        ];

        yield 'comment groups: /(?#this is a comment)abc/' => [
            'pattern' => '/(?#this is a comment)abc/',
            'recompiled' => '/(?#this is a comment)abc/',
        ];

        yield 'pcre verbs: /(*ACCEPT)/' => [
            'pattern' => '/(*ACCEPT)/',
            'recompiled' => '/(*ACCEPT)/',
        ];

        yield 'pcre verbs: /(*SKIP)/' => [
            'pattern' => '/(*SKIP)/',
            'recompiled' => '/(*SKIP)/',
        ];

        yield 'pcre verbs: /(*MARK:foo)/' => [
            'pattern' => '/(*MARK:foo)/',
            'recompiled' => '/(*MARK:foo)/',
        ];

        yield 'pcre verbs: /(*COMMIT)/' => [
            'pattern' => '/(*COMMIT)/',
            'recompiled' => '/(*COMMIT)/',
        ];

        yield 'pcre verbs: /(*PRUNE)/' => [
            'pattern' => '/(*PRUNE)/',
            'recompiled' => '/(*PRUNE)/',
        ];

        yield 'atomic groups: /(?>abc)/' => [
            'pattern' => '/(?>abc)/',
            'recompiled' => '/(?>abc)/',
        ];

        yield 'recursive pattern: /(?R)/' => [
            'pattern' => '/(?R)/',
            'recompiled' => '/(?R)/',
        ];

        yield 'octal sequences: /\\o{77}/' => [
            'pattern' => '/\\o{77}/',
            'recompiled' => '/\\o{77}/',
        ];

        yield 'octal sequences: /\\01/' => [
            'pattern' => '/\\01/',
            'recompiled' => '/\\01/',
        ];

        yield 'octal sequences: /\\077/' => [
            'pattern' => '/\\077/',
            'recompiled' => '/\\077/',
        ];

        yield 'unicode sequences: /\\u{41}/' => [
            'pattern' => '/\\u{41}/',
            'recompiled' => '/\\u{41}/',
        ];

        yield 'unicode sequences: /\\u{1F600}/' => [
            'pattern' => '/\\u{1F600}/',
            'recompiled' => '/\\u{1F600}/',
        ];

        yield 'unicode properties: /\\p{L}/' => [
            'pattern' => '/\\p{L}/',
            'recompiled' => '/\\p{L}/',
        ];

        yield 'unicode properties: /\\P{L}/' => [
            'pattern' => '/\\P{L}/',
            'recompiled' => '/\\P{L}/',
        ];

        yield 'unicode properties: /\\p{^L}/' => [
            'pattern' => '/\\p{^L}/',
            'recompiled' => '/\\p{^L}/',
        ];

        yield 'unicode properties: /\\P{^L}/' => [
            'pattern' => '/\\P{^L}/',
            'recompiled' => '/\\P{^L}/',
        ];

        yield 'unicode properties: /\\pL/' => [
            'pattern' => '/\\pL/',
            'recompiled' => '/\\pL/',
        ];

        yield 'posix character classes: /[[:alpha:]]/' => [
            'pattern' => '/[[:alpha:]]/',
            'recompiled' => '/[[:alpha:]]/',
        ];

        yield 'posix character classes: /[[:digit:]]/' => [
            'pattern' => '/[[:digit:]]/',
            'recompiled' => '/[[:digit:]]/',
        ];

        yield 'posix character classes: /[[:^digit:]]/' => [
            'pattern' => '/[[:^digit:]]/',
            'recompiled' => '/[[:^digit:]]/',
        ];

        yield 'inline flags: /(?i)abc/' => [
            'pattern' => '/(?i)abc/',
            'recompiled' => '/(?i)abc/',
        ];

        yield 'inline flags: /(?-i)abc/' => [
            'pattern' => '/(?-i)abc/',
            'recompiled' => '/(?-i)abc/',
        ];

        yield 'inline flags: /(?i:abc)/' => [
            'pattern' => '/(?i:abc)/',
            'recompiled' => '/(?i:abc)/',
        ];

        yield 'inline flags: /(?ims)abc/' => [
            'pattern' => '/(?ims)abc/',
            'recompiled' => '/(?ims)abc/',
        ];

        yield 'inline flags: /(?i-s)abc/' => [
            'pattern' => '/(?i-s)abc/',
            'recompiled' => '/(?i-s)abc/',
        ];

        yield 'backreferences: /(a)\\1/' => [
            'pattern' => '/(a)\\1/',
            'recompiled' => '/(a)\\1/',
        ];

        yield 'backreferences: /(a)\\k<1>/' => [
            'pattern' => '/(a)\\k<1>/',
            'recompiled' => '/(a)\\k<1>/',
        ];

        yield 'backreferences: /(?<n>a)\\k<n>/' => [
            'pattern' => '/(?<n>a)\\k<n>/',
            'recompiled' => '/(?<n>a)\\k<n>/',
        ];

        yield 'backreferences: /(?<n>a)\\k{n}/' => [
            'pattern' => '/(?<n>a)\\k{n}/',
            'recompiled' => '/(?<n>a)\\k{n}/',
        ];

        yield 'escaped special characters: /\\t/' => [
            'pattern' => '/\\t/',
            'recompiled' => '/\\t/',
        ];

        yield 'escaped special characters: /\\n/' => [
            'pattern' => '/\\n/',
            'recompiled' => '/\\n/',
        ];

        yield 'escaped special characters: /\\r/' => [
            'pattern' => '/\\r/',
            'recompiled' => '/\\r/',
        ];

        yield 'escaped special characters: /\\f/' => [
            'pattern' => '/\\f/',
            'recompiled' => '/\\f/',
        ];

        yield 'escaped special characters: /\\e/' => [
            'pattern' => '/\\e/',
            'recompiled' => '/\\e/',
        ];

        yield 'escaped special characters: /\\./' => [
            'pattern' => '/\\./',
            'recompiled' => '/\\./',
        ];

        yield 'escaped special characters: /\\[/' => [
            'pattern' => '/\\[/',
            'recompiled' => '/\\[/',
        ];

        yield 'escaped special characters: /\\]/' => [
            'pattern' => '/\\]/',
            'recompiled' => '/\\]/',
        ];

        yield 'escaped special characters: /\\(/' => [
            'pattern' => '/\\(/',
            'recompiled' => '/\\(/',
        ];

        yield 'escaped special characters: /\\)/' => [
            'pattern' => '/\\)/',
            'recompiled' => '/\\)/',
        ];

        yield 'quote mode: /\\Q*+?\\E/' => [
            'pattern' => '/\\Q*+?\\E/',
            'recompiled' => '/\\*\\+\\?/',
        ];

        yield 'quote mode: /\\Q*+?/' => [
            'pattern' => '/\\Q*+?/',
            'recompiled' => '/\\*\\+\\?/',
        ];

        yield 'quote mode: /a\\Q\\Eb/' => [
            'pattern' => '/a\\Q\\Eb/',
            'recompiled' => '/ab/',
        ];

        yield 'quote mode: /\\Q.\\E/' => [
            'pattern' => '/\\Q.\\E/',
            'recompiled' => '/\\./',
        ];

        yield 'lookaround assertions: /(?=abc)/' => [
            'pattern' => '/(?=abc)/',
            'recompiled' => '/(?=abc)/',
        ];

        yield 'lookaround assertions: /(?!abc)/' => [
            'pattern' => '/(?!abc)/',
            'recompiled' => '/(?!abc)/',
        ];

        yield 'lookaround assertions: /(?<=abc)/' => [
            'pattern' => '/(?<=abc)/',
            'recompiled' => '/(?<=abc)/',
        ];

        yield 'lookaround assertions: /(?<!abc)/' => [
            'pattern' => '/(?<!abc)/',
            'recompiled' => '/(?<!abc)/',
        ];

        yield 'ranges in character classes: /[A-Z]/' => [
            'pattern' => '/[A-Z]/',
            'recompiled' => '/[A-Z]/',
        ];

        yield 'ranges in character classes: /[0-9]/' => [
            'pattern' => '/[0-9]/',
            'recompiled' => '/[0-9]/',
        ];

        yield 'anchors: /^/' => [
            'pattern' => '/^/',
            'recompiled' => '/^/',
        ];

        yield 'anchors: /$/' => [
            'pattern' => '/$/',
            'recompiled' => '/$/',
        ];

        yield 'anchors: /^a$/' => [
            'pattern' => '/^a$/',
            'recompiled' => '/^a$/',
        ];

        yield 'nested groups: /((a)(b))/' => [
            'pattern' => '/((a)(b))/',
            'recompiled' => '/((a)(b))/',
        ];

        yield 'mixed quantifiers: /a{2,4}/' => [
            'pattern' => '/a{2,4}/',
            'recompiled' => '/a{2,4}/',
        ];

        yield 'mixed quantifiers: /a{2,4}?/' => [
            'pattern' => '/a{2,4}?/',
            'recompiled' => '/a{2,4}?/',
        ];

        yield 'parser get lexer reuse: /abc/' => [
            'pattern' => '/abc/',
            'recompiled' => '/abc/',
        ];

        yield 'parser get lexer reuse: /def/' => [
            'pattern' => '/def/',
            'recompiled' => '/def/',
        ];

        yield 'parser get lexer reuse: /[a-z]+/' => [
            'pattern' => '/[a-z]+/',
            'recompiled' => '/[a-z]+/',
        ];

        yield 'parser previous method: /a|b|c/' => [
            'pattern' => '/a|b|c/',
            'recompiled' => '/a|b|c/',
        ];

        yield 'parser consume while: /(?<longname>test)/' => [
            'pattern' => '/(?<longname>test)/',
            'recompiled' => '/(?<longname>test)/',
        ];

        yield 'parser consume while: /(?<abc123>pattern)/' => [
            'pattern' => '/(?<abc123>pattern)/',
            'recompiled' => '/(?<abc123>pattern)/',
        ];

        yield 'lexer comment mode: /(?#comment)test/' => [
            'pattern' => '/(?#comment)test/',
            'recompiled' => '/(?#comment)test/',
        ];

        yield 'lexer comment mode: /(?#this is a comment with spaces)abc/' => [
            'pattern' => '/(?#this is a comment with spaces)abc/',
            'recompiled' => '/(?#this is a comment with spaces)abc/',
        ];

        yield 'lexer comment mode: /test(?#end comment)/' => [
            'pattern' => '/test(?#end comment)/',
            'recompiled' => '/test(?#end comment)/',
        ];

        yield 'lexer comment mode: /(?#first)a(?#second)b/' => [
            'pattern' => '/(?#first)a(?#second)b/',
            'recompiled' => '/(?#first)a(?#second)b/',
        ];

        yield 'lexer extract token value: /\\t\\n\\r\\f\\v\\e/' => [
            'pattern' => '/\\t\\n\\r\\f\\v\\e/',
            'recompiled' => '/\\t\\n\\r\\f\\v\\e/',
        ];

        yield 'lexer extract token value: /\\b\\B\\A\\Z\\z/' => [
            'pattern' => '/\\b\\B\\A\\Z\\z/',
            'recompiled' => '/\\b\\B\\A\\Z\\z/',
        ];

        yield 'lexer extract token value: /\\d\\D\\w\\W\\s\\S/' => [
            'pattern' => '/\\d\\D\\w\\W\\s\\S/',
            'recompiled' => '/\\d\\D\\w\\W\\s\\S/',
        ];

        yield 'lexer extract token value: /\\K/' => [
            'pattern' => '/\\K/',
            'recompiled' => '/\\K/',
        ];

        yield 'lexer extract token value: /\\01\\02/' => [
            'pattern' => '/\\01\\02/',
            'recompiled' => '/\\01\\02/',
        ];

        yield 'lexer normalize unicode prop: /\\p{Ll}/' => [
            'pattern' => '/\\p{Ll}/',
            'recompiled' => '/\\p{Ll}/',
        ];

        yield 'lexer normalize unicode prop: /\\P{Lu}/' => [
            'pattern' => '/\\P{Lu}/',
            'recompiled' => '/\\P{Lu}/',
        ];

        yield 'lexer normalize unicode prop: /\\p{N}/' => [
            'pattern' => '/\\p{N}/',
            'recompiled' => '/\\p{N}/',
        ];

        yield 'lexer normalize unicode prop: /\\P{P}/' => [
            'pattern' => '/\\P{P}/',
            'recompiled' => '/\\P{P}/',
        ];

        yield 'lexer normalize unicode prop: /\\p{Sc}/' => [
            'pattern' => '/\\p{Sc}/',
            'recompiled' => '/\\p{Sc}/',
        ];

        yield 'subroutine with ampersand syntax: /(?<foo>a)(?&foo)/' => [
            'pattern' => '/(?<foo>a)(?&foo)/',
            'recompiled' => '/(?<foo>a)(?&foo)/',
        ];

        yield 'numeric subroutine positive: /(a)(?1)/' => [
            'pattern' => '/(a)(?1)/',
            'recompiled' => '/(a)(?1)/',
        ];

        yield 'numeric subroutine negative: /(a)(?-1)/' => [
            'pattern' => '/(a)(?-1)/',
            'recompiled' => '/(a)(?-1)/',
        ];

        yield 'numeric subroutine zero: /(?0)/' => [
            'pattern' => '/(?0)/',
            'recompiled' => '/(?0)/',
        ];

        yield 'numeric subroutine multi digit: /(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)(?10)/' => [
            'pattern' => '/(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)(?10)/',
            'recompiled' => '/(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)(?10)/',
        ];

        yield 'conditional with lookbehind condition positive: /(?(?<=test)yes|no)/' => [
            'pattern' => '/(?(?<=test)yes|no)/',
            'recompiled' => '/(?(?<=test)yes|no)/',
        ];

        yield 'conditional with lookbehind condition negative: /(?(?<!test)yes|no)/' => [
            'pattern' => '/(?(?<!test)yes|no)/',
            'recompiled' => '/(?(?<!test)yes|no)/',
        ];

        yield 'char class negation with literal bracket: /[^]abc]/' => [
            'pattern' => '/[^]abc]/',
            'recompiled' => '/[^]abc]/',
        ];

        yield 'char class starting with bracket: /[]abc]/' => [
            'pattern' => '/[]abc]/',
            'recompiled' => '/[]abc]/',
        ];

        yield 'alternation empty right: /a|/' => [
            'pattern' => '/a|/',
            'recompiled' => '/a|/',
        ];

        yield 'alternation empty left: /|a/' => [
            'pattern' => '/|a/',
            'recompiled' => '/|a/',
        ];

        yield 'alternation multiple empty: /||/' => [
            'pattern' => '/||/',
            'recompiled' => '/||/',
        ];

        yield 'comment with special chars: /(?#test.*+?|^$)abc/' => [
            'pattern' => '/(?#test.*+?|^$)abc/',
            'recompiled' => '/(?#test.*+?|^$)abc/',
        ];

        yield 'nested posix classes: /[a[[:digit:]]b]/' => [
            'pattern' => '/[a[[:digit:]]b]/',
            'recompiled' => '/[a[[:digit:]]b]/',
        ];

        yield 'backref k with number braces: /(a)\\k{1}/' => [
            'pattern' => '/(a)\\k{1}/',
            'recompiled' => '/(a)\\k{1}/',
        ];

        yield 'quantifier on group: /(abc)+/' => [
            'pattern' => '/(abc)+/',
            'recompiled' => '/(abc)+/',
        ];

        yield 'quantifier on char class: /[abc]+/' => [
            'pattern' => '/[abc]+/',
            'recompiled' => '/[abc]+/',
        ];

        yield 'pattern only anchors: /^$/' => [
            'pattern' => '/^$/',
            'recompiled' => '/^$/',
        ];

        yield 'deeply nested groups: /(((((a)))))/' => [
            'pattern' => '/(((((a)))))/',
            'recompiled' => '/(((((a)))))/',
        ];

        yield 'multiple quantifiers: /a+b*c?d{2,3}/' => [
            'pattern' => '/a+b*c?d{2,3}/',
            'recompiled' => '/a+b*c?d{2,3}/',
        ];

        yield 'complex nested alternation: /(a|b)|(c|d)/' => [
            'pattern' => '/(a|b)|(c|d)/',
            'recompiled' => '/(a|b)|(c|d)/',
        ];

        yield 'char class multiple ranges: /[a-zA-Z0-9_]/' => [
            'pattern' => '/[a-zA-Z0-9_]/',
            'recompiled' => '/[a-zA-Z0-9_]/',
        ];

        yield 'char class with escapes: /[\\^\\-\\]]/' => [
            'pattern' => '/[\\^\\-\\]]/',
            'recompiled' => '/[\\^\\-\\]]/',
        ];

        yield 'unicode prop in char class: /[\\p{L}\\d]/' => [
            'pattern' => '/[\\p{L}\\d]/',
            'recompiled' => '/[\\p{L}\\d]/',
        ];

        yield 'negated unicode prop in char class: /[\\P{L}]/' => [
            'pattern' => '/[\\P{L}]/',
            'recompiled' => '/[\\P{L}]/',
        ];

        yield 'char type in char class: /[\\d\\s\\w]/' => [
            'pattern' => '/[\\d\\s\\w]/',
            'recompiled' => '/[\\d\\s\\w]/',
        ];

        yield 'octal in char class: /[\\01\\o{77}]/' => [
            'pattern' => '/[\\01\\o{77}]/',
            'recompiled' => '/[\\01\\o{77}]/',
        ];

        yield 'unicode in char class: /[\\x41\\u{42}]/' => [
            'pattern' => '/[\\x41\\u{42}]/',
            'recompiled' => '/[\\x41\\u{42}]/',
        ];

        yield 'parser conditional with recursion: /(?(R)a|b)/' => [
            'pattern' => '/(?(R)a|b)/',
            'recompiled' => '/(?(R)a|b)/',
        ];

        yield 'parser conditional with numeric backref: /()abc(?(1)yes|no)/' => [
            'pattern' => '/()abc(?(1)yes|no)/',
            'recompiled' => '/()abc(?(1)yes|no)/',
        ];

        yield 'parser conditional bare name reference: /(?<test>x)(?(test)yes|no)/' => [
            'pattern' => '/(?<test>x)(?(test)yes|no)/',
            'recompiled' => '/(?<test>x)(?(test)yes|no)/',
        ];

        yield 'parser char class with posix and range: /[[:alpha:]a-z]/' => [
            'pattern' => '/[[:alpha:]a-z]/',
            'recompiled' => '/[[:alpha:]a-z]/',
        ];

        yield 'parser char class with nested posix: /[[:alpha:][:digit:]]/' => [
            'pattern' => '/[[:alpha:][:digit:]]/',
            'recompiled' => '/[[:alpha:][:digit:]]/',
        ];

        yield 'parser char class with unicode prop: /[\\p{L}\\p{N}]/' => [
            'pattern' => '/[\\p{L}\\p{N}]/',
            'recompiled' => '/[\\p{L}\\p{N}]/',
        ];

        yield 'parser char class negated unicode prop: /[\\P{L}\\P{N}]/' => [
            'pattern' => '/[\\P{L}\\P{N}]/',
            'recompiled' => '/[\\P{L}\\P{N}]/',
        ];

        yield 'parser char class with char type: /[\\d\\w\\s]/' => [
            'pattern' => '/[\\d\\w\\s]/',
            'recompiled' => '/[\\d\\w\\s]/',
        ];

        yield 'parser char class with octal: /[\\101\\o{102}]/' => [
            'pattern' => '/[\\101\\o{102}]/',
            'recompiled' => '/[\\101\\o{102}]/',
        ];

        yield 'parser char class with unicode: /[\\u{41}\\x42]/' => [
            'pattern' => '/[\\u{41}\\x42]/',
            'recompiled' => '/[\\u{41}\\x42]/',
        ];

        yield 'parser char class range with escaped chars: /[\\n-\\r]/' => [
            'pattern' => '/[\\n-\\r]/',
            'recompiled' => '/[\\n-\\r]/',
        ];

        yield 'optimizer visitor with nested groups: /(((a)))/' => [
            'pattern' => '/(((a)))/',
            'recompiled' => '/(((a)))/',
        ];

        yield 'validator with octal legacy: /\\07/' => [
            'pattern' => '/\\07/',
            'recompiled' => '/\\07/',
        ];

        yield 'validator posix class variations: /[[:word:]]/' => [
            'pattern' => '/[[:word:]]/',
            'recompiled' => '/[[:word:]]/',
        ];

        yield 'validator posix class variations: /[[:ascii:]]/' => [
            'pattern' => '/[[:ascii:]]/',
            'recompiled' => '/[[:ascii:]]/',
        ];

        yield 'validator posix class variations: /[[:xdigit:]]/' => [
            'pattern' => '/[[:xdigit:]]/',
            'recompiled' => '/[[:xdigit:]]/',
        ];

        yield 'validator unicode prop variations: /\\p{Lu}/' => [
            'pattern' => '/\\p{Lu}/',
            'recompiled' => '/\\p{Lu}/',
        ];

        yield 'validator backref edge cases: /(?<name>a)\\k<name>/' => [
            'pattern' => '/(?<name>a)\\k<name>/',
            'recompiled' => '/(?<name>a)\\k<name>/',
        ];

        yield 'parser subroutine variations: /(abc)(?1)/' => [
            'pattern' => '/(abc)(?1)/',
            'recompiled' => '/(abc)(?1)/',
        ];

        yield 'parser subroutine variations: /(?<name>abc)(?&name)/' => [
            'pattern' => '/(?<name>abc)(?&name)/',
            'recompiled' => '/(?<name>abc)(?&name)/',
        ];

        yield 'parser pcre verb with argument: /(*MARK:label)/' => [
            'pattern' => '/(*MARK:label)/',
            'recompiled' => '/(*MARK:label)/',
        ];

        yield 'parser pcre verb with argument: /(*PRUNE:name)/' => [
            'pattern' => '/(*PRUNE:name)/',
            'recompiled' => '/(*PRUNE:name)/',
        ];

        yield 'parser pcre verb with argument: /(*THEN:label)/' => [
            'pattern' => '/(*THEN:label)/',
            'recompiled' => '/(*THEN:label)/',
        ];

        yield 'parser complex char class: /[a-zA-Z0-9_\\-\\.]/' => [
            'pattern' => '/[a-zA-Z0-9_\\-\\.]/',
            'recompiled' => '/[a-zA-Z0-9_\\-\\.]/',
        ];

        yield 'parser complex char class: /[^[:digit:]]/' => [
            'pattern' => '/[^[:digit:]]/',
            'recompiled' => '/[^[:digit:]]/',
        ];

        yield 'parser unicode variations: /\\o{177}/' => [
            'pattern' => '/\\o{177}/',
            'recompiled' => '/\\o{177}/',
        ];

        yield 'parser backref variations: /(a)(b)\\g{-1}/' => [
            'pattern' => '/(a)(b)\\g{-1}/',
            'recompiled' => '/(a)(b)\\g{-1}/',
        ];

        yield 'parser group with modifiers: /(?s:test)/' => [
            'pattern' => '/(?s:test)/',
            'recompiled' => '/(?s:test)/',
        ];

        yield 'parser group with modifiers: /(?m:test)/' => [
            'pattern' => '/(?m:test)/',
            'recompiled' => '/(?m:test)/',
        ];

        yield 'parser group with modifiers: /(?x:test)/' => [
            'pattern' => '/(?x:test)/',
            'recompiled' => '/(?x:test)/',
        ];

        yield 'parser anchors all types: /\\Atest/' => [
            'pattern' => '/\\Atest/',
            'recompiled' => '/\\Atest/',
        ];

        yield 'parser anchors all types: /test\\Z/' => [
            'pattern' => '/test\\Z/',
            'recompiled' => '/test\\Z/',
        ];

        yield 'parser anchors all types: /test\\z/' => [
            'pattern' => '/test\\z/',
            'recompiled' => '/test\\z/',
        ];

        yield 'parser anchors all types: /\\btest/' => [
            'pattern' => '/\\btest/',
            'recompiled' => '/\\btest/',
        ];

        yield 'parser anchors all types: /\\Btest/' => [
            'pattern' => '/\\Btest/',
            'recompiled' => '/\\Btest/',
        ];

        yield 'parser anchors all types: /\\Gtest/' => [
            'pattern' => '/\\Gtest/',
            'recompiled' => '/\\Gtest/',
        ];

        yield 'sample generator posix classes: /[[:lower:]]/' => [
            'pattern' => '/[[:lower:]]/',
            'recompiled' => '/[[:lower:]]/',
        ];

        yield 'sample generator posix classes: /[[:upper:]]/' => [
            'pattern' => '/[[:upper:]]/',
            'recompiled' => '/[[:upper:]]/',
        ];

        yield 'parser named group variations: /(?<name>abc)/' => [
            'pattern' => '/(?<name>abc)/',
            'recompiled' => '/(?<name>abc)/',
        ];

        yield 'parser named group variations: /(?P<name>abc)/' => [
            'pattern' => '/(?P<name>abc)/',
            'recompiled' => '/(?P<name>abc)/',
        ];

        yield 'parser subroutine p syntax: /(?<foo>x)(?P>foo)/' => [
            'pattern' => '/(?<foo>x)(?P>foo)/',
            'recompiled' => '/(?<foo>x)(?P>foo)/',
        ];

        yield 'parser subroutine ampersand syntax: /(?<bar>y)(?&bar)/' => [
            'pattern' => '/(?<bar>y)(?&bar)/',
            'recompiled' => '/(?<bar>y)(?&bar)/',
        ];

        yield 'parser get lexer multiple calls: /another/' => [
            'pattern' => '/another/',
            'recompiled' => '/another/',
        ];

        yield 'parser get lexer multiple calls: /pattern/' => [
            'pattern' => '/pattern/',
            'recompiled' => '/pattern/',
        ];

        yield 'parser is at end: /a/' => [
            'pattern' => '/a/',
            'recompiled' => '/a/',
        ];

        yield 'parser is at end: /ab/' => [
            'pattern' => '/ab/',
            'recompiled' => '/ab/',
        ];

        yield 'lexer quote mode: /\\Qtest\\E/' => [
            'pattern' => '/\\Qtest\\E/',
            'recompiled' => '/test/',
        ];

        yield 'lexer quote mode: /\\Qhello world\\E/' => [
            'pattern' => '/\\Qhello world\\E/',
            'recompiled' => '/hello world/',
        ];

        yield 'lexer quote mode: /\\Q.*+?{}[]()\\E/' => [
            'pattern' => '/\\Q.*+?{}[]()\\E/',
            'recompiled' => '/\\.\\*\\+\\?\\{\\}\\[\\]\\(\\)/',
        ];

        yield 'lexer quote mode: /\\Qunclosed/' => [
            'pattern' => '/\\Qunclosed/',
            'recompiled' => '/unclosed/',
        ];

        yield 'lexer comment mode detailed: /(?#simple comment)/' => [
            'pattern' => '/(?#simple comment)/',
            'recompiled' => '/(?#simple comment)/',
        ];

        yield 'lexer comment mode detailed: /(?#comment with spaces and punctuation!)/' => [
            'pattern' => '/(?#comment with spaces and punctuation!)/',
            'recompiled' => '/(?#comment with spaces and punctuation!)/',
        ];

        yield 'lexer comment mode detailed: /a(?#comment)b/' => [
            'pattern' => '/a(?#comment)b/',
            'recompiled' => '/a(?#comment)b/',
        ];

        yield 'lexer comment mode detailed: /(?#first)x(?#second)/' => [
            'pattern' => '/(?#first)x(?#second)/',
            'recompiled' => '/(?#first)x(?#second)/',
        ];

        yield 'lexer comment mode detailed: /(?#)/' => [
            'pattern' => '/(?#)/',
            'recompiled' => '/(?#)/',
        ];

        yield 'lexer extract token value comprehensive: /(a)(b)\\2/' => [
            'pattern' => '/(a)(b)\\2/',
            'recompiled' => '/(a)(b)\\2/',
        ];

        yield 'lexer extract token value comprehensive: /\\77/' => [
            'pattern' => '/\\77/',
            'recompiled' => '/\\77/',
        ];

        yield 'lexer normalize unicode prop: /\\P{Sc}/' => [
            'pattern' => '/\\P{Sc}/',
            'recompiled' => '/\\P{Sc}/',
        ];

        yield 'validate valid: /foo{1,3}/ims' => [
            'pattern' => '/foo{1,3}/ims',
            'recompiled' => '/foo{1,3}/ims',
        ];

        yield 'allows nested quantifiers: /(a+)*b/' => [
            'pattern' => '/(a+)*b/',
            'recompiled' => '/(a+)*b/',
        ];

        yield 'valid java unicode properties: /\\p{javaLowerCase}/u' => [
            'pattern' => '/\\p{javaLowerCase}/u',
            'recompiled' => '/\\p{javaLowerCase}/u',
        ];

        yield 'valid java unicode properties: /\\p{javaUpperCase}/u' => [
            'pattern' => '/\\p{javaUpperCase}/u',
            'recompiled' => '/\\p{javaUpperCase}/u',
        ];

        yield 'valid java unicode properties: /\\p{javaWhitespace}/u' => [
            'pattern' => '/\\p{javaWhitespace}/u',
            'recompiled' => '/\\p{javaWhitespace}/u',
        ];

        yield 'valid java unicode properties: /\\p{javaMirrored}/u' => [
            'pattern' => '/\\p{javaMirrored}/u',
            'recompiled' => '/\\p{javaMirrored}/u',
        ];

        yield 'valid unicode named character: /\\N{U+0041}/u' => [
            'pattern' => '/\\N{U+0041}/u',
            'recompiled' => '/\\N{U+0041}/u',
        ];

        yield 'valid unicode four digit escape: /\\u0041/' => [
            'pattern' => '/\\u0041/',
            'recompiled' => '/\\u0041/',
        ];

        yield 'allows non nested quantifiers: /(a*)(b*)/' => [
            'pattern' => '/(a*)(b*)/',
            'recompiled' => '/(a*)(b*)/',
        ];

        yield 'allows nested possessive quantifiers: /(a++)*+b/' => [
            'pattern' => '/(a++)*+b/',
            'recompiled' => '/(a++)*+b/',
        ];

        yield 'allows nested possessive quantifiers: /([a-z]*+)++/' => [
            'pattern' => '/([a-z]*+)++/',
            'recompiled' => '/([a-z]*+)++/',
        ];

        yield 'allows nested possessive quantifiers: /(a*+)+/' => [
            'pattern' => '/(a*+)+/',
            'recompiled' => '/(a*+)+/',
        ];

        yield 'allows symfony patterns with possessive quantifiers: /^[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*+(?:\\\\[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*+)++$/' => [
            'pattern' => '/^[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*+(?:\\\\[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*+)++$/',
            'recompiled' => '/^[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*+(?:\\\\[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*+)++$/',
        ];

        yield 'allows symfony patterns with possessive quantifiers: /^(?:[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*+\\\\)++$/' => [
            'pattern' => '/^(?:[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*+\\\\)++$/',
            'recompiled' => '/^(?:[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*+\\\\)++$/',
        ];

        yield 'allows symfony patterns with possessive quantifiers: /([^\\\\]++\\\\)++/' => [
            'pattern' => '/([^\\\\]++\\\\)++/',
            'recompiled' => '/([^\\\\]++\\\\)++/',
        ];

        yield 'allows symfony patterns with possessive quantifiers: /^(?:[-.\\w\\\\]*+:)*+\\w*+$/' => [
            'pattern' => '/^(?:[-.\\w\\\\]*+:)*+\\w*+$/',
            'recompiled' => '/^(?:[-.\\w\\\\]*+:)*+\\w*+$/',
        ];

        yield 'validate valid char class: /[a-z\\d-]/' => [
            'pattern' => '/[a-z\\d-]/',
            'recompiled' => '/[a-z\\d-]/',
        ];

        yield 'multi digit backref falls back to octal: /(a)\\11/' => [
            'pattern' => '/(a)\\11/',
            'recompiled' => '/(a)\\11/',
        ];

        yield 'multi digit backref falls back to octal: /(a)(b)\\10/' => [
            'pattern' => '/(a)(b)\\10/',
            'recompiled' => '/(a)(b)\\10/',
        ];

        yield 'multi digit backref falls back to octal: /\\19/' => [
            'pattern' => '/\\19/',
            // "\1" alone would name group 1: the octal escape is spelled "\o{1}".
            'recompiled' => '/\\o{1}9/',
        ];

        yield 'validate valid subroutine: /(?<name>a)(?&name)/' => [
            'pattern' => '/(?<name>a)(?&name)/',
            'recompiled' => '/(?<name>a)(?&name)/',
        ];

        yield 'allows octal zero escape in validator: /\\0/' => [
            'pattern' => '/\\0/',
            'recompiled' => '/\\0/',
        ];

        yield 'validates named conditional: /(?<n>a)(?(n)b)/' => [
            'pattern' => '/(?<n>a)(?(n)b)/',
            'recompiled' => '/(?<n>a)(?(n)b)/',
        ];

        yield 'validator allows nested quantifiers: /(a+)+/' => [
            'pattern' => '/(a+)+/',
            'recompiled' => '/(a+)+/',
        ];

        yield 'accepts negated posix word class: /[[:^word:]]/' => [
            'pattern' => '/[[:^word:]]/',
            'recompiled' => '/[[:^word:]]/',
        ];

        yield 'parser with quote mode: /\\Qtest.*\\E/' => [
            'pattern' => '/\\Qtest.*\\E/',
            'recompiled' => '/test\\.\\*/',
        ];

        yield 'parser with special escapes: /\\t\\n\\r/' => [
            'pattern' => '/\\t\\n\\r/',
            'recompiled' => '/\\t\\n\\r/',
        ];

        yield 'an atomic verb wrapping nested groups: /(*atomic:((a)))/' => [
            'pattern' => '/(*atomic:((a)))/',
            'recompiled' => '/(?>((a)))/',
        ];

        yield 'a lookahead verb wrapping several groups: /(*pla:((a)b(c)))/' => [
            'pattern' => '/(*pla:((a)b(c)))/',
            'recompiled' => '/(?=((a)b(c)))/',
        ];
    }

    /**
     * The patterns left out are ones PCRE itself refuses — Java property
     * names, "\u" escapes, a pattern that is nothing but a recursion. The
     * parser reads them on purpose, so there is no compiled form to check.
     *
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideConstructsPcreAccepts(): iterable
    {
        yield 'python named group angle brackets: /(?P<name>test)/' => ['pattern' => '/(?P<name>test)/'];
        yield 'python subroutine call: /(?P<name>test)(?P>name)/' => ['pattern' => '/(?P<name>test)(?P>name)/'];
        yield 'positive lookbehind: /(?<=test)abc/' => ['pattern' => '/(?<=test)abc/'];
        yield 'negative lookbehind: /(?<!test)abc/' => ['pattern' => '/(?<!test)abc/'];
        yield 'conditional with number: /(test)(?(1)yes|no)/' => ['pattern' => '/(test)(?(1)yes|no)/'];
        yield 'conditional with named group: /(?<name>test)(?(<name>)yes|no)/' => ['pattern' => '/(?<name>test)(?(<name>)yes|no)/'];
        yield 'conditional lookaround condition: /(?(?=a)yes|no)/' => ['pattern' => '/(?(?=a)yes|no)/'];
        yield 'conditional without else: /(test)(?(1)yes)/' => ['pattern' => '/(test)(?(1)yes)/'];
        yield 'subroutine call by number: /(test)(?1)/' => ['pattern' => '/(test)(?1)/'];
        yield 'subroutine call relative: /(test)(?-1)/' => ['pattern' => '/(test)(?-1)/'];
        yield 'subroutine call named: /(?<name>test)(?&name)/' => ['pattern' => '/(?<name>test)(?&name)/'];
        yield 'atomic group: /(?>test)/' => ['pattern' => '/(?>test)/'];
        yield 'non capturing group: /(?:test)/' => ['pattern' => '/(?:test)/'];
        yield 'group with flags i: /(?i:test)/' => ['pattern' => '/(?i:test)/'];
        yield 'group with multiple flags: /(?im:test)/' => ['pattern' => '/(?im:test)/'];
        yield 'group with negative flags: /(?-i:test)/' => ['pattern' => '/(?-i:test)/'];
        yield 'group with mixed flags: /(?i-m:test)/' => ['pattern' => '/(?i-m:test)/'];
        yield 'char class with range: /[a-z]/' => ['pattern' => '/[a-z]/'];
        yield 'char class with multiple ranges: /[a-zA-Z0-9]/' => ['pattern' => '/[a-zA-Z0-9]/'];
        yield 'negated char class: /[^a-z]/' => ['pattern' => '/[^a-z]/'];
        yield 'posix char class: /[[:alnum:]]/' => ['pattern' => '/[[:alnum:]]/'];
        yield 'negated posix char class: /[[:^alnum:]]/' => ['pattern' => '/[[:^alnum:]]/'];
        yield 'octal legacy: /\\101/' => ['pattern' => '/\\101/'];
        yield 'octal modern: /\\o{101}/' => ['pattern' => '/\\o{101}/'];
        yield 'unicode escape hex: /\\x41/' => ['pattern' => '/\\x41/'];
        yield 'pcre verb fail: /(*FAIL)/' => ['pattern' => '/(*FAIL)/'];
        yield 'pcre verb mark: /(*MARK:test)/' => ['pattern' => '/(*MARK:test)/'];
        yield 'keep: /test\\K/' => ['pattern' => '/test\\K/'];
        yield 'anchors: /^test/' => ['pattern' => '/^test/'];
        yield 'anchors: /test$/' => ['pattern' => '/test$/'];
        yield 'anchors: /^test$/' => ['pattern' => '/^test$/'];
        yield 'assertions: /\\A/' => ['pattern' => '/\\A/'];
        yield 'assertions: /\\z/' => ['pattern' => '/\\z/'];
        yield 'assertions: /\\Z/' => ['pattern' => '/\\Z/'];
        yield 'assertions: /\\G/' => ['pattern' => '/\\G/'];
        yield 'assertions: /\\b/' => ['pattern' => '/\\b/'];
        yield 'assertions: /\\B/' => ['pattern' => '/\\B/'];
        yield 'char types: /\\d/' => ['pattern' => '/\\d/'];
        yield 'char types: /\\D/' => ['pattern' => '/\\D/'];
        yield 'char types: /\\s/' => ['pattern' => '/\\s/'];
        yield 'char types: /\\S/' => ['pattern' => '/\\S/'];
        yield 'char types: /\\w/' => ['pattern' => '/\\w/'];
        yield 'char types: /\\W/' => ['pattern' => '/\\W/'];
        yield 'char types: /\\h/' => ['pattern' => '/\\h/'];
        yield 'char types: /\\H/' => ['pattern' => '/\\H/'];
        yield 'char types: /\\v/' => ['pattern' => '/\\v/'];
        yield 'char types: /\\V/' => ['pattern' => '/\\V/'];
        yield 'char types: /\\R/' => ['pattern' => '/\\R/'];
        yield 'backref numbered: /(test)\\1/' => ['pattern' => '/(test)\\1/'];
        yield 'backref named k angle: /(?<name>test)\\k<name>/' => ['pattern' => '/(?<name>test)\\k<name>/'];
        yield 'backref named k brace: /(?<name>test)\\k{name}/' => ['pattern' => '/(?<name>test)\\k{name}/'];
        yield 'g reference number: /(test)\\g1/' => ['pattern' => '/(test)\\g1/'];
        yield 'g reference relative: /(test)\\g-1/' => ['pattern' => '/(test)\\g-1/'];
        yield 'g reference angle: /(?<name>test)\\g<name>/' => ['pattern' => '/(?<name>test)\\g<name>/'];
        yield 'g reference brace: /(?<name>test)\\g{name}/' => ['pattern' => '/(?<name>test)\\g{name}/'];
        yield 'dot: /./' => ['pattern' => '/./'];
        yield 'quantifiers: /a*/' => ['pattern' => '/a*/'];
        yield 'quantifiers: /a+/' => ['pattern' => '/a+/'];
        yield 'quantifiers: /a?/' => ['pattern' => '/a?/'];
        yield 'quantifiers: /a{2}/' => ['pattern' => '/a{2}/'];
        yield 'quantifiers: /a{2,}/' => ['pattern' => '/a{2,}/'];
        yield 'quantifiers: /a{2,5}/' => ['pattern' => '/a{2,5}/'];
        yield 'quantifiers: /a*?/' => ['pattern' => '/a*?/'];
        yield 'quantifiers: /a+?/' => ['pattern' => '/a+?/'];
        yield 'quantifiers: /a??/' => ['pattern' => '/a??/'];
        yield 'quantifiers: /a{2,5}?/' => ['pattern' => '/a{2,5}?/'];
        yield 'quantifiers: /a*+/' => ['pattern' => '/a*+/'];
        yield 'quantifiers: /a++/' => ['pattern' => '/a++/'];
        yield 'quantifiers: /a?+/' => ['pattern' => '/a?+/'];
        yield 'quantifiers: /a{2,5}+/' => ['pattern' => '/a{2,5}+/'];
        yield 'comment: /(?#this is a comment)test/' => ['pattern' => '/(?#this is a comment)test/'];
        yield 'alternation: /foo|bar|baz/' => ['pattern' => '/foo|bar|baz/'];
        yield 'empty alternation branches: /foo||bar/' => ['pattern' => '/foo||bar/'];
        yield 'complex nested: /(?:(?<name>test)|(?P<other>foo)){2,5}/' => ['pattern' => '/(?:(?<name>test)|(?P<other>foo)){2,5}/'];
        yield 'various delimiters: /test/' => ['pattern' => '/test/'];
        yield 'various delimiters: #test#' => ['pattern' => '#test#'];
        yield 'various delimiters: ~test~' => ['pattern' => '~test~'];
        yield 'various delimiters: @test@' => ['pattern' => '@test@'];
        yield 'various delimiters: !test!' => ['pattern' => '!test!'];
        yield 'various delimiters: %test%' => ['pattern' => '%test%'];
        yield 'various delimiters: {test}' => ['pattern' => '{test}'];
        yield 'extract pattern and flags: /test/i' => ['pattern' => '/test/i'];
        yield 'extract pattern and flags: /test/im' => ['pattern' => '/test/im'];
        yield 'extract pattern and flags: /test/ims' => ['pattern' => '/test/ims'];
        yield 'extract pattern and flags: /test/imsx' => ['pattern' => '/test/imsx'];
        yield 'extract pattern and flags: /test/imsxu' => ['pattern' => '/test/imsxu'];
        yield 'extract pattern and flags: /test/imsxuD' => ['pattern' => '/test/imsxuD'];
        yield 'extract pattern and flags: /test/imsxuDU' => ['pattern' => '/test/imsxuDU'];
        yield 'extract pattern and flags: /test/imsxuDUA' => ['pattern' => '/test/imsxuDUA'];
        yield 'extract pattern and flags: /test/imsxuDUAJ' => ['pattern' => '/test/imsxuDUAJ'];
        yield 'conditional with numeric reference: /(a)(?(1)yes|no)/' => ['pattern' => '/(a)(?(1)yes|no)/'];
        yield 'conditional with multi digit numeric reference: /(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)(k)(l)(?(12)yes|no)/' => ['pattern' => '/(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)(k)(l)(?(12)yes|no)/'];
        yield 'conditional with lookahead condition: /(?(?=test)yes|no)/' => ['pattern' => '/(?(?=test)yes|no)/'];
        yield 'conditional with negative lookahead condition: /(?(?!test)yes|no)/' => ['pattern' => '/(?(?!test)yes|no)/'];
        yield 'conditional with bare group name: /(?<foo>x)(?(foo)yes|no)/' => ['pattern' => '/(?<foo>x)(?(foo)yes|no)/'];
        yield 'conditional with recursion check: /(?(R)yes|no)/' => ['pattern' => '/(?(R)yes|no)/'];
        yield 'conditional with angle bracket name: /(?<name>x)(?(<name>)yes|no)/' => ['pattern' => '/(?<name>x)(?(<name>)yes|no)/'];
        yield 'quantifier with exact count: /a{5}/' => ['pattern' => '/a{5}/'];
        yield 'g reference variations: /(?<name>a)\\g<name>/' => ['pattern' => '/(?<name>a)\\g<name>/'];
        yield 'g reference variations: /(?<name>a)\\g{name}/' => ['pattern' => '/(?<name>a)\\g{name}/'];
        yield 'g reference variations: /(a)\\g1/' => ['pattern' => '/(a)\\g1/'];
        yield 'g reference variations: /(a)\\g{1}/' => ['pattern' => '/(a)\\g{1}/'];
        yield 'g reference variations: /(a)\\g{-1}/' => ['pattern' => '/(a)\\g{-1}/'];
        yield 'subroutine call p syntax: /(?<foo>a)(?P>foo)/' => ['pattern' => '/(?<foo>a)(?P>foo)/'];
        yield 'char class negation: /[^abc]/' => ['pattern' => '/[^abc]/'];
        yield 'char class with dash positions: /[-abc]/' => ['pattern' => '/[-abc]/'];
        yield 'char class with dash positions: /[abc-]/' => ['pattern' => '/[abc-]/'];
        yield 'empty alternation branches: /abc|/' => ['pattern' => '/abc|/'];
        yield 'empty alternation branches: /|abc/' => ['pattern' => '/|abc/'];
        yield 'empty alternation branches: /abc||def/' => ['pattern' => '/abc||def/'];
        yield 'comment groups: /(?#this is a comment)abc/' => ['pattern' => '/(?#this is a comment)abc/'];
        yield 'pcre verbs: /(*ACCEPT)/' => ['pattern' => '/(*ACCEPT)/'];
        yield 'pcre verbs: /(*SKIP)/' => ['pattern' => '/(*SKIP)/'];
        yield 'pcre verbs: /(*MARK:foo)/' => ['pattern' => '/(*MARK:foo)/'];
        yield 'pcre verbs: /(*COMMIT)/' => ['pattern' => '/(*COMMIT)/'];
        yield 'pcre verbs: /(*PRUNE)/' => ['pattern' => '/(*PRUNE)/'];
        yield 'atomic groups: /(?>abc)/' => ['pattern' => '/(?>abc)/'];
        yield 'octal sequences: /\\o{77}/' => ['pattern' => '/\\o{77}/'];
        yield 'octal sequences: /\\01/' => ['pattern' => '/\\01/'];
        yield 'octal sequences: /\\077/' => ['pattern' => '/\\077/'];
        yield 'unicode properties: /\\p{L}/' => ['pattern' => '/\\p{L}/'];
        yield 'unicode properties: /\\P{L}/' => ['pattern' => '/\\P{L}/'];
        yield 'unicode properties: /\\p{^L}/' => ['pattern' => '/\\p{^L}/'];
        yield 'unicode properties: /\\P{^L}/' => ['pattern' => '/\\P{^L}/'];
        yield 'unicode properties: /\\pL/' => ['pattern' => '/\\pL/'];
        yield 'posix character classes: /[[:alpha:]]/' => ['pattern' => '/[[:alpha:]]/'];
        yield 'posix character classes: /[[:digit:]]/' => ['pattern' => '/[[:digit:]]/'];
        yield 'posix character classes: /[[:^digit:]]/' => ['pattern' => '/[[:^digit:]]/'];
        yield 'inline flags: /(?i)abc/' => ['pattern' => '/(?i)abc/'];
        yield 'inline flags: /(?-i)abc/' => ['pattern' => '/(?-i)abc/'];
        yield 'inline flags: /(?i:abc)/' => ['pattern' => '/(?i:abc)/'];
        yield 'inline flags: /(?ims)abc/' => ['pattern' => '/(?ims)abc/'];
        yield 'inline flags: /(?i-s)abc/' => ['pattern' => '/(?i-s)abc/'];
        yield 'backreferences: /(a)\\1/' => ['pattern' => '/(a)\\1/'];
        yield 'backreferences: /(?<n>a)\\k<n>/' => ['pattern' => '/(?<n>a)\\k<n>/'];
        yield 'backreferences: /(?<n>a)\\k{n}/' => ['pattern' => '/(?<n>a)\\k{n}/'];
        yield 'escaped special characters: /\\t/' => ['pattern' => '/\\t/'];
        yield 'escaped special characters: /\\n/' => ['pattern' => '/\\n/'];
        yield 'escaped special characters: /\\r/' => ['pattern' => '/\\r/'];
        yield 'escaped special characters: /\\f/' => ['pattern' => '/\\f/'];
        yield 'escaped special characters: /\\e/' => ['pattern' => '/\\e/'];
        yield 'escaped special characters: /\\./' => ['pattern' => '/\\./'];
        yield 'escaped special characters: /\\[/' => ['pattern' => '/\\[/'];
        yield 'escaped special characters: /\\]/' => ['pattern' => '/\\]/'];
        yield 'escaped special characters: /\\(/' => ['pattern' => '/\\(/'];
        yield 'escaped special characters: /\\)/' => ['pattern' => '/\\)/'];
        yield 'quote mode: /\\Q*+?\\E/' => ['pattern' => '/\\Q*+?\\E/'];
        yield 'quote mode: /\\Q*+?/' => ['pattern' => '/\\Q*+?/'];
        yield 'quote mode: /a\\Q\\Eb/' => ['pattern' => '/a\\Q\\Eb/'];
        yield 'quote mode: /\\Q.\\E/' => ['pattern' => '/\\Q.\\E/'];
        yield 'lookaround assertions: /(?=abc)/' => ['pattern' => '/(?=abc)/'];
        yield 'lookaround assertions: /(?!abc)/' => ['pattern' => '/(?!abc)/'];
        yield 'lookaround assertions: /(?<=abc)/' => ['pattern' => '/(?<=abc)/'];
        yield 'lookaround assertions: /(?<!abc)/' => ['pattern' => '/(?<!abc)/'];
        yield 'ranges in character classes: /[A-Z]/' => ['pattern' => '/[A-Z]/'];
        yield 'ranges in character classes: /[0-9]/' => ['pattern' => '/[0-9]/'];
        yield 'anchors: /^/' => ['pattern' => '/^/'];
        yield 'anchors: /$/' => ['pattern' => '/$/'];
        yield 'anchors: /^a$/' => ['pattern' => '/^a$/'];
        yield 'nested groups: /((a)(b))/' => ['pattern' => '/((a)(b))/'];
        yield 'mixed quantifiers: /a{2,4}/' => ['pattern' => '/a{2,4}/'];
        yield 'mixed quantifiers: /a{2,4}?/' => ['pattern' => '/a{2,4}?/'];
        yield 'parser get lexer reuse: /abc/' => ['pattern' => '/abc/'];
        yield 'parser get lexer reuse: /def/' => ['pattern' => '/def/'];
        yield 'parser get lexer reuse: /[a-z]+/' => ['pattern' => '/[a-z]+/'];
        yield 'parser previous method: /a|b|c/' => ['pattern' => '/a|b|c/'];
        yield 'parser consume while: /(?<longname>test)/' => ['pattern' => '/(?<longname>test)/'];
        yield 'parser consume while: /(?<abc123>pattern)/' => ['pattern' => '/(?<abc123>pattern)/'];
        yield 'lexer comment mode: /(?#comment)test/' => ['pattern' => '/(?#comment)test/'];
        yield 'lexer comment mode: /(?#this is a comment with spaces)abc/' => ['pattern' => '/(?#this is a comment with spaces)abc/'];
        yield 'lexer comment mode: /test(?#end comment)/' => ['pattern' => '/test(?#end comment)/'];
        yield 'lexer comment mode: /(?#first)a(?#second)b/' => ['pattern' => '/(?#first)a(?#second)b/'];
        yield 'lexer extract token value: /\\t\\n\\r\\f\\v\\e/' => ['pattern' => '/\\t\\n\\r\\f\\v\\e/'];
        yield 'lexer extract token value: /\\b\\B\\A\\Z\\z/' => ['pattern' => '/\\b\\B\\A\\Z\\z/'];
        yield 'lexer extract token value: /\\d\\D\\w\\W\\s\\S/' => ['pattern' => '/\\d\\D\\w\\W\\s\\S/'];
        yield 'lexer extract token value: /\\K/' => ['pattern' => '/\\K/'];
        yield 'lexer extract token value: /\\01\\02/' => ['pattern' => '/\\01\\02/'];
        yield 'lexer normalize unicode prop: /\\p{Ll}/' => ['pattern' => '/\\p{Ll}/'];
        yield 'lexer normalize unicode prop: /\\P{Lu}/' => ['pattern' => '/\\P{Lu}/'];
        yield 'lexer normalize unicode prop: /\\p{N}/' => ['pattern' => '/\\p{N}/'];
        yield 'lexer normalize unicode prop: /\\P{P}/' => ['pattern' => '/\\P{P}/'];
        yield 'lexer normalize unicode prop: /\\p{Sc}/' => ['pattern' => '/\\p{Sc}/'];
        yield 'subroutine with ampersand syntax: /(?<foo>a)(?&foo)/' => ['pattern' => '/(?<foo>a)(?&foo)/'];
        yield 'numeric subroutine positive: /(a)(?1)/' => ['pattern' => '/(a)(?1)/'];
        yield 'numeric subroutine negative: /(a)(?-1)/' => ['pattern' => '/(a)(?-1)/'];
        yield 'numeric subroutine multi digit: /(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)(?10)/' => ['pattern' => '/(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)(?10)/'];
        yield 'conditional with lookbehind condition positive: /(?(?<=test)yes|no)/' => ['pattern' => '/(?(?<=test)yes|no)/'];
        yield 'conditional with lookbehind condition negative: /(?(?<!test)yes|no)/' => ['pattern' => '/(?(?<!test)yes|no)/'];
        yield 'char class negation with literal bracket: /[^]abc]/' => ['pattern' => '/[^]abc]/'];
        yield 'char class starting with bracket: /[]abc]/' => ['pattern' => '/[]abc]/'];
        yield 'alternation empty right: /a|/' => ['pattern' => '/a|/'];
        yield 'alternation empty left: /|a/' => ['pattern' => '/|a/'];
        yield 'alternation multiple empty: /||/' => ['pattern' => '/||/'];
        yield 'comment with special chars: /(?#test.*+?|^$)abc/' => ['pattern' => '/(?#test.*+?|^$)abc/'];
        yield 'nested posix classes: /[a[[:digit:]]b]/' => ['pattern' => '/[a[[:digit:]]b]/'];
        yield 'quantifier on group: /(abc)+/' => ['pattern' => '/(abc)+/'];
        yield 'quantifier on char class: /[abc]+/' => ['pattern' => '/[abc]+/'];
        yield 'pattern only anchors: /^$/' => ['pattern' => '/^$/'];
        yield 'deeply nested groups: /(((((a)))))/' => ['pattern' => '/(((((a)))))/'];
        yield 'multiple quantifiers: /a+b*c?d{2,3}/' => ['pattern' => '/a+b*c?d{2,3}/'];
        yield 'complex nested alternation: /(a|b)|(c|d)/' => ['pattern' => '/(a|b)|(c|d)/'];
        yield 'char class multiple ranges: /[a-zA-Z0-9_]/' => ['pattern' => '/[a-zA-Z0-9_]/'];
        yield 'char class with escapes: /[\\^\\-\\]]/' => ['pattern' => '/[\\^\\-\\]]/'];
        yield 'unicode prop in char class: /[\\p{L}\\d]/' => ['pattern' => '/[\\p{L}\\d]/'];
        yield 'negated unicode prop in char class: /[\\P{L}]/' => ['pattern' => '/[\\P{L}]/'];
        yield 'char type in char class: /[\\d\\s\\w]/' => ['pattern' => '/[\\d\\s\\w]/'];
        yield 'octal in char class: /[\\01\\o{77}]/' => ['pattern' => '/[\\01\\o{77}]/'];
        yield 'parser conditional with recursion: /(?(R)a|b)/' => ['pattern' => '/(?(R)a|b)/'];
        yield 'parser conditional with numeric backref: /()abc(?(1)yes|no)/' => ['pattern' => '/()abc(?(1)yes|no)/'];
        yield 'parser conditional bare name reference: /(?<test>x)(?(test)yes|no)/' => ['pattern' => '/(?<test>x)(?(test)yes|no)/'];
        yield 'parser char class with posix and range: /[[:alpha:]a-z]/' => ['pattern' => '/[[:alpha:]a-z]/'];
        yield 'parser char class with nested posix: /[[:alpha:][:digit:]]/' => ['pattern' => '/[[:alpha:][:digit:]]/'];
        yield 'parser char class with unicode prop: /[\\p{L}\\p{N}]/' => ['pattern' => '/[\\p{L}\\p{N}]/'];
        yield 'parser char class negated unicode prop: /[\\P{L}\\P{N}]/' => ['pattern' => '/[\\P{L}\\P{N}]/'];
        yield 'parser char class with char type: /[\\d\\w\\s]/' => ['pattern' => '/[\\d\\w\\s]/'];
        yield 'parser char class with octal: /[\\101\\o{102}]/' => ['pattern' => '/[\\101\\o{102}]/'];
        yield 'parser char class range with escaped chars: /[\\n-\\r]/' => ['pattern' => '/[\\n-\\r]/'];
        yield 'optimizer visitor with nested groups: /(((a)))/' => ['pattern' => '/(((a)))/'];
        yield 'validator with octal legacy: /\\07/' => ['pattern' => '/\\07/'];
        yield 'validator posix class variations: /[[:word:]]/' => ['pattern' => '/[[:word:]]/'];
        yield 'validator posix class variations: /[[:ascii:]]/' => ['pattern' => '/[[:ascii:]]/'];
        yield 'validator posix class variations: /[[:xdigit:]]/' => ['pattern' => '/[[:xdigit:]]/'];
        yield 'validator unicode prop variations: /\\p{Lu}/' => ['pattern' => '/\\p{Lu}/'];
        yield 'validator backref edge cases: /(?<name>a)\\k<name>/' => ['pattern' => '/(?<name>a)\\k<name>/'];
        yield 'parser subroutine variations: /(abc)(?1)/' => ['pattern' => '/(abc)(?1)/'];
        yield 'parser subroutine variations: /(?<name>abc)(?&name)/' => ['pattern' => '/(?<name>abc)(?&name)/'];
        yield 'parser pcre verb with argument: /(*MARK:label)/' => ['pattern' => '/(*MARK:label)/'];
        yield 'parser pcre verb with argument: /(*PRUNE:name)/' => ['pattern' => '/(*PRUNE:name)/'];
        yield 'parser pcre verb with argument: /(*THEN:label)/' => ['pattern' => '/(*THEN:label)/'];
        yield 'parser complex char class: /[a-zA-Z0-9_\\-\\.]/' => ['pattern' => '/[a-zA-Z0-9_\\-\\.]/'];
        yield 'parser complex char class: /[^[:digit:]]/' => ['pattern' => '/[^[:digit:]]/'];
        yield 'parser unicode variations: /\\o{177}/' => ['pattern' => '/\\o{177}/'];
        yield 'parser backref variations: /(a)(b)\\g{-1}/' => ['pattern' => '/(a)(b)\\g{-1}/'];
        yield 'parser group with modifiers: /(?s:test)/' => ['pattern' => '/(?s:test)/'];
        yield 'parser group with modifiers: /(?m:test)/' => ['pattern' => '/(?m:test)/'];
        yield 'parser group with modifiers: /(?x:test)/' => ['pattern' => '/(?x:test)/'];
        yield 'parser anchors all types: /\\Atest/' => ['pattern' => '/\\Atest/'];
        yield 'parser anchors all types: /test\\Z/' => ['pattern' => '/test\\Z/'];
        yield 'parser anchors all types: /test\\z/' => ['pattern' => '/test\\z/'];
        yield 'parser anchors all types: /\\btest/' => ['pattern' => '/\\btest/'];
        yield 'parser anchors all types: /\\Btest/' => ['pattern' => '/\\Btest/'];
        yield 'parser anchors all types: /\\Gtest/' => ['pattern' => '/\\Gtest/'];
        yield 'sample generator posix classes: /[[:lower:]]/' => ['pattern' => '/[[:lower:]]/'];
        yield 'sample generator posix classes: /[[:upper:]]/' => ['pattern' => '/[[:upper:]]/'];
        yield 'parser named group variations: /(?<name>abc)/' => ['pattern' => '/(?<name>abc)/'];
        yield 'parser named group variations: /(?P<name>abc)/' => ['pattern' => '/(?P<name>abc)/'];
        yield 'parser subroutine p syntax: /(?<foo>x)(?P>foo)/' => ['pattern' => '/(?<foo>x)(?P>foo)/'];
        yield 'parser subroutine ampersand syntax: /(?<bar>y)(?&bar)/' => ['pattern' => '/(?<bar>y)(?&bar)/'];
        yield 'parser get lexer multiple calls: /another/' => ['pattern' => '/another/'];
        yield 'parser get lexer multiple calls: /pattern/' => ['pattern' => '/pattern/'];
        yield 'parser is at end: /a/' => ['pattern' => '/a/'];
        yield 'parser is at end: /ab/' => ['pattern' => '/ab/'];
        yield 'lexer quote mode: /\\Qtest\\E/' => ['pattern' => '/\\Qtest\\E/'];
        yield 'lexer quote mode: /\\Qhello world\\E/' => ['pattern' => '/\\Qhello world\\E/'];
        yield 'lexer quote mode: /\\Q.*+?{}[]()\\E/' => ['pattern' => '/\\Q.*+?{}[]()\\E/'];
        yield 'lexer quote mode: /\\Qunclosed/' => ['pattern' => '/\\Qunclosed/'];
        yield 'lexer comment mode detailed: /(?#simple comment)/' => ['pattern' => '/(?#simple comment)/'];
        yield 'lexer comment mode detailed: /(?#comment with spaces and punctuation!)/' => ['pattern' => '/(?#comment with spaces and punctuation!)/'];
        yield 'lexer comment mode detailed: /a(?#comment)b/' => ['pattern' => '/a(?#comment)b/'];
        yield 'lexer comment mode detailed: /(?#first)x(?#second)/' => ['pattern' => '/(?#first)x(?#second)/'];
        yield 'lexer comment mode detailed: /(?#)/' => ['pattern' => '/(?#)/'];
        yield 'lexer extract token value comprehensive: /(a)(b)\\2/' => ['pattern' => '/(a)(b)\\2/'];
        yield 'lexer extract token value comprehensive: /\\77/' => ['pattern' => '/\\77/'];
        yield 'lexer normalize unicode prop: /\\P{Sc}/' => ['pattern' => '/\\P{Sc}/'];
        yield 'validate valid: /foo{1,3}/ims' => ['pattern' => '/foo{1,3}/ims'];
        yield 'allows nested quantifiers: /(a+)*b/' => ['pattern' => '/(a+)*b/'];
        yield 'valid unicode named character: /\\N{U+0041}/u' => ['pattern' => '/\\N{U+0041}/u'];
        yield 'allows non nested quantifiers: /(a*)(b*)/' => ['pattern' => '/(a*)(b*)/'];
        yield 'allows nested possessive quantifiers: /(a++)*+b/' => ['pattern' => '/(a++)*+b/'];
        yield 'allows nested possessive quantifiers: /([a-z]*+)++/' => ['pattern' => '/([a-z]*+)++/'];
        yield 'allows nested possessive quantifiers: /(a*+)+/' => ['pattern' => '/(a*+)+/'];
        yield 'allows symfony patterns with possessive quantifiers: /^[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*+(?:\\\\[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*+)++$/' => ['pattern' => '/^[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*+(?:\\\\[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*+)++$/'];
        yield 'allows symfony patterns with possessive quantifiers: /^(?:[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*+\\\\)++$/' => ['pattern' => '/^(?:[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*+\\\\)++$/'];
        yield 'allows symfony patterns with possessive quantifiers: /([^\\\\]++\\\\)++/' => ['pattern' => '/([^\\\\]++\\\\)++/'];
        yield 'allows symfony patterns with possessive quantifiers: /^(?:[-.\\w\\\\]*+:)*+\\w*+$/' => ['pattern' => '/^(?:[-.\\w\\\\]*+:)*+\\w*+$/'];
        yield 'validate valid char class: /[a-z\\d-]/' => ['pattern' => '/[a-z\\d-]/'];
        yield 'multi digit backref falls back to octal: /(a)\\11/' => ['pattern' => '/(a)\\11/'];
        yield 'multi digit backref falls back to octal: /(a)(b)\\10/' => ['pattern' => '/(a)(b)\\10/'];
        yield 'multi digit backref falls back to octal: /\\19/' => ['pattern' => '/\\19/'];
        yield 'validate valid subroutine: /(?<name>a)(?&name)/' => ['pattern' => '/(?<name>a)(?&name)/'];
        yield 'allows octal zero escape in validator: /\\0/' => ['pattern' => '/\\0/'];
        yield 'validates named conditional: /(?<n>a)(?(n)b)/' => ['pattern' => '/(?<n>a)(?(n)b)/'];
        yield 'validator allows nested quantifiers: /(a+)+/' => ['pattern' => '/(a+)+/'];
        yield 'accepts negated posix word class: /[[:^word:]]/' => ['pattern' => '/[[:^word:]]/'];
        yield 'parser with quote mode: /\\Qtest.*\\E/' => ['pattern' => '/\\Qtest.*\\E/'];
        yield 'parser with special escapes: /\\t\\n\\r/' => ['pattern' => '/\\t\\n\\r/'];
        yield 'an atomic verb wrapping nested groups: /(*atomic:((a)))/' => ['pattern' => '/(*atomic:((a)))/'];
        yield 'a lookahead verb wrapping several groups: /(*pla:((a)b(c)))/' => ['pattern' => '/(*pla:((a)b(c)))/'];
    }

    private function compiles(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);
        $compiles = false !== @preg_match($pattern, '');
        restore_error_handler();

        return $compiles;
    }
}
