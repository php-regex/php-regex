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

namespace PHPRegex\Tests\Unit\Bridge\Infection;

use PHPRegex\Infection\Hint\HintWriter;
use PHPRegex\Infection\Hint\KillString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The kill string a mutant carries: a PHP double-quoted literal that pastes
 * into a test as is, written into the mutant as a comment the engine skips.
 */
final class KillStringTest extends TestCase
{
    #[Test]
    #[DataProvider('provideSubjects')]
    public function test_kill_string_encoding_is_a_pasteable_php_literal(string $subject, string $expected): void
    {
        $literal = KillString::encode($subject, '/');

        $this->assertNotNull($literal);
        $this->assertSame($expected, $literal);
        $this->assertSame($subject, MutatorHarness::decode($literal));
    }

    /**
     * @return iterable<string, array{subject: string, expected: string}>
     */
    public static function provideSubjects(): iterable
    {
        yield 'empty' => ['subject' => '', 'expected' => '""'];
        yield 'plain' => ['subject' => 'abc 123', 'expected' => '"abc 123"'];
        yield 'dollar' => ['subject' => '$x', 'expected' => '"\x24x"'];
        yield 'backslash' => ['subject' => 'a\\b', 'expected' => '"a\x5cb"'];
        yield 'double quote' => ['subject' => 'say "hi"', 'expected' => '"say \x22hi\x22"'];
        yield 'parentheses' => ['subject' => '(a)', 'expected' => '"\x28a\x29"'];
        yield 'braces' => ['subject' => '{a}', 'expected' => '"\x7ba\x7d"'];
        yield 'hash' => ['subject' => '#1', 'expected' => '"\x231"'];
        yield 'delimiter' => ['subject' => 'a/b', 'expected' => '"a\x2fb"'];
        yield 'newline' => ['subject' => "a\nb", 'expected' => '"a\x0ab"'];
        yield 'NUL' => ['subject' => "a\0b", 'expected' => '"a\x00b"'];
        yield 'invalid UTF-8' => ['subject' => "\xff", 'expected' => '"\xff"'];
        yield 'multibyte' => ['subject' => 'é', 'expected' => '"\xc3\xa9"'];
        yield 'delete' => ['subject' => "a\x7f", 'expected' => '"a\x7f"'];
        yield 'tilde' => ['subject' => '~', 'expected' => '"~"'];
        yield 'other punctuation stays' => ['subject' => "a.b*c?[d]-e!'", 'expected' => "\"a.b*c?[d]-e!'\""];
    }

    #[Test]
    public function test_the_delimiter_pair_is_escaped(): void
    {
        $this->assertSame('"\x7ba\x7d|"', KillString::encode('{a}|', '{'));
        $this->assertSame('"a\x7cb"', KillString::encode('a|b', '|'));
        $this->assertSame('"a\x7eb"', KillString::encode('a~b', '~'));
    }

    #[Test]
    public function test_the_strict_form_writes_every_byte_but_letters_digits_and_spaces_as_hex(): void
    {
        $literal = KillString::encode('a.b*1 [x]', '#', true);

        $this->assertSame('"a\x2eb\x2a1 \x5bx\x5d"', $literal);
        $this->assertSame('a.b*1 [x]', MutatorHarness::decode($literal));
    }

    #[Test]
    public function test_the_strict_form_keeps_every_letter_and_digit(): void
    {
        $this->assertSame('"09AZaz 5Mm"', KillString::encode('09AZaz 5Mm', '#', true));
        $this->assertSame('"\x2f\x3a\x40\x5b\x60\x7b"', KillString::encode('/:@[`{', '#', true));
    }

    #[Test]
    public function test_no_hint_above_120_encoded_bytes(): void
    {
        $this->assertSame('"'.str_repeat('0', 120).'"', KillString::encode(str_repeat('0', 120), '/'));
        $this->assertNull(KillString::encode(str_repeat('0', 121), '/'));
        // Thirty escaped bytes are 120 encoded bytes, thirty-one are not.
        $this->assertNotNull(KillString::encode(str_repeat("\n", 30), '/'));
        $this->assertNull(KillString::encode(str_repeat("\n", 31), '/'));
    }

    /**
     * @param ?string $expected the hinted pattern, null when no hint survives
     */
    #[Test]
    #[DataProvider('provideHints')]
    public function test_hint_form_per_delimiter(string $pattern, string $subject, ?string $expected): void
    {
        $this->assertSame($expected, (new HintWriter())->write($pattern, $subject));
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, expected: ?string}>
     */
    public static function provideHints(): iterable
    {
        yield 'slash' => ['pattern' => '/^a/', 'subject' => 'b', 'expected' => '/^a(?#kill: "b")/'];
        yield 'flags kept after the hint' => ['pattern' => '/^a/i', 'subject' => 'b', 'expected' => '/^a(?#kill: "b")/i'];
        yield 'tilde' => ['pattern' => '~^a~', 'subject' => 'b', 'expected' => '~^a(?#kill: "b")~'];
        yield 'braces' => ['pattern' => '{^a}', 'subject' => '}', 'expected' => '{^a(?#kill: "\x7d")}'];
        yield 'parentheses' => ['pattern' => '(^a)', 'subject' => 'b', 'expected' => '(^a(?#kill: "b"))'];
        yield 'pipe' => ['pattern' => '|^a|', 'subject' => 'a|b', 'expected' => '|^a(?#kill: "a\x7cb")|'];
        yield 'exclamation mark' => ['pattern' => '!^a!', 'subject' => 'b', 'expected' => '!^a(?#kill: "b")!'];
        yield 'top-level alternation' => ['pattern' => '/a|b/', 'subject' => 'c', 'expected' => '/a|b(?#kill: "c")/'];
        yield 'empty body' => ['pattern' => '//', 'subject' => 'c', 'expected' => '/(?#kill: "c")/'];
        yield 'hash takes the DEFINE form' => ['pattern' => '#^a#', 'subject' => 'a.b', 'expected' => '#^a(?(DEFINE)kill: "a\x2eb")#'];
        yield 'question mark has no form' => ['pattern' => '?^a?', 'subject' => 'b', 'expected' => null];
        yield 'colon has no form' => ['pattern' => ':^a:', 'subject' => 'b', 'expected' => null];
        yield 'double quote has no form' => ['pattern' => '"^a"', 'subject' => 'b', 'expected' => null];
        yield 'open \Q swallows the hint' => ['pattern' => '/a\Qb/', 'subject' => 'c', 'expected' => null];
        yield 'closed \Q' => ['pattern' => '/a\Qb\E/', 'subject' => 'c', 'expected' => '/a\Qb\E(?#kill: "c")/'];
        yield 'x comment at the end takes a newline' => ['pattern' => '/a # tail/x', 'subject' => 'b', 'expected' => "/a # tail\n(?#kill: \"b\")/x"];
        yield 'x without a trailing comment' => ['pattern' => '/a b/x', 'subject' => 'ab', 'expected' => '/a b(?#kill: "ab")/x'];
        yield 'too long' => ['pattern' => '/a/', 'subject' => str_repeat('a', 121), 'expected' => null];
        yield 'leading whitespace' => ['pattern' => ' /a/', 'subject' => 'b', 'expected' => ' /a(?#kill: "b")/'];
    }

    #[Test]
    #[DataProvider('provideInertHints')]
    public function test_hinted_pattern_matches_like_the_pattern(string $pattern, string $subject): void
    {
        $hinted = (new HintWriter())->write($pattern, $subject);
        $this->assertNotNull($hinted);

        foreach ([$subject, '', 'a', "b\n", 'ab', 'ba'] as $probe) {
            $this->assertSame(preg_match($pattern, $probe, $expected), preg_match($hinted, $probe, $actual), $probe);
            $this->assertSame($expected, $actual, $probe);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideInertHints(): iterable
    {
        yield 'slash' => ['pattern' => '/^(a)(b)?/', 'subject' => 'x)y'];
        yield 'hash' => ['pattern' => '#^(a)(b)?#', 'subject' => 'x)y[('];
        yield 'alternation' => ['pattern' => '/(a)|b/', 'subject' => 'c'];
        yield 'extended' => ['pattern' => "/a # tail\n | b # other/x", 'subject' => 'z z'];
        yield 'caseless' => ['pattern' => '/A/i', 'subject' => 'Q'];
        yield 'unicode' => ['pattern' => '/^\w/u', 'subject' => 'é'];
    }
}
