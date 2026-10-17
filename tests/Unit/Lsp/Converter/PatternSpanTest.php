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

use PHPRegex\LanguageServer\Converter\PatternSpan;
use PHPRegex\LanguageServer\Converter\PositionConverter;
use PHPRegex\LanguageServer\Document\RegexOccurrence;
use PHPRegex\Linter\Extraction\PhpStringLiteral;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where a cursor stands in the pattern: the inverse of the spelling table
 * the span places diagnostics through. A document byte offset maps to the
 * pattern byte the cursor has fully passed — an escape the cursor stands
 * inside is not passed, so it maps down to the byte before it — and an
 * offset outside the literal maps to nothing.
 *
 * The literal sits at document offset 16 ("<?php\n" then ten spaces).
 */
final class PatternSpanTest extends TestCase
{
    private const LITERAL_AT = 16;

    /**
     * The cursor, one row per place it can stand: $at counts bytes from the
     * literal's opening quote, 0 the quote itself.
     *
     * @return iterable<string, array{literal: string, at: int, expected: int|null}>
     */
    public static function provideCursorOffsets(): iterable
    {
        // Spelling [1, 2, 3, 7, 8]: "/x[" decoded from "/x\x5b/".
        yield 'hex escape — the opening quote' => ['literal' => '"/x\\x5b/"', 'at' => 0, 'expected' => 0];
        yield 'hex escape — the opening delimiter' => ['literal' => '"/x\\x5b/"', 'at' => 1, 'expected' => 0];
        yield 'hex escape — the byte before the escape' => ['literal' => '"/x\\x5b/"', 'at' => 2, 'expected' => 1];
        yield 'hex escape — on its backslash' => ['literal' => '"/x\\x5b/"', 'at' => 3, 'expected' => 2];
        yield 'hex escape — inside it, after the x' => ['literal' => '"/x\\x5b/"', 'at' => 4, 'expected' => 2];
        yield 'hex escape — inside it, after the 5' => ['literal' => '"/x\\x5b/"', 'at' => 5, 'expected' => 2];
        yield 'hex escape — on its last character' => ['literal' => '"/x\\x5b/"', 'at' => 6, 'expected' => 2];
        yield 'hex escape — the closing delimiter' => ['literal' => '"/x\\x5b/"', 'at' => 7, 'expected' => 3];
        yield 'hex escape — the closing quote' => ['literal' => '"/x\\x5b/"', 'at' => 8, 'expected' => 4];
        yield 'hex escape — before the literal' => ['literal' => '"/x\\x5b/"', 'at' => -1, 'expected' => null];
        yield 'hex escape — past the literal' => ['literal' => '"/x\\x5b/"', 'at' => 9, 'expected' => null];

        // Spelling [1, 2, 2, 8, 9]: "/é/" decoded from "/\u{e9}/".
        yield 'unicode escape — the opening delimiter' => ['literal' => '"/\\u{e9}/"', 'at' => 1, 'expected' => 0];
        yield 'unicode escape — on its backslash' => ['literal' => '"/\\u{e9}/"', 'at' => 2, 'expected' => 1];
        yield 'unicode escape — inside it, after the u' => ['literal' => '"/\\u{e9}/"', 'at' => 3, 'expected' => 1];
        yield 'unicode escape — inside it, after the {' => ['literal' => '"/\\u{e9}/"', 'at' => 4, 'expected' => 1];
        yield 'unicode escape — inside it, after the e' => ['literal' => '"/\\u{e9}/"', 'at' => 5, 'expected' => 1];
        yield 'unicode escape — inside it, after the 9' => ['literal' => '"/\\u{e9}/"', 'at' => 6, 'expected' => 1];
        yield 'unicode escape — on its closing brace' => ['literal' => '"/\\u{e9}/"', 'at' => 7, 'expected' => 1];
        yield 'unicode escape — the closing delimiter' => ['literal' => '"/\\u{e9}/"', 'at' => 8, 'expected' => 3];
        yield 'unicode escape — the closing quote' => ['literal' => '"/\\u{e9}/"', 'at' => 9, 'expected' => 4];

        // Spelling [1, 2, 3, 4, 5]: "/é/" spelled raw — each byte its own.
        yield 'raw multibyte — on its first byte' => ['literal' => '"/é/"', 'at' => 2, 'expected' => 1];
        yield 'raw multibyte — on its second byte' => ['literal' => '"/é/"', 'at' => 3, 'expected' => 2];
        yield 'raw multibyte — the closing delimiter' => ['literal' => '"/é/"', 'at' => 4, 'expected' => 3];
        yield 'raw multibyte — the closing quote' => ['literal' => '"/é/"', 'at' => 5, 'expected' => 4];

        // Spelling [1, 2, 3, 4, 5, 6]: "/ab/i", the modifiers included.
        yield 'modifiers — the closing delimiter' => ['literal' => '"/ab/i"', 'at' => 4, 'expected' => 3];
        yield 'modifiers — on the modifier' => ['literal' => '"/ab/i"', 'at' => 5, 'expected' => 4];
        yield 'modifiers — the closing quote' => ['literal' => '"/ab/i"', 'at' => 6, 'expected' => 5];
    }

    #[Test]
    #[DataProvider('provideCursorOffsets')]
    public function test_a_document_offset_maps_to_the_pattern_byte_the_cursor_passed(string $literal, int $at, ?int $expected): void
    {
        $this->assertSame($expected, self::span($literal)->patternOffsetAt(self::LITERAL_AT + $at));
    }

    /**
     * The inverse walks the same table the forward map trusts: at every
     * offset of the literal it answers the number of pattern bytes whose
     * spelling the cursor has fully passed — an escape shared by several
     * bytes passes them together, a byte the cursor stands inside is not
     * passed. The rule is recomputed here from the table itself.
     *
     * @return iterable<string, array{literal: string}>
     */
    public static function provideLiteralsForTheWalk(): iterable
    {
        yield 'hexadecimal escape' => ['literal' => '"/x\\x5b/"'];
        yield 'unicode escape' => ['literal' => '"/\\u{e9}/"'];
        yield 'raw multibyte' => ['literal' => '"/é/"'];
        yield 'plain' => ['literal' => '"/ab/i"'];
    }

    #[Test]
    #[DataProvider('provideLiteralsForTheWalk')]
    public function test_the_inverse_follows_the_spelling_table_at_every_offset(string $literal): void
    {
        $pattern = (string) PhpStringLiteral::decode($literal);
        $spelling = PhpStringLiteral::spellingOffsets($literal);
        $this->assertIsArray($spelling);
        $this->assertCount(\strlen($pattern) + 1, $spelling);

        // Where each pattern byte's spelling ends: at the next entry the
        // table holds further right, the closing quote's offset included.
        $ends = [];
        foreach ($spelling as $offset => $startsAt) {
            $end = \strlen($literal) - 1;
            for ($after = $offset + 1; $after < \count($spelling); $after++) {
                if ($spelling[$after] > $startsAt) {
                    $end = $spelling[$after];

                    break;
                }
            }
            $ends[$offset] = $end;
        }

        $span = self::span($literal);
        for ($at = 0; $at < \strlen($literal); $at++) {
            $passed = 0;
            foreach ($ends as $offset => $end) {
                if ($offset < \strlen($pattern) && $end <= $at) {
                    $passed++;
                }
            }

            $this->assertSame(
                $passed,
                $span->patternOffsetAt(self::LITERAL_AT + $at),
                \sprintf('The cursor at byte %d of %s', $at, $literal),
            );
        }
    }

    /**
     * The span of a literal written on line 1 from column 10.
     */
    private static function span(string $literal): PatternSpan
    {
        $content = "<?php\n".str_repeat(' ', 10).$literal.";\n";
        $positions = new PositionConverter($content);
        $offset = \strlen("<?php\n") + 10;

        return PatternSpan::of(new RegexOccurrence(
            pattern: (string) PhpStringLiteral::decode($literal),
            start: $positions->offsetToPosition($offset),
            end: $positions->offsetToPosition($offset + \strlen($literal)),
            byteOffset: $offset,
            literal: $literal,
        ), $positions);
    }
}
