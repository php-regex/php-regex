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
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubroutineParserTest extends TestCase
{
    public function test_parse_recursion(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/(?R)/');

        $this->assertInstanceOf(SubroutineNode::class, $ast->pattern);
        $this->assertSame('R', $ast->pattern->reference);
        $this->assertSame('', $ast->pattern->syntax);
    }

    public function test_parse_numeric_subroutine(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/(?1)/');

        $this->assertInstanceOf(SubroutineNode::class, $ast->pattern);
        $this->assertSame('1', $ast->pattern->reference);
    }

    public function test_parse_relative_numeric_subroutine(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/(?-1)/');

        $this->assertInstanceOf(SubroutineNode::class, $ast->pattern);
        $this->assertSame('-1', $ast->pattern->reference);
    }

    public function test_parse_named_subroutine(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/(?&name)/');

        $this->assertInstanceOf(SubroutineNode::class, $ast->pattern);
        $this->assertSame('name', $ast->pattern->reference);
        $this->assertSame('&', $ast->pattern->syntax);
    }

    public function test_parse_p_named_subroutine(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/(?P>name)/');

        $this->assertInstanceOf(SubroutineNode::class, $ast->pattern);
        $this->assertSame('name', $ast->pattern->reference);
        $this->assertSame('P>', $ast->pattern->syntax);
    }

    /**
     * A name no ")" ends is a name left open up to PCRE2 10.46, a group left
     * open from 10.47, where a call may go on with the groups it returns
     * (pcre2test 10.32 to 10.49: "syntax error in subpattern name (missing
     * terminator?)", error 142, up to 10.46; "missing closing parenthesis",
     * error 114, from 10.47; at the same offset).
     */
    #[DataProvider('provideCallsLeftOpen')]
    public function test_throws_on_incomplete_subroutine(string $pattern, string $release, ErrorCode $code, string $message, int $offset): void
    {
        try {
            RegexParser::create(['cache' => null, 'pcre_version' => $release])->parse($pattern);
            $this->fail(\sprintf('%s must be refused on PCRE2 %s.', $pattern, $release));
        } catch (ParserException $e) {
            $this->assertSame($code, $e->errorCode, \sprintf('%s on PCRE2 %s: %s', $pattern, $release, $e->getMessage()));
            $this->assertStringContainsString($message, $e->getMessage(), \sprintf('%s on PCRE2 %s', $pattern, $release));
            $this->assertSame($offset, $e->position, \sprintf('%s on PCRE2 %s', $pattern, $release));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, release: string, code: ErrorCode, message: string, offset: int}>
     */
    public static function provideCallsLeftOpen(): iterable
    {
        foreach (['/(?&name/i' => 7, '/(?&L}/' => 4, '/(?P>a}/' => 5] as $pattern => $offset) {
            foreach (['10.40', '10.46'] as $release) {
                yield \sprintf('%s, PCRE2 %s', $pattern, $release) => ['pattern' => $pattern, 'release' => $release, 'code' => ErrorCode::GroupNameUnterminated, 'message' => 'Missing ")" to close subroutine call', 'offset' => $offset];
            }

            foreach (['10.47', '10.49'] as $release) {
                yield \sprintf('%s, PCRE2 %s', $pattern, $release) => ['pattern' => $pattern, 'release' => $release, 'code' => ErrorCode::GroupUnclosed, 'message' => 'Expected ) to close subroutine call', 'offset' => $offset];
            }
        }
    }
}
