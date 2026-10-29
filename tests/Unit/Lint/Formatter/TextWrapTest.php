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

namespace PHPRegex\Tests\Unit\Lint\Formatter;

use PHPRegex\Linter\Formatter\TextWrap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A line of the console report folds on its spaces into the room it has,
 * counted in display columns; a double-quoted span is one word, as is a
 * word wider than the room, which overflows rather than breaks.
 */
final class TextWrapTest extends TestCase
{
    /**
     * @return iterable<string, array{text: string, room: int, expected: list<string>}>
     */
    public static function provideTexts(): iterable
    {
        yield 'folds on spaces' => ['text' => 'aaa bbb ccc', 'room' => 7, 'expected' => ['aaa bbb', 'ccc']];
        yield 'a word exactly the room fills its line' => ['text' => 'aaaa bb', 'room' => 4, 'expected' => ['aaaa', 'bb']];
        yield 'a line exactly the room stays whole' => ['text' => 'aaa bbb', 'room' => 7, 'expected' => ['aaa bbb']];
        yield 'a quoted span is one word' => ['text' => 'Attack: "a b" x n . "!"', 'room' => 12, 'expected' => ['Attack:', '"a b" x n .', '"!"']];
        yield 'a quoted span wider than the room overflows whole' => ['text' => 'at "a b c d" end', 'room' => 6, 'expected' => ['at', '"a b c d"', 'end']];
        yield 'an unbalanced quote is an ordinary character' => ['text' => 'say "hi there', 'room' => 8, 'expected' => ['say "hi', 'there']];
        yield 'an escaped quote does not end the span' => ['text' => 'Attack: "a \"b c\" d" x n', 'room' => 10, 'expected' => ['Attack:', '"a \"b c\" d"', 'x n']];
        yield 'an escaped backslash before the closing quote still closes it' => ['text' => 'Attack: "\\\\" x n . "!"', 'room' => 12, 'expected' => ['Attack: "\\\\"', 'x n . "!"']];
        yield 'an odd run of three backslashes escapes the quote' => ['text' => 'x "a \\\\\\" b" y', 'room' => 8, 'expected' => ['x', '"a \\\\\\" b"', 'y']];
        yield 'the quote right after an escaped one closes the span' => ['text' => '"a \\"" b c', 'room' => 4, 'expected' => ['"a \\""', 'b c']];
        yield 'a run of spaces is kept' => ['text' => 'a  b   c', 'room' => 10, 'expected' => ['a  b   c']];
        yield 'a run of spaces folds at its first space' => ['text' => 'aaa  bbb', 'room' => 5, 'expected' => ['aaa ', 'bbb']];
        yield 'leading spaces are kept' => ['text' => '  a b', 'room' => 10, 'expected' => ['  a b']];
        yield 'trailing spaces are kept past the room' => ['text' => 'aaaa  ', 'room' => 4, 'expected' => ['aaaa  ']];
        yield 'spaces alone are kept' => ['text' => '   ', 'room' => 20, 'expected' => ['   ']];
        yield 'a leading space counts toward the fold' => ['text' => ' aaa bb', 'room' => 6, 'expected' => [' aaa', 'bb']];
        yield 'an extra space counts toward the fold' => ['text' => 'aa  bb', 'room' => 5, 'expected' => ['aa ', 'bb']];
        yield 'a fold after a run of spaces consumes one space' => ['text' => 'aaaa  bbbb', 'room' => 4, 'expected' => ['aaaa ', 'bbbb']];
        yield 'a word wider than the room stays whole' => ['text' => 'a bbbbbbbbbb c', 'room' => 5, 'expected' => ['a', 'bbbbbbbbbb', 'c']];
        yield 'width counts display columns' => ['text' => 'éé 漢漢 x', 'room' => 4, 'expected' => ['éé', '漢漢', 'x']];
        yield 'no room does not loop' => ['text' => 'a b', 'room' => 0, 'expected' => ['a', 'b']];
        yield 'empty text is one empty line' => ['text' => '', 'room' => 10, 'expected' => ['']];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideTexts')]
    public function test_lines_fold_into_the_room(string $text, int $room, array $expected): void
    {
        $this->assertSame($expected, TextWrap::lines($text, $room));
    }
}
