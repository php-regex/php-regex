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

namespace PHPRegex\Tests\Unit\Parser\Validation;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\Validator;
use PHPRegex\Tests\TestUtils\PhpErrorOffset;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReferenceChecksTest extends TestCase
{
    /**
     * Before PCRE2 10.43 a space after the number of "\g{" ends "\g": PCRE
     * refuses it on the brace, for the number it read first when that
     * number is wrong (pcre2test 10.42 under PHP 8.3: "subpattern number is
     * too big", "reference to non-existent subpattern", or "\g is not
     * followed by..." at offset 5, 8 here).
     */
    #[Test]
    #[DataProvider('providePaddedBraceNumbers')]
    public function test_the_number_before_a_space_in_braces_decides_the_error_before_10_43(string $pattern, int $offset, ErrorCode $code): void
    {
        $result = RegexParser::create(['cache' => null, 'php_version' => '8.3', 'pcre_version' => '10.42', 'runtime_pcre_validation' => false])->validate($pattern);

        $this->assertSame([$code, $offset], [$result->errorCode, $result->offset], $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int, code: ErrorCode}>
     */
    public static function providePaddedBraceNumbers(): iterable
    {
        yield 'forward number at the highest group' => ['pattern' => '/(a)\g{+65534 }/', 'offset' => 5, 'code' => ErrorCode::BackrefInvalidSyntax];
        yield 'forward number one past the highest group' => ['pattern' => '/(a)\g{+65535 }/', 'offset' => 5, 'code' => ErrorCode::GroupNumberTooBig];
        yield 'backward number to the first group' => ['pattern' => '/(a)(b)\g{-2 }/', 'offset' => 8, 'code' => ErrorCode::BackrefInvalidSyntax];
        yield 'backward number past the first group' => ['pattern' => '/(a)(b)\g{-3 }/', 'offset' => 8, 'code' => ErrorCode::BackrefRelative];
        yield 'number of six digits, five of them zeros' => ['pattern' => '/(a)\g{000001 }/', 'offset' => 5, 'code' => ErrorCode::BackrefInvalidSyntax];
        yield 'number of five digits' => ['pattern' => '/(a)\g{12345 }/', 'offset' => 5, 'code' => ErrorCode::BackrefInvalidSyntax];
        yield 'highest group number' => ['pattern' => '/(a)\g{65535 }/', 'offset' => 5, 'code' => ErrorCode::BackrefInvalidSyntax];
        yield 'number past the groups' => ['pattern' => '/(a)\g{5 }/', 'offset' => 5, 'code' => ErrorCode::BackrefInvalidSyntax];
    }

    /**
     * PCRE keeps one offset for each group a reference names by a number
     * below 10, that of the first such reference, and reports a missing
     * group there, whichever reference it meets first: one in a lookbehind,
     * which it measures before it compiles the rest. The first reference is
     * the first written, wherever the measure meets one: in a lookahead or a
     * DEFINE group of the lookbehind, after an "(*ACCEPT)" there, or after
     * the lookbehind, in a group it calls. A name, a call, a condition, or a
     * reference to another group keeps its own offset. Each row is the
     * offset preg_match() reports on PHP 8.4.27 with PCRE2 10.49.
     */
    #[Test]
    #[DataProvider('provideReferencesToTheSameMissingGroup')]
    public function test_a_missing_group_below_10_is_reported_at_its_first_reference(string $pattern, int $offset, ErrorCode $code): void
    {
        // PHP first: it refuses the pattern at the row's offset where it
        // links PCRE2 10.49, and where the library judging the running PHP
        // says on any release.
        $reported = PhpErrorOffset::of($pattern);
        $this->assertNotNull($reported, 'Oracle: PHP refuses '.$pattern);
        if ('10.49' === PcreTarget::runtime()->pcreVersion) {
            $this->assertSame($offset, $reported, 'Oracle on PCRE2 10.49: '.$pattern);
        }
        $this->assertSame($reported, RegexParser::create(['cache' => null, 'runtime_pcre_validation' => false])->validate($pattern)->offset, \sprintf('%s on PCRE2 %s', $pattern, \PCRE_VERSION));

        $result = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => '10.49', 'runtime_pcre_validation' => false])->validate($pattern);

        $this->assertSame([$code, $offset], [$result->errorCode, $result->offset], $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int, code: ErrorCode}>
     */
    public static function provideReferencesToTheSameMissingGroup(): iterable
    {
        yield 'reference, then one in a lookbehind' => ['pattern' => '/\1(?<=\1)/', 'offset' => 2, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'braced reference, then one in a lookbehind' => ['pattern' => '/\g{1}(?<=\1)/', 'offset' => 5, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'reference, then a braced one in a lookbehind' => ['pattern' => '/\1(?<=\g{1})/', 'offset' => 2, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'unbraced \g, then a braced one in a lookbehind' => ['pattern' => '/\g1(?<=\g{1})/', 'offset' => 3, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'forward reference, then one in a lookbehind' => ['pattern' => '/\g{+1}(?<=\1)/', 'offset' => 6, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'forward reference past a group, then one in a lookbehind' => ['pattern' => '/(a)\g{+1}(?<=\2)/', 'offset' => 9, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'reference to group 9, then one in a lookbehind' => ['pattern' => '/\9(?<=\9)/', 'offset' => 2, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'reference repeated no time, then one in a lookbehind' => ['pattern' => '/\1{0}(?<=\1)/', 'offset' => 2, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'reference in a lookahead, then one in a lookbehind' => ['pattern' => '/(?=\1)(?<=\1)/', 'offset' => 5, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'reference in a DEFINE group, then one in a lookbehind' => ['pattern' => '/(?(DEFINE)\1)(?<=\1)/', 'offset' => 12, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'reference, then one in a branch of a lookbehind' => ['pattern' => '/\1(?<=a|\1)/', 'offset' => 2, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'reference, then one in a negative lookbehind' => ['pattern' => '/\1(?<!\1)/', 'offset' => 2, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'reference, then one in an alphabetic lookbehind' => ['pattern' => '/\1(*plb:\1)/', 'offset' => 2, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'reference, then one in a nested lookbehind' => ['pattern' => '/\1(?<=(?<=\1))/', 'offset' => 2, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'two references, then both in a lookbehind' => ['pattern' => '/\1\2(?<=\2\1)/', 'offset' => 4, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'reference to another group first' => ['pattern' => '/\1(?<=\2)/', 'offset' => 8, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'named reference first' => ['pattern' => '/\k<n>(?<=\k<n>)/', 'offset' => 12, 'code' => ErrorCode::BackrefMissingNamedGroup];
        yield 'condition first' => ['pattern' => '/(?(1)a)(?<=\1)/', 'offset' => 13, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'call first' => ['pattern' => '/(?1)(?<=\1)/', 'offset' => 10, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'reference in a lookahead of the lookbehind, then one the measure meets' => ['pattern' => '/(?<=(?=\1)\1)/', 'offset' => 9, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'reference in a negative lookahead of the lookbehind, then one the measure meets' => ['pattern' => '/(?<=(?!\1)\1)/', 'offset' => 9, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'reference in a lookahead of one branch, then one in another' => ['pattern' => '/(?<=(?=\1)a|\1)/', 'offset' => 9, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'braced reference in a lookahead of the lookbehind, then one the measure meets' => ['pattern' => '/(?<=a(?=\g{1})\1)/', 'offset' => 13, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'reference in a lookahead of the lookbehind, then a braced one' => ['pattern' => '/(?<=(?=\1)\g{1})/', 'offset' => 9, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'reference after an (*ACCEPT) in the lookbehind, then one in another branch' => ['pattern' => '/(?<=(*ACCEPT)\1|\1)/', 'offset' => 15, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'reference in a DEFINE group of the lookbehind, then one the measure meets' => ['pattern' => '/(?<=(?(DEFINE)\1)\1)/', 'offset' => 16, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'reference in a lookahead of a nested lookbehind, then one the measure meets' => ['pattern' => '/(?<=(?<=(?=\1))\1)/', 'offset' => 13, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'reference in a lookahead of the lookbehind, then one in a nested lookbehind' => ['pattern' => '/(?<=(?=\1)(?<=\1))/', 'offset' => 9, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'reference in a lookahead of a lookbehind in a payload' => ['pattern' => '/(*pla:(?<=(?=\1)\1))/', 'offset' => 15, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'reference after the lookbehind, then one in the group it calls' => ['pattern' => '/(?<=(?1))\2(\2)/', 'offset' => 11, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'reference in a lookahead after the lookbehind, then one in the group it calls' => ['pattern' => '/(?<=(?1))(?=\2)(\2)/', 'offset' => 14, 'code' => ErrorCode::BackrefMissingGroup];
        yield 'reference in the group the lookbehind calls, then one after it' => ['pattern' => '/(?<=(?1))(\2)\2/', 'offset' => 12, 'code' => ErrorCode::BackrefMissingGroup];
    }

    /**
     * In a tree built by hand whose lookahead body counts positions from its
     * own start, as a payload read apart does, a reference in a lookbehind
     * there is still reported where the first one to its group stands
     * (PHP 8.4.27, PCRE2 10.49: "/\\1(*pla:(?<=\\1))/" at offset 2).
     */
    #[Test]
    public function test_a_missing_group_below_10_in_a_payload_read_apart_is_reported_at_its_first_reference(): void
    {
        $lookbehind = new GroupNode(new BackrefNode('\\1', 4, 6), GroupType::LookbehindPositive, null, null, 0, 7);
        $lookahead = new GroupNode($lookbehind, GroupType::LookaheadPositive, null, null, 2, 16);
        $tree = new RegexNode(new SequenceNode([new BackrefNode('\\1', 0, 2), $lookahead], 0, 16), '', '/', 0, 16, '\\1(*pla:(?<=\\1))');

        if ('10.49' === PcreTarget::runtime()->pcreVersion) {
            $this->assertSame(2, PhpErrorOffset::of('/\\1(*pla:(?<=\\1))/'), 'Oracle on PCRE2 10.49.');
        }

        try {
            $tree->accept(new Validator(pattern: '/\\1(*pla:(?<=\\1))/', target: new PcreTarget(80400, '10.49')));
            $this->fail('A reference to a missing group is refused.');
        } catch (SemanticErrorException $error) {
            $this->assertSame([ErrorCode::BackrefMissingGroup, 2], [$error->errorCode, $error->offset]);
        }
    }

    /**
     * A call "R-1", which only a tree built by hand holds, counts back from
     * where it stands; any other name after "R" is a group name.
     */
    #[Test]
    public function test_a_call_to_r_and_a_number_back_is_a_relative_recursion(): void
    {
        $this->assertSame(
            [ErrorCode::SubroutineRecursion, 'Recursion condition relative reference -1 is outside the range of available capture groups.'],
            self::refusal(new SubroutineNode('R-1', 'R-1', 0, 0)),
        );
        $this->assertSame(
            [ErrorCode::SubroutineMissingNamedGroup, 'Subroutine call to non-existent named group: "R-a".'],
            self::refusal(new SubroutineNode('R-a', 'R-a', 0, 0)),
        );
    }

    /**
     * @return array{0: ErrorCode|null, 1: string}|null
     */
    private static function refusal(SubroutineNode $node): ?array
    {
        try {
            $node->accept(new Validator());
        } catch (SemanticErrorException $error) {
            return [$error->errorCode, $error->getMessage()];
        }

        return null;
    }
}
