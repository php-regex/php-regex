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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\TestUtils\Pcre2CaseRunner;
use PHPRegex\Tests\TestUtils\PcreMessageCodes;
use PHPRegex\Tests\TestUtils\PhpErrorOffset;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every refused pattern carries a code that names its problem.
 *
 * The syntax errors used to share two catch-all codes, one for the parser
 * and one for the lexer. Each family of raise sites now has its own code:
 * the families below reach every kind of raise site (delimiters and
 * modifiers, the lexer, the parser, group names, extended classes, scan
 * substring lists, the library's own limits), and the net over the PCRE2
 * suite's rejected cases catches a raise site the hand-written list forgets.
 *
 * Every pattern is refused by the running PHP, checked in the test itself,
 * except the two limits of the library's own: a pattern past
 * "max_pattern_length" and nesting past "max_recursion_depth" compile in
 * PHP, and the library refuses them on purpose.
 *
 * @phpstan-type Pcre2Override = array{reason: string, verdict: string, offset: int|null, pcre2Code: int|null}
 * @phpstan-type Pcre2Case = array{id: string, pattern: string, delimiter: string, flags: string, verdict: string|null, offset: int|null, error: string|null, pcre2Code: int|null, phpOverride: Pcre2Override|null, floor: array{verdict: string, offset: int|null, pcre2Code: int|null}|null, skipCategory: string|null, skipReason: string|null}
 */
final class ErrorCodeSpecificityTest extends TestCase
{
    private const SUITE_JSON = __DIR__.'/../Fixtures/Pcre2/suite-cases.json';

    /**
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('provideRefusedPatternsWithTheirCode')]
    public function test_validate_reports_the_code_of_the_problem(string $pattern, array $options, bool $engineRefuses, string $expected): void
    {
        if ($this->judgedBeforeItsRelease($pattern, $options)) {
            return;
        }

        $this->assertEngineVerdict($pattern, $engineRefuses);

        $result = self::validator($options)->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s must be refused.', var_export($pattern, true)));
        $this->assertSame(ErrorCode::from($expected), $result->errorCode, \sprintf('%s: %s', var_export($pattern, true), (string) $result->error));
    }

    #[Test]
    #[DataProvider('provideRefusedPatternsOfEveryFamily')]
    public function test_validate_reports_a_specific_code_for_every_family(string $pattern): void
    {
        $this->assertEngineVerdict($pattern, true);

        $result = self::validator([])->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s must be refused.', var_export($pattern, true)));
        $this->assertInstanceOf(ErrorCode::class, $result->errorCode, \sprintf('%s: %s', var_export($pattern, true), (string) $result->error));
    }

    /**
     * @param Pcre2Case $case
     */
    #[Test]
    #[DataProvider('provideSuiteRejectedCases')]
    public function test_validate_reports_a_specific_code_for_every_suite_rejection(array $case, string $pin): void
    {
        $pattern = (new Pcre2CaseRunner())->phpPattern($case);

        $result = RegexParser::create(['cache' => null, 'pcre_version' => $pin])->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s (%s) is refused by PCRE2 %s but was reported valid.', $case['id'], $pattern, $pin));
        $this->assertInstanceOf(ErrorCode::class, $result->errorCode, \sprintf('%s (%s): %s', $case['id'], $pattern, (string) $result->error));
    }

    /**
     * The message names the same problem as the code: an unclosed verb is no
     * quantifier without a target.
     */
    #[Test]
    public function test_an_unclosed_verb_is_reported_as_one(): void
    {
        foreach (['/(*MARK:a/' => 8, '/(*ACCEPT/' => 8, '/(*:a/' => 4] as $pattern => $offset) {
            $result = self::validator([])->validate($pattern);

            $this->assertSame(ErrorCode::VerbUnclosed, $result->errorCode, $pattern);
            $this->assertSame($offset, $result->offset, $pattern);
            $this->assertStringStartsWith(\sprintf('Missing ")" to close the verb at position %d.', $offset), (string) $result->error, $pattern);
        }
    }

    #[Test]
    public function test_suite_net_is_never_empty(): void
    {
        // A net that holds no case proves nothing: the fixture must yield
        // the rejected cases the suite records.
        $this->assertGreaterThan(400, iterator_count(self::provideSuiteRejectedCases()));
    }

    /**
     * The unambiguous families, with the code the error contract names.
     *
     * @return iterable<string, array{pattern: string, options: array<string, mixed>, engineRefuses: bool, expected: string}>
     */
    public static function provideRefusedPatternsWithTheirCode(): iterable
    {
        // Delimiters and modifiers.
        yield 'alphanumeric delimiter' => ['pattern' => 'abc', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.delimiter.invalid'];
        yield 'backslash delimiter' => ['pattern' => '\\a\\', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.delimiter.invalid'];
        yield 'NUL delimiter' => ['pattern' => "\0a\0", 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.delimiter.invalid'];
        // NUL is no whitespace: PHP never skips it, so it is the delimiter.
        yield 'NUL before a valid delimiter' => ['pattern' => "\0/a/", 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.delimiter.invalid'];
        yield 'lone NUL' => ['pattern' => "\0", 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.delimiter.invalid'];
        // Form feed is whitespace to PHP: skipped, then nothing is left.
        yield 'lone form feed' => ['pattern' => "\x0C", 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.pattern.empty'];
        yield 'no closing delimiter' => ['pattern' => '/abc', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.delimiter.unclosed'];
        yield 'no closing bracket delimiter' => ['pattern' => '(abc', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.delimiter.unclosed'];
        yield 'bracket delimiter left open by nesting' => ['pattern' => '{a{b}', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.delimiter.unclosed'];
        // PHP scans for the closing delimiter first: a backslash before it
        // escapes it, so no body can end in a lone backslash and PHP reports
        // "No ending delimiter '/' found".
        yield 'backslash escaping the closing delimiter' => ['pattern' => '/a\\/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.delimiter.unclosed'];
        yield 'empty string' => ['pattern' => '', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.pattern.empty'];
        yield 'whitespace only' => ['pattern' => "  \t\n", 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.pattern.empty'];
        yield 'unknown modifier' => ['pattern' => '/a/Q', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.flag.unknown'];
        yield 'unknown modifier after a known one' => ['pattern' => '/a/iQ', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.flag.unknown'];
        yield 'removed e modifier' => ['pattern' => '/a/e', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.flag.removed_e'];
        yield 'removed e modifier after a known one' => ['pattern' => '/a/ie', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.flag.removed_e'];

        // Encoding.
        yield 'illegal byte under u' => ['pattern' => "/\xff/u", 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.encoding.invalid_utf8'];
        yield 'truncated sequence under u' => ['pattern' => "/a\xc3/u", 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.encoding.invalid_utf8'];
        yield 'overlong sequence under u' => ['pattern' => "/\xc0\xaf/u", 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.encoding.invalid_utf8'];

        // Groups.
        yield 'group left open' => ['pattern' => '/a(/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.unclosed'];
        yield 'inner group closed, outer left open' => ['pattern' => '/((a)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.unclosed'];
        yield 'named group left open' => ['pattern' => '/(?<n>a/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.unclosed'];
        yield 'lookahead left open' => ['pattern' => '/(?=a/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.unclosed'];
        yield 'unmatched closing parenthesis' => ['pattern' => '/a)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.unmatched_close'];
        yield 'unmatched closing parenthesis under x' => ['pattern' => '/a) # c/x', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.unmatched_close'];
        yield 'duplicate name' => ['pattern' => '/(?<n>a)(?<n>b)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.duplicate_name'];
        yield 'duplicate name, P syntax' => ['pattern' => '/(?P<n>a)(?P<n>b)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.duplicate_name'];
        yield 'duplicate name, quoted syntax' => ['pattern' => "/(?'n'a)(?'n'b)/", 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.duplicate_name'];

        // Quantifiers with nothing to repeat.
        yield 'quantifier at the start' => ['pattern' => '/+a/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.quantifier.nothing_to_repeat'];
        yield 'question mark alone' => ['pattern' => '/?/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.quantifier.nothing_to_repeat'];
        yield 'braced count at the start' => ['pattern' => '/{2}a/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.quantifier.nothing_to_repeat'];
        yield 'quantifier after an alternation bar' => ['pattern' => '/a|*b/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.quantifier.nothing_to_repeat'];
        yield 'quantifier opening a group' => ['pattern' => '/(+)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.quantifier.nothing_to_repeat'];
        yield 'quantifier on a quantifier' => ['pattern' => '/a**/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.quantifier.nothing_to_repeat'];

        // Classes, comments, POSIX names.
        yield 'class left open' => ['pattern' => '/[a/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.charclass.unclosed'];
        yield 'negated class left open' => ['pattern' => '/[^/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.charclass.unclosed'];
        yield 'class whose first ] is a member' => ['pattern' => '/[]/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.charclass.unclosed'];
        yield 'negated class whose first ] is a member' => ['pattern' => '/[^]/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.charclass.unclosed'];
        yield 'comment left open' => ['pattern' => '/(?#abc/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.comment.unclosed'];
        yield 'comment left open under x' => ['pattern' => '/a(?#/x', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.comment.unclosed'];
        yield 'unknown POSIX class' => ['pattern' => '/[[:foo:]]/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.posix.invalid'];
        // Refused by the parser, not the validator, today: the same problem,
        // so the same code.
        yield 'word-boundary POSIX name in an extended class' => ['pattern' => '/(?[ [[:<:]] ])/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.posix.invalid'];

        // The library's own limits: PHP compiles these.
        yield 'one character past the maximum length' => ['pattern' => '/abcdefghi/', 'options' => ['max_pattern_length' => 10], 'engineRefuses' => false, 'expected' => 'regex.pattern.too_long'];
        yield 'far past the maximum length' => ['pattern' => '/abcdefghijklmnop/', 'options' => ['max_pattern_length' => 10], 'engineRefuses' => false, 'expected' => 'regex.pattern.too_long'];
        yield 'one group past the nesting limit' => ['pattern' => '/(((((a)))))/', 'options' => ['max_recursion_depth' => 5], 'engineRefuses' => false, 'expected' => 'regex.nesting.too_deep'];
        yield 'ten groups against a nesting limit of five' => ['pattern' => '/((((((((((a))))))))))/', 'options' => ['max_recursion_depth' => 5], 'engineRefuses' => false, 'expected' => 'regex.nesting.too_deep'];

        // The code follows what PCRE reports, not the raise site: PHP says
        // "No ending delimiter" for a lone delimiter and "Delimiter must not
        // be alphanumeric" for a lone letter.
        yield 'lone delimiter' => ['pattern' => '/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.delimiter.unclosed'];
        yield 'lone delimiter after whitespace' => ['pattern' => ' #', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.delimiter.unclosed'];
        yield 'lone letter' => ['pattern' => 'a', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.delimiter.invalid'];
        yield 'unescaped delimiter ending the pattern early' => ['pattern' => '/a/b/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.delimiter.unescaped'];
        yield 'non-hex digit in \\N{U+}' => ['pattern' => '/\\N{U+zz}/u', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.unicode.invalid_digit'];
        yield 'mark shorthand with =' => ['pattern' => '/(*=x)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.verb.invalid'];
        yield 'empty property name' => ['pattern' => '/\\p{}/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.unicode.property_invalid'];

        // PCRE reads a braced count before it asks whether the item before
        // it can repeat: "numbers out of order" / "number too big" wins.
        yield 'reversed count on an assertion' => ['pattern' => '/^{2,1}/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.quantifier.invalid_range'];
        yield 'reversed count on a word boundary' => ['pattern' => '/\\b{2,1}/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.quantifier.invalid_range'];
        yield 'count too big on a word boundary' => ['pattern' => '/\\b{65536}/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.quantifier.too_big'];
        yield 'reversed count on a quantifier' => ['pattern' => '/a*{2,1}/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.quantifier.invalid_range'];
        yield 'reversed count on a callout' => ['pattern' => '/(?C1){2,1}/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.quantifier.invalid_range'];
        yield 'count on a quantifier, in order' => ['pattern' => '/a*{2}/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.quantifier.nothing_to_repeat'];

        // "subpattern name expected": a condition that is no reference.
        yield 'sign with no number as a condition' => ['pattern' => '/(?(+a)a)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.conditional.invalid'];
        yield 'braced count as a condition' => ['pattern' => '/(?({2})a)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.conditional.invalid'];

        // "subpattern name must start with a non-digit", as for "(?P=1a)".
        yield '\\k<> name starting with a digit' => ['pattern' => '/\\k<1>/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.name_invalid'];
        yield '\\k{} name starting with a digit' => ['pattern' => '/\\k{1a}/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.name_invalid'];
        yield '\\k\'\' name starting with a digit' => ['pattern' => "/\\k'1'/", 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.name_invalid'];
        // PCRE measures a name before it looks for what closes it: past 128
        // code units the length is the fault, at 128 the missing terminator.
        yield 'name past 128 units, then no terminator' => ['pattern' => '/(?<'.str_repeat('a', 129).' b>x)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.name_too_long'];
        yield 'name of 128 units, then no terminator' => ['pattern' => '/(?<'.str_repeat('a', 128).' b>x)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.name_unterminated'];
        yield 'subroutine name starting with a digit' => ['pattern' => '/(?&1}/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.name_invalid'];
        yield 'P> subroutine name starting with a digit' => ['pattern' => '/(?P>1a}/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.name_invalid'];
        yield '\\g{} number with a letter in it' => ['pattern' => '/\\g{1a}/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.backref.invalid_syntax'];
        yield '\\k<> name starting with a digit inside a script run' => ['pattern' => '/(*pla:\\k<1>)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.name_invalid'];

        // An unclosed "(?" gets the code of what PCRE reports, as the
        // closed form does.
        yield 'unclosed callout with an unknown delimiter' => ['pattern' => '/(?Cb/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.callout.invalid_delimiter'];
        yield 'unclosed callout number past 255' => ['pattern' => '/(?C300/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.callout.out_of_range'];
        yield 'unclosed callout string left open' => ['pattern' => '/(?C"a/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.callout.unclosed_string'];
        yield 'unclosed relative call to no group' => ['pattern' => '/(?-1/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.subroutine.relative_missing'];
        yield 'unclosed call number too big' => ['pattern' => '/(?70000/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.number_too_big'];
        yield 'unclosed inline option' => ['pattern' => '/(?i/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.unclosed'];
        yield 'unclosed call' => ['pattern' => '/(?1/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.unclosed'];
        yield 'second hyphen in an option setting' => ['pattern' => '/(?x-i-i)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.option_hyphen'];
        yield 'extended class as a condition' => ['pattern' => '/(?(?[a])b)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.condition.assertion_expected'];

        // "closing parenthesis for (?C expected".
        yield 'callout number followed by a letter' => ['pattern' => '/(?C1x)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.callout.unclosed'];
        yield 'callout string followed by a letter' => ['pattern' => '/(?C"a"b)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.callout.unclosed'];

        // "quantifier does not follow a repeatable item".
        yield 'lone (* at the end' => ['pattern' => '/(*/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.quantifier.nothing_to_repeat'];

        // Group lists share their codes, whatever the construct holding them.
        yield 'call list naming group zero' => ['pattern' => '/(?1(0))/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group_list.missing_group'];
        yield 'call list with a relative zero' => ['pattern' => '/(?1(-0))/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group_list.relative_zero'];
        yield 'recursion list with a letter' => ['pattern' => '/(?R(x))/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group_list.item_expected'];
        yield 'call list naming a group past the last' => ['pattern' => '/(a)(?1(2))/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group_list.missing_group'];
        yield 'call list with a relative reference past the first' => ['pattern' => '/(a)(?1(-2))/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group_list.missing_group'];
        yield 'scan substring list naming group zero' => ['pattern' => '/(*scs:(0)a)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group_list.missing_group'];
        yield 'scan substring list with a relative zero' => ['pattern' => '/(*scs:(-0)a)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group_list.relative_zero'];
        yield 'scan substring list with a letter' => ['pattern' => '/(*scs:(x)a)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group_list.item_expected'];
        yield 'scan substring list naming a group past the last' => ['pattern' => '/(a)(*scs:(2)a)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group_list.missing_group'];

        // "subpattern number is too big", wherever the number stands.
        yield '\\g number too big' => ['pattern' => '/\\g70000/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.number_too_big'];
        yield 'call number too big' => ['pattern' => '/(?70000)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.number_too_big'];
        yield 'backslash number too big' => ['pattern' => '/\\800000/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.number_too_big'];
        yield 'recursion condition number too big' => ['pattern' => '/(?(R70000)a)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.number_too_big'];
        yield 'relative \\g number too big' => ['pattern' => '/\\g+70000/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.number_too_big'];
        yield '\\g number too big after a missing name' => ['pattern' => '/\\k<a>\\g70000/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.number_too_big'];
        // 65535 is the largest group number: a missing group, not a number too big.
        yield '\\g at the largest group number' => ['pattern' => '/\\g65535/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.backref.missing_group'];
        yield '\\g one past the largest group number' => ['pattern' => '/\\g65536/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.number_too_big'];
        yield 'call one past the largest group number' => ['pattern' => '/(?65536)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.number_too_big'];

        // Callout number boundaries: 255 is read, then ")" is due; 256 is
        // out of range even when the callout is left open.
        yield 'callout 255 followed by a letter' => ['pattern' => '/(?C255x)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.callout.unclosed'];
        yield 'unclosed callout 256' => ['pattern' => '/(?C256/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.callout.out_of_range'];

        // "syntax error or number too big in (?(VERSION condition".
        yield 'version with a letter' => ['pattern' => '/(?(VERSION=10z)yes|no)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.condition.version_syntax'];
        yield 'version with no number' => ['pattern' => '/(?(VERSION=)a)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.condition.version_syntax'];
        yield 'version with no minor' => ['pattern' => '/(?(VERSION=10.)a)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.condition.version_syntax'];

        // "subpattern name expected".
        yield 'quoted group name at the end' => ['pattern' => "/(?'/", 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.name_expected'];
        yield 'angle-bracketed group name at the end' => ['pattern' => '/(?</', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.name_expected'];
        yield 'subroutine name starting with a comma' => ['pattern' => '/(?&,/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.name_expected'];

        // "missing closing parenthesis" after a subroutine name.
        yield 'subroutine name followed by a brace' => ['pattern' => '/(?&L}/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.unclosed'];
        yield 'P> subroutine name followed by a brace' => ['pattern' => '/(?P>a}/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.unclosed'];

        // "unmatched closing parenthesis" inside an extended class.
        yield 'extended class: ) where an operand is due' => ['pattern' => '/(?[ ) ])/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.extended_class.unmatched_close'];
        yield 'extended class: ) right after (?[' => ['pattern' => '/(?[)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.extended_class.unmatched_close'];

        // PCRE2 10.43 and later read a spaced count and "{,n}" as counts,
        // before they ask whether the item before can repeat.
        yield 'reversed spaced count on an assertion' => ['pattern' => '/^{ 3,2 }/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.quantifier.invalid_range'];
        yield '{,n} count too big at the start' => ['pattern' => '/{,70000}/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.quantifier.too_big'];
        yield 'spaced count too big at the start' => ['pattern' => '/{ 70000 }/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.quantifier.too_big'];

        // "atomic assertion expected after (?( or (?(?C)".
        yield 'lone (* as a condition' => ['pattern' => '/(?(*/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.condition.assertion_expected'];

        // "reference to non-existent subpattern" from a named condition.
        yield 'angle-bracketed condition naming no group' => ['pattern' => '/(?(<a>)x)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.condition.missing_group'];
        yield 'bare condition naming no group' => ['pattern' => '/(?(abc))/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.condition.missing_group'];

        // "syntax error in subpattern name (missing terminator?)".
        yield 'space inside a group name' => ['pattern' => '/(?<a b>)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.name_unterminated'];
        yield 'P= name followed by a !' => ['pattern' => '/(?P=a!)/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.group.name_unterminated'];

        // "expected operand after operator in extended character class".
        yield 'extended class: operator with nothing after it' => ['pattern' => '/(?[ [a] + ])/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.extended_class.missing_operand'];

        // "PCRE2 does not support \F, \L, \l, \N{name}, \U, or \u", in a class too.
        yield '\\N{} in a class' => ['pattern' => '/[\\N{4}]/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.escape.unsupported'];

        // "invalid range in character class": a collating element is no
        // range end.
        yield 'collating element as a range end' => ['pattern' => '/[a-[.x.]]/', 'options' => [], 'engineRefuses' => true, 'expected' => 'regex.range.invalid_bounds'];

        // The library's own budget: PHP compiles a flat extended class of
        // twelve operands; eleven operations run past a limit of five.
        yield 'flat extended class past the operation budget' => ['pattern' => '/(?[ '.implode(' | ', array_fill(0, 12, '[a]')).' ])/', 'options' => ['max_recursion_depth' => 5], 'engineRefuses' => false, 'expected' => 'regex.extended_class.too_complex'];
    }

    /**
     * PCRE's offset, not the first fault the library happens to meet: in
     * "\k<a>\g70000" the missing name is only checked once the pattern is
     * read, and the number too big is reported first.
     */
    #[Test]
    #[DataProvider('provideGroupNumbersTooBig')]
    public function test_validate_reports_a_group_number_too_big_where_pcre_does(string $pattern, int $offset): void
    {
        $this->assertSame($offset, PhpErrorOffset::of($pattern), \sprintf('%s: the running PHP moved the offset.', $pattern));

        $result = self::validator([])->validate($pattern);

        $this->assertSame(ErrorCode::from('regex.group.number_too_big'), $result->errorCode, \sprintf('%s: %s', $pattern, (string) $result->error));
        $this->assertSame($offset, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideGroupNumbersTooBig(): iterable
    {
        yield '\\g number' => ['pattern' => '/\\g70000/', 'offset' => 7];
        yield 'call number' => ['pattern' => '/(?70000)/', 'offset' => 7];
        yield 'backslash number' => ['pattern' => '/\\800000/', 'offset' => 7];
        yield 'recursion condition number' => ['pattern' => '/(?(R70000)a)/', 'offset' => 8];
        yield 'relative \\g number' => ['pattern' => '/\\g+70000/', 'offset' => 8];
        yield '\\g number after a missing name' => ['pattern' => '/\\k<a>\\g70000/', 'offset' => 12];
    }

    /**
     * Code, offset and message name the same fault as PCRE, at the offset
     * the running PHP reports.
     */
    #[Test]
    #[DataProvider('provideFaultsWithTheirOffsetAndMessage')]
    public function test_validate_reports_code_offset_and_message_of_the_fault(string $pattern, string $code, string $message): void
    {
        if ($this->judgedBeforeItsRelease($pattern, [])) {
            return;
        }

        $offset = PhpErrorOffset::of($pattern);
        $this->assertNotNull($offset, \sprintf('%s must be refused by the running PHP.', $pattern));

        $result = self::validator([])->validate($pattern);

        $this->assertSame(ErrorCode::from($code), $result->errorCode, \sprintf('%s: %s', $pattern, (string) $result->error));
        $this->assertSame($offset, $result->offset, $pattern);
        $this->assertStringContainsString($message, (string) $result->error, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, code: string, message: string}>
     */
    public static function provideFaultsWithTheirOffsetAndMessage(): iterable
    {
        yield 'extended class as a condition' => ['pattern' => '/(?(?[a])b)/', 'code' => 'regex.condition.assertion_expected', 'message' => 'a lookaround assertion is expected'];
        yield 'reversed count on an option setting' => ['pattern' => '/(?i){2,1}/', 'code' => 'regex.quantifier.invalid_range', 'message' => 'out of order'];
        yield 'reversed count on an option setting after a literal' => ['pattern' => '/a(?i){2,1}/', 'code' => 'regex.quantifier.invalid_range', 'message' => 'out of order'];
        yield 'non-hex digit in \\N{U+}' => ['pattern' => '/\\N{U+zz}/u', 'code' => 'regex.unicode.invalid_digit', 'message' => '\\N{U+}'];
        yield 'non-hex digit in \\x{}' => ['pattern' => '/\\x{zz}/u', 'code' => 'regex.unicode.invalid_digit', 'message' => '\\x{}'];
        yield 'quoted list name left open' => ['pattern' => "/(*scs:('a/", 'code' => 'regex.group.name_unterminated', 'message' => 'Missing "\'"'];
        yield 'angle list name left open' => ['pattern' => '/(*scs:(<a/', 'code' => 'regex.group.name_unterminated', 'message' => 'Missing ">"'];
        yield 'name past 128 units' => ['pattern' => '/(?<'.str_repeat('a', 129).' b>x)/', 'code' => 'regex.group.name_too_long', 'message' => '129 code units'];
        yield '\\k name starting with a digit, left open' => ['pattern' => "/\\k'1a/", 'code' => 'regex.group.name_invalid', 'message' => 'must not start with a digit'];
        yield 'invalid UTF-8 under u' => ['pattern' => "/\xff/u", 'code' => 'regex.encoding.invalid_utf8', 'message' => 'not valid UTF-8'];

        // UTF mode set by a start-of-pattern verb checks the bytes as "u"
        // does, and PCRE reports the first byte that is no UTF-8.
        yield 'illegal byte under (*UTF)' => ['pattern' => "/(*UTF)\xff/", 'code' => 'regex.encoding.invalid_utf8', 'message' => 'not valid UTF-8'];
        yield 'illegal byte under (*UTF8)' => ['pattern' => "/(*UTF8)\xff/", 'code' => 'regex.encoding.invalid_utf8', 'message' => 'not valid UTF-8'];
        yield 'truncated sequence under (*UTF)' => ['pattern' => "/(*UTF)a\xc3/", 'code' => 'regex.encoding.invalid_utf8', 'message' => 'not valid UTF-8'];
        yield '(*UTF) after another setting' => ['pattern' => "/(*LIMIT_MATCH=10)(*UTF)\xff/", 'code' => 'regex.encoding.invalid_utf8', 'message' => 'not valid UTF-8'];
        yield 'illegal byte under (*UTF) and u' => ['pattern' => "/(*UTF)\xff/u", 'code' => 'regex.encoding.invalid_utf8', 'message' => 'not valid UTF-8'];
        yield 'isolated continuation byte after text' => ['pattern' => "/ab\x80/u", 'code' => 'regex.encoding.invalid_utf8', 'message' => 'not valid UTF-8'];
        yield 'broken three-byte sequence after text' => ['pattern' => "/ab\xe2\x82z/u", 'code' => 'regex.encoding.invalid_utf8', 'message' => 'not valid UTF-8'];
        yield 'surrogate after text' => ['pattern' => "/ab\xed\xa0\x80/u", 'code' => 'regex.encoding.invalid_utf8', 'message' => 'not valid UTF-8'];
        yield '(*UTF) not at the start' => ['pattern' => "/a(*UTF)\xff/", 'code' => 'regex.verb.misplaced', 'message' => '(*UTF)'];
        // PCRE: "subpattern name expected", where the name should start.
        yield '\\g brace with nothing in it' => ['pattern' => '/\\g{/', 'code' => 'regex.group.name_expected', 'message' => 'a group name or number is expected'];
        yield '\\g brace holding only padding' => ['pattern' => '/(a)\\g{  }/', 'code' => 'regex.group.name_expected', 'message' => 'a group name or number is expected'];
        yield '\\g brace holding a lone sign' => ['pattern' => '/(a)\\g{- 1}/', 'code' => 'regex.group.name_expected', 'message' => 'a group name or number is expected'];
    }

    /**
     * PCRE2 10.43 and later read "{ 3,2 }" and "{,n}" as counts: the count
     * is refused where it ends, before repeatability is asked.
     */
    #[Test]
    #[DataProvider('provideCountsReadBeforeRepeatability')]
    public function test_validate_reads_a_count_before_repeatability_in_every_form(string $pattern, string $expected, int $offset): void
    {
        if ($this->judgedBeforeItsRelease($pattern, [])) {
            return;
        }

        $this->assertSame($offset, PhpErrorOffset::of($pattern), \sprintf('%s: the running PHP moved the offset.', $pattern));

        $result = self::validator([])->validate($pattern);

        $this->assertSame(ErrorCode::from($expected), $result->errorCode, \sprintf('%s: %s', $pattern, (string) $result->error));
        $this->assertSame($offset, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, expected: string, offset: int}>
     */
    public static function provideCountsReadBeforeRepeatability(): iterable
    {
        yield 'reversed spaced count on an assertion' => ['pattern' => '/^{ 3,2 }/', 'expected' => 'regex.quantifier.invalid_range', 'offset' => 6];
        yield '{,n} count too big at the start' => ['pattern' => '/{,70000}/', 'expected' => 'regex.quantifier.too_big', 'offset' => 7];
        yield 'spaced count too big at the start' => ['pattern' => '/{ 70000 }/', 'expected' => 'regex.quantifier.too_big', 'offset' => 7];
    }

    /**
     * The code names the problem PCRE names: the live warning of the running
     * PHP is read, and the library's code must be one of those allowed for
     * its message. The library is pinned to the running release, so both
     * judge the same PCRE2.
     */
    #[Test]
    #[DataProvider('provideSuiteRejectionsWithTheirMessage')]
    public function test_validate_reports_a_code_matching_the_pcre_message_for_every_suite_rejection(string $id, string $pattern, string $message, ?int $offset): void
    {
        $this->assertArrayHasKey($message, PcreMessageCodes::CODES, \sprintf('%s (%s): PCRE message "%s" is missing from the message table.', $id, $pattern, $message));

        $allowed = PcreMessageCodes::CODES[$message];

        $this->assertNotSame([], $allowed, \sprintf('%s (%s): no code is decided yet for PCRE message "%s".', $id, $pattern, $message));

        $result = RegexParser::create(['cache' => null, 'pcre_version' => self::runtimePin()])->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s (%s) is refused by the running PHP ("%s") but was reported valid.', $id, $pattern, $message));
        $this->assertContains(
            $result->errorCode?->value,
            $allowed,
            \sprintf('%s (%s): PCRE says "%s" at offset %s, the library reports %s (%s).', $id, $pattern, $message, var_export($offset, true), $result->errorCode->value ?? 'no code', (string) $result->error),
        );
    }

    #[Test]
    public function test_pcre_message_net_is_never_empty(): void
    {
        // Only cases the running PHP refuses are in the net: a new release
        // accepting them all would leave it empty.
        $this->assertGreaterThan(400, iterator_count(self::provideSuiteRejectionsWithTheirMessage()));
    }

    /**
     * Every suite rejection the running PHP refuses too, with the message
     * and offset PHP reports.
     *
     * @return iterable<string, array{id: string, pattern: string, message: string, offset: int|null}>
     */
    public static function provideSuiteRejectionsWithTheirMessage(): iterable
    {
        $runner = new Pcre2CaseRunner();

        foreach (self::provideSuiteRejectedCases() as $id => ['case' => $case]) {
            $pattern = $runner->phpPattern($case);
            $warning = PcreMessageCodes::warningOf($pattern);

            if (null === $warning) {
                continue;
            }

            ['message' => $message, 'offset' => $offset] = PcreMessageCodes::read($warning);

            yield $id => ['id' => $id, 'pattern' => $pattern, 'message' => $message, 'offset' => $offset];
        }
    }

    /**
     * One pattern at least per family of raise sites whose code the error
     * contract leaves to the implementation: only "specific" is asserted.
     *
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRefusedPatternsOfEveryFamily(): iterable
    {
        // Delimiter layer.
        yield 'lone delimiter' => ['pattern' => '/'];
        yield 'unescaped delimiter ending the pattern early' => ['pattern' => '/a/b/'];

        // Lexer.
        yield '\\c at the end' => ['pattern' => '/a\\c/'];
        yield '\\c before a control byte' => ['pattern' => "/\\c\x01/"];
        yield 'empty braced hex escape' => ['pattern' => '/\\x{}/'];
        yield 'alpha assertion left open' => ['pattern' => '/(*pla:a/'];

        // Quantifiers on what cannot repeat.
        yield 'quantifier after ^' => ['pattern' => '/^*/'];
        yield 'quantifier after $' => ['pattern' => '/$+/'];
        yield 'quantifier on a word boundary' => ['pattern' => '/\\b+/'];
        yield 'quantifier on \\K' => ['pattern' => '/\\K+/'];
        yield 'quantifier on a callout' => ['pattern' => '/(?C1)*/'];
        yield 'quantifier on COMMIT' => ['pattern' => '/(*COMMIT)+/'];

        // \k and \g syntax.
        yield 'bare \\k' => ['pattern' => '/\\k/'];
        yield 'empty angle-bracketed \\k name' => ['pattern' => '/\\k<>/'];
        yield 'empty quoted \\k name' => ['pattern' => "/\\k''/"];
        yield '\\k name left open' => ['pattern' => '/\\k<a/'];
        yield 'bare \\g' => ['pattern' => '/\\g/'];
        yield '\\g brace left open' => ['pattern' => '/\\g{/'];
        yield '\\g before a letter' => ['pattern' => '/\\ga/'];

        // Callouts.
        yield 'callout with an unknown delimiter' => ['pattern' => '/(?Cx)/'];
        yield 'callout string left open' => ['pattern' => '/(?C"abc)/'];

        // Group names.
        yield 'empty group name' => ['pattern' => '/(?<>a)/'];
        yield 'empty P group name' => ['pattern' => '/(?P<>a)/'];
        yield 'group name starting with a digit' => ['pattern' => '/(?<1a>x)/'];
        yield 'group name with a hyphen' => ['pattern' => '/(?<a-b>x)/'];
        yield 'group name left open' => ['pattern' => '/(?<a/'];
        yield 'quoted group name closed by >' => ['pattern' => "/(?'a>x)/"];
        yield 'group name of 200 code units' => ['pattern' => '/(?<'.str_repeat('a', 200).'>x)/'];
        yield 'two names for one number in a branch reset' => ['pattern' => '/(?|(?<a>A)|(?<b>B))/'];

        // Subroutine calls.
        yield 'empty subroutine name' => ['pattern' => '/(?&)/'];
        yield 'subroutine name starting with a digit' => ['pattern' => '/(?&1a)/'];
        yield 'empty P> subroutine name' => ['pattern' => '/(?P>)/'];

        // Group openers and inline options.
        yield 'unknown character after (?P' => ['pattern' => '/(?Px)/'];
        yield 'quote after (?P' => ['pattern' => "/(?P'abc'x)/"];
        yield 'hyphen after (?^' => ['pattern' => '/(?^-i)/'];
        yield 'unknown character after (?' => ['pattern' => '/(?z)/'];

        // Conditionals.
        yield 'callout condition without an assertion' => ['pattern' => '/(?(?C1)a)/'];
        yield 'non-capturing group as a condition' => ['pattern' => '/(?(?:a)b)/'];
        yield 'ACCEPT as a condition' => ['pattern' => '/(?(*ACCEPT)xxx)/'];
        yield 'condition number followed by a letter' => ['pattern' => '/(?(1x)a)/'];
        yield 'empty condition' => ['pattern' => '/(?()a)/'];
        yield 'condition left open' => ['pattern' => '/(?(1/'];
        yield 'condition number too big' => ['pattern' => '/(?(70000)a)/'];

        // Class ranges with an endpoint that is no character.
        yield 'character type as a range start' => ['pattern' => '/[\\d-z]/'];
        yield 'POSIX class as a range start' => ['pattern' => '/[[:alpha:]-z]/'];

        // Extended classes, PCRE2 10.45.
        yield 'extended class: unmatched )' => ['pattern' => '/(?[ a ]))/'];
        yield 'extended class: missing ]' => ['pattern' => '/(?[ [a] /'];
        yield 'extended class: ] not followed by )' => ['pattern' => '/(?[ [a] ]x/'];
        yield 'extended class: empty expression' => ['pattern' => '/(?[ ])/'];
        yield 'extended class: operator where an operand is due' => ['pattern' => '/(?[ + [a] ])/'];
        yield 'extended class: bare character' => ['pattern' => '/(?[ a ])/'];
        yield 'extended class: two operands without an operator' => ['pattern' => '/(?[ [a] [b] ])/'];
        yield 'extended class: missing )' => ['pattern' => '/(?[ ( [a] ])/'];
        yield 'extended class: \\p{ left open' => ['pattern' => '/(?[ \\p{L /'];
        yield 'extended class: nested too deeply' => ['pattern' => '/(?[ '.str_repeat('(', 40).'[a]'.str_repeat(')', 40).' ])/'];

        // Scan substring, PCRE2 10.45.
        yield 'scan substring without its group list' => ['pattern' => '/(*scs:a)/'];
        yield 'scan substring with an empty group list' => ['pattern' => '/(*scs:()a)/'];
        yield 'scan substring list with a trailing comma' => ['pattern' => '/(a)(*scs:(1,)a)/'];
    }

    /**
     * Every case the pinned PCRE2 suite records as refused in PHP, run for
     * the release the fixture was extracted from.
     *
     * @return iterable<string, array{case: Pcre2Case, pin: string}>
     */
    public static function provideSuiteRejectedCases(): iterable
    {
        $raw = file_get_contents(self::SUITE_JSON);

        if (false === $raw) {
            throw new \RuntimeException(\sprintf('Missing %s: the net over the suite cannot be cast.', self::SUITE_JSON));
        }

        /** @var array{meta: array{pin: string}} $suite */
        $suite = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        $pin = $suite['meta']['pin'];
        unset($suite['meta']);

        /** @var array<string, list<Pcre2Case>> $files */
        $files = $suite;

        foreach ($files as $cases) {
            foreach ($cases as $case) {
                if (null !== $case['skipCategory']) {
                    continue;
                }

                // PHP's own compile context wins over pcre2test's where the
                // fixture recorded a difference.
                $verdict = null !== $case['phpOverride'] ? $case['phpOverride']['verdict'] : $case['verdict'];

                if ('reject' === $verdict) {
                    yield $case['id'] => ['case' => $case, 'pin' => $pin];
                }
            }
        }
    }

    /**
     * The library judging for the PCRE2 the running PHP links: the tests
     * ground a refusal, an offset or a message in that PHP, so both must
     * judge the same release.
     *
     * @param array<string, mixed> $options
     */
    private static function validator(array $options): RegexParser
    {
        return RegexParser::create(['cache' => null, 'pcre_version' => self::runtimePin()] + $options);
    }

    /**
     * The major.minor release of the PCRE2 the running PHP links.
     */
    private static function runtimePin(): string
    {
        return implode('.', \array_slice(explode('.', explode(' ', \PCRE_VERSION)[0]), 0, 2));
    }

    /**
     * The PCRE2 release that gives a row its meaning: an extended class, a
     * substring scan, the groups a call returns, a padded count, a "\N{"
     * read as a name, a group name past 32 code units or the wording of a
     * version condition only exist from it. On an older engine the row cannot
     * be asked what it asks; the library must then agree with that engine,
     * refusing what it refuses and accepting what it compiles.
     */
    private static function releaseOf(string $pattern): ?PcreFeature
    {
        return match (true) {
            str_contains($pattern, '(?[') => PcreFeature::ExtendedCharClass,
            str_contains($pattern, '(*scs:') => PcreFeature::ScanSubstring,
            1 === preg_match('/\(\?(?:R|[+-]?\d+)\(/', $pattern) => PcreFeature::CallsReturnCaptureGroups,
            1 === preg_match('/\{(?: |,)/', $pattern) => PcreFeature::OpenAndPaddedRepeatCounts,
            str_contains($pattern, '[\\N{') => PcreFeature::ErrorOffsetPastTheFault,
            1 === preg_match('/\(\?<\w{33,128}\W/', $pattern) => PcreFeature::LongGroupNames,
            str_contains($pattern, '(?(VERSION') => PcreFeature::VersionConditionLeftOpenIsVersionError,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    private function judgedBeforeItsRelease(string $pattern, array $options): bool
    {
        $feature = self::releaseOf($pattern);
        if (null === $feature || PcreTarget::runtime()->supports($feature)) {
            return false;
        }

        $engineRefuses = false === @preg_match($pattern, '');
        $this->assertSame(
            $engineRefuses,
            !self::validator($options)->validate($pattern)->isValid,
            \sprintf('%s: PCRE2 %s %s it, before %s gives it its meaning; the library must agree.', var_export($pattern, true), self::runtimePin(), $engineRefuses ? 'refuses' : 'compiles', $feature->release()),
        );

        return true;
    }

    private function assertEngineVerdict(string $pattern, bool $engineRefuses): void
    {
        $compiled = @preg_match($pattern, '');

        if ($engineRefuses) {
            $this->assertFalse($compiled, \sprintf('%s compiles in the running PHP: it cannot ground a refusal.', var_export($pattern, true)));

            return;
        }

        $this->assertSame(0, $compiled, \sprintf('%s must compile in PHP: only the library limits it.', var_export($pattern, true)));
    }
}
