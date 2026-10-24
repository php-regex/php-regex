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
use PHPRegex\Tests\Support\PcreGates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a character type or a dot matches by the flags in force.
 *
 * PHP turns Unicode properties on with the u flag: "\d", "\s" and "\w" then
 * match by the Unicode tables, as they do after a leading "(*UCP)". Without
 * it they match ASCII, and the ASCII options of PCRE2 10.43, "(?aD)" and
 * the others, take a type back to ASCII under u.
 */
final class ExplainCharacterTypeTest extends TestCase
{
    use PcreGates;

    private const DIGIT = 'A digit: [0-9]';

    private const NON_DIGIT = 'A non-digit: [^0-9]';

    private const UNICODE_DIGIT = 'A decimal digit: any Unicode decimal digit (\p{Nd})';

    private const UNICODE_NON_DIGIT = 'A non-digit: any character but a Unicode decimal digit (\P{Nd})';

    private const WORD = 'A word character: [a-zA-Z_0-9], and the letters of the LC_CTYPE locale when it is not C';

    private const UNICODE_WORD = 'A word character: a Unicode letter (\p{L}) or number (\p{N}), an underscore, and from PCRE2 10.43 a non-spacing mark (\p{Mn}) or connector punctuation (\p{Pc})';

    private const SPACE = 'A whitespace character: [ \t\n\x0B\f\r]';

    private const UNICODE_SPACE = 'A whitespace character: a Unicode separator (\p{Z}), or a horizontal or vertical whitespace character (\h, \v)';

    private const GRAPHEME = 'An extended grapheme cluster: a character with the marks that combine with it, or a \r\n pair';

    private const BYTE = 'One code unit: a single byte, a newline included';

    private const HSPACE = 'A horizontal whitespace character: [ \t\xA0]';

    private const UNICODE_HSPACE = 'A horizontal whitespace character: [ \t\xA0\u1680\u180e\u2000-\u200a\u202f\u205f\u3000]';

    private const VSPACE = 'A vertical whitespace character: [\n\x0B\f\r\x85]';

    private const UNICODE_VSPACE = 'A vertical whitespace character: [\n\x0B\f\r\x85\u2028\u2029]';

    private const LINEBREAK = 'Any linebreak sequence (\r\n|[\n\x0B\f\r\x85])';

    private const UNICODE_LINEBREAK = 'Any Unicode linebreak sequence (\u000D\u000A|[\u000A\u000B\u000C\u000D\u0085\u2028\u2029])';

    private const ANYCRLF_LINEBREAK = 'A CR, LF or CRLF linebreak sequence, as (*BSR_ANYCRLF) sets: (\r\n|[\n\r])';

    /**
     * The rows that hold from a PCRE2 newer than the floor: every pattern
     * with an ASCII option is 10.43's syntax, refused before.
     */
    private const NEWER_RELEASES = ['10.43' => ['/(?aD)\d/u', '/(?aD)\w/u', '/(?a)\w/u', '/(?aS)\s/u', '/(?aW)\w/u', '/(?aD)(?-a)\d/u', '/(?a)(?-aD)\d/u', '/(?:(?aD))\d/u', '/(?aD:\d)/u', '/(?aDaS)\d/u', '/(?aD)(?aS)\d/u', '/(?iaD)\w/u', '/(?aS)\d/u', '/(?aW)\d/u', '/(?aT)\d/u', '/(?aP)\d/u']];

    #[Test]
    #[DataProvider('provideTypes')]
    public function test_a_character_type_is_explained_by_the_flags_in_force(string $pattern, string $description, string $subject, int $matches): void
    {
        $this->requiresPcre2For($pattern, self::NEWER_RELEASES);

        // "\w" under u takes a mark or a connector from PCRE2 10.43 only: a
        // spacing mark no release takes, the other subjects hold everywhere.
        if ('/\w/u' === $pattern && 1 === preg_match('/^[\p{Mn}\p{Pc}]$/u', $subject)) {
            $this->requiresPcre2('10.43', '\w under u on a mark or a connector');
        }

        // The engine: the subject tells the meaning described from the others.
        $this->assertSame($matches, preg_match($pattern, $subject), 'engine');

        $tree = RegexParser::create()->parse($pattern);
        $text = (string) $tree->accept(new TextExplainer());
        $html = html_entity_decode((string) $tree->accept(new HtmlExplainer()), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        // The whole description, not the start of a longer one.
        $this->assertMatchesRegularExpression('/: '.preg_quote($description, '/').'$/m', $text, 'text');
        $this->assertMatchesRegularExpression('/[>("]'.preg_quote($description, '/').'[)"<]/', $html, 'html');
    }

    /**
     * @return iterable<string, array{pattern: string, description: string, subject: string, matches: int}>
     */
    public static function provideTypes(): iterable
    {
        yield 'a digit without u' => ['pattern' => '/\d/', 'description' => self::DIGIT, 'subject' => '٣', 'matches' => 0];
        yield 'a digit under u' => ['pattern' => '/\d/u', 'description' => self::UNICODE_DIGIT, 'subject' => '٣', 'matches' => 1];
        yield 'a digit under u is no other number' => ['pattern' => '/\d/u', 'description' => self::UNICODE_DIGIT, 'subject' => '④', 'matches' => 0];
        yield 'a digit under a leading UCP' => ['pattern' => '/(*UTF)(*UCP)\d/', 'description' => self::UNICODE_DIGIT, 'subject' => '٣', 'matches' => 1];
        // UTF alone reads characters, but matches types by ASCII.
        yield 'a digit under a leading UTF alone' => ['pattern' => '/(*UTF)\d/', 'description' => self::DIGIT, 'subject' => '٣', 'matches' => 0];
        yield 'a non-digit without u' => ['pattern' => '/^\D$/', 'description' => self::NON_DIGIT, 'subject' => '٣', 'matches' => 0];
        yield 'a non-digit under u' => ['pattern' => '/^\D$/u', 'description' => self::UNICODE_NON_DIGIT, 'subject' => '٣', 'matches' => 0];
        yield 'a word character without u' => ['pattern' => '/\w/', 'description' => self::WORD, 'subject' => 'é', 'matches' => 0];
        yield 'a word character under u' => ['pattern' => '/\w/u', 'description' => self::UNICODE_WORD, 'subject' => 'é', 'matches' => 1];
        yield 'a letter number under u' => ['pattern' => '/\w/u', 'description' => self::UNICODE_WORD, 'subject' => 'Ⅰ', 'matches' => 1];
        yield 'a non-spacing mark under u' => ['pattern' => '/\w/u', 'description' => self::UNICODE_WORD, 'subject' => "\u{300}", 'matches' => 1];
        yield 'a spacing mark under u' => ['pattern' => '/\w/u', 'description' => self::UNICODE_WORD, 'subject' => "\u{903}", 'matches' => 0];
        yield 'a connector under u' => ['pattern' => '/\w/u', 'description' => self::UNICODE_WORD, 'subject' => "\u{2040}", 'matches' => 1];
        yield 'a whitespace character without u' => ['pattern' => '/\s/', 'description' => self::SPACE, 'subject' => "\x0B", 'matches' => 1];
        yield 'a whitespace character under u' => ['pattern' => '/\s/u', 'description' => self::UNICODE_SPACE, 'subject' => "\u{2003}", 'matches' => 1];
        yield 'a next line under u' => ['pattern' => '/\s/u', 'description' => self::UNICODE_SPACE, 'subject' => "\u{85}", 'matches' => 1];
        yield 'a format character under u' => ['pattern' => '/\s/u', 'description' => self::UNICODE_SPACE, 'subject' => "\u{200B}", 'matches' => 0];
        yield 'a digit after an ASCII digit option' => ['pattern' => '/(?aD)\d/u', 'description' => self::DIGIT, 'subject' => '٣', 'matches' => 0];
        yield 'a word character after an ASCII digit option' => ['pattern' => '/(?aD)\w/u', 'description' => self::UNICODE_WORD, 'subject' => 'é', 'matches' => 1];
        yield 'a word character after every ASCII option' => ['pattern' => '/(?a)\w/u', 'description' => self::WORD, 'subject' => 'é', 'matches' => 0];
        yield 'a whitespace character after an ASCII space option' => ['pattern' => '/(?aS)\s/u', 'description' => self::SPACE, 'subject' => "\u{2003}", 'matches' => 0];
        yield 'a word character after an ASCII word option' => ['pattern' => '/(?aW)\w/u', 'description' => self::WORD, 'subject' => 'é', 'matches' => 0];
        yield 'a digit after the ASCII options are turned off' => ['pattern' => '/(?aD)(?-a)\d/u', 'description' => self::UNICODE_DIGIT, 'subject' => '٣', 'matches' => 1];
        yield 'a digit after one ASCII option is turned off' => ['pattern' => '/(?a)(?-aD)\d/u', 'description' => self::UNICODE_DIGIT, 'subject' => '٣', 'matches' => 1];
        yield 'a digit after an ASCII option ended by its group' => ['pattern' => '/(?:(?aD))\d/u', 'description' => self::UNICODE_DIGIT, 'subject' => '٣', 'matches' => 1];
        yield 'a digit in a group setting an ASCII option' => ['pattern' => '/(?aD:\d)/u', 'description' => self::DIGIT, 'subject' => '٣', 'matches' => 0];
        yield 'a digit after an ASCII digit and an ASCII space option' => ['pattern' => '/(?aDaS)\d/u', 'description' => self::DIGIT, 'subject' => '٣', 'matches' => 0];
        yield 'a digit after an ASCII digit option, then an ASCII space one' => ['pattern' => '/(?aD)(?aS)\d/u', 'description' => self::DIGIT, 'subject' => '٣', 'matches' => 0];
        yield 'a word character after an ASCII digit option behind another flag' => ['pattern' => '/(?iaD)\w/u', 'description' => self::UNICODE_WORD, 'subject' => 'é', 'matches' => 1];
        yield 'a digit after an ASCII space option' => ['pattern' => '/(?aS)\d/u', 'description' => self::UNICODE_DIGIT, 'subject' => '٣', 'matches' => 1];
        yield 'a digit after an ASCII word option' => ['pattern' => '/(?aW)\d/u', 'description' => self::UNICODE_DIGIT, 'subject' => '٣', 'matches' => 1];
        yield 'a digit after an ASCII property option' => ['pattern' => '/(?aT)\d/u', 'description' => self::UNICODE_DIGIT, 'subject' => '٣', 'matches' => 1];
        // The other options leave the three types alone.
        yield 'a digit after an ASCII POSIX option' => ['pattern' => '/(?aP)\d/u', 'description' => self::UNICODE_DIGIT, 'subject' => '٣', 'matches' => 1];
        yield 'a grapheme cluster under u' => ['pattern' => '/^\X$/u', 'description' => self::GRAPHEME, 'subject' => "e\u{301}", 'matches' => 1];
        yield 'a carriage return and line feed as one cluster' => ['pattern' => '/^\X$/u', 'description' => self::GRAPHEME, 'subject' => "\r\n", 'matches' => 1];
        yield 'a grapheme cluster of bytes without u' => ['pattern' => '/^\X$/', 'description' => self::GRAPHEME, 'subject' => "e\u{301}", 'matches' => 0];
        yield 'anything but a newline' => ['pattern' => '/a\Nb/', 'description' => 'Any character but a newline (\n), whatever s says', 'subject' => "a\nb", 'matches' => 0];
        yield 'anything but a newline under s' => ['pattern' => '/a\Nb/s', 'description' => 'Any character but a newline (\n), whatever s says', 'subject' => "a\nb", 'matches' => 0];
        yield 'anything but a newline under CR' => ['pattern' => '/(*CR)a\Nb/', 'description' => 'Any character but a newline (\r), whatever s says', 'subject' => "a\nb", 'matches' => 1];
        yield 'anything but a newline is one character under u' => ['pattern' => '/^\N$/u', 'description' => 'Any character but a newline (\n), whatever s says', 'subject' => 'é', 'matches' => 1];
        yield 'one byte, a newline included' => ['pattern' => '/^\C$/', 'description' => self::BYTE, 'subject' => "\n", 'matches' => 1];
        yield 'one byte of a character' => ['pattern' => '/^\C\C$/', 'description' => self::BYTE, 'subject' => 'é', 'matches' => 1];
        // The dot takes a newline under s only.
        yield 'a dot without s' => ['pattern' => '/a.b/', 'description' => 'any character but a newline (\n)', 'subject' => "a\nb", 'matches' => 0];
        yield 'a dot takes a carriage return under LF' => ['pattern' => '/a.b/', 'description' => 'any character but a newline (\n)', 'subject' => "a\rb", 'matches' => 1];
        yield 'a dot under s' => ['pattern' => '/a.b/s', 'description' => 'any character, a newline included', 'subject' => "a\nb", 'matches' => 1];
        yield 'a dot after an inline s' => ['pattern' => '/(?s)a.b/', 'description' => 'any character, a newline included', 'subject' => "a\nb", 'matches' => 1];
        yield 'a dot after a group that set s' => ['pattern' => '/(?s:x)|a.b/', 'description' => 'any character but a newline (\n)', 'subject' => "a\nb", 'matches' => 0];
        yield 'a dot after s is turned off' => ['pattern' => '/(?-s)a.b/s', 'description' => 'any character but a newline (\n)', 'subject' => "a\nb", 'matches' => 0];
        yield 'a dot after a caret reset' => ['pattern' => '/(?^)a.b/s', 'description' => 'any character but a newline (\n)', 'subject' => "a\nb", 'matches' => 0];
        yield 'a dot under CR' => ['pattern' => '/(*CR)a.b/', 'description' => 'any character but a newline (\r)', 'subject' => "a\rb", 'matches' => 0];
        // Without UTF, "\h", "\v" and "\R" read one byte: none past 0xFF.
        yield 'a horizontal space without u' => ['pattern' => '/^\h$/', 'description' => self::HSPACE, 'subject' => "\xA0", 'matches' => 1];
        yield 'an ideographic space is no horizontal space without u' => ['pattern' => '/^\h$/', 'description' => self::HSPACE, 'subject' => "\u{3000}", 'matches' => 0];
        yield 'an ideographic space is a horizontal space under u' => ['pattern' => '/^\h$/u', 'description' => self::UNICODE_HSPACE, 'subject' => "\u{3000}", 'matches' => 1];
        yield 'a next line byte is a vertical space without u' => ['pattern' => '/^\v$/', 'description' => self::VSPACE, 'subject' => "\x85", 'matches' => 1];
        yield 'a line separator is no vertical space without u' => ['pattern' => '/^\v$/', 'description' => self::VSPACE, 'subject' => "\u{2028}", 'matches' => 0];
        yield 'a line separator is a vertical space under u' => ['pattern' => '/^\v$/u', 'description' => self::UNICODE_VSPACE, 'subject' => "\u{2028}", 'matches' => 1];
        yield 'a line separator is no linebreak without u' => ['pattern' => '/^\R$/', 'description' => self::LINEBREAK, 'subject' => "\u{2028}", 'matches' => 0];
        yield 'a line separator is a linebreak under u' => ['pattern' => '/^\R$/u', 'description' => self::UNICODE_LINEBREAK, 'subject' => "\u{2028}", 'matches' => 1];
        yield 'a vertical tab is no linebreak under BSR_ANYCRLF' => ['pattern' => '/(*BSR_ANYCRLF)^\R$/u', 'description' => self::ANYCRLF_LINEBREAK, 'subject' => "\x0B", 'matches' => 0];
        yield 'a CRLF is one linebreak under BSR_ANYCRLF' => ['pattern' => '/(*BSR_ANYCRLF)^\R\z/', 'description' => self::ANYCRLF_LINEBREAK, 'subject' => "\r\n", 'matches' => 1];
        yield 'a next line byte is a linebreak under BSR_UNICODE' => ['pattern' => '/(*BSR_UNICODE)^\R$/', 'description' => self::LINEBREAK, 'subject' => "\x85", 'matches' => 1];
        yield 'the last BSR option holds' => ['pattern' => '/(*BSR_ANYCRLF)(*BSR_UNICODE)^\R$/u', 'description' => self::UNICODE_LINEBREAK, 'subject' => "\x0B", 'matches' => 1];
    }

    /**
     * Once LC_CTYPE has been set, PHP builds the character tables from it
     * for the rest of the process, even after it is set back: the test runs
     * on its own.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function test_a_word_character_without_u_follows_the_locale(): void
    {
        $locale = setlocale(\LC_CTYPE, '0');
        if (false === setlocale(\LC_CTYPE, 'fr_FR.ISO8859-1', 'fr_FR.ISO-8859-1', 'fr_FR.iso88591')) {
            // Not every machine has the locale: the C tables are checked above.
            $this->assertSame(0, preg_match('/\w/', "\xE9"));

            return;
        }

        try {
            $this->assertSame(1, preg_match('/\w/', "\xE9"));
            $this->assertSame(0, preg_match('/\d/', "\xB2"));
        } finally {
            setlocale(\LC_CTYPE, (string) $locale);
        }
    }
}
