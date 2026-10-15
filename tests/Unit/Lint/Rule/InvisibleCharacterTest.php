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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A control character, or a character that hides or moves text, written
 * raw in a pattern does not show where the pattern is read. The patterns
 * below are built with PHP's own escapes, so that this file holds none of
 * them raw. PHP 8.4.26 / PCRE2 10.49: each reported pattern matches every
 * subject below as the rewrite its tip names does.
 */
final class InvisibleCharacterTest extends TestCase
{
    private const RULE = 'regex.lint.literal.invisible';

    private const SUBJECTS = ['', 'a', 'ab', "a\tb", "a\nb", "a\rb", "a\0b", "a\eb", "a\x7Fb", "a\u{200B}b", "\u{FEFF}abc", "a\u{AD}b", "a\u{85}b", "\u{202E}", "a\u{2028}", "\t", ' ', 'a\\b', "a\\\tb"];

    /**
     * @param list<array{offset: int, message: string, hint: string}> $issues
     */
    #[Test]
    #[DataProvider('provideRawCharacters')]
    public function test_a_raw_invisible_character_is_reported_with_its_escape(string $pattern, string $rewrite, array $issues): void
    {
        foreach (self::SUBJECTS as $subject) {
            $this->assertSame(
                [preg_match_all($rewrite, $subject, $expected, \PREG_OFFSET_CAPTURE), $expected],
                [preg_match_all($pattern, $subject, $actual, \PREG_OFFSET_CAPTURE), $actual],
                json_encode($pattern).' on '.json_encode($subject),
            );
        }

        $this->assertSame($issues, array_map(
            static fn (RuleViolation $issue): array => ['offset' => (int) $issue->offset, 'message' => $issue->message, 'hint' => (string) $issue->hint],
            $this->issues($pattern),
        ));
        foreach ($this->issues($pattern) as $issue) {
            $this->assertSame(LintSeverity::Warning, $issue->severity);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, rewrite: string, issues: list<array{offset: int, message: string, hint: string}>}>
     */
    public static function provideRawCharacters(): iterable
    {
        $control = static fn (int $offset, string $codePoint, string $escape): array => ['offset' => $offset, 'message' => \sprintf('The pattern holds a raw control character U+%s, which does not show where the pattern is read.', $codePoint), 'hint' => \sprintf('Write "%s".', $escape)];
        $format = static fn (int $offset, string $codePoint, string $escape): array => ['offset' => $offset, 'message' => \sprintf('The pattern holds a raw format character U+%s, which does not show where the pattern is read.', $codePoint), 'hint' => \sprintf('Write "%s".', $escape)];

        yield 'a tab' => ['pattern' => "/a\tb/", 'rewrite' => '/a\tb/', 'issues' => [$control(1, '0009', '\t')]];
        yield 'a line feed' => ['pattern' => "/a\nb/", 'rewrite' => '/a\nb/', 'issues' => [$control(1, '000A', '\n')]];
        yield 'a carriage return' => ['pattern' => "/a\rb/", 'rewrite' => '/a\rb/', 'issues' => [$control(1, '000D', '\r')]];
        yield 'a NUL' => ['pattern' => "/a\0b/", 'rewrite' => '/a\x00b/', 'issues' => [$control(1, '0000', '\x00')]];
        yield 'an escape' => ['pattern' => "/a\eb/", 'rewrite' => '/a\x1Bb/', 'issues' => [$control(1, '001B', '\x1B')]];
        yield 'a delete' => ['pattern' => "/a\x7Fb/", 'rewrite' => '/a\x7Fb/', 'issues' => [$control(1, '007F', '\x7F')]];
        yield 'a next line under u' => ['pattern' => "/a\u{85}b/u", 'rewrite' => '/a\x{85}b/u', 'issues' => [$control(1, '0085', '\x{85}')]];
        yield 'a zero-width space under u' => ['pattern' => "/a\u{200B}b/u", 'rewrite' => '/a\x{200B}b/u', 'issues' => [$format(1, '200B', '\x{200B}')]];
        yield 'a zero-width space read as bytes' => ['pattern' => "/a\u{200B}b/", 'rewrite' => '/a\xE2\x80\x8Bb/', 'issues' => [$format(1, '200B', '\xE2\x80\x8B')]];
        yield 'a zero-width space under (*UTF)' => ['pattern' => "/(*UTF)a\u{200B}b/", 'rewrite' => '/(*UTF)a\x{200B}b/', 'issues' => [$format(7, '200B', '\x{200B}')]];
        yield 'a byte order mark' => ['pattern' => "/\u{FEFF}abc/u", 'rewrite' => '/\x{FEFF}abc/u', 'issues' => [$format(0, 'FEFF', '\x{FEFF}')]];
        yield 'a soft hyphen' => ['pattern' => "/a\u{AD}b/u", 'rewrite' => '/a\x{AD}b/u', 'issues' => [$format(1, '00AD', '\x{AD}')]];
        yield 'a right-to-left override in a class' => ['pattern' => "/[a\u{202E}]/u", 'rewrite' => '/[a\x{202E}]/u', 'issues' => [$format(2, '202E', '\x{202E}')]];
        yield 'a line separator ending a range' => ['pattern' => "/[\u{2027}-\u{2028}]/u", 'rewrite' => '/[\x{2027}-\x{2028}]/u', 'issues' => [
            ['offset' => 5, 'message' => 'The pattern holds a raw line separator U+2028, which does not show where the pattern is read.', 'hint' => 'Write "\x{2028}".'],
        ]];
        yield 'a tab in a class under x, which x keeps' => ['pattern' => "/[\t]/x", 'rewrite' => '/[\t]/x', 'issues' => [$control(1, '0009', '\t')]];
        yield 'a raw tab after a backslash' => ['pattern' => "/a\\\tb/", 'rewrite' => '/a\tb/', 'issues' => [$control(1, '0009', '\t')]];
        yield 'a tab in a quote' => ['pattern' => "/\\Qa\tb\\E/", 'rewrite' => '/\Qa\E\t\Qb\E/', 'issues' => [
            ['offset' => 3, 'message' => 'The pattern holds a raw control character U+0009, which does not show where the pattern is read.', 'hint' => 'Write "\E\t\Q" in its place.'],
        ]];
        yield 'a tab in a quote left open' => ['pattern' => "/\\Qa\tb/", 'rewrite' => '/\Qa\E\t\Qb/', 'issues' => [
            ['offset' => 3, 'message' => 'The pattern holds a raw control character U+0009, which does not show where the pattern is read.', 'hint' => 'Write "\E\t\Q" in its place.'],
        ]];
        yield 'a paragraph separator' => ['pattern' => "/a\u{2029}/u", 'rewrite' => '/a\x{2029}/u', 'issues' => [
            ['offset' => 1, 'message' => 'The pattern holds a raw paragraph separator U+2029, which does not show where the pattern is read.', 'hint' => 'Write "\x{2029}".'],
        ]];
        yield 'two characters' => ['pattern' => "/\t\u{200B}/u", 'rewrite' => '/\t\x{200B}/u', 'issues' => [$control(0, '0009', '\t'), $format(1, '200B', '\x{200B}')]];
    }

    #[Test]
    #[DataProvider('provideVisibleSpellings')]
    public function test_a_character_written_as_an_escape_or_skipped_is_not_reported(string $pattern): void
    {
        $this->assertSame([], $this->issues($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideVisibleSpellings(): iterable
    {
        yield 'a tab escape' => ['pattern' => '/a\tb/'];
        yield 'a hexadecimal escape' => ['pattern' => '/a\x09b/'];
        yield 'a control escape' => ['pattern' => '/a\cIb/'];
        yield 'an octal escape' => ['pattern' => '/a\011b/'];
        yield 'a braced escape' => ['pattern' => '/a\x{200B}b/u'];
        yield 'a code point escape' => ['pattern' => '/a\N{U+200B}b/u'];
        yield 'a line break x skips' => ['pattern' => "/a\n  b/x"];
        yield 'a tab x skips' => ['pattern' => "/a\tb/x"];
        yield 'a next line x skips under u' => ['pattern' => "/a\u{85}b/ux"];
        yield 'a comment group' => ['pattern' => "/a(?#\u{200B})b/u"];
        yield 'a comment under x' => ['pattern' => "/a # \u{200B}\nb/xu"];
        yield 'a verb name' => ['pattern' => "/(*MARK:\u{200B})a/u"];
        yield 'a no-break space, which shows as a space' => ['pattern' => "/a\u{A0}b/u"];
        yield 'a byte that is no UTF-8' => ['pattern' => "/a\x85b/"];
        yield 'a printable letter' => ['pattern' => '/é/u'];
    }

    #[Test]
    public function test_the_rule_can_be_turned_off(): void
    {
        $linter = new PatternLinter(['literal.invisible' => false]);
        Regex::create(['cache' => null])->parse("/a\tb/")->accept($linter);

        $this->assertSame([], array_values(array_filter($linter->getIssues(), static fn (RuleViolation $issue): bool => self::RULE === $issue->id)));
    }

    /**
     * A character of several bytes is sorted by its first byte, read alone:
     * PHP 8.5 deprecates ord() on a longer string.
     */
    #[Test]
    public function test_a_character_of_several_bytes_is_sorted_without_a_notice(): void
    {
        set_error_handler(static function (int $level, string $message): never {
            throw new \ErrorException($message, 0, $level);
        });

        try {
            $issues = [...$this->issues("/a\u{85}b/u"), ...$this->issues("/a\u{200B}b/u")];
        } finally {
            restore_error_handler();
        }

        $this->assertStringContainsString('control character U+0085', $issues[0]->message);
        $this->assertStringContainsString('format character U+200B', $issues[1]->message);
    }

    /**
     * @return list<RuleViolation>
     */
    private function issues(string $pattern): array
    {
        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        return array_values(array_filter($linter->getIssues(), static fn (RuleViolation $issue): bool => self::RULE === $issue->id));
    }
}
