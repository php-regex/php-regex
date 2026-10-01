<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Parser\ErrorCode;
use PhpRegex\Tests\TestUtils\PhpErrorOffset;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Escapes and character-class forms PHP refuses to compile.
 *
 * Every rejected pattern comes from PCRE2's own test suite and is refused by
 * both PCRE2 10.40 and 10.48 with the error code in its case name. Every
 * accepted control compiles on the same engines. The offsets are PCRE2's
 * body offsets: the 10.48 one first, then the 10.40 one where the two
 * releases disagree.
 */
final class PcreRejectedEscapesTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRejectedPatterns')]
    #[DataProvider('provideRejectedPatternsVerdictOnly')]
    public function test_validate_rejects_pattern_pcre_refuses(string $pattern): void
    {
        $result = Regex::create()->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s compiles in no PCRE2 release but was reported valid.', $pattern));
        $this->assertNotNull($result->error);
    }

    #[Test]
    #[DataProvider('provideRejectedPatterns')]
    public function test_validate_rejects_pattern_pcre_refuses_with_a_regex_error_code(string $pattern): void
    {
        $result = Regex::create()->validate($pattern);

        $this->assertFalse($result->isValid);

        // An unterminated class fails while tokenizing, before any AST exists,
        // and reports the unclosed-class code like "[abc" does; the \E family
        // (PCRE2 error 106) is that same error hidden behind an empty \Q...\E.
        if (str_starts_with($pattern, '/[') && str_ends_with($pattern, ']AAA/')) {
            $this->assertSame(ErrorCode::from('regex.charclass.unclosed'), $result->errorCode);

            return;
        }

        $this->assertInstanceOf(ErrorCode::class, $result->errorCode);
    }

    /**
     * @param list<int> $offsets
     */
    #[Test]
    #[DataProvider('provideRejectedPatternsWithOffsets')]
    public function test_validate_rejects_pattern_pcre_refuses_at_pcre_offset(string $pattern, array $offsets): void
    {
        $result = Regex::create()->validate($pattern);

        $this->assertFalse($result->isValid);
        $this->assertContains(
            $result->offset,
            $offsets,
            \sprintf('%s reported at offset %s, PCRE2 reports %s.', $pattern, var_export($result->offset, true), implode(' or ', $offsets)),
        );
    }

    #[Test]
    #[DataProvider('provideAcceptedControls')]
    public function test_validate_accepts_neighbouring_pattern_pcre_compiles(string $pattern): void
    {
        $result = Regex::create()->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s compiles in PCRE2 but was reported invalid: %s', $pattern, (string) $result->error));
        $this->assertNull($result->error);
    }

    /**
     * PCRE2 10.48 lets spaces and tabs pad the inside of a braced escape;
     * 10.40 refuses every one of these with error 137, 164 or 167. The
     * escape is judged as the newer release reads it.
     */
    #[Test]
    #[DataProvider('providePaddedBracedEscapes')]
    public function test_validate_accepts_padded_braced_escape_as_pcre2_10_48_does(string $pattern): void
    {
        // Padding arrived in PCRE2 10.43; PHP 8.4 bundles 10.44, which reads
        // these as 10.48 does, whatever PCRE2 the PHP running links.
        $result = Regex::create(['php_version' => '8.4'])->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s compiles in PCRE2 10.48 but was reported invalid: %s', $pattern, (string) $result->error));
        $this->assertNull($result->error);
    }

    /**
     * PCRE2 10.48 reports a bad digit in "\x{}" just past it, and in UTF mode
     * that is past the whole UTF-8 sequence, not past its first byte. PCRE2
     * 10.40 reports all of these at offset 4, where the character starts.
     */
    #[Test]
    #[DataProvider('provideBadBracedDigitOffsets')]
    public function test_validate_reports_bad_braced_digit_past_the_whole_character(string $pattern, int $offset): void
    {
        $result = Regex::create()->validate($pattern);

        $this->assertFalse($result->isValid);
        $this->assertSame(ErrorCode::from('regex.unicode.invalid_digit'), $result->errorCode);
        // The offset is PHP's on PCRE2 10.47 and later; the running PHP
        // decides, as the library follows the PCRE2 it links.
        $this->assertSame(PhpErrorOffset::of($pattern), $result->offset, $pattern);
        if (PhpErrorOffset::runsPcre1047()) {
            $this->assertSame($offset, $result->offset, $pattern);
        }
    }

    /**
     * "(*pla:...)" and its kin are parsed apart from the rest of the pattern,
     * so the message names the range from its endpoints rather than from the
     * pattern text. Both PCRE2 releases refuse each of these with error 108.
     */
    #[Test]
    #[DataProvider('provideReversedRangesInsideAlphaAssertions')]
    public function test_validate_names_reversed_range_inside_alpha_assertion(string $pattern, string $range): void
    {
        $result = Regex::create()->validate($pattern);

        $this->assertFalse($result->isValid);
        $this->assertSame(ErrorCode::from('regex.range.reversed'), $result->errorCode);
        $this->assertStringContainsString(\sprintf('Invalid range "%s"', $range), (string) $result->error);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePaddedBracedEscapes(): iterable
    {
        yield '171 family, empty padded code point in a class: /[\\N{U+ }]/u' => ['pattern' => '/[\\N{U+ }]/u'];
        yield 'padded \\x{}: /\\x{ 41 }/' => ['pattern' => '/\\x{ 41 }/'];
        yield 'padded \\o{}: /\\o{ 101 }/' => ['pattern' => '/\\o{ 101 }/'];
        yield 'trailing padding in \\N{U+} under UTF: /\\N{U+41 }/u' => ['pattern' => '/\\N{U+41 }/u'];
        yield 'padded \\x{} as range start: /[\\x{ 41 }-\\x{7a}]/' => ['pattern' => '/[\\x{ 41 }-\\x{7a}]/'];
        yield 'padded \\o{} as range start: /[\\o{ 101 }-\\101]/' => ['pattern' => '/[\\o{ 101 }-\\101]/'];
        yield 'padded \\x{} up to \\N{U+}: /[\\x{ 41 }-\\N{U+7A}]/u' => ['pattern' => '/[\\x{ 41 }-\\N{U+7A}]/u'];
        yield 'padded \\N{U+} in a class: /[\\N{U+41 }]/u' => ['pattern' => '/[\\N{U+41 }]/u'];
        yield 'padded \\N{U+} after a member: /[a\\N{U+41 }]/u' => ['pattern' => '/[a\\N{U+41 }]/u'];
        yield 'tab-padded \\N{U+} in a class: /[\\N{U+41\\t}]/u' => ['pattern' => "/[\\N{U+41\t}]/u"];
        yield 'padded \\N{U+} in a class under (*UTF): /(*UTF)[\\N{U+41 }]/' => ['pattern' => '/(*UTF)[\\N{U+41 }]/'];
        yield 'space before U+: /\\N{ U+41}/u' => ['pattern' => '/\\N{ U+41}/u'];
        yield 'tab before U+: /\\N{\\tU+41}/u' => ['pattern' => "/\\N{\tU+41}/u"];
    }

    /**
     * Refused by both releases, where only the verdict is pinned: the offset
     * is left to the rows above once the code can report it.
     *
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRejectedPatternsVerdictOnly(): iterable
    {
        // Inside a class, "\N{U+" is judged like outside one: a non-hex digit
        // is error 167 (preg_match() on 10.48: at offset 7).
        yield '167 non-hex digit in a class: /[\\N{U+zz}]/u' => ['pattern' => '/[\\N{U+zz}]/u'];
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideBadBracedDigitOffsets(): iterable
    {
        yield 'one-byte character under UTF: /\\x{4Z}/u' => ['pattern' => '/\\x{4Z}/u', 'offset' => 5];
        yield 'two-byte character under UTF: /\\x{4\\u{e9}}/u' => ['pattern' => "/\\x{4\u{e9}}/u", 'offset' => 6];
        yield 'three-byte character under UTF: /\\x{4\\u{20ac}}/u' => ['pattern' => "/\\x{4\u{20ac}}/u", 'offset' => 7];
        yield 'four-byte character under UTF: /\\x{4\\u{1f600}}/u' => ['pattern' => "/\\x{4\u{1f600}}/u", 'offset' => 8];
        yield 'two-byte character without UTF steps one byte: /\\x{4\\u{e9}}/' => ['pattern' => "/\\x{4\u{e9}}/", 'offset' => 5];
    }

    /**
     * @return iterable<string, array{pattern: string, range: string}>
     */
    public static function provideReversedRangesInsideAlphaAssertions(): iterable
    {
        yield 'literal endpoints: /(*pla:[z-a])/' => ['pattern' => '/(*pla:[z-a])/', 'range' => 'z-a'];
        yield 'hex endpoints: /(*pla:[\\x{7a}-\\x{61}])/' => ['pattern' => '/(*pla:[\\x{7a}-\\x{61}])/', 'range' => '\\x{7a}-\\x{61}'];
        yield 'control endpoints: /(*pla:[\\cZ-\\cA])/' => ['pattern' => '/(*pla:[\\cZ-\\cA])/', 'range' => '\\cZ-\\cA'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRejectedPatterns(): iterable
    {
        foreach (self::rejectedPatterns() as $name => $case) {
            yield $name => ['pattern' => $case['pattern']];
        }
    }

    /**
     * @return iterable<string, array{pattern: string, offsets: list<int>}>
     */
    public static function provideRejectedPatternsWithOffsets(): iterable
    {
        yield from self::rejectedPatterns();
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideAcceptedControls(): iterable
    {
        yield '103 family, known escape: /ab\\ddef/' => ['pattern' => '/ab\\ddef/'];
        yield '103 family, known escape in class: /[ \\d ]/' => ['pattern' => '/[ \\d ]/'];
        yield '106 family, \\E then literal ] closes: /[\\E]]AAA/' => ['pattern' => '/[\\E]]AAA/'];
        yield '106 family, \\Qa\\E class closes: /[\\Qa\\E]AAA/' => ['pattern' => '/[\\Qa\\E]AAA/'];
        yield '106 family, negated \\E then literal ]: /[^\\E]]AAA/' => ['pattern' => '/[^\\E]]AAA/'];
        yield '106 family, \\E then ^ as member: /[\\E^a]AAA/' => ['pattern' => '/[\\E^a]AAA/'];
        yield '103 family, \\g in class: /[\\g]/' => ['pattern' => '/[\\g]/'];
        yield '112 family, old word boundaries: /[[:<:]]a[[:>:]]/' => ['pattern' => '/[[:<:]]a[[:>:]]/'];
        yield '112 family, ] ends the search for :]: /[:a]b:]/' => ['pattern' => '/[:a]b:]/'];
        yield '112 family, escaped bracket opens no POSIX item: /[\\[:foo:]]/' => ['pattern' => '/[\\[:foo:]]/'];
        yield '112 family, text before (*pla:) is not read as its class: /a:b:]c(*pla:[d])/' => ['pattern' => '/a:b:]c(*pla:[d])/'];
        yield '113 family, quoted bracket opens no POSIX item: /[\\Q[\\E.ch.]]/' => ['pattern' => '/[\\Q[\\E.ch.]]/'];
        yield '113 family, bracket member inside (*pla:): /(*pla:[a[b])/' => ['pattern' => '/(*pla:[a[b])/'];
        yield '107 family, \\d in class: /[\\d]/' => ['pattern' => '/[\\d]/'];
        yield '107 family, \\b backspace in class: /[\\b]/' => ['pattern' => '/[\\b]/'];
        yield '107 family, type escapes in class: /[\\s\\w\\h\\v]/' => ['pattern' => '/[\\s\\w\\h\\v]/'];
        yield '108 family, octal range in order: /[\\110-\\200]/' => ['pattern' => '/[\\110-\\200]/'];
        yield '108 family, braced hex range in order: /[\\x{100}-\\x{200}]/u' => ['pattern' => '/[\\x{100}-\\x{200}]/u'];
        yield '108 family, escaped hyphen: /[a\\-z]/' => ['pattern' => '/[a\\-z]/'];
        yield '108 family, octal above 0xff up to \\400 under UTF: /[\\0-\\400]/u' => ['pattern' => '/[\\0-\\400]/u'];
        yield '108 family, octal above 0xff up to \\777 under UTF: /[\\0-\\777]/u' => ['pattern' => '/[\\0-\\777]/u'];
        yield '108 family, space up to \\400 under UTF: /[ -\\400]/u' => ['pattern' => '/[ -\\400]/u'];
        yield '108 family, \\x{100} up to \\777 under UTF: /[\\x{100}-\\777]/u' => ['pattern' => '/[\\x{100}-\\777]/u'];
        yield '108 family, octal \\400 under UTF: /\\400/u' => ['pattern' => '/\\400/u'];
        yield '108 family, octal \\400 in a class under UTF: /[\\400]/u' => ['pattern' => '/[\\400]/u'];
        // Without UTF a multibyte literal is its bytes: the range runs from
        // its last byte, 0xa9 or 0xac, to the escaped endpoint.
        yield '108 family, byte range from a two-byte literal: /[\\u{e9}-\\xe0]/' => ['pattern' => "/[\u{e9}-\\xe0]/"];
        yield '108 family, byte range from a three-byte literal: /[\\u{20ac}-\\xff]/' => ['pattern' => "/[\u{20ac}-\\xff]/"];
        yield '108 family, byte range up to legacy octal: /[\\u{e9}-\\340]/' => ['pattern' => "/[\u{e9}-\\340]/"];
        yield '108 family, byte range up to \\o{}: /[\\u{e9}-\\o{340}]/' => ['pattern' => "/[\u{e9}-\\o{340}]/"];
        yield '108 family, byte range up to \\x{}: /[\\u{e9}-\\x{e0}]/' => ['pattern' => "/[\u{e9}-\\x{e0}]/"];
        yield '108 family, hex range in order: /[\\x41-\\x5a]/' => ['pattern' => '/[\\x41-\\x5a]/'];
        yield '112 family, POSIX class inside a class: /[[:alpha:]]/' => ['pattern' => '/[[:alpha:]]/'];
        yield '112 family, colon-x class without closing colon: /[:x]/' => ['pattern' => '/[:x]/'];
        yield '112 family, x-colon class: /[x:]/' => ['pattern' => '/[x:]/'];
        // Under (?xx) a space or tab in a class is ignored, so "]" right after
        // it is still the first member and the class ends at the last "]".
        yield '112 family, (?xx) space before a literal ]: /(?xx)[ ][:digit:]]/' => ['pattern' => '/(?xx)[ ][:digit:]]/'];
        yield '112 family, (?xx) spaces and \\Q\\E before ]: /(?xx)[ \\Q\\E ][:alpha:]]/' => ['pattern' => '/(?xx)[ \\Q\\E ][:alpha:]]/'];
        yield '112 family, (?xx) tab then ^ before ]: /(?xx)[\\t^][:alpha:]]/' => ['pattern' => "/(?xx)[\t^][:alpha:]]/"];
        yield '113 family, dot-x class: /[.x]/' => ['pattern' => '/[.x]/'];
        yield '113 family, equals-x class: /[=x]/' => ['pattern' => '/[=x]/'];
        yield '113 family, bracket-dot class: /[[.]/' => ['pattern' => '/[[.]/'];
        yield '130 family, negated POSIX class: /[[:^digit:]]/' => ['pattern' => '/[[:^digit:]]/'];
        yield '130 family, word POSIX class: /[[:word:]]/' => ['pattern' => '/[[:word:]]/'];
        yield '130 family, two POSIX classes: /[[:alpha:][:digit:]]/' => ['pattern' => '/[[:alpha:][:digit:]]/'];
        yield '137 family, \\N outside a class: /\\N/' => ['pattern' => '/\\N/'];
        yield '137 family, \\N{U+41} under UTF: /\\N{U+41}/u' => ['pattern' => '/\\N{U+41}/u'];
        yield '155 family, \\o{101}: /\\o{101}/' => ['pattern' => '/\\o{101}/'];
        yield '164 family, \\o{7}: /\\o{7}/' => ['pattern' => '/\\o{7}/'];
        yield '167 family, \\x{41}: /\\x{41}/' => ['pattern' => '/\\x{41}/'];
        yield '167 family, \\x41: /\\x41/' => ['pattern' => '/\\x41/'];
        yield '171 family, \\n in class: /a[\\nB]c/' => ['pattern' => '/a[\\nB]c/'];
        yield '173 family, \\x{d7ff} under UTF: /\\x{d7ff}/u' => ['pattern' => '/\\x{d7ff}/u'];
        yield '173 family, \\x{e000} under UTF: /\\x{e000}/u' => ['pattern' => '/\\x{e000}/u'];
        yield '173 family, \\o{153777} under UTF: /\\o{153777}/u' => ['pattern' => '/\\o{153777}/u'];
        yield '178 family, \\o{0} has a digit: /\\o{0}/' => ['pattern' => '/\\o{0}/'];
    }

    /**
     * @return array<string, array{pattern: string, offsets: list<int>}>
     */
    private static function rejectedPatterns(): array
    {
        return [
            '103 unrecognized escape: /ab\\idef/' => ['pattern' => '/ab\\idef/', 'offsets' => [4, 3]],
            '103 unrecognized escape in class: /[ \\j ]/' => ['pattern' => '/[ \\j ]/', 'offsets' => [4, 3]],
            '106 unterminated class after \\E: /[\\E]AAA/' => ['pattern' => '/[\\E]AAA/', 'offsets' => [7]],
            '106 unterminated class after \\Q\\E: /[\\Q\\E]AAA/' => ['pattern' => '/[\\Q\\E]AAA/', 'offsets' => [9]],
            '106 unterminated negated class after \\E: /[^\\E]AAA/' => ['pattern' => '/[^\\E]AAA/', 'offsets' => [8]],
            '106 unterminated negated class after \\Q\\E: /[^\\Q\\E]AAA/' => ['pattern' => '/[^\\Q\\E]AAA/', 'offsets' => [10]],
            '106 unterminated class after \\E then ^: /[\\E^]AAA/' => ['pattern' => '/[\\E^]AAA/', 'offsets' => [8]],
            '106 unterminated class after \\Q\\E then ^: /[\\Q\\E^]AAA/' => ['pattern' => '/[\\Q\\E^]AAA/', 'offsets' => [10]],
            '107 \\B in class: /[\\B]/' => ['pattern' => '/[\\B]/', 'offsets' => [3, 2]],
            '107 \\R in class: /[\\R]/' => ['pattern' => '/[\\R]/', 'offsets' => [3, 2]],
            '107 \\X in class: /[\\X]/' => ['pattern' => '/[\\X]/', 'offsets' => [3, 2]],
            '107 \\B\\R\\X in class: /[\\B\\R\\X]/' => ['pattern' => '/[\\B\\R\\X]/', 'offsets' => [3, 2]],
            '107 \\A in class: /[\\A]/' => ['pattern' => '/[\\A]/', 'offsets' => [3, 2]],
            '107 \\Z in class: /[\\Z]/' => ['pattern' => '/[\\Z]/', 'offsets' => [3, 2]],
            '107 \\z in class: /[\\z]/' => ['pattern' => '/[\\z]/', 'offsets' => [3, 2]],
            '107 \\G in class: /[\\G]/' => ['pattern' => '/[\\G]/', 'offsets' => [3, 2]],
            '107 \\K in class: /[\\K]/' => ['pattern' => '/[\\K]/', 'offsets' => [3, 2]],
            '108 octal range out of order: /[\\200-\\110]/' => ['pattern' => '/[\\200-\\110]/', 'offsets' => [10, 9]],
            '108 braced hex range out of order: /[\\x{200}-\\x{100}]/u' => ['pattern' => '/[\\x{200}-\\x{100}]/u', 'offsets' => [16, 15]],
            '112 POSIX class outside a class: /[:x:]/' => ['pattern' => '/[:x:]/', 'offsets' => [5, 0]],
            '113 collating element [. .]: /[[.ch.]]/' => ['pattern' => '/[[.ch.]]/', 'offsets' => [7, 1]],
            '113 equivalence class [= =]: /[[=ch=]]/' => ['pattern' => '/[[=ch=]]/', 'offsets' => [7, 1]],
            '113 collating element after an escaped backslash and Q: /[\\\\Q[.ch.]]/' => ['pattern' => '/[\\\\Q[.ch.]]/', 'offsets' => [10, 4]],
            '113 collating element outside a class: /[.x.]/' => ['pattern' => '/[.x.]/', 'offsets' => [5, 0]],
            '113 equivalence class outside a class: /[=x=]/' => ['pattern' => '/[=x=]/', 'offsets' => [5, 0]],
            '130 unknown POSIX name: /[[:foo:]]/' => ['pattern' => '/[[:foo:]]/', 'offsets' => [8, 3]],
            '130 POSIX name in upper case: /[[:ALPHA:]]/' => ['pattern' => '/[[:ALPHA:]]/', 'offsets' => [10, 3]],
            '130 digits as POSIX name: /[[:1234:]]/' => ['pattern' => '/[[:1234:]]/', 'offsets' => [9, 3]],
            '130 escape inside POSIX name: /[[:f\\oo:]]/' => ['pattern' => '/[[:f\\oo:]]/', 'offsets' => [9, 3]],
            '130 space as POSIX name: /[[: :]]/' => ['pattern' => '/[[: :]]/', 'offsets' => [6, 3]],
            '130 dots as POSIX name: /[[:...:]]/' => ['pattern' => '/[[:...:]]/', 'offsets' => [8, 3]],
            '130 escaped letter inside POSIX name: /[[:l\\ower:]]/' => ['pattern' => '/[[:l\\ower:]]/', 'offsets' => [11, 3]],
            '130 escaped colon before POSIX close: /[[:abc\\:]]/' => ['pattern' => '/[[:abc\\:]]/', 'offsets' => [9, 3]],
            '130 escaped bracket inside POSIX name: /[abc[:x\\]pqr:]]/' => ['pattern' => '/[abc[:x\\]pqr:]]/', 'offsets' => [14, 6]],
            '130 \\d inside POSIX name: /[[:a\\dz:]]/' => ['pattern' => '/[[:a\\dz:]]/', 'offsets' => [9, 3]],
            '130 word-boundary POSIX name: /[a[:<:]] should give error/' => ['pattern' => '/[a[:<:]] should give error/', 'offsets' => [7, 4]],
            '130 code point inside POSIX name: /[[:a\\x{100}b:]]/u' => ['pattern' => '/[[:a\\x{100}b:]]/u', 'offsets' => [14, 3]],
            '137 \\F: /\\F/' => ['pattern' => '/\\F/', 'offsets' => [2]],
            '137 \\l: /\\l/' => ['pattern' => '/\\l/', 'offsets' => [2]],
            '137 \\L: /\\L/' => ['pattern' => '/\\L/', 'offsets' => [2]],
            '137 \\u: /\\u/' => ['pattern' => '/\\u/', 'offsets' => [2]],
            '137 \\U: /\\U/' => ['pattern' => '/\\U/', 'offsets' => [2]],
            '137 \\N{,}: /\\N{,}/' => ['pattern' => '/\\N{,}/', 'offsets' => [3, 2]],
            '137 \\N{25,ab}: /\\N{25,ab}/' => ['pattern' => '/\\N{25,ab}/', 'offsets' => [3, 2]],
            '155 \\o without brace mid-pattern: /^A\\oB/' => ['pattern' => '/^A\\oB/', 'offsets' => [4]],
            '155 \\o without brace at start: /\\othing/' => ['pattern' => '/\\othing/', 'offsets' => [2]],
            '164 non-octal digit in \\o{}: /^A\\o{1239}B/' => ['pattern' => '/^A\\o{1239}B/', 'offsets' => [9, 8]],
            '164 unclosed \\o{: /\\o{7/' => ['pattern' => '/\\o{7/', 'offsets' => [4, 3]],
            '164 letters in \\o{}: /\\o{whatever}/' => ['pattern' => '/\\o{whatever}/', 'offsets' => [4, 3]],
            '167 non-hex letters in \\x{}: /^A\\x{zz}B/' => ['pattern' => '/^A\\x{zz}B/', 'offsets' => [6, 5]],
            '167 unclosed \\x{ with bad char: /^A\\x{12Z/' => ['pattern' => '/^A\\x{12Z/', 'offsets' => [8, 7]],
            '167 unclosed \\x{: /\\x{2/' => ['pattern' => '/\\x{2/', 'offsets' => [4, 3]],
            '167 letters in \\x{}: /\\x{whatever}/' => ['pattern' => '/\\x{whatever}/', 'offsets' => [4, 3]],
            '167 unclosed \\N{U+ under (*UTF): /(*UTF)\\N{U+2/' => ['pattern' => '/(*UTF)\\N{U+2/', 'offsets' => [12, 11]],
            '171 \\N alone in class: /[\\N]/' => ['pattern' => '/[\\N]/', 'offsets' => [3]],
            '171 \\N first in class: /a[\\NB]c/' => ['pattern' => '/a[\\NB]c/', 'offsets' => [4]],
            '171 \\N mid class: /a[B\\Nc]/' => ['pattern' => '/a[B\\Nc]/', 'offsets' => [5]],
            '173 \\x{d800} under UTF: /\\x{d800}/u' => ['pattern' => '/\\x{d800}/u', 'offsets' => [7]],
            '173 \\x{dfff} under UTF: /\\x{dfff}/u' => ['pattern' => '/\\x{dfff}/u', 'offsets' => [7]],
            '173 \\o{154000} under UTF: /\\o{154000}/u' => ['pattern' => '/\\o{154000}/u', 'offsets' => [9]],
            '173 \\o{157777} under UTF: /\\o{157777}/u' => ['pattern' => '/\\o{157777}/u', 'offsets' => [9]],
            '178 unclosed empty \\x{: /^A\\x{/' => ['pattern' => '/^A\\x{/', 'offsets' => [5]],
            '178 empty \\o{}: /\\o{}/' => ['pattern' => '/\\o{}/', 'offsets' => [3]],
            '178 empty \\N{U+} under UTF: /\\N{U+}/u' => ['pattern' => '/\\N{U+}/u', 'offsets' => [5]],
            '193 \\N{U+} without UTF: /\\N{U+}/' => ['pattern' => '/\\N{U+}/', 'offsets' => [6, 2]],
            '193 \\N{U+1 } without UTF: /\\N{U+1 }/' => ['pattern' => '/\\N{U+1 }/', 'offsets' => [8, 2]],
        ];
    }
}
