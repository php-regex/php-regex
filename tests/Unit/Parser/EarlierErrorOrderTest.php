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
 * PCRE reads a pattern in one pass and reports the first error it meets.
 * Some errors only show once the whole pattern is read as a tree: a
 * relative reference to no group, a reversed count, a code point too large,
 * a verb that is unknown or not at the start. When one of those stands
 * before an error found while reading (a repeated name, a quantifier on a
 * quantifier, a group or class left open, a stray ")"), it is still the one
 * PCRE reports.
 *
 * The library agrees with the running engine on every release, the offset
 * and a code its message allows; the row holds what PCRE2 10.49 reports,
 * checked against the engine when it is 10.49.
 */
final class EarlierErrorOrderTest extends TestCase
{
    #[Test]
    #[DataProvider('provideErrorsBeforeAParseError')]
    public function test_validate_reports_an_error_found_late_that_stands_first(string $pattern, ErrorCode $code, int $offset): void
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
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideErrorsBeforeAParseError(): iterable
    {
        // PCRE: "reference to non-existent subpattern".
        yield 'relative reference before a name repeated in a body' => ['pattern' => '/\\g{-1}(?<o>)(*pla:(?<o>))/', 'code' => ErrorCode::BackrefRelative, 'offset' => 2];
        yield 'relative reference before a name repeated' => ['pattern' => '/\\g{-1}(?<o>)(?<o>)/', 'code' => ErrorCode::BackrefRelative, 'offset' => 2];
        yield 'relative reference before two names for one number' => ['pattern' => '/\\g{-1}(?|(?<n>a)|(*pla:(?<m>b)))/', 'code' => ErrorCode::BackrefRelative, 'offset' => 2];
        yield 'relative reference before a quantified lazy quantifier on a multibyte letter' => ['pattern' => "/\\g{-1}\u{e9}*??/", 'code' => ErrorCode::BackrefRelative, 'offset' => 2];
        yield 'relative reference before a quantified lazy quantifier' => ['pattern' => '/\\g{-1}a*??/', 'code' => ErrorCode::BackrefRelative, 'offset' => 2];
        // PCRE: "numbers out of order in {} quantifier".
        yield 'reversed count before a name repeated in a body' => ['pattern' => '/a{3,2}(?<o>)(*pla:(?<o>))/', 'code' => ErrorCode::QuantifierInvalidRange, 'offset' => 5];
        yield 'reversed count before a counted quantifier on a multibyte letter' => ['pattern' => "/a{3,2}\u{e9}+{2}/", 'code' => ErrorCode::QuantifierInvalidRange, 'offset' => 5];
        yield 'reversed count before a counted quantifier' => ['pattern' => '/a{3,2}a+{2}/', 'code' => ErrorCode::QuantifierInvalidRange, 'offset' => 5];
        yield 'reversed count in a group left open' => ['pattern' => '/(a{3,2}/', 'code' => ErrorCode::QuantifierInvalidRange, 'offset' => 6];
        yield 'reversed count in a body left open after a verb' => ['pattern' => '/a(*COMMIT)(*pla:a{2,1}/', 'code' => ErrorCode::QuantifierInvalidRange, 'offset' => 21];
        yield 'reversed count before a group left open on a code point without digits' => ['pattern' => '/a{3,2}(\\x/', 'code' => ErrorCode::QuantifierInvalidRange, 'offset' => 5];
        // PCRE: "character code point value in \x{} or \o{} is too large".
        yield 'code point too large before a name repeated in a body' => ['pattern' => '/\\x{110000}(?<o>)(*pla:(?<o>))/u', 'code' => ErrorCode::UnicodeOutOfRange, 'offset' => 9];
        yield 'code point too large before a group left open' => ['pattern' => '/\\x{110000}(/u', 'code' => ErrorCode::UnicodeOutOfRange, 'offset' => 9];
        // PCRE: "(*VERB) not recognized or malformed": a start verb placed later.
        yield 'newline verb after a letter before a counted quantifier' => ['pattern' => "/a(*CR)\u{e9}+{2}/", 'code' => ErrorCode::VerbMisplaced, 'offset' => 5];
        yield 'newline verb after an option before a lazy quantifier' => ['pattern' => "/(?x)(*CR)\u{e9}*??/", 'code' => ErrorCode::VerbMisplaced, 'offset' => 8];
        yield 'newline verb after xx before a lazy quantifier' => ['pattern' => '/(?xx)(*CR)a*??/', 'code' => ErrorCode::VerbMisplaced, 'offset' => 9];
        yield 'newline verb in a body left open' => ['pattern' => '/(*pla:a(*CR)/', 'code' => ErrorCode::VerbMisplaced, 'offset' => 11];
        yield 'match limit in a short lookahead left open' => ['pattern' => '/(?*(*LIMIT_MATCH=1)/', 'code' => ErrorCode::VerbMisplaced, 'offset' => 16];
        yield 'newline verb in a body left open inside another' => ['pattern' => '/(*pla:(*pla:(*CR)/', 'code' => ErrorCode::VerbMisplaced, 'offset' => 16];
        // The verb comes before a later error found apart, which loses.
        yield 'newline verb before a reversed range and a group left open' => ['pattern' => '/a(*CR)[z-a](/', 'code' => ErrorCode::VerbMisplaced, 'offset' => 5];
        yield 'newline verb in a lookahead left open before a property left open' => ['pattern' => '/(?=a(*CR)\\p{/', 'code' => ErrorCode::VerbMisplaced, 'offset' => 8];
        yield 'newline verb before a group left open on a code point without digits' => ['pattern' => '/a(*CR)(\\x/', 'code' => ErrorCode::VerbMisplaced, 'offset' => 5];
        // A comment closes before the group around it is left open.
        yield 'newline verb before a comment in a group left open' => ['pattern' => '/a(*CR)((?#c)/', 'code' => ErrorCode::VerbMisplaced, 'offset' => 5];
        yield 'newline verb before a comment in a body left open' => ['pattern' => '/(*pla:a(*CR)(?#c)/', 'code' => ErrorCode::VerbMisplaced, 'offset' => 11];
        // PCRE: "(*VERB) not recognized or malformed": an unknown verb.
        yield 'unknown verb before a group left open' => ['pattern' => '/(*FOO)(/', 'code' => ErrorCode::VerbInvalid, 'offset' => 5];
        yield 'unknown verb in a group left open' => ['pattern' => '/(a(*FOO)/', 'code' => ErrorCode::VerbInvalid, 'offset' => 7];
        yield 'unknown verb in a short lookahead left open' => ['pattern' => '/(?*a(*FOO)/', 'code' => ErrorCode::VerbInvalid, 'offset' => 9];
        // PCRE: "(*alpha_assertion) not recognized".
        yield 'unknown assertion before a group left open' => ['pattern' => '/(*foo:a)(/', 'code' => ErrorCode::VerbInvalid, 'offset' => 5];
        yield 'unknown assertion before a stray parenthesis' => ['pattern' => '/(*foo:a)x)/', 'code' => ErrorCode::VerbInvalid, 'offset' => 5];
        yield 'unknown assertion before a class left open' => ['pattern' => '/(*foo:a)[a/', 'code' => ErrorCode::VerbInvalid, 'offset' => 5];
        yield 'unknown lowercase verb before a group left open' => ['pattern' => '/(*foo)(/', 'code' => ErrorCode::VerbInvalid, 'offset' => 6];
        // Reported where PCRE reports them already: kept as guards.
        yield 'reversed count in a closed group' => ['pattern' => '/(a{3,2})/', 'code' => ErrorCode::QuantifierInvalidRange, 'offset' => 6];
        yield 'unknown verb in a closed group' => ['pattern' => '/(a(*FOO))/', 'code' => ErrorCode::VerbInvalid, 'offset' => 7];
        yield 'unknown assertion before text' => ['pattern' => '/(*foo:a)x/', 'code' => ErrorCode::VerbInvalid, 'offset' => 5];
        yield 'name repeated before a relative reference' => ['pattern' => '/(?<o>)(?<o>)\\g{-1}/', 'code' => ErrorCode::GroupDuplicateName, 'offset' => 11];
    }

    /**
     * The major.minor release of the PCRE2 the running PHP links.
     */
    private static function runningRelease(): string
    {
        return implode('.', \array_slice(explode('.', explode(' ', \PCRE_VERSION)[0]), 0, 2));
    }
}
