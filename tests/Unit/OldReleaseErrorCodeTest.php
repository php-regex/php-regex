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
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A code names what the judged release reports, not what the latest one
 * does: before PCRE2 10.45 "(?[" is no extended class and "(*scs:" no
 * known name, and before 10.47 "(*pla" and "(?(VERSION=10z" are read
 * another way. Each row was run through pcre2test on the releases named,
 * and through PHP 8.2 (10.40) and 8.4 (10.44).
 */
final class OldReleaseErrorCodeTest extends TestCase
{
    #[Test]
    #[DataProvider('provideCodesByRelease')]
    public function test_the_code_follows_the_judged_release(string $pattern, string $release, ErrorCode $code, int $offset): void
    {
        $result = RegexParser::create(['cache' => null, 'pcre_version' => $release])->validate($pattern);

        $this->assertSame($code, $result->errorCode, \sprintf('%s on %s: %s', $pattern, $release, (string) $result->error));
        $this->assertSame($offset, $result->offset, \sprintf('%s on %s', $pattern, $release));
    }

    /**
     * @return iterable<string, array{pattern: string, release: string, code: ErrorCode, offset: int}>
     */
    public static function provideCodesByRelease(): iterable
    {
        // "unrecognized character after (? or (?-" before 10.45.
        yield '(?[ left open, 10.40' => ['pattern' => '/(?[/', 'release' => '10.40', 'code' => ErrorCode::GroupSyntax, 'offset' => 2];
        yield '(?[] left open, 10.44' => ['pattern' => '/(?[]/', 'release' => '10.44', 'code' => ErrorCode::GroupSyntax, 'offset' => 2];

        // "(*alpha_assertion) not recognized" before 10.47.
        yield '(*pla left open, 10.40' => ['pattern' => '/(*pla/', 'release' => '10.40', 'code' => ErrorCode::VerbInvalid, 'offset' => 5];
        yield '(*pla left open, 10.46' => ['pattern' => '/(*pla/', 'release' => '10.46', 'code' => ErrorCode::VerbInvalid, 'offset' => 5];
        yield '(*pla left open, 10.47' => ['pattern' => '/(*pla/', 'release' => '10.47', 'code' => ErrorCode::VerbUnclosed, 'offset' => 5];

        // "(*scs:" is an unknown name before 10.45.
        yield 'substring scan as a condition, 10.44' => ['pattern' => '/(\\w++)=(?(*scs:(1)(abc))pqr|xyz)(\\w++)/', 'release' => '10.44', 'code' => ErrorCode::VerbInvalid, 'offset' => 14];

        // "\ at end of pattern" before 10.45.
        yield '[\\E left open, 10.40' => ['pattern' => '/[\\E/', 'release' => '10.40', 'code' => ErrorCode::EscapeTrailingBackslash, 'offset' => 3];
        yield '[\\Q\\E left open, 10.44' => ['pattern' => '/[\\Q\\E/', 'release' => '10.44', 'code' => ErrorCode::EscapeTrailingBackslash, 'offset' => 5];
        yield '[\\E left open, 10.45' => ['pattern' => '/[\\E/', 'release' => '10.45', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 3];

        // "invalid range in character class" before 10.45.
        yield 'range ending in \\N, 10.40' => ['pattern' => '/a[B-\\Nc]/', 'release' => '10.40', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 6];

        // "missing closing parenthesis for condition" before 10.47.
        yield 'version with a letter, 10.40' => ['pattern' => '/(?(VERSION=10z)yes|no)/', 'release' => '10.40', 'code' => ErrorCode::ConditionalUnclosed, 'offset' => 13];
        yield 'version with a letter, 10.46' => ['pattern' => '/(?(VERSION=10z)yes|no)/', 'release' => '10.46', 'code' => ErrorCode::ConditionalUnclosed, 'offset' => 13];
        yield 'version with a letter, 10.47' => ['pattern' => '/(?(VERSION=10z)yes|no)/', 'release' => '10.47', 'code' => ErrorCode::ConditionalVersionSyntax, 'offset' => 14];

        // "syntax error in subpattern name (missing terminator?)" after the
        // name of a call before 10.47, which reads a list of groups there.
        yield 'call name before a list, 10.46' => ['pattern' => "/(?<fn>a)(?&fn('ret'))/", 'release' => '10.46', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 13];
        yield 'call name before a hyphen, 10.40' => ['pattern' => '/(?<fn>a)(?&fn-)/', 'release' => '10.40', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 13];
        yield 'call name before a hyphen, 10.47' => ['pattern' => '/(?<fn>a)(?&fn-)/', 'release' => '10.47', 'code' => ErrorCode::GroupUnclosed, 'offset' => 13];
        yield 'call name at the end, 10.46' => ['pattern' => '/(?<fn>a)(?&fn/', 'release' => '10.46', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 13];
        yield 'Python call name before a hyphen, 10.46' => ['pattern' => '/(?<fn>a)(?P>fn-)/', 'release' => '10.46', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 14];
        yield 'Python call name at the end, 10.46' => ['pattern' => '/(?<fn>a)(?P>fn/', 'release' => '10.46', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 14];
        yield 'Python call name at the end, 10.47' => ['pattern' => '/(?<fn>a)(?P>fn/', 'release' => '10.47', 'code' => ErrorCode::GroupUnclosed, 'offset' => 14];
        yield 'call name before an empty list, 10.46' => ['pattern' => '/(?<fn>a)(?&fn()/', 'release' => '10.46', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 13];

        // A list of groups left open or holding no group is "missing closing
        // parenthesis" at the end of the pattern and "reference to
        // non-existent subpattern" elsewhere before 10.47.
        yield 'substring scan with no list at the end, 10.45' => ['pattern' => '/(a)(*scs:/', 'release' => '10.45', 'code' => ErrorCode::GroupUnclosed, 'offset' => 9];
        yield 'substring scan with no list at the end, 10.47' => ['pattern' => '/(a)(*scs:/', 'release' => '10.47', 'code' => ErrorCode::ScanSubstringMissingList, 'offset' => 9];
        yield 'substring scan with no list, 10.46' => ['pattern' => '/(a)(*scs:)/', 'release' => '10.46', 'code' => ErrorCode::GroupListMissingGroup, 'offset' => 9];
        yield 'substring scan with a letter for a list, 10.45' => ['pattern' => '/(a)(*scs:x/', 'release' => '10.45', 'code' => ErrorCode::GroupListMissingGroup, 'offset' => 9];
        yield 'substring scan list left open, 10.45' => ['pattern' => '/(a)(*scs:(/', 'release' => '10.45', 'code' => ErrorCode::GroupUnclosed, 'offset' => 10];
        yield 'empty substring scan list, 10.45' => ['pattern' => '/(a)(*scs:()a)/', 'release' => '10.45', 'code' => ErrorCode::GroupListMissingGroup, 'offset' => 10];
        yield 'empty substring scan list, 10.47' => ['pattern' => '/(a)(*scs:()a)/', 'release' => '10.47', 'code' => ErrorCode::GroupListItemExpected, 'offset' => 10];
        yield 'substring scan list left open after a comma, 10.46' => ['pattern' => '/(a)(*scs:(1,/', 'release' => '10.46', 'code' => ErrorCode::GroupUnclosed, 'offset' => 12];
        yield 'substring scan list naming group zero, 10.45' => ['pattern' => '/(a)(*scs:(0)a)/', 'release' => '10.45', 'code' => ErrorCode::GroupListMissingGroup, 'offset' => 11];
        yield 'substring scan list holding a name left open, 10.45' => ['pattern' => '/(a)(*scs:(<a)a)/', 'release' => '10.45', 'code' => ErrorCode::GroupNameUnterminated, 'offset' => 12];
        yield 'substring scan list ending in a comma, 10.45' => ['pattern' => '/(a)(*scs:(1,)a)/', 'release' => '10.45', 'code' => ErrorCode::GroupListMissingGroup, 'offset' => 12];
    }

    /**
     * PHP 7.3 and 7.4 compile with PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL, which
     * the "X" modifier clears: before 10.45 "[\E" and "[\Q\E" at the end of
     * the pattern then leave the class open, where the backslash would end
     * the pattern (pcre2test 10.32 to 10.44 with "bad_escape_is_literal").
     */
    #[Test]
    #[DataProvider('provideCodesByPhpVersion')]
    public function test_the_code_follows_the_judged_php_version(string $pattern, int $phpVersion, string $release, ErrorCode $code, int $offset): void
    {
        $result = RegexParser::create(['cache' => null, 'php_version' => $phpVersion, 'pcre_version' => $release, 'runtime_pcre_validation' => false])->validate($pattern);

        $this->assertSame($code, $result->errorCode, \sprintf('%s for PHP %d on %s: %s', $pattern, $phpVersion, $release, (string) $result->error));
        $this->assertSame($offset, $result->offset, \sprintf('%s for PHP %d on %s', $pattern, $phpVersion, $release));
    }

    /**
     * @return iterable<string, array{pattern: string, phpVersion: int, release: string, code: ErrorCode, offset: int}>
     */
    public static function provideCodesByPhpVersion(): iterable
    {
        yield '[\\E left open, PHP 7.3' => ['pattern' => '/[\\E/', 'phpVersion' => 70300, 'release' => '10.32', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 3];
        yield '[\\Q\\E left open, PHP 7.4' => ['pattern' => '/[\\Q\\E/', 'phpVersion' => 70400, 'release' => '10.35', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 5];
        yield '[\\E left open under X, PHP 7.4' => ['pattern' => '/[\\E/X', 'phpVersion' => 70400, 'release' => '10.33', 'code' => ErrorCode::EscapeTrailingBackslash, 'offset' => 3];
        yield '[\\E left open, PHP 8.0' => ['pattern' => '/[\\E/', 'phpVersion' => 80000, 'release' => '10.35', 'code' => ErrorCode::EscapeTrailingBackslash, 'offset' => 3];
    }
}
