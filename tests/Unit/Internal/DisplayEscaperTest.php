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

namespace PHPRegex\Tests\Unit\Internal;

use PHPRegex\Parser\Internal\DisplayEscaper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DisplayEscaperTest extends TestCase
{
    /**
     * The escape map is built once per process; each test builds it again,
     * so what it checks does not depend on a test run before it.
     */
    protected function setUp(): void
    {
        (new \ReflectionProperty(DisplayEscaper::class, 'escapes'))->setValue(null, []);
    }

    #[Test]
    #[DataProvider('provideEscapedText')]
    public function test_escape_only_rewrites_unprintable_bytes(string $text, string $expected): void
    {
        $this->assertSame($expected, DisplayEscaper::escape($text));
    }

    /**
     * A displayed pattern pasted back matches what the pattern matched,
     * where PCRE reads the byte in context: after an escaping backslash the
     * byte's escape takes that backslash's place, and inside \Q..\E, where
     * an escape would be literal text, the quote is closed around it.
     *
     * @return iterable<string, array{pattern: string, subject: string, shown: string}>
     */
    public static function provideBytesInContext(): iterable
    {
        yield 'escaped control byte' => ['pattern' => "/a\\\x01b/", 'subject' => "a\x01b", 'shown' => '/a\x01b/'];
        yield 'escaped newline' => ['pattern' => "/a\\\nb/", 'subject' => "a\nb", 'shown' => '/a\nb/'];
        yield 'escaped control byte in a class' => ['pattern' => "/[\\\x01]/", 'subject' => "\x01", 'shown' => '/[\x01]/'];
        yield 'control byte inside \Q..\E' => ['pattern' => "/\\Q\x01\\E/", 'subject' => "\x01", 'shown' => '/\Q\E\x01\Q\E/'];
        yield 'tab inside \Q..\E' => ['pattern' => "/\\Q\t\\E/", 'subject' => "\t", 'shown' => '/\Q\E\t\Q\E/'];
        yield 'invalid UTF-8 inside and after \Q..\E' => ['pattern' => "/\\Q\xFF\\E\xC3\xA9/", 'subject' => "\xFF\xC3\xA9", 'shown' => '/\Q\E\xFF\Q\E\xC3\xA9/'];
        // An escaped backslash, then the byte: both stay.
        yield 'escaped backslash before a control byte' => ['pattern' => "/\\\\\x01/", 'subject' => "\\\x01", 'shown' => '/\\\\\x01/'];
        // Inside \Q..\E only "\E" ends the quote: a backslash before another
        // letter, or an "E" after another character, is quoted text.
        yield 'backslash inside \Q..\E before a control byte' => ['pattern' => "/\\Q\\a\x01\\E/", 'subject' => "\\a\x01", 'shown' => '/\Q\a\E\x01\Q\E/'];
        yield 'letter E inside \Q..\E before a control byte' => ['pattern' => "/\\QaE\x01\\E/", 'subject' => "aE\x01", 'shown' => '/\QaE\E\x01\Q\E/'];
    }

    #[Test]
    #[DataProvider('provideBytesInContext')]
    public function test_a_displayed_pattern_reads_back_in_context(string $pattern, string $subject, string $shown): void
    {
        // Oracle: the pattern and the spelling both match the subject.
        $this->assertSame(1, preg_match($pattern, $subject));
        $this->assertSame(1, preg_match($shown, $subject));

        $this->assertSame($shown, DisplayEscaper::escape($pattern));
    }

    /**
     * Inside a class "(?#" is plain text and a backslash before a byte is an
     * escape; outside, "(?#...)" is a comment that reads no escape. Each row
     * puts a backslash and a raw control byte where the context decides how
     * it is spelled. A "]" at the start of a class body is literal, "\E" and
     * empty "\Q\E" before or after the "^" included; a POSIX class
     * "[:...:]" does not close the class; a "[" that opens no POSIX class is
     * a literal. "[.x.]" and "[=x=]" PCRE refuses, and so does a class left
     * open: the spelling is refused alike.
     *
     * @return iterable<string, array{pattern: string, shown: string, compiles: bool}>
     */
    public static function provideClassContexts(): iterable
    {
        yield '\Q\E before a literal ]' => ['pattern' => "/[\\Q\\E](?#\\\x01)]/", 'shown' => '/[\Q\E](?#\x01)]/', 'compiles' => true];
        yield '\E before a literal ]' => ['pattern' => "/[\\E](?#\\\x01)]/", 'shown' => '/[\E](?#\x01)]/', 'compiles' => true];
        yield 'literal ] after ^' => ['pattern' => "/[^](?#\\\x01)]/", 'shown' => '/[^](?#\x01)]/', 'compiles' => true];
        yield '\E before ^ and a literal ]' => ['pattern' => "/[\\E^](?#\\\x01)]/", 'shown' => '/[\E^](?#\x01)]/', 'compiles' => true];
        yield '\Q\E after ^ before a literal ]' => ['pattern' => "/[^\\Q\\E](?#\\\x01)]/", 'shown' => '/[^\Q\E](?#\x01)]/', 'compiles' => true];
        yield 'empty quotes on both sides of ^' => ['pattern' => "/[\\E\\Q\\E^\\E](?#\\\x01)]/", 'shown' => '/[\E\Q\E^\E](?#\x01)]/', 'compiles' => true];
        yield 'POSIX class inside a class' => ['pattern' => "/[[:alpha:](?#\\\x01)]/", 'shown' => '/[[:alpha:](?#\x01)]/', 'compiles' => true];
        yield 'POSIX class, then a ] and a quote' => ['pattern' => "/[[:alpha:](?#]\\Q)\x01\\E/", 'shown' => '/[[:alpha:](?#]\Q)\E\x01\Q\E/', 'compiles' => true];
        yield '[: closed by ] first is a literal' => ['pattern' => "/[[:a](?#\\\x01)/", 'shown' => '/[[:a](?#\\\x01)/', 'compiles' => true];
        yield '[: followed by another [: is a literal' => ['pattern' => "/[[:a[:alpha:]](?#\\\x01)/", 'shown' => '/[[:a[:alpha:]](?#\\\x01)/', 'compiles' => true];
        yield 'escaped ] inside [: is skipped' => ['pattern' => "/[[:\\]x](?#\\\x01)/", 'shown' => '/[[:\]x](?#\\\x01)/', 'compiles' => true];
        yield '[ with no POSIX terminator is a literal' => ['pattern' => "/[a[b](?#\\\x01)/", 'shown' => '/[a[b](?#\\\x01)/', 'compiles' => true];
        yield 'collating element' => ['pattern' => "/[[.x.]\x01]/", 'shown' => '/[[.x.]\x01]/', 'compiles' => false];
        yield 'equivalence class' => ['pattern' => "/[[=x=]\x01]/", 'shown' => '/[[=x=]\x01]/', 'compiles' => false];
        yield 'POSIX class never closed' => ['pattern' => "/[[:abc\x01/", 'shown' => '/[[:abc\x01/', 'compiles' => false];
        // The class body starts after "\Q\E": an "a" there is no literal "]",
        // so the "]" after it closes the class.
        yield '\Q\E before a letter, then ]' => ['pattern' => "/[\\Q\\Ea](?#\\\x01)/", 'shown' => '/[\Q\Ea](?#\\\x01)/', 'compiles' => true];
        // A "[" followed by a letter opens no POSIX class, even with that
        // letter and a "]" later; "[:" right before "]" opens none either.
        yield '[ followed by a letter, the letter before ] later' => ['pattern' => "/[a[bxb](?#\\\x01)/", 'shown' => '/[a[bxb](?#\\\x01)/', 'compiles' => true];
        yield '[: right before ]' => ['pattern' => "/[[:](?#\\\x01)/", 'shown' => '/[[:](?#\\\x01)/', 'compiles' => true];
        yield '[: right before ], a :] later' => ['pattern' => "/[[:]x:](?#\\\x01)/", 'shown' => '/[[:]x:](?#\\\x01)/', 'compiles' => true];
        yield '[: then [: right before ]' => ['pattern' => "/[[:a[:](?#\\\x01)/", 'shown' => '/[[:a[:](?#\\\x01)/', 'compiles' => true];
        // PCRE skips only "\]" and "\\" inside a POSIX name: "\:" is two
        // characters, "[" before another character is one. PCRE refuses
        // these names, and the display keeps the class open alike.
        yield 'backslash before the terminator inside a POSIX name' => ['pattern' => "/[[:a\\:](?#\\\x01)/", 'shown' => '/[[:a\:](?#\x01)/', 'compiles' => false];
        yield '[ before a letter inside a POSIX name' => ['pattern' => "/[[:a[b:](?#\\\x01)/", 'shown' => '/[[:a[b:](?#\x01)/', 'compiles' => false];
        // Outside a class: an empty comment ends at its ")", a verb argument
        // that is empty ends there too, and a verb with no argument opens
        // no run at all.
        yield 'empty comment before an escaped byte' => ['pattern' => "/(?#)\\\x01(?#)/", 'shown' => '/(?#)\x01(?#)/', 'compiles' => true];
        yield 'empty verb argument before an escaped byte' => ['pattern' => "/(*MARK:)\\\x01)/", 'shown' => '/(*MARK:)\x01)/', 'compiles' => false];
        yield 'verb with no argument before an escaped byte' => ['pattern' => "/(*COMMIT)a\\\x01/", 'shown' => '/(*COMMIT)a\x01/', 'compiles' => true];
        yield 'class opened after a POSIX-looking text outside a class' => ['pattern' => "/[:a(?#\\\x01)]/", 'shown' => '/[:a(?#\x01)]/', 'compiles' => true];
        // A verb argument after a group reads to its own ")".
        yield 'verb argument after a group' => ['pattern' => "/()(*:x\\\x01)/", 'shown' => '/()(*:x\\\x01)/', 'compiles' => true];
        yield 'named verb argument after a group' => ['pattern' => "/()(*MARK:\\\x01)/", 'shown' => '/()(*MARK:\\\x01)/', 'compiles' => true];
        // The operand of "\c" is the backslash: "\c\" is 0x1C, and the byte
        // after it stands alone.
        // A callout string left open runs to the end of the text, as PCRE
        // reads it ("missing terminating delimiter for callout").
        yield 'callout string never closed' => ['pattern' => "/(?C\"\\Q\x01/", 'shown' => '/(?C"\Q\x01/', 'compiles' => false];
        yield 'backslash operand of \c before a raw byte' => ['pattern' => "/\\c\\\x01/", 'shown' => '/\c\\\x01/', 'compiles' => true];
    }

    /**
     * A display ends where the text ends: "\c" with no operand after it is
     * printed as it is, without reading past the text.
     */
    #[Test]
    public function test_a_trailing_backslash_c_is_printed_without_a_warning(): void
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $shown = DisplayEscaper::escape('/a\c');
        } finally {
            restore_error_handler();
        }

        $this->assertSame('/a\c', $shown);
        $this->assertSame([], $warnings);
    }

    /**
     * A control byte as the operand of "\c" is spelled like any other, so
     * the terminal never receives it raw. PCRE refuses the pattern ("\c
     * must be followed by a printable ASCII character").
     */
    #[Test]
    public function test_a_control_byte_operand_of_backslash_c_is_spelled(): void
    {
        $pattern = self::backslashCWithAControlByte();
        $this->assertFalse(@preg_match($pattern, ''));

        $this->assertSame('/a\c\x01/', DisplayEscaper::escape($pattern));
    }

    #[Test]
    #[DataProvider('provideClassContexts')]
    public function test_a_displayed_class_reads_back_in_its_context(string $pattern, string $shown, bool $compiles): void
    {
        $this->assertSame($shown, DisplayEscaper::escape($pattern));

        // Oracle: compiled or refused alike, and on every subject the same
        // verdict, with at least one match for a pattern that compiles.
        $this->assertSame($compiles, false !== @preg_match($pattern, ''));
        $this->assertSame($compiles, false !== @preg_match($shown, ''));

        $matched = 0;
        foreach (['a', 'b', 'x', ']', '[', ':', '(', '?', '#', '\\', "\x01", ')', '^', '0', 'y', 'A', '.', '=', 'Q', 'E', "a)\x01", "#)\x01", "a\x01", "\x1C\x01", '[x:]'] as $subject) {
            $expected = @preg_match($pattern, $subject);
            $this->assertSame($expected, @preg_match($shown, $subject), json_encode($subject, \JSON_THROW_ON_ERROR));
            $matched += 1 === $expected ? 1 : 0;
        }

        $this->assertSame($compiles, $matched > 0);
    }

    /**
     * Where PCRE reads no escape, neither does the display. A comment
     * "(?#...)" and a verb argument "(*MARK:...)" end at the first ")",
     * backslash or not, and a "\Q" inside them quotes nothing. "\c" takes
     * the next character as its operand, a backslash included ("\c\" is
     * 0x1C), so that backslash escapes nothing after it. Each row reads the
     * pattern and its display back over the same subjects.
     *
     * @return iterable<string, array{pattern: string, subjects: list<string>, shown: string}>
     */
    public static function provideEscapesPcreDoesNotRead(): iterable
    {
        $runs = ["a\x01bb", "a\x01b+", "a\x01b", 'ab'];

        yield '\Q inside a comment' => ['pattern' => "/(?#\\Q)a\x01b+/", 'subjects' => $runs, 'shown' => '/(?#\Q)a\x01b+/'];
        yield '\Q and a control byte inside a comment' => ['pattern' => "/(?#\\Q\x01)a\x01b+/", 'subjects' => $runs, 'shown' => '/(?#\Q\x01)a\x01b+/'];
        yield '\Q inside a comment between two control bytes' => ['pattern' => "/a\x01b+(?#\\Q)c\x01d+/", 'subjects' => ["a\x01bbc\x01dd", "a\x01b+c\x01d+", "a\x01bc\x01d"], 'shown' => '/a\x01b+(?#\Q)c\x01d+/'];
        yield '\Q inside a mark name' => ['pattern' => "/(*MARK:\\Q)a\x01b+/", 'subjects' => $runs, 'shown' => '/(*MARK:\Q)a\x01b+/'];
        yield '\Q inside a short mark name' => ['pattern' => "/(*:\\Q)a\x01b+/", 'subjects' => $runs, 'shown' => '/(*:\Q)a\x01b+/'];
        yield '\Q inside a verb argument' => ['pattern' => "/(*PRUNE:\\Q)a\x01b+/", 'subjects' => $runs, 'shown' => '/(*PRUNE:\Q)a\x01b+/'];
        // A comment ends at its first ")", even one after a backslash: the
        // "\Q" past it quotes.
        yield 'comment ending after a backslash' => ['pattern' => "/(?#\\)\\Qa\x01b+\\E/", 'subjects' => $runs, 'shown' => '/(?#\)\Qa\E\x01\Qb+\E/'];
        yield 'mark name ending after a backslash' => ['pattern' => "/(*MARK:\\)\\Qa\x01b+\\E/", 'subjects' => $runs, 'shown' => '/(*MARK:\)\Qa\E\x01\Qb+\E/'];
        // "(?#" opens no comment inside a class, nor after an escaped "(".
        yield '(?# inside a class' => ['pattern' => "/[(?#]\\Q)\x01\\E/", 'subjects' => [")\x01", "#)\x01", "(\x01"], 'shown' => '/[(?#]\Q)\E\x01\Q\E/'];
        yield '(?# after an escaped parenthesis' => ['pattern' => "/\\(?#\\Q)\x01b+/", 'subjects' => ["(#)\x01b+", "#)\x01b+", "(#)\x01bb"], 'shown' => '/\(?#\Q)\E\x01\Qb+/'];
        // HEAD printed "\c\\001": the operand stays, the byte follows in an
        // escape of its own.
        yield 'backslash operand of \c before a control byte' => ['pattern' => "/\\c\\\x01/", 'subjects' => ["\x1C\x01", "\x1Cx01", "\x1C\\x01"], 'shown' => '/\c\\\x01/'];
        yield 'backslash operand of \c before a control byte in a class' => ['pattern' => "/[\\c\\\x01]+/", 'subjects' => ["\x1C\x01", "\x1Cx01", 'x', '0'], 'shown' => '/[\c\\\x01]+/'];
        yield 'backslash operand of \c before an escaped control byte' => ['pattern' => "/\\c\\\\\x01/", 'subjects' => ["\x1C\x01", "\x1C\\x01", "\x1C\\\x01"], 'shown' => '/\c\\\x01/'];
        yield 'backslash operand of \c before a Q' => ['pattern' => "/\\c\\Q\x01b+/", 'subjects' => ["\x1CQ\x01bb", "\x1CQ\x01b+", "\x1CQ\x01b"], 'shown' => '/\c\Q\x01b+/'];
        // Inside \Q..\E, "\c" is quoted text and the "\E" still ends the quote.
        yield '\c inside \Q..\E' => ['pattern' => "/\\Q\\c\\E\\\x01b+/", 'subjects' => ["\\c\x01bb", "\\c\x01b+", "\x1C\x01bb"], 'shown' => '/\Q\c\E\x01b+/'];
        yield 'escaped backslash before c' => ['pattern' => "/\\\\c\\\x01b+/", 'subjects' => ["\\c\x01bb", "\\c\x01b+", "\x1C\x01bb"], 'shown' => '/\\\\c\x01b+/'];
        // A callout string reads no escape either, whatever
        // its delimiter (PCRE2 10.49 takes ` ' " ^ % # $ and {, closed by
        // the same character or "}" for "{"); a doubled delimiter is one
        // literal delimiter and leaves the string open.
        $callout = ["\x01a", "\x01", 'a', "\\E\x01a", "Q\x01a"];
        foreach (['"' => '"', '`' => '`', "'" => "'", '^' => '^', '%' => '%', '#' => '#', '$' => '$', '{' => '}'] as $open => $close) {
            yield \sprintf('\Q inside a callout string delimited by %s', $open) => ['pattern' => "/(?C{$open}\\Q{$close})\x01./", 'subjects' => $callout, 'shown' => "/(?C{$open}\\Q{$close})\\x01./"];
        }
        yield '\Q after a doubled delimiter in a callout string' => ['pattern' => "/(?C\"a\"\"\\Q\")\x01./", 'subjects' => $callout, 'shown' => '/(?C"a""\Q")\x01./'];
        yield '\Q after a doubled closing brace in a callout string' => ['pattern' => "/(?C{a}}\\Q})\x01./", 'subjects' => $callout, 'shown' => '/(?C{a}}\Q})\x01./'];
        // The string ends at its own closing delimiter, never earlier nor
        // later: a "\Q" inside it quotes nothing, one after it quotes.
        yield '\Q after a callout string' => ['pattern' => "/(?C\"a\")\\Q\x01\\E./", 'subjects' => $callout, 'shown' => '/(?C"a")\Q\E\x01\Q\E./'];
        yield '\Q after an empty callout string' => ['pattern' => "/(?C\"\")\\Q\x01\\E./", 'subjects' => $callout, 'shown' => '/(?C"")\Q\E\x01\Q\E./'];
        yield '\Q at the end of a longer callout string' => ['pattern' => "/(?C\"ab\\Q\")\x01./", 'subjects' => $callout, 'shown' => '/(?C"ab\Q")\x01./'];
        // A numbered callout holds no string: the "\Q" after it quotes.
        yield '\Q after a numbered callout' => ['pattern' => "/(?C1)\\Q\x01\\E./", 'subjects' => $callout, 'shown' => '/(?C1)\Q\E\x01\Q\E./'];
        // "[" as the pattern delimiter opens no class, so the
        // comment and the verb argument after it read no escape.
        yield '\Q inside a comment, [ as the delimiter' => ['pattern' => "[(?#\\Q)\x01.]", 'subjects' => $callout, 'shown' => '[(?#\Q)\x01.]'];
        yield '\Q inside a mark name, [ as the delimiter' => ['pattern' => "[(*MARK:\\Q)\x01.]", 'subjects' => $callout, 'shown' => '[(*MARK:\Q)\x01.]'];
        yield '\Q inside a comment after a class, [ as the delimiter' => ['pattern' => "[[a](?#\\Q)\x01.]", 'subjects' => ["a\x01b", "a\x01", "\x01a", "a\\E\x01b"], 'shown' => '[[a](?#\Q)\x01.]'];
        // The modifiers after the closing "]", and the whitespace PHP skips
        // before the opening one, keep "[" a delimiter.
        yield '\Q inside a comment, [ as the delimiter, a modifier after it' => ['pattern' => "[(?#\\Q)\x01.]i", 'subjects' => [...$callout, "\x01A"], 'shown' => '[(?#\Q)\x01.]i'];
        yield '\Q inside a comment, [ as the delimiter after a space' => ['pattern' => " [(?#\\Q)\x01.]", 'subjects' => $callout, 'shown' => ' [(?#\Q)\x01.]'];
        yield '\Q inside a comment, { as the delimiter' => ['pattern' => "{(?#\\Q)\x01.}", 'subjects' => $callout, 'shown' => '{(?#\Q)\x01.}'];
    }

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideEscapesPcreDoesNotRead')]
    public function test_a_displayed_pattern_reads_back_where_pcre_reads_no_escape(string $pattern, array $subjects, string $shown): void
    {
        foreach ($subjects as $subject) {
            // Oracle: the pinned spelling matches what the pattern matches.
            $this->assertSame(preg_match($pattern, $subject), preg_match($shown, $subject), \sprintf('Oracle disagrees with the row on %s.', DisplayEscaper::quote($subject)));
        }

        $escaped = DisplayEscaper::escape($pattern);
        foreach ($subjects as $subject) {
            $this->assertSame(preg_match($pattern, $subject), @preg_match($escaped, $subject), \sprintf('%s does not read back on %s.', $escaped, DisplayEscaper::quote($subject)));
        }

        $this->assertSame($shown, $escaped);
    }

    /**
     * A text that opens with a "[" no "]" closes is no pattern delimited by
     * brackets: the "[" opens a class, where "(?#" is plain text and "\Q"
     * quotes. Closed with a "]", the text and its display match alike.
     */
    #[Test]
    public function test_an_unclosed_bracket_opens_a_class(): void
    {
        $fragment = "[(?#\\Q)\x01\\E";
        $shown = DisplayEscaper::escape($fragment);

        $this->assertSame('[(?#\Q)\E\x01\Q\E', $shown);

        $matched = 0;
        foreach (["\x01", '#', 'Q', 'x', 'E', '\\', ')', '(', '?', '0', '1'] as $subject) {
            $expected = preg_match('/'.$fragment.']/', $subject);
            $this->assertSame($expected, preg_match('/'.$shown.']/', $subject), DisplayEscaper::quote($subject));
            $matched += $expected;
        }

        $this->assertSame(5, $matched);
    }

    /**
     * A text that ends with a class closed by "]" is no pattern delimited
     * by brackets when it does not open with "[": the comment before the
     * class still reads no escape. Wrapped in "/", the text and its
     * display match alike.
     */
    #[Test]
    public function test_a_text_not_opening_with_a_bracket_has_no_bracket_delimiter(): void
    {
        $fragment = "(?#\\Q)\x01[a]";
        $shown = DisplayEscaper::escape($fragment);

        $this->assertSame('(?#\Q)\x01[a]', $shown);

        foreach (["\x01a", "\x01", 'a', "\\E\x01a", "Q\x01a", "\x01A"] as $subject) {
            $this->assertSame(preg_match('/'.$fragment.'/', $subject), preg_match('/'.$shown.'/', $subject), DisplayEscaper::quote($subject));
        }
    }

    #[Test]
    public function test_escape_keeps_utf8_patterns_equivalent(): void
    {
        $pattern = '/《붉은별》/iu';

        $this->assertSame($pattern, DisplayEscaper::escape($pattern));
    }

    /**
     * @return iterable<string, array{text: string, expected: string}>
     */
    public static function provideEscapedText(): iterable
    {
        yield 'ascii is untouched' => ['text' => '/[a-z]+/i', 'expected' => '/[a-z]+/i'];
        yield 'space is untouched' => ['text' => '/a b/', 'expected' => '/a b/'];
        yield 'control chars are escaped' => ['text' => "/a\tb\nc/", 'expected' => '/a\tb\nc/'];
        yield 'del is escaped' => ['text' => "/a\177b/", 'expected' => '/a\x7Fb/'];
        yield 'utf8 is preserved' => ['text' => '/«»“”/u', 'expected' => '/«»“”/u'];
        yield 'invalid utf8 is escaped' => ['text' => "/x\x80\xFEy/", 'expected' => '/x\x80\xFEy/'];
        // PCRE reads \b as a word boundary and \v as vertical whitespace:
        // only \t, \n and \r keep their letter escape.
        yield 'bell, backspace, vertical tab, form feed, escape' => ['text' => "/\x07\x08\x0B\x0C\x1B/", 'expected' => '/\x07\x08\x0B\x0C\x1B/'];
        yield 'NUL and unit separator' => ['text' => "/a\0\x1F/", 'expected' => '/a\x00\x1F/'];
        yield 'control byte inside valid utf8' => ['text' => "/é\x0B/u", 'expected' => '/é\x0B/u'];
    }

    /**
     * A displayed pattern pasted back into preg_match() must match the byte
     * it was printed from, and that byte only.
     */
    #[Test]
    #[DataProvider('provideUnprintableBytes')]
    public function test_display_escapes_read_back_as_the_same_bytes(string $byte): void
    {
        $readBack = '/'.DisplayEscaper::escape($byte).'/';

        $this->assertSame(1, preg_match($readBack, $byte), \sprintf('%s does not match byte 0x%02X.', $readBack, \ord($byte)));

        for ($other = 0; $other < 256; $other++) {
            if ($other === \ord($byte)) {
                continue;
            }

            $this->assertSame(0, preg_match($readBack, \chr($other)), \sprintf('%s printed for 0x%02X also matches 0x%02X.', $readBack, \ord($byte), $other));
        }
    }

    /**
     * @return iterable<string, array{byte: string}>
     */
    public static function provideUnprintableBytes(): iterable
    {
        foreach ([...range(0x00, 0x1F), 0x7F, ...range(0x80, 0xFF)] as $code) {
            yield \sprintf('byte 0x%02X', $code) => ['byte' => \chr($code)];
        }
    }

    /**
     * Inside a valid UTF-8 pattern compiled with /u, an escaped control byte
     * still reads back as that byte.
     */
    #[Test]
    public function test_display_escapes_read_back_under_the_u_flag(): void
    {
        foreach ([0x00, 0x07, 0x08, 0x0B, 0x0C, 0x1B, 0x7F] as $code) {
            $text = 'é'.\chr($code);
            $readBack = '/'.DisplayEscaper::escape($text).'/u';

            $this->assertSame(1, preg_match($readBack, $text), $readBack);
            $this->assertSame(0, preg_match($readBack, "é\n"), $readBack);
        }
    }

    #[Test]
    public function test_quote_wraps_a_sample_and_escapes_what_would_break_the_layout(): void
    {
        $this->assertSame('"" (empty string)', DisplayEscaper::quote(''));
        $this->assertSame('"a\\nb"', DisplayEscaper::quote("a\nb"));
        $this->assertSame('"\\x00\\x1F"', DisplayEscaper::quote("\x00\x1F"));
        $this->assertSame('"\\\\ \\""', DisplayEscaper::quote('\\ "'));
    }

    #[Test]
    public function test_quote_puts_the_markup_around_the_quotes(): void
    {
        $this->assertSame('<fg=cyan>"ok"</>', DisplayEscaper::quote('ok', '<fg=cyan>', '</>'));
        // An empty sample says so in words, with no markup to colour.
        $this->assertSame('"" (empty string)', DisplayEscaper::quote('', '<fg=cyan>', '</>'));
    }

    /**
     * Through a call, so that static analysis does not compile it.
     */
    private static function backslashCWithAControlByte(): string
    {
        return "/a\\c\x01/";
    }
}
