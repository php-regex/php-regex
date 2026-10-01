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

namespace PhpRegex\Tests\Unit\Internal;

use PhpRegex\Parser\Exception\ParserException;
use PhpRegex\Parser\Internal\GroupNameReader;
use PhpRegex\Parser\Token\Token;
use PhpRegex\Parser\Token\TokenStream;
use PhpRegex\Parser\Token\TokenType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading the name of a group, and refusing the ones PCRE would refuse.
 */
final class GroupNameReaderTest extends TestCase
{
    #[Test]
    public function test_a_plain_name_is_read_up_to_its_closing_bracket(): void
    {
        $stream = $this->streamOf('name', '>');
        $reader = new GroupNameReader($stream);

        $this->assertSame('name', $reader->read());
        $this->assertSame('>', $stream->current()->value, 'The closing bracket is left for the caller.');
    }

    #[Test]
    public function test_a_quoted_name_loses_its_quotes(): void
    {
        $stream = $this->streamOf("'", 'test_name', "'", '>');
        $reader = new GroupNameReader($stream);

        $this->assertSame('test_name', $reader->read(quoted: true));
        $this->assertSame('>', $stream->current()->value);
    }

    #[Test]
    public function test_a_quote_starts_no_name_where_the_name_is_not_quoted(): void
    {
        // PCRE: "(?<'n'>x)" is "subpattern name expected" on the quote.
        $reader = new GroupNameReader($this->streamOf("'", 'name', "'", '>'));

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Expected group name at position 0');

        $reader->read();
    }

    #[Test]
    public function test_a_name_must_be_there(): void
    {
        $reader = new GroupNameReader($this->streamOf('>', 'a', ')'));

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Expected group name at position');

        $reader->read();
    }

    #[Test]
    public function test_a_name_must_read_like_one(): void
    {
        $reader = new GroupNameReader($this->streamOf('1st', '>'));

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Invalid group name "1st"');

        $reader->read();
    }

    #[Test]
    public function test_a_quoted_name_must_be_closed(): void
    {
        $reader = new GroupNameReader($this->streamOf("'", 'name', '>'));

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Expected closing quote');

        $reader->read(quoted: true);
    }

    #[Test]
    public function test_a_name_is_used_once_unless_duplicates_are_allowed(): void
    {
        $reader = new GroupNameReader($this->streamOf());
        $reader->register('name', 0);

        $this->assertFalse($reader->duplicatesAllowed());

        $reader->allowDuplicates(true);
        $reader->register('name', 5);

        $reader->allowDuplicates(false);

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Duplicate group name "name" at position 9.');

        $reader->register('name', 9);
    }

    #[Test]
    public function test_a_reference_does_not_claim_the_name(): void
    {
        $reader = new GroupNameReader($this->streamOf('name', '>'));

        $this->assertSame('name', $reader->read(register: false));

        // Nothing was claimed, so declaring it afterwards is fine.
        $reader->register('name', 0);
        $this->assertFalse($reader->duplicatesAllowed());
    }

    private function streamOf(string ...$literals): TokenStream
    {
        $tokens = [];
        $position = 0;

        foreach ($literals as $literal) {
            $tokens[] = new Token(TokenType::Literal, $literal, $position);
            $position += \strlen($literal);
        }

        $tokens[] = new Token(TokenType::Eof, '', $position);

        return new TokenStream($tokens, implode('', $literals));
    }
}
