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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE2 reads the body of a "(*scs:" that no ")" closes as the body of any
 * group, after its list of groups: the first error in it is reported where
 * it stands, and only a body read without one is refused at the end of the
 * pattern for the ")" it misses. Each row as pcre2test 10.45 to 10.49
 * report it.
 */
final class UnclosedScanSubstringBodyTest extends TestCase
{
    /**
     * @param array<string, int> $offsets
     */
    #[Test]
    #[DataProvider('provideBodies')]
    public function test_the_first_error_in_the_body_is_reported_where_it_stands(string $pattern, array $offsets, ErrorCode $code): void
    {
        foreach ($offsets as $release => $offset) {
            $validation = RegexParser::create(['php_version' => '8.4', 'pcre_version' => $release, 'runtime_pcre_validation' => false])->validate($pattern);

            $this->assertFalse($validation->isValid, \sprintf('%s on PCRE2 %s', $pattern, $release));
            $this->assertSame($offset, $validation->offset, \sprintf('%s on PCRE2 %s: %s', $pattern, $release, $validation->error ?? ''));
            $this->assertSame($code, $validation->errorCode, \sprintf('%s on PCRE2 %s', $pattern, $release));
        }
    }

    /**
     * A fault in the list of groups comes first, and where "(*scs:" opens
     * no substring scan, no list is read.
     */
    #[Test]
    #[DataProvider('provideListsAndOpenings')]
    public function test_the_list_is_read_first_and_only_where_a_substring_scan_opens(string $pattern, string $release, int $offset, ErrorCode $code): void
    {
        $validation = RegexParser::create(['php_version' => '8.4', 'pcre_version' => $release, 'runtime_pcre_validation' => false])->validate($pattern);

        $this->assertSame($offset, $validation->offset, \sprintf('%s on PCRE2 %s: %s', $pattern, $release, $validation->error ?? ''));
        $this->assertSame($code, $validation->errorCode, \sprintf('%s on PCRE2 %s', $pattern, $release));
    }

    /**
     * @return iterable<string, array{pattern: string, release: string, offset: int, code: ErrorCode}>
     */
    public static function provideListsAndOpenings(): iterable
    {
        yield 'list item that is no group' => ['pattern' => '/(*scs:(a)b/', 'release' => '10.47', 'offset' => 7, 'code' => ErrorCode::GroupListItemExpected];
        yield 'list item that is no group on 10.45' => ['pattern' => '/(*scs:(a)b/', 'release' => '10.45', 'offset' => 7, 'code' => ErrorCode::GroupListMissingGroup];
        yield 'no list' => ['pattern' => '/(*scs:)b/', 'release' => '10.49', 'offset' => 6, 'code' => ErrorCode::ScanSubstringMissingList];
        yield 'list no parenthesis closes' => ['pattern' => '/(*scs:(1/', 'release' => '10.46', 'offset' => 8, 'code' => ErrorCode::GroupUnclosed];
        yield 'name starting with a digit in the list' => ['pattern' => '/(*scs:(<1>)a/', 'release' => '10.47', 'offset' => 9, 'code' => ErrorCode::GroupNameInvalid];
        // A group the list names that PCRE refuses as it reads the list,
        // before the body.
        yield 'relative zero in the list before an error in the body' => ['pattern' => '/(a)(*scs:(+0)\c/', 'release' => '10.45', 'offset' => 12, 'code' => ErrorCode::GroupListRelativeZero];
        yield 'group zero in the list' => ['pattern' => '/(*scs:(0)x/', 'release' => '10.49', 'offset' => 8, 'code' => ErrorCode::GroupListMissingGroup];
        yield 'relative zero after a group in the list' => ['pattern' => '/(a)(*scs:(1,+0)x/', 'release' => '10.47', 'offset' => 14, 'code' => ErrorCode::GroupListRelativeZero];
        yield 'relative group before the first in the list' => ['pattern' => '/(*scs:(-5)x/', 'release' => '10.46', 'offset' => 9, 'code' => ErrorCode::GroupListMissingGroup];
        yield 'substring scan as the condition' => ['pattern' => '/(?(*scs:(1)a/', 'release' => '10.49', 'offset' => 7, 'code' => ErrorCode::ConditionalAssertionExpected];
        yield 'substring scan where a group name is due' => ['pattern' => '/(?<(*scs:a/', 'release' => '10.49', 'offset' => 3, 'code' => ErrorCode::GroupNameExpected];
        yield 'substring scan before 10.45' => ['pattern' => '/(*scs:(1)(*UTF:x)/', 'release' => '10.44', 'offset' => 5, 'code' => ErrorCode::VerbInvalid];
    }

    /**
     * @return iterable<string, array{pattern: string, offsets: array<string, int>, code: ErrorCode}>
     */
    public static function provideBodies(): iterable
    {
        yield 'setting given an argument' => ['pattern' => '/(*scs:(1)(*UTF:x)/', 'offsets' => ['10.45' => 14, '10.47' => 14, '10.49' => 14], 'code' => ErrorCode::VerbInvalid];
        yield 'alphabetic name with no colon' => ['pattern' => '/(*scs:(1)(*pla)/', 'offsets' => ['10.45' => 14, '10.46' => 14, '10.47' => 15, '10.49' => 15], 'code' => ErrorCode::VerbInvalid];
        yield 'setting inside the pattern' => ['pattern' => '/(*scs:(1)(*UTF)/', 'offsets' => ['10.45' => 14, '10.49' => 14], 'code' => ErrorCode::VerbMisplaced];
        yield 'verb no parenthesis closes' => ['pattern' => '/(*scs:(1)(*ACCEPT/', 'offsets' => ['10.45' => 17, '10.49' => 17], 'code' => ErrorCode::VerbUnclosed];
        yield 'unknown verb after a character' => ['pattern' => '/(*scs:(1)a(*FOO)/', 'offsets' => ['10.45' => 15, '10.49' => 15], 'code' => ErrorCode::VerbInvalid];
        yield 'mark with no name' => ['pattern' => '/(*scs:(1)(*MARK)/', 'offsets' => ['10.45' => 15, '10.49' => 15], 'code' => ErrorCode::VerbMarkNameMissing];
        yield 'group name no bracket closes' => ['pattern' => '/(*scs:(1)(?<n/', 'offsets' => ['10.45' => 13, '10.49' => 13], 'code' => ErrorCode::GroupNameUnterminated];
        yield 'class left open' => ['pattern' => '/(*scs:(1)[a/', 'offsets' => ['10.45' => 11, '10.49' => 11], 'code' => ErrorCode::CharclassUnclosed];
        yield 'class holding the closing parenthesis' => ['pattern' => '/(*scs:(1)[a)/', 'offsets' => ['10.45' => 12, '10.49' => 12], 'code' => ErrorCode::CharclassUnclosed];
        yield 'count too big' => ['pattern' => '/(*scs:(1)a{99999}/', 'offsets' => ['10.45' => 16, '10.49' => 16], 'code' => ErrorCode::QuantifierTooBig];
        yield 'property no brace closes' => ['pattern' => '/(*scs:(1,2)\p{/', 'offsets' => ['10.45' => 14, '10.49' => 14], 'code' => ErrorCode::UnicodePropertyMalformed];
        yield 'long spelling' => ['pattern' => '/(*scan_substring:(1)(*UTF:x)/', 'offsets' => ['10.45' => 25, '10.49' => 25], 'code' => ErrorCode::VerbInvalid];
        yield 'list of a name' => ['pattern' => '/(*scs:(<n>)(*UTF:x)/', 'offsets' => ['10.45' => 16, '10.49' => 16], 'code' => ErrorCode::VerbInvalid];
        yield 'after a comment' => ['pattern' => '/(*scs:(1)(?#)(*UTF:x)/', 'offsets' => ['10.45' => 18, '10.49' => 18], 'code' => ErrorCode::VerbInvalid];
        yield 'inside a group' => ['pattern' => '/(*scs:(1)(?:(*UTF:x)/', 'offsets' => ['10.45' => 17, '10.49' => 17], 'code' => ErrorCode::VerbInvalid];
        yield 'inside another one' => ['pattern' => '/(*scs:(1)(*scs:(1)(*UTF:x)/', 'offsets' => ['10.45' => 23, '10.49' => 23], 'code' => ErrorCode::VerbInvalid];
        yield 'inside a group no parenthesis closes' => ['pattern' => '/(?:(1)(*scs:(1)(*UTF:x)/', 'offsets' => ['10.45' => 20, '10.49' => 20], 'code' => ErrorCode::VerbInvalid];
        yield 'after a closed one' => ['pattern' => '/(*scs:(1)a)(*scs:(1)(*pla)/', 'offsets' => ['10.46' => 25, '10.47' => 26], 'code' => ErrorCode::VerbInvalid];
        // A body read without an error misses its ")" at the end.
        yield 'body of a character' => ['pattern' => '/(*scs:(1)a/', 'offsets' => ['10.45' => 10, '10.49' => 10], 'code' => ErrorCode::GroupUnclosed];
        yield 'body quoting the rest' => ['pattern' => '/(*scs:(1)\Q)(*UTF:x)/', 'offsets' => ['10.45' => 20, '10.49' => 20], 'code' => ErrorCode::GroupUnclosed];
        yield 'body commenting out the rest under x' => ['pattern' => '/(*scs:(1)(?x)a #)(*UTF:x)/', 'offsets' => ['10.45' => 25, '10.49' => 25], 'code' => ErrorCode::GroupUnclosed];
        yield 'conditional no parenthesis closes' => ['pattern' => '/(*scs:(1)(?(1)/', 'offsets' => ['10.45' => 14, '10.49' => 14], 'code' => ErrorCode::GroupUnclosed];
    }
}
