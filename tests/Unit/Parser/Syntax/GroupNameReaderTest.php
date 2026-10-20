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

namespace PHPRegex\Tests\Unit\Parser\Syntax;

use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\GroupNameReader;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenStream;
use PHPRegex\Parser\Token\TokenType;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading the name of a group, and refusing the ones PCRE would refuse.
 */
final class GroupNameReaderTest extends TestCase
{
    use PhpUnitForwardCompat;

    #[Test]
    public function test_a_plain_name_is_read_up_to_its_closing_bracket(): void
    {
        $stream = $this->streamOf('name', '>');
        $reader = new GroupNameReader($stream, self::target());

        $this->assertSame('name', $reader->read());
        $this->assertSame('>', $stream->current()->value, 'The closing bracket is left for the caller.');
    }

    #[Test]
    public function test_a_quoted_name_loses_its_quotes(): void
    {
        $stream = $this->streamOf("'", 'test_name', "'", '>');
        $reader = new GroupNameReader($stream, self::target());

        $this->assertSame('test_name', $reader->read(quoted: true));
        $this->assertSame('>', $stream->current()->value);
    }

    #[Test]
    public function test_a_quote_starts_no_name_where_the_name_is_not_quoted(): void
    {
        // PCRE: "(?<'n'>x)" is "subpattern name expected" on the quote.
        $reader = new GroupNameReader($this->streamOf("'", 'name', "'", '>'), self::target());

        $this->expectException(ParserException::class);
        $this->expectExceptionMessageIsOrContains('Expected group name at position 0, found');

        $reader->read();
    }

    #[Test]
    public function test_a_name_must_be_there(): void
    {
        $reader = new GroupNameReader($this->streamOf('>', 'a', ')'), self::target());

        $this->expectException(ParserException::class);
        $this->expectExceptionMessageMatches('/^Expected group name at position \d+\.$/');

        $reader->read();
    }

    #[Test]
    public function test_a_name_must_read_like_one(): void
    {
        $reader = new GroupNameReader($this->streamOf('1st', '>'), self::target());

        $this->expectException(ParserException::class);
        $this->expectExceptionMessageIsOrContains('Invalid group name "1st"');

        $reader->read();
    }

    #[Test]
    public function test_a_quoted_name_must_be_closed(): void
    {
        $reader = new GroupNameReader($this->streamOf("'", 'name', '>'), self::target());

        $this->expectException(ParserException::class);
        $this->expectExceptionMessageMatches('/^Expected closing quote "\'" for group name at position \d+\.$/');

        $reader->read(quoted: true);
    }

    #[Test]
    public function test_a_name_is_used_once_unless_duplicates_are_allowed(): void
    {
        $reader = new GroupNameReader($this->streamOf(), self::target());
        $reader->register('name', 0);

        $this->assertFalse($reader->duplicatesAllowed());

        $reader->allowDuplicates(true);
        $reader->register('name', 5);

        $reader->allowDuplicates(false);

        $this->expectException(ParserException::class);
        $this->expectExceptionMessageIsOrContains('Duplicate group name "name" at position 9.');

        $reader->register('name', 9);
    }

    #[Test]
    public function test_a_reference_does_not_claim_the_name(): void
    {
        $reader = new GroupNameReader($this->streamOf('name', '>'), self::target());

        $this->assertSame('name', $reader->read(register: false));

        // Nothing was claimed, so declaring it afterwards is fine.
        $reader->register('name', 0);
        $this->assertFalse($reader->duplicatesAllowed());
    }

    /**
     * The longest name a release takes, in code units: 32 before PCRE2
     * 10.44, 128 from it.
     *
     * Oracle (PCRE2 10.49): a name of 128 letters compiles, one of 129 is
     * refused.
     */
    #[Test]
    #[DataProvider('provideNameLengths')]
    public function test_the_longest_name_follows_the_release(string $pcreVersion, int $length): void
    {
        $this->assertSame($length, GroupNameReader::maxNameLength(new PcreTarget(80400, $pcreVersion)));
    }

    /**
     * @return iterable<string, array{pcreVersion: string, length: int}>
     */
    public static function provideNameLengths(): iterable
    {
        yield 'before 10.44' => ['pcreVersion' => '10.43', 'length' => 32];
        yield 'from 10.44' => ['pcreVersion' => '10.44', 'length' => 128];
    }

    /**
     * A name that starts with a digit is refused past the digit from PCRE2
     * 10.47, on it before.
     */
    #[Test]
    #[DataProvider('provideDigitOffsets')]
    public function test_a_name_starting_with_a_digit_is_refused_where_the_release_says(string $pcreVersion, int $offset): void
    {
        $reader = new GroupNameReader($this->streamOf('<', '1st', '>'), new PcreTarget(80500, $pcreVersion));

        $this->assertSame($offset, $reader->invalidNameOffset(1));
    }

    /**
     * @return iterable<string, array{pcreVersion: string, offset: int}>
     */
    public static function provideDigitOffsets(): iterable
    {
        yield 'before 10.47' => ['pcreVersion' => '10.46', 'offset' => 1];
        yield 'from 10.47' => ['pcreVersion' => '10.47', 'offset' => 2];
    }

    private static function target(): PcreTarget
    {
        return new PcreTarget(80500, '10.49');
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
