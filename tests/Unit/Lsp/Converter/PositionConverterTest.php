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

namespace PHPRegex\Tests\Unit\Lsp\Converter;

use PHPRegex\LanguageServer\Converter\PositionConverter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PositionConverterTest extends TestCase
{
    #[Test]
    public function test_offset_to_position_first_line(): void
    {
        $converter = new PositionConverter('hello world');

        $position = $converter->offsetToPosition(0);
        $this->assertSame(['line' => 0, 'character' => 0], $position);

        $position = $converter->offsetToPosition(5);
        $this->assertSame(['line' => 0, 'character' => 5], $position);
    }

    #[Test]
    public function test_offset_to_position_second_line(): void
    {
        $converter = new PositionConverter("hello\nworld");

        $position = $converter->offsetToPosition(6);
        $this->assertSame(['line' => 1, 'character' => 0], $position);

        $position = $converter->offsetToPosition(10);
        $this->assertSame(['line' => 1, 'character' => 4], $position);
    }

    #[Test]
    public function test_offset_to_position_multiple_lines(): void
    {
        $converter = new PositionConverter("line1\nline2\nline3");

        $position = $converter->offsetToPosition(0);
        $this->assertSame(['line' => 0, 'character' => 0], $position);

        $position = $converter->offsetToPosition(6);
        $this->assertSame(['line' => 1, 'character' => 0], $position);

        $position = $converter->offsetToPosition(12);
        $this->assertSame(['line' => 2, 'character' => 0], $position);
    }

    #[Test]
    public function test_position_to_offset(): void
    {
        $converter = new PositionConverter("hello\nworld");

        $this->assertSame(0, $converter->positionToOffset(0, 0));
        $this->assertSame(5, $converter->positionToOffset(0, 5));
        $this->assertSame(6, $converter->positionToOffset(1, 0));
        $this->assertSame(10, $converter->positionToOffset(1, 4));
    }

    #[Test]
    public function test_roundtrip_conversion(): void
    {
        $content = "<?php\npreg_match('/test/', \$t);\n";
        $converter = new PositionConverter($content);

        // Test several offsets
        for ($offset = 0; $offset < \strlen($content); $offset++) {
            $position = $converter->offsetToPosition($offset);
            $backToOffset = $converter->positionToOffset($position['line'], $position['character']);
            $this->assertSame($offset, $backToOffset, "Roundtrip failed for offset $offset");
        }
    }

    #[Test]
    public function test_get_line_content(): void
    {
        $converter = new PositionConverter("line1\nline2\nline3");

        $this->assertSame("line1\n", $converter->getLineContent(0));
        $this->assertSame("line2\n", $converter->getLineContent(1));
        $this->assertSame('line3', $converter->getLineContent(2));
    }

    #[Test]
    public function test_is_offset_in_range(): void
    {
        $converter = new PositionConverter("hello\nworld");

        $start = ['line' => 0, 'character' => 2];
        $end = ['line' => 0, 'character' => 5];

        $this->assertFalse($converter->isOffsetInRange(0, $start, $end));
        $this->assertFalse($converter->isOffsetInRange(1, $start, $end));
        $this->assertTrue($converter->isOffsetInRange(2, $start, $end));
        $this->assertTrue($converter->isOffsetInRange(3, $start, $end));
        $this->assertTrue($converter->isOffsetInRange(5, $start, $end));
        $this->assertFalse($converter->isOffsetInRange(6, $start, $end));
    }

    #[Test]
    public function test_is_position_in_byte_range(): void
    {
        $converter = new PositionConverter("hello\nworld");

        // Range from offset 2 to 5 (characters 'llo')
        $this->assertFalse($converter->isPositionInByteRange(0, 0, 2, 5));
        $this->assertFalse($converter->isPositionInByteRange(0, 1, 2, 5));
        $this->assertTrue($converter->isPositionInByteRange(0, 2, 2, 5));
        $this->assertTrue($converter->isPositionInByteRange(0, 3, 2, 5));
        $this->assertTrue($converter->isPositionInByteRange(0, 5, 2, 5));
        $this->assertFalse($converter->isPositionInByteRange(1, 0, 2, 5));
    }

    #[Test]
    public function test_empty_content(): void
    {
        $converter = new PositionConverter('');

        $position = $converter->offsetToPosition(0);
        $this->assertSame(['line' => 0, 'character' => 0], $position);
    }

    #[Test]
    public function test_single_newline(): void
    {
        $converter = new PositionConverter("\n");

        $position = $converter->offsetToPosition(0);
        $this->assertSame(['line' => 0, 'character' => 0], $position);

        $position = $converter->offsetToPosition(1);
        $this->assertSame(['line' => 1, 'character' => 0], $position);
    }

    #[Test]
    public function test_offset_to_position_counts_utf16_units(): void
    {
        // "héllo " is 7 bytes but 6 UTF-16 code units.
        $converter = new PositionConverter('héllo world');

        $this->assertSame(['line' => 0, 'character' => 6], $converter->offsetToPosition(7));
    }

    #[Test]
    public function test_offset_to_position_counts_a_surrogate_pair_twice(): void
    {
        // U+1F600 is one code point, two UTF-16 code units, four bytes.
        $converter = new PositionConverter("\u{1F600}x");

        $this->assertSame(['line' => 0, 'character' => 2], $converter->offsetToPosition(4));
        $this->assertSame(['line' => 0, 'character' => 3], $converter->offsetToPosition(5));
    }

    #[Test]
    public function test_position_to_offset_round_trips_through_utf16(): void
    {
        $converter = new PositionConverter("héllo\n\u{1F600}monde");

        $this->assertSame(7, $converter->positionToOffset(0, 6));
        $this->assertSame(11, $converter->positionToOffset(1, 2));
        $this->assertSame(['line' => 1, 'character' => 2], $converter->offsetToPosition(11));
    }

    #[Test]
    public function test_a_line_that_is_not_utf8_falls_back_to_bytes(): void
    {
        $converter = new PositionConverter("caf\xE9 bar");

        $this->assertSame(['line' => 0, 'character' => 4], $converter->offsetToPosition(4));
        $this->assertSame(4, $converter->positionToOffset(0, 4));
    }

    /**
     * LSP counts "\n", "\r\n" and a lone "\r" as a line break alike.
     */
    #[Test]
    public function test_every_line_break_the_protocol_names_ends_a_line(): void
    {
        foreach (["\n", "\r\n", "\r"] as $break) {
            $content = 'ab'.$break.'cd'.$break.'ef';
            $converter = new PositionConverter($content);
            $offset = (int) strpos($content, 'e');

            $this->assertSame(['line' => 2, 'character' => 0], $converter->offsetToPosition($offset), json_encode($break) ?: '');
            $this->assertSame(['line' => 2, 'character' => 0], PositionConverter::positionIn($content, $offset), json_encode($break) ?: '');
            $this->assertSame($offset, $converter->positionToOffset(2, 0), json_encode($break) ?: '');
        }

        // "\r\n" is one break, not two.
        $this->assertSame(['line' => 1, 'character' => 0], (new PositionConverter("a\r\nb"))->offsetToPosition(3));
    }

    /**
     * A position past the last line stands at the end of the document, as
     * the protocol reads a character past a line's end.
     */
    #[Test]
    public function test_a_position_past_the_document_is_its_end(): void
    {
        $this->assertSame(5, (new PositionConverter("ab\ncd"))->positionToOffset(9, 0));
    }
}
