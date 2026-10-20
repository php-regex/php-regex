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
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\Validator;
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
