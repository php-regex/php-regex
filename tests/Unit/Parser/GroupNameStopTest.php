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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Tests\TestUtils\PcreMessageCodes;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE reads a group name up to the first character a name cannot hold and
 * refuses the name there, whatever follows: a body that never closes, a
 * group left open, an escape, the end of the pattern after a quote; and a
 * name read past 128 code units is too long there. A character after "(?P"
 * that is none of "<", "=" and ">" is refused past it, a whole character
 * under /u.
 *
 * The library agrees with the running engine on every release, the offset
 * and a code its message allows; the row holds what PCRE2 10.49 reports,
 * checked against the engine when it is 10.49.
 */
final class GroupNameStopTest extends TestCase
{
    #[Test]
    #[DataProvider('provideNamesStoppedBeforeTheEnd')]
    public function test_validate_refuses_a_name_where_pcre_stops_reading_it(string $pattern, ErrorCode $code, int $offset): void
    {
        $this->assertRefusedAsTheEngineRefuses($pattern, $code, $offset);
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideNamesStoppedBeforeTheEnd(): iterable
    {
        // PCRE: "syntax error in subpattern name (missing terminator?)", on
        // the first character that is not a word character.
        yield 'a hyphen before a body left open' => ['pattern' => '/(?<a-(*pla:/', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        yield 'a hyphen in a Python name before a body left open' => ['pattern' => '/(?P<a-(*pla:/', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 5];
        yield 'a hyphen in a quoted name before a body left open' => ['pattern' => "/(?'a-(*pla:/", 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        yield 'a hyphen in a condition name before a body left open' => ['pattern' => '/(?(<a-(*pla:/', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 5];
        yield 'a hyphen in a Python reference before a body left open' => ['pattern' => '/(?P=a-(*pla:/', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 5];
        yield 'a hyphen before a body that would close' => ['pattern' => '/(?<a-(*pla:)>)/', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        yield 'a hyphen before a short lookahead left open' => ['pattern' => '/(?<a-(?*/', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        yield 'a hyphen before a group left open' => ['pattern' => '/(?<a-(/', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        yield 'a hyphen before a callout left open' => ['pattern' => '/(?<a-(?C1/', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        yield 'a hyphen before an escape' => ['pattern' => '/(?<a-\\d/', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        yield 'a hyphen before a property left open' => ['pattern' => '/(?<a-\\p{/', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        yield 'a space before a group left open' => ['pattern' => '/(?<a b(/', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        // A relative reference to no group, after the name, is never read.
        yield 'a space before a body holding a relative reference' => ['pattern' => '/(?<a b(*pla:\\g{-1}(/', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        // Nor a code point too large.
        yield 'a newline before a body holding a code point too large' => ['pattern' => "/(?<a\n(*pla:\\x{110000}(/", 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        // Inside a name /x does not skip a space.
        yield 'a space under x before a body left open' => ['pattern' => '/(?x)(?<a b(*pla:/', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 8];
        // Without /u a multibyte letter is two bytes no name holds.
        yield 'a multibyte letter without u before a body left open' => ['pattern' => "/(?<a\u{e9}-(*pla:/", 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        // Under /u it is a letter, read whole: the hyphen after it stops.
        yield 'a multibyte letter under u, then a hyphen before a body left open' => ['pattern' => "/(?<a\u{e9}-(*pla:/u", 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 6];
        yield 'a multibyte first letter under u, then a hyphen before a group left open' => ['pattern' => "/(?P<\u{e9}-(/u", 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 6];
        // PCRE: "subpattern name expected", where the name starts.
        yield 'a hyphen first before a body left open' => ['pattern' => '/(?<-(*pla:/', 'code' => ErrorCode::GroupNameExpected, 'offset' => 3];
        yield 'a bracket first in a Python name before a callout condition' => ['pattern' => '/(?P<](?(?C1)/', 'code' => ErrorCode::GroupNameExpected, 'offset' => 4];
        // PCRE: "subpattern name must start with a non-digit".
        yield 'a digit first, then a hyphen before a body left open' => ['pattern' => '/(?<1-(*pla:/', 'code' => ErrorCode::GroupNameInvalid, 'offset' => 4];
        // Reported where PCRE reports them already: kept as guards.
        yield 'a hyphen in a \\k reference before a body left open' => ['pattern' => '/\\k<a-(*pla:/', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        yield 'a hyphen in a \\g reference before a body left open' => ['pattern' => '/\\g{a-(*pla:/', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        yield 'a hyphen in a bare condition name before a body left open' => ['pattern' => '/(?(a-(*pla:/', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        yield 'a hyphen before the closing bracket' => ['pattern' => '/(?<a->(*pla:/', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        yield 'a hyphen at the end of the pattern' => ['pattern' => '/(?<a-/', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        yield 'a hyphen before a parenthesis' => ['pattern' => '/(?<a-)/', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        yield 'a bracket first before a body left open' => ['pattern' => '/(?<]>(*pla:/', 'code' => ErrorCode::GroupNameExpected, 'offset' => 3];
    }

    /**
     * PCRE refuses a name it stops reading on a character no name holds as
     * a name with no terminator ("syntax error in subpattern name (missing
     * terminator?)"); the message says so, not that the name is malformed.
     */
    #[Test]
    #[DataProvider('provideNamesLeftWithoutTerminator')]
    public function test_validate_says_a_name_stopped_short_is_not_closed(string $pattern, string $name, int $offset): void
    {
        $this->assertRefusedAsTheEngineRefuses($pattern, ErrorCode::GroupNameUnterminated, $offset);

        $error = (string) Regex::create(['cache' => null])->validate($pattern)->error;

        $this->assertStringContainsString(\sprintf('"%s": the name ends at position %d, and nothing closes it there', $name, $offset), $error);
    }

    /**
     * @return iterable<string, array{pattern: string, name: string, offset: int}>
     */
    public static function provideNamesLeftWithoutTerminator(): iterable
    {
        yield 'a hyphen, then the end' => ['pattern' => '/(?<a-/', 'name' => 'a-', 'offset' => 4];
        yield 'a hyphen before a parenthesis' => ['pattern' => '/(?<a-)/', 'name' => 'a-', 'offset' => 4];
        yield 'a hyphen in a quoted name, then a letter and the end' => ['pattern' => "/(?'a-b/", 'name' => 'a-b', 'offset' => 4];
        yield 'a space in a quoted name, then a letter and the end' => ['pattern' => "/(?'a b/", 'name' => 'a b', 'offset' => 4];
    }

    #[Test]
    #[DataProvider('provideQuotedNamesStoppedBeforeTheEnd')]
    public function test_validate_refuses_a_quoted_name_where_pcre_stops_reading_it(string $pattern, ErrorCode $code, int $offset): void
    {
        $this->assertRefusedAsTheEngineRefuses($pattern, $code, $offset);
    }

    /**
     * A quoted name no quote closes runs to the end of the pattern: PCRE
     * still stops on the first character a name cannot hold.
     *
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideQuotedNamesStoppedBeforeTheEnd(): iterable
    {
        // PCRE: "syntax error in subpattern name (missing terminator?)".
        yield 'a hyphen, then the end' => ['pattern' => "/(?'a-/", 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        yield 'a hyphen, then a letter and the end' => ['pattern' => "/(?'a-b/", 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        yield 'a space, then a letter and the end' => ['pattern' => "/(?'a b/", 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        // Inside a name /x does not skip a space.
        yield 'a space under x, then a letter and the end' => ['pattern' => "/(?x)(?'a b/", 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 8];
        // Without /u a multibyte letter is two bytes no name holds.
        yield 'a multibyte letter without u, then a hyphen and the end' => ['pattern' => "/(?'a\u{e9}-/", 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        // Under /u it is a letter, read whole: the hyphen after it stops.
        yield 'a multibyte letter under u, then a hyphen and the end' => ['pattern' => "/(?'\u{e9}-/u", 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 5];
        yield 'two multibyte letters under u, then a hyphen and the end' => ['pattern' => "/(?'\u{e9}\u{e9}-/u", 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 7];
        yield 'a hyphen in a quoted condition name, then the end' => ['pattern' => "/(?<a>x)(?('a-/", 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 12];
        // At the longest name PCRE takes, the hyphen still ends it.
        yield 'a name of 128 letters, then a hyphen and the end' => ['pattern' => "/(?'".str_repeat('b', 128).'-/', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 131];
        // PCRE: "subpattern name expected", where the name starts.
        yield 'a hyphen first, then a letter and the end' => ['pattern' => "/(?'-a/", 'code' => ErrorCode::GroupNameExpected, 'offset' => 3];
        // PCRE: "subpattern name must start with a non-digit".
        yield 'a digit first, then a letter and the end' => ['pattern' => "/(?'1a/", 'code' => ErrorCode::GroupNameInvalid, 'offset' => 4];
        yield 'a digit first, then the end' => ['pattern' => "/(?'1/", 'code' => ErrorCode::GroupNameInvalid, 'offset' => 4];
        yield 'an Arabic-Indic digit first under u, then a letter and the end' => ['pattern' => "/(?'\u{663}a/u", 'code' => ErrorCode::GroupNameInvalid, 'offset' => 5];
        // Reported where PCRE reports them already: kept as guards.
        yield 'a letter, then the end' => ['pattern' => "/(?'a/", 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        yield 'a letter, then a group left open' => ['pattern' => "/(?'a(/", 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        yield 'a letter, then an escape' => ['pattern' => "/(?'a\\d/", 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 4];
        yield 'a name of 128 letters, then the end' => ['pattern' => "/(?'".str_repeat('b', 128).'/', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 131];
        yield 'nothing, then the end' => ['pattern' => "/(?'/", 'code' => ErrorCode::GroupNameExpected, 'offset' => 3];
        yield 'an empty name, then the end' => ['pattern' => "/(?''/", 'code' => ErrorCode::GroupNameExpected, 'offset' => 3];
    }

    #[Test]
    #[DataProvider('provideNamesTooLongStoppedBeforeTheEnd')]
    public function test_validate_refuses_a_name_too_long_where_pcre_stops_reading_it(string $pattern, ErrorCode $code, int $offset): void
    {
        $this->assertRefusedAsTheEngineRefuses($pattern, $code, $offset);
    }

    /**
     * PCRE measures the name it read before it looks for what closes it:
     * past 128 code units, the name is too long where it stops, whatever
     * stops it.
     *
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideNamesTooLongStoppedBeforeTheEnd(): iterable
    {
        $long = str_repeat('b', 130);

        // PCRE: "subpattern name is too long (maximum 128 code units)".
        yield 'a quoted name, then a hyphen and the end' => ['pattern' => "/(?'".$long.'-/', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 133];
        yield 'a quoted name one past the limit, then a hyphen and the end' => ['pattern' => "/(?'".str_repeat('b', 129).'-/', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 132];
        yield 'a quoted name, then the end' => ['pattern' => "/(?'".$long.'/', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 133];
        yield 'a quoted name, then an escape' => ['pattern' => "/(?'".$long.'\\d/', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 133];
        yield 'a quoted name, then a body left open' => ['pattern' => "/(?'a".$long.'(*pla:/', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 134];
        yield 'a quoted condition name, then a hyphen and the end' => ['pattern' => "/(?<a>x)(?('".$long.'-/', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 141];
        yield 'a name, then a group left open' => ['pattern' => '/(?<'.$long.'(/', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 133];
        yield 'a name one past the limit, then a group left open' => ['pattern' => '/(?<'.str_repeat('b', 129).'(/', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 132];
        yield 'a name, then a non-capturing group left open' => ['pattern' => '/(?<'.$long.'(?:/', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 133];
        yield 'a name, then an escape' => ['pattern' => '/(?<'.$long.'\\d/', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 133];
        yield 'a name, then a property left open' => ['pattern' => '/(?<'.$long.'\\p{/', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 133];
        yield 'a Python name, then an escape' => ['pattern' => '/(?P<'.$long.'\\d/', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 134];
        yield 'a Python reference, then a group left open' => ['pattern' => '/(?P='.$long.'(/', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 134];
        yield 'a name, then a body left open' => ['pattern' => '/(?<a'.$long.'(*pla:/', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 134];
        yield 'a condition name, then a group left open' => ['pattern' => '/(?<a>x)(?(<'.$long.'(/', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 141];
        // 65 two-byte letters under /u are 130 code units.
        yield 'a quoted name of multibyte letters under u, then a group left open' => ['pattern' => "/(?'".str_repeat("\u{e9}", 65).'(/u', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 133];
        yield 'a quoted name of multibyte letters under u, then a hyphen and the end' => ['pattern' => "/(?'".str_repeat("\u{e9}", 65).'-/u', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 133];
        // Reported where PCRE reports them already: kept as guards.
        yield 'a name, then the end' => ['pattern' => '/(?<'.$long.'/', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 133];
        yield 'a name, then a space' => ['pattern' => '/(?<'.$long.' /', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 133];
        yield 'a name, then a hyphen before a group left open' => ['pattern' => '/(?<'.$long.'-(/', 'code' => ErrorCode::GroupNameTooLong, 'offset' => 133];
        // At the longest name PCRE takes, what stops it is refused.
        yield 'a name of 128 letters, then a group left open' => ['pattern' => '/(?<'.str_repeat('b', 128).'(/', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 131];
    }

    #[Test]
    #[DataProvider('provideQuotedNamesPcreAccepts')]
    public function test_validate_accepts_a_quoted_name_pcre_accepts(string $pattern): void
    {
        $compiles = null === PcreMessageCodes::warningOf($pattern);
        if ('10.49' === self::runningRelease()) {
            $this->assertTrue($compiles, \sprintf('Oracle: %s compiles.', $pattern));
        }

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertSame($compiles, $result->isValid, \sprintf('%s: PCRE %s it, the library says "%s".', $pattern, $compiles ? 'compiles' : 'refuses', (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideQuotedNamesPcreAccepts(): iterable
    {
        yield 'a letter' => ['pattern' => "/(?'a'x)/"];
        yield 'an underscore' => ['pattern' => "/(?'_'x)/"];
        yield 'a letter and a digit, referenced' => ['pattern' => "/(?'a_1'x)\\k'a_1'/"];
        yield 'multibyte letters under u' => ['pattern' => "/(?'\u{e9}t\u{e9}'x)/u"];
        yield 'a letter under x' => ['pattern' => "/(?x)(?'a'x)/"];
        yield 'a quoted condition name' => ['pattern' => "/(?'a1'x)(?('a1')y|z)/"];
        // The longest name PCRE takes, from PCRE2 10.44 on.
        yield 'a name of 128 letters' => ['pattern' => "/(?'".str_repeat('b', 128)."'x)/"];
    }

    #[Test]
    #[DataProvider('provideCharactersAfterP')]
    public function test_validate_refuses_a_character_after_p_past_it(string $pattern, ErrorCode $code, int $offset): void
    {
        $this->assertRefusedAsTheEngineRefuses($pattern, $code, $offset);
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideCharactersAfterP(): iterable
    {
        // PCRE: "unrecognized character after (?P", past the character:
        // past all its bytes under /u.
        yield 'a two-byte letter under u' => ['pattern' => "/(?P\u{e9})/u", 'code' => ErrorCode::GroupSyntax, 'offset' => 5];
        yield 'a two-byte letter under u, the group left open' => ['pattern' => "/(?P\u{e9}/u", 'code' => ErrorCode::GroupSyntax, 'offset' => 5];
        yield 'a two-byte letter under u before a bracket' => ['pattern' => "/(?P\u{e9}>)/u", 'code' => ErrorCode::GroupSyntax, 'offset' => 5];
        yield 'a three-byte character under u' => ['pattern' => "/(?P\u{20ac})/u", 'code' => ErrorCode::GroupSyntax, 'offset' => 6];
        yield 'a four-byte character under u' => ['pattern' => "/(?P\u{1f600})/u", 'code' => ErrorCode::GroupSyntax, 'offset' => 7];
        yield 'a two-byte letter under u in a body' => ['pattern' => "/(*pla:(?P\u{e9})/u", 'code' => ErrorCode::GroupSyntax, 'offset' => 11];
        // Without /u, past its first byte: kept as guards.
        yield 'a two-byte letter' => ['pattern' => "/(?P\u{e9})/", 'code' => ErrorCode::GroupSyntax, 'offset' => 4];
        yield 'a two-byte letter, the group left open' => ['pattern' => "/(?P\u{e9}/", 'code' => ErrorCode::GroupSyntax, 'offset' => 4];
        yield 'a two-byte letter under i' => ['pattern' => "/(?P\u{e9})/i", 'code' => ErrorCode::GroupSyntax, 'offset' => 4];
    }

    /**
     * A pattern that ends right after "(?P" misses the ")" of the group
     * ("missing closing parenthesis"), at the end of the pattern; the
     * message gives that position.
     */
    #[Test]
    #[DataProvider('providePatternsEndingAfterP')]
    public function test_validate_refuses_a_pattern_ending_after_p_at_its_end(string $pattern, int $offset): void
    {
        $this->assertRefusedAsTheEngineRefuses($pattern, ErrorCode::GroupUnclosed, $offset);

        $error = (string) Regex::create(['cache' => null])->validate($pattern)->error;

        $this->assertStringContainsString(\sprintf('"(?P" at position %d.', $offset), $error);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function providePatternsEndingAfterP(): iterable
    {
        yield 'alone' => ['pattern' => '/(?P/', 'offset' => 3];
        yield 'in a group' => ['pattern' => '/((?P/', 'offset' => 4];
        yield 'after a letter under x' => ['pattern' => '/a(?P/x', 'offset' => 4];
    }

    /**
     * The library refuses the pattern at the running engine's offset, with a
     * code the engine's message allows; on PCRE2 10.49 the row's offset and
     * code are the engine's and the library's.
     */
    private function assertRefusedAsTheEngineRefuses(string $pattern, ErrorCode $code, int $offset): void
    {
        $pcre = PcreMessageCodes::read(PcreMessageCodes::warningOf($pattern) ?? 'compiles');
        $this->assertArrayHasKey($pcre['message'], PcreMessageCodes::CODES, \sprintf('Oracle: %s, "%s" is not a message the code map knows.', $pattern, $pcre['message']));
        $allowed = PcreMessageCodes::CODES[$pcre['message']];
        $pinned = '10.49' === self::runningRelease();
        if ($pinned) {
            $this->assertSame($offset, $pcre['offset'], \sprintf('Oracle: %s (%s).', $pattern, $pcre['message']));
            $this->assertContains($code->value, $allowed, \sprintf('Oracle: %s does not name "%s".', $code->value, $pcre['message']));
        }

        $result = Regex::create(['cache' => null])->validate($pattern);
        $said = \sprintf('%s: PCRE says "%s" at %s, the library "%s" (%s) at %s.', $pattern, $pcre['message'], var_export($pcre['offset'], true), (string) $result->error, $result->errorCode?->value, var_export($result->offset, true));

        $this->assertFalse($result->isValid, $said);
        $this->assertSame($pcre['offset'], $result->offset, $said);
        $this->assertContains($result->errorCode?->value, $allowed, $said);
        if ($pinned) {
            $this->assertSame($code, $result->errorCode, $said);
        }
    }

    /**
     * The major.minor release of the PCRE2 the running PHP links.
     */
    private static function runningRelease(): string
    {
        return implode('.', \array_slice(explode('.', explode(' ', \PCRE_VERSION)[0]), 0, 2));
    }
}
