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

namespace PHPRegex\Tests\Unit\Automata;

use PHPRegex\Automata\TrivialMatch;
use PHPRegex\Automata\TrivialMatchKind;
use PHPRegex\Linter\Formatter\ReportSpelling;
use PHPRegex\Parser\Internal\DisplayEscaper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The PHP string literal a trivial match is written with is the literal's
 * own bytes when PHP reads it back, and it never holds a character that
 * moves or hides text when printed: a C1 control, a bidirectional control,
 * the zero-width space or a line or paragraph separator is spelled
 * "\u{HEX}" inside the double-quoted string. Printable UTF-8 is kept.
 */
final class TrivialMatchTest extends TestCase
{
    /**
     * The C1 controls U+0080 to U+009F, the bidirectional controls, the
     * zero-width space and the line and paragraph separators.
     */
    private const HIDDEN = '/[\x{80}-\x{9F}\x{61C}\x{200B}\x{200E}\x{200F}\x{2028}-\x{202E}\x{2066}-\x{2069}]/u';

    #[Test]
    #[DataProvider('provideHiddenCharacters')]
    public function test_php_string_spells_a_hidden_character_as_its_code_point(string $literal, int $codePoint): void
    {
        $php = self::stringOf((new TrivialMatch(TrivialMatchKind::StartsWith, [$literal]))->phpExpression('$subject'));

        $this->assertSame(0, preg_match(self::HIDDEN, $php), $php);
        $this->assertSame(1, preg_match('/\\\u\{([0-9A-Fa-f]+)\}/', $php, $escape), $php);
        $this->assertSame($codePoint, hexdec($escape[1]), $php);
        $this->assertSame($literal, self::readPhpLiteral($php));
    }

    /**
     * @return iterable<string, array{literal: string, codePoint: int}>
     */
    public static function provideHiddenCharacters(): iterable
    {
        yield 'right-to-left override' => ['literal' => "\u{202E}ab", 'codePoint' => 0x202E];
        yield 'next line, a C1 control' => ['literal' => "\u{85}ab", 'codePoint' => 0x85];
        yield 'zero-width space' => ['literal' => "a\u{200B}b", 'codePoint' => 0x200B];
        yield 'line separator' => ['literal' => "ab\u{2028}", 'codePoint' => 0x2028];
        yield 'paragraph separator' => ['literal' => "ab\u{2029}", 'codePoint' => 0x2029];
        yield 'first C1 control' => ['literal' => "\u{80}ab", 'codePoint' => 0x80];
        yield 'last C1 control' => ['literal' => "ab\u{9F}", 'codePoint' => 0x9F];
        yield 'arabic letter mark' => ['literal' => "\u{61C}ab", 'codePoint' => 0x61C];
        yield 'left-to-right mark' => ['literal' => "a\u{200E}b", 'codePoint' => 0x200E];
        yield 'first isolate' => ['literal' => "a\u{2066}b", 'codePoint' => 0x2066];
        yield 'pop directional isolate' => ['literal' => "a\u{2069}b", 'codePoint' => 0x2069];
        yield 'printable UTF-8 around it' => ['literal' => "é\u{202E}é", 'codePoint' => 0x202E];
        // A Unicode format character (general category Cf) moves or hides
        // text as a bidirectional control does.
        yield 'byte order mark' => ['literal' => "\u{FEFF}ab", 'codePoint' => 0xFEFF];
        yield 'zero-width non-joiner' => ['literal' => "a\u{200C}b", 'codePoint' => 0x200C];
        yield 'zero-width joiner' => ['literal' => "a\u{200D}b", 'codePoint' => 0x200D];
        yield 'word joiner' => ['literal' => "a\u{2060}b", 'codePoint' => 0x2060];
        yield 'invisible plus' => ['literal' => "a\u{2064}b", 'codePoint' => 0x2064];
        yield 'soft hyphen' => ['literal' => "a\u{AD}b", 'codePoint' => 0xAD];
        yield 'mongolian vowel separator' => ['literal' => "a\u{180E}b", 'codePoint' => 0x180E];
        yield 'tag latin capital letter A' => ['literal' => "a\u{E0041}b", 'codePoint' => 0xE0041];
        yield 'arabic number sign' => ['literal' => "\u{600}ab", 'codePoint' => 0x600];
    }

    /**
     * The literal is spelled as PHP reads it back: printable UTF-8 left as
     * it is, the bytes of text that is no valid UTF-8 in hex, and the
     * characters a double-quoted string reads as syntax escaped.
     */
    #[Test]
    #[DataProvider('providePlainLiterals')]
    public function test_php_string_keeps_what_prints_safely(string $literal, string $php): void
    {
        $this->assertSame($php, self::stringOf((new TrivialMatch(TrivialMatchKind::StartsWith, [$literal]))->phpExpression('$subject')));
        $this->assertSame($literal, self::readPhpLiteral($php));
    }

    /**
     * @return iterable<string, array{literal: string, php: string}>
     */
    public static function providePlainLiterals(): iterable
    {
        yield 'printable ASCII' => ['literal' => "it's", 'php' => "'it\\'s'"];
        yield 'printable UTF-8' => ['literal' => 'éab', 'php' => '"éab"'];
        // U+2065 is unassigned and U+2070 a superscript: neither is a
        // format character, so both print as they are.
        yield 'a character next to the hidden ones' => ['literal' => "\u{A0}\u{2030}\u{2065}\u{2070}", 'php' => "\"\u{A0}\u{2030}\u{2065}\u{2070}\""];
        yield 'syntax and C0 controls' => ['literal' => "é\$x\"\\\n\x01", 'php' => '"é\$x\"\\\\\n\x01"'];
        yield 'not valid UTF-8' => ['literal' => "\xE2\x80\xAE\xFF", 'php' => '"\xE2\x80\xAE\xFF"'];
        // A tab and a carriage return keep their letter escapes, as a line
        // feed does, rather than "\x09" and "\x0D".
        yield 'tab and carriage return' => ['literal' => "é\t\r", 'php' => '"é\t\r"'];
        // The space and the tilde are the printable bytes around the
        // controls: they stay as they are; DEL, the control after the
        // tilde, is spelled in hex.
        yield 'space, tilde and delete' => ['literal' => "é ~\x7F", 'php' => '"é ~\x7F"'];
        // In text that is no valid UTF-8, only the bytes from 0x80 are
        // spelled in hex: the ASCII letters around them stay.
        yield 'ASCII letters in text that is no valid UTF-8' => ['literal' => "ab\xFFc", 'php' => '"ab\xFFc"'];
        // A no-break space, a per mille sign and a letter are no format
        // characters: they print as themselves.
        yield 'printable characters next to the format characters' => ['literal' => "\u{A0}\u{2030}\u{E9}", 'php' => "\"\u{A0}\u{2030}\u{E9}\""];
    }

    #[Test]
    public function test_php_string_spells_every_literal_of_one_of(): void
    {
        $expression = (new TrivialMatch(TrivialMatchKind::OneOf, ["\u{202E}a", "b\u{2028}"]))->phpExpression('$subject');

        $this->assertSame(0, preg_match(self::HIDDEN, $expression), $expression);
        $this->assertSame(1, preg_match('/^in_array\(\$subject, \[(".*"), (".*")\], true\)$/', $expression, $match), $expression);
        $this->assertSame("\u{202E}a", self::readPhpLiteral($match[1]));
        $this->assertSame("b\u{2028}", self::readPhpLiteral($match[2]));
    }

    /**
     * The PHP string of a trivial match, the pattern display and the text
     * of a report hide the same code points: every C1 control, every
     * Unicode format character (general category Cf, as PCRE2 reads
     * \p{Cf}), the line separator and the paragraph separator, and none of
     * the characters next to them that print as themselves. The set is the
     * engine's: every code point it holds is tried, and a few it does not.
     */
    #[Test]
    public function test_php_string_hides_the_code_points_the_display_hides(): void
    {
        $expected = self::hiddenCodePoints();
        $sample = [...$expected, 0xA0, 0xE9, 0x2030, 0x2065, 0x2070, 0x200A, 0x2010, 0x2027, 0x202F, 0xFEFE, 0xFF00, 0xFFFD, 0x1F600, 0xE0080, 0xE0100];
        sort($sample);

        $hidden = ['php string' => [], 'pattern display' => [], 'report text' => []];
        foreach ($sample as $codePoint) {
            $character = mb_chr($codePoint, 'UTF-8');
            $this->assertIsString($character);
            $php = self::stringOf((new TrivialMatch(TrivialMatchKind::StartsWith, ["a{$character}b"]))->phpExpression('$subject'));
            if (self::hides($php, $character, \sprintf('/\\\\u\{0*%X\}/i', $codePoint))) {
                $hidden['php string'][] = \sprintf('U+%04X', $codePoint);
            }
            if (self::hides(DisplayEscaper::escape("/a{$character}b/u"), $character, \sprintf('/\\\\x\{0*%X\}/i', $codePoint))) {
                $hidden['pattern display'][] = \sprintf('U+%04X', $codePoint);
            }
            if (self::hides(ReportSpelling::displayText("a{$character}b"), $character, \sprintf('/\\\\x\{0*%X\}/i', $codePoint))) {
                $hidden['report text'][] = \sprintf('U+%04X', $codePoint);
            }
        }

        $named = array_map(static fn (int $codePoint): string => \sprintf('U+%04X', $codePoint), $expected);
        $this->assertSame(['php string' => $named, 'pattern display' => $named, 'report text' => $named], $hidden);
    }

    /**
     * Every code point PCRE2 reads as a C1 control, a format character, a
     * line separator or a paragraph separator, in order.
     *
     * @return list<int>
     */
    private static function hiddenCodePoints(): array
    {
        $text = '';
        for ($codePoint = 0x80; $codePoint <= 0x10FFFF; $codePoint++) {
            if ($codePoint < 0xD800 || $codePoint > 0xDFFF) {
                $text .= mb_chr($codePoint, 'UTF-8');
            }
        }

        self::assertNotFalse(preg_match_all('/[\x{80}-\x{9F}\p{Cf}\p{Zl}\p{Zp}]/u', $text, $matches));

        return array_map(static fn (string $character): int => (int) mb_ord($character, 'UTF-8'), $matches[0]);
    }

    /**
     * Whether the text spells the character with the escape and never
     * writes it raw.
     */
    private static function hides(string $text, string $character, string $escape): bool
    {
        return !str_contains($text, $character) && 1 === preg_match($escape, $text);
    }

    /**
     * The literal of "str_starts_with($subject, <literal>)".
     */
    private static function stringOf(string $expression): string
    {
        self::assertSame(1, preg_match('/^str_starts_with\(\$subject, (.*)\)$/s', $expression, $match), $expression);

        return $match[1];
    }

    /**
     * Reads a literal the way PHP reads it. The tokenizer first proves the
     * text is one constant string, with no interpolation and no code, so
     * that the eval() below can only produce that string.
     */
    private static function readPhpLiteral(string $php): string
    {
        $tokens = token_get_all('<?php '.$php.';');

        self::assertCount(3, $tokens, $php);
        self::assertIsArray($tokens[1]);
        self::assertSame(\T_CONSTANT_ENCAPSED_STRING, $tokens[1][0], 'not a constant string: '.$php);
        self::assertSame($php, $tokens[1][1]);

        $value = eval('return '.$php.';');
        self::assertIsString($value);

        return $value;
    }
}
