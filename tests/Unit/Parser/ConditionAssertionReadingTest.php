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
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\TestUtils\PcreMessageCodes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What stands where the condition of "(?(" is due, as PCRE2 10.40, 10.44
 * and 10.49 read it: a comment skipped before the assertion, a callout, an
 * alphabetic name, a sign after "R", a version condition in a pattern that
 * goes wrong further on. When the running PHP links the release of a row,
 * the row is checked against the engine itself.
 */
final class ConditionAssertionReadingTest extends TestCase
{
    private const RELEASES = ['10.40', '10.44', '10.49'];

    private const BEFORE_10_47 = ['10.40', '10.44'];

    #[Test]
    #[DataProvider('provideConditions')]
    public function test_validate_reads_the_condition_as_pcre_does(string $pattern, string $release, ?ErrorCode $code, ?int $offset): void
    {
        if (self::runtimePin() === $release) {
            $read = PcreMessageCodes::read(PcreMessageCodes::warningOf($pattern) ?? 'compiles');
            if (null === $code) {
                $this->assertNull(PcreMessageCodes::warningOf($pattern), \sprintf('%s must compile on PCRE2 %s.', $pattern, $release));
            } else {
                $this->assertSame($offset, $read['offset'], \sprintf('%s: the running PCRE2 %s refuses it elsewhere.', $pattern, $release));
                $this->assertContains($code->value, PcreMessageCodes::CODES[$read['message']] ?? [], \sprintf('%s: PCRE reports "%s".', $pattern, $read['message']));
            }
        }

        $result = RegexParser::create(['cache' => null, 'pcre_version' => $release])->validate($pattern);

        $this->assertSame($code, $result->errorCode, \sprintf('%s on %s: %s', $pattern, $release, (string) $result->error));
        $this->assertSame($offset, $result->offset, \sprintf('%s on %s: %s', $pattern, $release, (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string, release: string, code: ?ErrorCode, offset: ?int}>
     */
    public static function provideConditions(): iterable
    {
        // A comment is skipped: the assertion, or a callout before it, follows.
        yield from self::rows('comment, then a lookahead', '/(?(?#c)(?=a)b)/', self::RELEASES, null, null);
        yield from self::rows('comment, then a callout and a lookahead', '/(?(?#c)(?C1)(?=a)b)/', self::RELEASES, null, null);
        yield from self::rows('comment, then an alphabetic lookahead', '/(?(?#c)(*pla:a)b)/', self::RELEASES, null, null);
        yield from self::rows('comment ending the pattern', '/(?(?#c)/', self::RELEASES, ErrorCode::GroupUnclosed, 7);

        // A callout, then no assertion.
        yield from self::rows('callout, then a letter', '/(?(?C1)a)/', self::RELEASES, ErrorCode::ConditionAssertionExpected, 7);
        yield from self::rows('callout, then an atomic group left open', '/(?(?C1)(*atomic:a/', self::RELEASES, ErrorCode::ConditionAssertionExpected, 15);

        // A verb or a name PCRE knows that is no lookaround.
        yield from self::rows('a verb as the condition', '/(?(*MARK:a)b)/', self::BEFORE_10_47, ErrorCode::ConditionAssertionExpected, 2);
        yield from self::rows('a verb as the condition', '/(?(*MARK:a)b)/', ['10.49'], ErrorCode::ConditionAssertionExpected, 3);
        yield from self::rows('an atomic group as the condition', '/(?(*atomic:a)b)/', self::RELEASES, ErrorCode::ConditionAssertionExpected, 10);

        // An alphabetic name no ":" follows, the pattern going on.
        yield from self::rows('alphabetic name, then "!"', '/(?(*pla!/', self::BEFORE_10_47, ErrorCode::VerbInvalid, 7);
        yield from self::rows('alphabetic name, then "!"', '/(?(*pla!/', ['10.49'], ErrorCode::VerbInvalid, 8);

        // A forward number past 65535 with the group before it, the pattern
        // left open: the number is refused first.
        yield from self::rows('forward number past the limit, left open', '/()(?(+65535)/', self::RELEASES, ErrorCode::GroupNumberTooBig, 11);

        // "(?(R" with a plus sign.
        yield from self::rows('recursion condition with a plus sign', '/(?(R+1)a)/', self::RELEASES, ErrorCode::GroupNameUnterminated, 4);

        // A version condition, then an error the lexer or the parser meets.
        yield from self::rows('version condition left open', '/(?(VERSION=10/', self::RELEASES, ErrorCode::ConditionVersionSyntax, 13);
        yield from self::rows('bad version, then a class left open', '/(?(VERSION=10z)a)[/', self::BEFORE_10_47, ErrorCode::ConditionUnclosed, 13);
        yield from self::rows('bad version, then a class left open', '/(?(VERSION=10z)a)[/', ['10.49'], ErrorCode::ConditionVersionSyntax, 14);

        // A group named "VERSION" is no version condition.
        yield from self::rows('group named VERSION as the condition, then a group left open', '/(?<VERSION>x)(?(VERSION)a)(/', self::RELEASES, ErrorCode::GroupUnclosed, 27);

        // A quoted name where quotes belong.
        yield from self::rows('quoted name in a condition', "/(?<n>x)(?('n')a)/", self::RELEASES, null, null);
    }

    /**
     * @param list<string> $releases
     *
     * @return iterable<string, array{pattern: string, release: string, code: ?ErrorCode, offset: ?int}>
     */
    private static function rows(string $name, string $pattern, array $releases, ?ErrorCode $code, ?int $offset): iterable
    {
        foreach ($releases as $release) {
            yield $name.', '.$release => ['pattern' => $pattern, 'release' => $release, 'code' => $code, 'offset' => $offset];
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
