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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Tests\TestUtils\PcreMessageCodes;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "(?(VERSION>=10.4)yes|no)" branches on the version of PCRE reading the
 * pattern, and PCRE compares two ways only: "=" and ">=".
 *
 * The parser reads the other comparisons so that such a pattern still
 * produces a tree to look at; the validator is what says they will not
 * compile. Each case is checked against PCRE's own answer first.
 */
final class VersionConditionValidationTest extends TestCase
{
    #[Test]
    #[DataProvider('provideVersionConditions')]
    public function test_a_version_condition_is_valid_where_pcre_compiles_it(string $pattern, bool $valid): void
    {
        $this->assertSame($valid, $this->pcreCompiles($pattern), 'The expectation does not match PCRE.');

        $result = Regex::create(['cache' => new NullCache()])->validate($pattern);

        $this->assertSame($valid, $result->isValid, (string) $result->error);
    }

    /**
     * @return iterable<string, array{pattern: string, valid: bool}>
     */
    public static function provideVersionConditions(): iterable
    {
        yield 'at least' => ['pattern' => '/(?(VERSION>=10.4)y|n)/', 'valid' => true];
        yield 'exactly' => ['pattern' => '/(?(VERSION=10.4)y|n)/', 'valid' => true];

        // PCRE offers no other comparison.
        yield 'strictly above' => ['pattern' => '/(?(VERSION>10.4)y|n)/', 'valid' => false];
        yield 'strictly below' => ['pattern' => '/(?(VERSION<10.4)y|n)/', 'valid' => false];
        yield 'at most' => ['pattern' => '/(?(VERSION<=10.4)y|n)/', 'valid' => false];
        yield 'not equal' => ['pattern' => '/(?(VERSION!=10.4)y|n)/', 'valid' => false];
    }

    #[Test]
    public function test_the_error_names_the_comparisons_pcre_makes(): void
    {
        $result = Regex::create(['cache' => new NullCache()])->validate('/(?(VERSION>10.4)y|n)/');

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('PCRE compares with "=" or ">="', (string) $result->error);
    }

    /**
     * PCRE reads the pattern left to right and judges a version condition
     * where it reads it: an invalid one is refused before a group left open,
     * a class left open or a stray ")" after it, and a valid one lets that
     * later error be the one reported. The library parses the whole pattern
     * before it judges the version, so the condition's own error must still
     * come first. A condition past the later error, or never closed, is not
     * the one reported.
     *
     * The library agrees with the running engine on every release, the
     * offset and a code its message allows; the row holds what PCRE2 10.49
     * reports, checked against the engine when it is 10.49 (some of these
     * offsets moved one place in 10.47).
     */
    #[Test]
    #[DataProvider('provideVersionConditionsBeforeALaterError')]
    public function test_a_version_condition_error_is_reported_before_a_later_one_as_pcre_does(string $pattern, ErrorCode $code, int $offset): void
    {
        $pcre = PcreMessageCodes::read(PcreMessageCodes::warningOf($pattern) ?? 'compiles');
        $this->assertArrayHasKey($pcre['message'], PcreMessageCodes::CODES, \sprintf('Oracle: %s, "%s" is not a message the code map knows.', $pattern, $pcre['message']));
        if ('10.49' === self::runningRelease()) {
            $this->assertSame($offset, $pcre['offset'], \sprintf('Oracle: %s (%s).', $pattern, $pcre['message']));
            $this->assertContains($code->value, PcreMessageCodes::CODES[$pcre['message']], \sprintf('Oracle: %s does not name "%s".', $code->value, $pcre['message']));
        }

        $result = Regex::create(['cache' => new NullCache()])->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s does not compile but was reported valid.', $pattern));
        $this->assertSame($pcre['offset'], $result->offset, \sprintf('%s: PCRE says "%s" at %d, the library "%s".', $pattern, $pcre['message'], (int) $pcre['offset'], (string) $result->error));
        $this->assertContains($result->errorCode?->value, PcreMessageCodes::CODES[$pcre['message']], \sprintf('%s: PCRE says "%s", the library "%s".', $pattern, $pcre['message'], (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideVersionConditionsBeforeALaterError(): iterable
    {
        // PCRE: "syntax error or number too big in (?(VERSION condition".
        yield 'no number, then a group left open' => ['pattern' => '/(?(VERSION>=x)a|b)(c/', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 12];
        yield 'no number, then a class left open' => ['pattern' => '/(?(VERSION>=x)a|b)[a/', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 12];
        yield 'no number, then a stray parenthesis' => ['pattern' => '/(?(VERSION>=x)a|b)x)/', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 12];
        yield 'no number, then a reversed range' => ['pattern' => '/(?(VERSION>=x)a|b)[z-a]/', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 12];
        yield 'letter as the minor, then a group left open' => ['pattern' => '/(?(VERSION>=10.x)a|b)(c/', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 16];
        yield 'no minor after the dot, then a group left open' => ['pattern' => '/(?(VERSION>=10.)a|b)(c/', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 16];
        yield 'no comparison, then a group left open' => ['pattern' => '/(?(VERSION10.4)a|b)(c/', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 11];
        yield 'empty version, then a group left open' => ['pattern' => '/(?(VERSION>=)a|b)(c/', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 12];
        yield 'letter after VERSION, then a stray parenthesis' => ['pattern' => '/(?(VERSIONx)a|b)x)/', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 11];
        // A number too big is judged once the condition is read whole, past
        // the parsing that stops on the later error.
        yield 'number too big, then a group left open' => ['pattern' => '/(?(VERSION>=99999999999)a)(c/', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 23];
        yield 'major past 1000, then a group left open' => ['pattern' => '/(?(VERSION=1001.1)a)(c/', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 15];
        yield 'major past 1000, then a class left open' => ['pattern' => '/(?(VERSION=1001.1)a)[a/', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 15];
        yield 'major past 1000, then a stray parenthesis' => ['pattern' => '/(?(VERSION=1001.1)a)x)/', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 15];
        yield 'major past 1000, the condition never closed' => ['pattern' => '/(?(VERSION=1001.1)a/', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 15];
        yield 'minor past 1000, then a group left open' => ['pattern' => '/(?(VERSION=10.1001)a)(c/', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 18];
        yield 'major past 1000 after a valid condition' => ['pattern' => '/(?(VERSION>=10.4)a)(?(VERSION=1001.1)b)(c/', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 34];
        yield 'two majors past 1000, the first is reported' => ['pattern' => '/(?(VERSION=1001.1)a)(?(VERSION=1002.1)b)(c/', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 15];
        yield 'major past 1000 in an alphabetic lookahead' => ['pattern' => '/(*pla:(?(VERSION=1001.1)a))(c/', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 21];
        yield 'two invalid conditions, the first is reported' => ['pattern' => '/(?(VERSION>=x)a|b)(?(VERSION>=y)c)(d/', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 12];
        yield 'invalid condition after a valid one' => ['pattern' => '/(?(VERSION>=10.4)a|b)(?(VERSION>=x)c)(d/', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 33];
        yield 'invalid condition nested in a valid one' => ['pattern' => '/(?(VERSION>=10.4)(?(VERSION>=x)a))(c/', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 29];
        yield 'invalid condition in an alphabetic lookahead' => ['pattern' => '/(*pla:(?(VERSION>=x)a))(c/', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 18];
        yield 'invalid condition never closed' => ['pattern' => '/(?(VERSION>=x/', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 12];
        // PCRE: "missing closing parenthesis": the condition is valid.
        yield 'valid condition, then a group left open' => ['pattern' => '/(?(VERSION>=10.4)a|b)(c/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 23];
        yield 'valid exact condition, then a group left open' => ['pattern' => '/(?(VERSION=10.4)a|b)(c/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 22];
        yield 'three-branch condition, then a group left open' => ['pattern' => '/(?(VERSION>=10.4)a|b|c)(d/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 25];
        // Valid from PCRE2 10.47 only, which reads the minor as a whole number.
        yield 'three-digit minor, then a group left open' => ['pattern' => '/(?(VERSION>=10.123)a|b)(c/', 'code' => ErrorCode::GroupUnclosed, 'offset' => 25];
        // PCRE: "missing terminating ] for character class".
        yield 'valid condition, then a class left open' => ['pattern' => '/(?(VERSION>=10.4)a|b)[a/', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 23];
        // PCRE: "unmatched closing parenthesis": before the condition, or
        // after a valid one.
        yield 'valid condition, then a stray parenthesis' => ['pattern' => '/(?(VERSION>=10.4)a|b)x)/', 'code' => ErrorCode::GroupUnmatchedClose, 'offset' => 23];
        yield 'stray parenthesis, then an invalid condition' => ['pattern' => '/x)(?(VERSION>=x)a)/', 'code' => ErrorCode::GroupUnmatchedClose, 'offset' => 2];
        yield 'stray parenthesis, then a major past 1000' => ['pattern' => '/x)(?(VERSION=1001.1)a)/', 'code' => ErrorCode::GroupUnmatchedClose, 'offset' => 2];
    }

    private function pcreCompiles(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);
        $compiles = false !== @preg_match($pattern, '');
        restore_error_handler();

        return $compiles;
    }

    /**
     * The major.minor release of the PCRE2 the running PHP links.
     */
    private static function runningRelease(): string
    {
        return implode('.', \array_slice(explode('.', explode(' ', \PCRE_VERSION)[0]), 0, 2));
    }
}
