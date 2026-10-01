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

namespace PhpRegex\Tests\Unit;

use PhpRegex\Parser\ErrorCode;
use PhpRegex\Parser\RegexParser;
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
     * @return iterable<string, array{pattern: string, release: string, code: \PhpRegex\Parser\ErrorCode, offset: int}>
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
        yield 'version with a letter, 10.40' => ['pattern' => '/(?(VERSION=10z)yes|no)/', 'release' => '10.40', 'code' => ErrorCode::ConditionUnclosed, 'offset' => 13];
        yield 'version with a letter, 10.46' => ['pattern' => '/(?(VERSION=10z)yes|no)/', 'release' => '10.46', 'code' => ErrorCode::ConditionUnclosed, 'offset' => 13];
        yield 'version with a letter, 10.47' => ['pattern' => '/(?(VERSION=10z)yes|no)/', 'release' => '10.47', 'code' => ErrorCode::ConditionVersionSyntax, 'offset' => 14];
    }
}
