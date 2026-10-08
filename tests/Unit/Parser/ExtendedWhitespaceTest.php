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

use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Under "x", PCRE skips Pattern_White_Space, not only ASCII whitespace: in
 * UTF mode U+0085, U+200E, U+200F, U+2028 and U+2029 too, and byte 0x85
 * without it (preg_match("/^a\u{2028}b$/xu", "ab") === 1). U+00A0 is not
 * among them.
 */
final class ExtendedWhitespaceTest extends TestCase
{
    #[Test]
    #[DataProvider('provideSkippedCharacters')]
    public function test_extended_mode_skips_pattern_white_space(string $pattern): void
    {
        $this->assertSame(1, preg_match($pattern, 'ab'));

        $ast = Regex::create(['cache' => null])->parse($pattern);

        $this->assertInstanceOf(SequenceNode::class, $ast->pattern);
        $values = array_map(
            static fn ($node): string => $node instanceof LiteralNode ? $node->value : '',
            $ast->pattern->children,
        );
        $this->assertSame(['a', 'b'], array_values(array_filter($values, static fn (string $value): bool => '' !== $value)));
    }

    #[Test]
    public function test_extended_mode_keeps_a_no_break_space(): void
    {
        $this->assertSame(0, preg_match("/^a\u{a0}b$/xu", 'ab'));

        $ast = Regex::create(['cache' => null])->parse("/a\u{a0}b/xu");

        $this->assertInstanceOf(SequenceNode::class, $ast->pattern);
        $this->assertCount(3, $ast->pattern->children);
    }

    /**
     * Without UTF mode PCRE reads bytes: under "x" it skips a 0x85 byte
     * inside a multibyte character too, outside a class, a quote and a group
     * turning "x" off.
     */
    #[Test]
    #[DataProvider('provideNextLineBytesInsideACharacter')]
    public function test_extended_mode_skips_the_next_line_byte_inside_a_character_without_utf(string $pattern, string $subject, string $literals): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49.
        $this->assertSame(1, preg_match($pattern, $subject));

        $ast = Regex::create(['cache' => null])->parse($pattern);

        $this->assertSame(bin2hex($literals), bin2hex(self::literals($ast->pattern)));
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, literals: string}>
     */
    public static function provideNextLineBytesInsideACharacter(): iterable
    {
        yield 'two-byte character' => ['pattern' => '/^Å$/x', 'subject' => "\xC3", 'literals' => "\xC3"];
        yield 'three-byte character holding two' => ['pattern' => "/^\u{2145}$/x", 'subject' => "\xE2", 'literals' => "\xE2"];
        yield 'quantified character' => ['pattern' => '/^Å+$/x', 'subject' => "\xC3\xC3", 'literals' => "\xC3"];
        yield 'character beside text' => ['pattern' => '/^aÅb$/x', 'subject' => "a\xC3b", 'literals' => "a\xC3b"];
        yield 'kept in a class' => ['pattern' => '/^[Å]+$/x', 'subject' => "\xC3\x85", 'literals' => "\xC3\x85"];
        yield 'kept in a quote' => ['pattern' => '/^\QÅ\E$/x', 'subject' => "\xC3\x85", 'literals' => "\xC3\x85"];
        yield 'kept where x is turned off' => ['pattern' => '/^(?-x:Å)$/x', 'subject' => "\xC3\x85", 'literals' => "\xC3\x85"];
        yield 'kept in UTF mode' => ['pattern' => '/^Å$/xu', 'subject' => 'Å', 'literals' => 'Å'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideSkippedCharacters(): iterable
    {
        yield 'next line in UTF mode' => ['pattern' => "/a\u{85}b/xu"];
        yield 'left-to-right mark' => ['pattern' => "/a\u{200e}b/xu"];
        yield 'right-to-left mark' => ['pattern' => "/a\u{200f}b/xu"];
        yield 'line separator' => ['pattern' => "/a\u{2028}b/xu"];
        yield 'paragraph separator' => ['pattern' => "/a\u{2029}b/xu"];
        yield 'next-line byte without UTF' => ['pattern' => "/a\x85b/x"];
    }

    private static function literals(NodeInterface $node): string
    {
        $text = $node instanceof LiteralNode ? $node->value : '';
        foreach ($node->getChildren() as $child) {
            $text .= self::literals($child);
        }

        return $text;
    }
}
