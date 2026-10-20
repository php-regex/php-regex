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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Explain\HtmlExplainer;
use PHPRegex\Explain\TextExplainer;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A character above ASCII is one character under UTF, read whole by the
 * engine, and as many bytes as UTF-8 spells it without: the explanation
 * shows the character in the first case and each byte in the second.
 */
final class ExplainCharacterLiteralTest extends TestCase
{
    #[Test]
    #[DataProvider('provideLiterals')]
    public function test_a_literal_above_ascii_is_shown_as_the_engine_reads_it(string $pattern, string $shown, string $hidden, string $subject, int $matches): void
    {
        // What the engine reads the pattern as: one character, or bytes.
        $this->assertSame($matches, preg_match($pattern, $subject), 'engine');

        $tree = RegexParser::create()->parse($pattern);
        $text = (string) $tree->accept(new TextExplainer());
        $html = html_entity_decode((string) $tree->accept(new HtmlExplainer()), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        foreach (['text' => $text, 'html' => $html] as $format => $explanation) {
            $this->assertStringContainsString($shown, $explanation, $format);
            $this->assertStringNotContainsString($hidden, $explanation, $format);
            $this->assertTrue(mb_check_encoding($explanation, 'UTF-8'), $format);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, shown: string, hidden: string, subject: string, matches: int}>
     */
    public static function provideLiterals(): iterable
    {
        // "\xC3" alone is no character: under /u the pattern takes "é" whole.
        yield 'a character under u' => ['pattern' => '/^é$/u', 'shown' => "'é'", 'hidden' => '\xC3', 'subject' => 'é', 'matches' => 1];
        yield 'a character under a leading UTF option' => ['pattern' => '/(*UTF)^é$/', 'shown' => "'é'", 'hidden' => '\xC3', 'subject' => 'é', 'matches' => 1];
        yield 'a four-byte character under u' => ['pattern' => '/^😀$/u', 'shown' => "'😀'", 'hidden' => '\xF0', 'subject' => '😀', 'matches' => 1];
        // Without UTF the same text is two bytes, each matched on its own.
        yield 'the bytes of a character without u' => ['pattern' => '/^é$/', 'shown' => "'\\xC3\\xA9'", 'hidden' => "'é'", 'subject' => "\xC3\xA9", 'matches' => 1];
        yield 'one raw byte without u' => ['pattern' => "/^\xE9$/", 'shown' => "'\\xE9'", 'hidden' => "\xE9", 'subject' => "\xE9", 'matches' => 1];
        yield 'a character in a class under u' => ['pattern' => '/^[é]$/u', 'shown' => "'é'", 'hidden' => '\xC3', 'subject' => 'é', 'matches' => 1];
        yield 'the ends of a range under u' => ['pattern' => '/^[à-é]$/u', 'shown' => "from 'à' to 'é'", 'hidden' => '\xC3', 'subject' => 'è', 'matches' => 1];
        // A character that prints as nothing is spelled by its code point.
        yield 'a hidden character under u' => ['pattern' => "/^\u{200B}$/u", 'shown' => "'\\x{200B}'", 'hidden' => "\u{200B}", 'subject' => "\u{200B}", 'matches' => 1];
        yield 'a hidden character without u' => ['pattern' => "/^\u{200B}$/", 'shown' => "'\\xE2\\x80\\x8B'", 'hidden' => "\u{200B}", 'subject' => "\u{200B}", 'matches' => 1];
    }
}
