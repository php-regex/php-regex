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

namespace RegexParser\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\ErrorCode;
use RegexParser\Exception\RegexException;
use RegexParser\RegexParser;
use RegexParser\Tests\TestUtils\PcreMessageCodes;

/**
 * Patterns the engine refuses with one message at one offset, where the
 * library named another problem or another place.
 *
 * Each row holds what PHP printed on the release named: PHP 8.2 (PCRE2
 * 10.40), 8.3 (10.42), 8.4 (10.44) and PHP 8.4 linked against 10.49. The
 * releases in between have no engine to ask, so no row claims them. When
 * the running PHP links the release of a row, the row is checked against
 * the engine itself.
 */
final class PcreDivergenceTest extends TestCase
{
    private const LIVE_RELEASES = ['10.40', '10.42', '10.44', '10.49'];

    private const BEFORE_10_45 = ['10.40', '10.42', '10.44'];

    #[Test]
    #[DataProvider('provideDivergences')]
    public function test_validate_reports_the_code_and_offset_pcre_reports(string $pattern, string $release, string $message, int $offset, ErrorCode $code): void
    {
        $this->assertArrayHasKey($message, PcreMessageCodes::CODES, \sprintf('"%s" is not a message the code map knows.', $message));
        $this->assertContains($code->value, PcreMessageCodes::CODES[$message], \sprintf('%s does not name "%s".', $code->value, $message));

        if (self::runtimePin() === $release) {
            $this->assertSame(
                ['message' => $message, 'offset' => $offset],
                PcreMessageCodes::read(PcreMessageCodes::warningOf($pattern) ?? 'compiles'),
                \sprintf('%s: the running PCRE2 %s no longer reports what this row holds.', $pattern, $release),
            );
        }

        $result = RegexParser::create(['cache' => null, 'pcre_version' => $release])->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s on %s must be refused: PCRE reports "%s".', $pattern, $release, $message));
        $this->assertSame($code, $result->errorCode, \sprintf('%s on %s: %s', $pattern, $release, (string) $result->error));
        $this->assertSame($offset, $result->offset, \sprintf('%s on %s: %s', $pattern, $release, (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string, release: string, message: string, offset: int, code: ErrorCode}>
     */
    public static function provideDivergences(): iterable
    {
        // A relative condition past the largest group number. One below it
        // names a missing group, which the library already reports.
        yield from self::rows('relative condition past the group limit', '/()(?(+65535)a)/', self::LIVE_RELEASES, 'subpattern number is too big', 11, ErrorCode::GroupNumberTooBig);
        yield from self::rows('relative condition one below the group limit', '/()(?(+65534)a)/', self::LIVE_RELEASES, 'reference to non-existent subpattern', 9, ErrorCode::BackrefRelative);

        // "(?(R" takes a number or "&name": a sign starts neither, so any
        // "(?(R-" is a name left unterminated, whatever the digits.
        yield from self::rows('recursion condition with a large negative number', '/(?(R-70000)a)/', self::LIVE_RELEASES, 'syntax error in subpattern name (missing terminator?)', 4, ErrorCode::GroupNameUnterminated);
        yield from self::rows('recursion condition with a negative number', '/(?(R-1)a)/', self::LIVE_RELEASES, 'syntax error in subpattern name (missing terminator?)', 4, ErrorCode::GroupNameUnterminated);
        yield from self::rows('recursion condition on a missing group', '/(?(R1)a)/', self::LIVE_RELEASES, 'reference to non-existent subpattern', 3, ErrorCode::SubroutineRecursion);

        // Past a number, a "VERSION" and a quoted name, "(?(" reads a name
        // up to the ")": a character no name holds leaves it unterminated,
        // "R", "R1" and "DEFINE" included, once the name is measured.
        yield from self::rows('recursion condition then a character no name holds', '/(?(R!)a)/', self::LIVE_RELEASES, 'syntax error in subpattern name (missing terminator?)', 4, ErrorCode::GroupNameUnterminated);
        yield from self::rows('name starting with R left open', '/(?(Rx/', self::LIVE_RELEASES, 'syntax error in subpattern name (missing terminator?)', 5, ErrorCode::GroupNameUnterminated);
        yield from self::rows('recursion condition on a number then a character no name holds', '/(?(R1!)a)/', self::LIVE_RELEASES, 'syntax error in subpattern name (missing terminator?)', 5, ErrorCode::GroupNameUnterminated);
        yield from self::rows('bare name then a character no name holds', '/(?(ab!)a)/', self::LIVE_RELEASES, 'syntax error in subpattern name (missing terminator?)', 5, ErrorCode::GroupNameUnterminated);
        yield from self::rows('bare name then a sign', '/(?(a-1)a)/', self::LIVE_RELEASES, 'syntax error in subpattern name (missing terminator?)', 4, ErrorCode::GroupNameUnterminated);
        yield from self::rows('DEFINE then a character no name holds', '/(?(DEFINE!)a)/', self::LIVE_RELEASES, 'syntax error in subpattern name (missing terminator?)', 9, ErrorCode::GroupNameUnterminated);
        yield from self::rows('name starting with R past the length limit, unterminated', '/(?(R'.str_repeat('a', 130).'!)a)/', ['10.40', '10.42'], 'subpattern name is too long (maximum 32 code units)', 134, ErrorCode::GroupNameTooLong);
        yield from self::rows('name starting with R past the length limit, unterminated', '/(?(R'.str_repeat('a', 130).'!)a)/', ['10.44', '10.49'], 'subpattern name is too long (maximum 128 code units)', 134, ErrorCode::GroupNameTooLong);
        // "R" and forty digits is a name past 32 code units before it is a
        // group number past 65535.
        yield from self::rows('recursion condition on a number of forty digits', '/(?(R'.str_repeat('1', 40).')a)/', ['10.40', '10.42'], 'subpattern name is too long (maximum 32 code units)', 44, ErrorCode::GroupNameTooLong);
        yield from self::rows('recursion condition on a number of forty digits', '/(?(R'.str_repeat('1', 40).')a)/', ['10.44', '10.49'], 'subpattern number is too big', 9, ErrorCode::GroupNumberTooBig);
        yield from self::rows('bare name past the length limit, unterminated', '/(?('.str_repeat('a', 130).'!)a)/', ['10.40', '10.42'], 'subpattern name is too long (maximum 32 code units)', 133, ErrorCode::GroupNameTooLong);
        yield from self::rows('bare name past the length limit, unterminated', '/(?('.str_repeat('a', 130).'!)a)/', ['10.44', '10.49'], 'subpattern name is too long (maximum 128 code units)', 133, ErrorCode::GroupNameTooLong);

        // "R" followed by anything but digits is a name like any other.
        yield from self::rows('recursion condition with a letter after the number', '/(?(R1a)a)/', self::LIVE_RELEASES, 'reference to non-existent subpattern', 3, ErrorCode::ConditionMissingGroup);
        yield from self::rows('name starting with R', '/(?(Rx)a)/', self::LIVE_RELEASES, 'reference to non-existent subpattern', 3, ErrorCode::ConditionMissingGroup);
        yield from self::rows('recursion condition with a letter after the number, left open', '/(?(R1a)/', self::LIVE_RELEASES, 'missing closing parenthesis', 7, ErrorCode::GroupUnclosed);

        // A space before "VERSION" is where the name was expected.
        yield from self::rows('version condition after a space', '/(?( VERSION=10)a)/', self::LIVE_RELEASES, 'subpattern name expected', 3, ErrorCode::ConditionalInvalid);

        // "VERSION" not followed by ")", with ten characters left, is a
        // version condition before it can be a name, a group's name included.
        foreach (['VERSION then a letter' => ['/(?(VERSIONx)a)/', 10], 'VERSION then a letter, a group left open after' => ['/(?(VERSIONx)a)(/', 10], 'VERSION then letters naming a group' => ['/(?<VERSIONab>a)(?(VERSIONab)a)/', 25]] as $name => [$pattern, $offset]) {
            yield from self::rows($name, $pattern, self::BEFORE_10_45, 'syntax error or number too big in (?(VERSION condition', $offset, ErrorCode::ConditionVersionSyntax);
            yield from self::rows($name, $pattern, ['10.49'], 'syntax error or number too big in (?(VERSION condition', $offset + 1, ErrorCode::ConditionVersionSyntax);
        }

        // A group that is no assertion after the callout of a condition is
        // refused where it starts, on every release.
        foreach (['callout condition then a non-capturing group' => ['/(?(?C1)(?:a))/', 7], 'string callout condition then a non-capturing group' => ['/(?(?C"x")(?:a))/', 9], 'callout condition then a comment and a non-capturing group' => ['/(?(?C1)(?#c)(?:a))/', 12]] as $name => [$pattern, $offset]) {
            yield from self::rows($name, $pattern, self::BEFORE_10_45, 'assertion expected after (?( or (?(?C)', $offset, ErrorCode::ConditionAssertionExpected);
            yield from self::rows($name, $pattern, ['10.49'], 'atomic assertion expected after (?( or (?(?C)', $offset, ErrorCode::ConditionAssertionExpected);
        }

        // A comment where the condition belongs: 10.49 reports one character
        // later, under its "atomic assertion" wording.
        yield from self::rows('comment as the condition', '/(?(?#c)a)/', self::BEFORE_10_45, 'assertion expected after (?( or (?(?C)', 7, ErrorCode::ConditionAssertionExpected);
        yield from self::rows('comment as the condition', '/(?(?#c)a)/', ['10.49'], 'atomic assertion expected after (?( or (?(?C)', 8, ErrorCode::ConditionAssertionExpected);

        // An alpha assertion as the condition, left open.
        yield from self::rows('alpha lookahead condition left open', '/(?(*pla:a/', self::LIVE_RELEASES, 'missing closing parenthesis', 9, ErrorCode::GroupUnclosed);

        // "(*atomic:" is no assertion: refused where the condition must be.
        yield from self::rows('atomic group as the condition', '/(?(*atomic:a/', self::BEFORE_10_45, 'assertion expected after (?( or (?(?C)', 10, ErrorCode::ConditionAssertionExpected);
        yield from self::rows('atomic group as the condition', '/(?(*atomic:a/', ['10.49'], 'atomic assertion expected after (?( or (?(?C)', 10, ErrorCode::ConditionAssertionExpected);

        // A quote after "(?P<" starts no name.
        yield from self::rows('quote after (?P< before a name', "/(?P<'name>x)/", self::LIVE_RELEASES, 'subpattern name expected', 4, ErrorCode::GroupNameExpected);
        // The library accepts this one: PCRE refuses it on every release.
        yield from self::rows('quote-delimited name inside (?P<', "/(?P<'n'>x)/", self::LIVE_RELEASES, 'subpattern name expected', 4, ErrorCode::GroupNameExpected);

        // An unknown alpha name as the condition.
        yield from self::rows('unknown alpha assertion as the condition', '/(?(*xyz:a)b)/', self::LIVE_RELEASES, '(*alpha_assertion) not recognized', 7, ErrorCode::VerbInvalid);
        yield from self::rows('unknown alpha name with no colon as the condition', '/(?(*xyz)b)/', self::BEFORE_10_45, '(*alpha_assertion) not recognized', 7, ErrorCode::VerbInvalid);
        yield from self::rows('unknown alpha name with no colon as the condition', '/(?(*xyz)b)/', ['10.49'], '(*alpha_assertion) not recognized', 8, ErrorCode::VerbInvalid);

        // "(*pla" followed by a character no name or verb goes on with: an
        // unknown alpha name on every release, one character later on 10.49.
        foreach (['(*pla then !' => '/(*pla!/', '(*pla then \\E' => '/(*pla\\E/', '(*pla then # under x' => '/(*pla#/x'] as $name => $pattern) {
            yield from self::rows($name, $pattern, self::BEFORE_10_45, '(*alpha_assertion) not recognized', 5, ErrorCode::VerbInvalid);
            yield from self::rows($name, $pattern, ['10.49'], '(*alpha_assertion) not recognized', 6, ErrorCode::VerbInvalid);
        }

        // A substring scan as the condition, left open: an unknown name
        // before it exists, a group left open on 10.49.
        yield from self::rows('(*scs as the condition, left open', '/(?(*scs/', self::BEFORE_10_45, '(*alpha_assertion) not recognized', 7, ErrorCode::VerbInvalid);
        yield from self::rows('(*scs as the condition, left open', '/(?(*scs/', ['10.49'], 'missing closing parenthesis', 7, ErrorCode::GroupUnclosed);
        yield from self::rows('(*scan_substring as the condition, left open', '/(?(*scan_substring/', self::BEFORE_10_45, '(*alpha_assertion) not recognized', 18, ErrorCode::VerbInvalid);
        yield from self::rows('(*scan_substring as the condition, left open', '/(?(*scan_substring/', ['10.49'], 'missing closing parenthesis', 18, ErrorCode::GroupUnclosed);

        // A space inside the version.
        yield from self::rows('version condition with a space', '/(?(VERSION=10 )/', self::BEFORE_10_45, 'missing closing parenthesis for condition', 13, ErrorCode::ConditionUnclosed);
        yield from self::rows('version condition with a space', '/(?(VERSION=10 )/', ['10.49'], 'syntax error or number too big in (?(VERSION condition', 14, ErrorCode::ConditionVersionSyntax);

        // Under (?xx) the space in the class is skipped, which leaves "\E"
        // ending the pattern before 10.45.
        yield from self::rows('(?xx) class left open after \\E', '/(?xx)[ \\E/', self::BEFORE_10_45, '\\ at end of pattern', 9, ErrorCode::EscapeTrailingBackslash);
        yield from self::rows('(?xx) class left open after \\E', '/(?xx)[ \\E/', ['10.49'], 'missing terminating ] for character class', 9, ErrorCode::CharclassUnclosed);
    }

    /**
     * Parentheses nested in an extended class count against the library's
     * nesting limit, like groups do: 10.49 compiles this pattern (its own
     * limit sits past fourteen levels), so only the library refuses it.
     */
    #[Test]
    public function test_validate_refuses_extended_class_parentheses_nested_past_the_limit(): void
    {
        $pattern = '/(?[ (((((( [a] )))))) ])/';

        if ('10.49' === self::runtimePin()) {
            $this->assertSame(0, @preg_match($pattern, ''), \sprintf('%s must compile in PHP: only the library limits it.', $pattern));
        }

        $result = RegexParser::create(['cache' => null, 'pcre_version' => '10.49', 'max_recursion_depth' => 5])->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s nests six levels against a limit of five.', $pattern));
        $this->assertSame(ErrorCode::NestingTooDeep, $result->errorCode, (string) $result->error);
    }

    #[Test]
    public function test_validate_accepts_an_extended_class_nested_within_the_limit(): void
    {
        $result = RegexParser::create(['cache' => null, 'pcre_version' => '10.49', 'max_recursion_depth' => 5])->validate('/(?[ ( [a] ) ])/');

        $this->assertTrue($result->isValid, (string) $result->error);
    }

    /**
     * A tolerant parse reports first what validate() reports first, and
     * both report what PCRE does: not the group left open after it.
     */
    #[Test]
    #[DataProvider('provideErrorsBeforeAnUnclosedGroup')]
    public function test_parse_tolerant_reports_the_first_error_validate_reports(string $pattern, string $release, ErrorCode $code, int $offset): void
    {
        $parser = RegexParser::create(['cache' => null, 'pcre_version' => $release]);

        $validation = $parser->validate($pattern);
        $this->assertSame($code, $validation->errorCode, \sprintf('%s on %s: %s', $pattern, $release, (string) $validation->error));
        $this->assertSame($offset, $validation->offset, \sprintf('%s on %s', $pattern, $release));

        $tolerant = $parser->parseTolerant($pattern);
        $this->assertTrue($tolerant->hasErrors(), \sprintf('%s on %s must report an error.', $pattern, $release));
        $first = $tolerant->errors[0];
        $this->assertInstanceOf(RegexException::class, $first);
        $this->assertSame($code, $first->getErrorCode(), \sprintf('%s on %s: %s', $pattern, $release, $first->getMessage()));
        $this->assertSame($offset, $first->getPosition(), \sprintf('%s on %s: %s', $pattern, $release, $first->getMessage()));
    }

    /**
     * @return iterable<string, array{pattern: string, release: string, code: ErrorCode, offset: int}>
     */
    public static function provideErrorsBeforeAnUnclosedGroup(): iterable
    {
        // PCRE: "range out of order in character class" at 3, at 4 on 10.49.
        yield 'reversed range, 10.44' => ['pattern' => '/[z-a](/', 'release' => '10.44', 'code' => ErrorCode::RangeReversed, 'offset' => 3];
        yield 'reversed range, 10.49' => ['pattern' => '/[z-a](/', 'release' => '10.49', 'code' => ErrorCode::RangeReversed, 'offset' => 4];
        // PCRE: "unrecognized character follows \" at 1, at 2 on 10.49.
        yield 'unknown escape, 10.44' => ['pattern' => '/\\q(/', 'release' => '10.44', 'code' => ErrorCode::EscapeUnrecognized, 'offset' => 1];
        yield 'unknown escape, 10.49' => ['pattern' => '/\\q(/', 'release' => '10.49', 'code' => ErrorCode::EscapeUnrecognized, 'offset' => 2];
        // PCRE: "unknown POSIX class name" at 3, at 8 on 10.49.
        yield 'unknown POSIX class, 10.44' => ['pattern' => '/[[:foo:]](/', 'release' => '10.44', 'code' => ErrorCode::PosixInvalid, 'offset' => 3];
        yield 'unknown POSIX class, 10.49' => ['pattern' => '/[[:foo:]](/', 'release' => '10.49', 'code' => ErrorCode::PosixInvalid, 'offset' => 8];
        // PCRE: "syntax error or number too big in (?(VERSION condition" at 10, at 11 on 10.49.
        yield 'VERSION then a letter, 10.44' => ['pattern' => '/(?(VERSIONx)a)(/', 'release' => '10.44', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 10];
        yield 'VERSION then a letter, 10.49' => ['pattern' => '/(?(VERSIONx)a)(/', 'release' => '10.49', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 11];
        // PCRE: "syntax error in subpattern name (missing terminator?)" at 5.
        yield 'bare name then a character no name holds, 10.49' => ['pattern' => '/(?(ab!)a)(/', 'release' => '10.49', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 5];
    }

    /**
     * @param list<string> $releases
     *
     * @return iterable<string, array{pattern: string, release: string, message: string, offset: int, code: ErrorCode}>
     */
    private static function rows(string $name, string $pattern, array $releases, string $message, int $offset, ErrorCode $code): iterable
    {
        foreach ($releases as $release) {
            yield $name.', '.$release => ['pattern' => $pattern, 'release' => $release, 'message' => $message, 'offset' => $offset, 'code' => $code];
        }
    }

    /**
     * The major.minor release of the PCRE2 the running PHP links.
     */
    private static function runtimePin(): string
    {
        return implode('.', \array_slice(explode('.', explode(' ', \PCRE_VERSION)[0]), 0, 2));
    }
}
