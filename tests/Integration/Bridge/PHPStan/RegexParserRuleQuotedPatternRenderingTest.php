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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan;

use PHPRegex\PHPStan\RegexPatternRule;
use PHPStan\Analyser\Error;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The pattern quoted by the trivial-match message and by the optimization
 * message is the one the console shows, as in the ReDoS message: valid UTF-8
 * however it is cut, cut after 50 characters and followed by "..." only
 * then, on one line, and without the characters that move or hide text in a
 * terminal, spelled in the pattern's own mode.
 *
 * Every pattern shown reads back with the matches of the pattern it was
 * written from (PHP 8.4, PCRE2 10.49, JIT off).
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexParserRuleQuotedPatternRenderingTest extends RuleTestCase
{
    private const FIXTURE = __DIR__.'/Fixtures/QuotedPatternRenderingFixture.php';

    /**
     * A pattern longer than 50 characters is cut after its 50th character:
     * "/^x" and "/[0-9]+xy" are an odd number of bytes, so byte 50 is the
     * first byte of an "é" and a cut there leaves half of it.
     */
    #[Test]
    #[DataProvider('provideLongPatterns')]
    public function test_quoted_pattern_cuts_a_long_pattern_on_a_character_boundary(string $identifier, int $line, string $pattern): void
    {
        $this->assertFalse(mb_check_encoding(substr($pattern, 0, 50), 'UTF-8'), 'Byte 50 falls inside a character.');

        $message = $this->errorOnLine($identifier, $line)->getMessage();

        $this->assertTrue(mb_check_encoding($message, 'UTF-8'), $message);
        $this->assertSame(mb_substr($pattern, 0, 50, 'UTF-8').'...', self::quotedPattern($identifier, $message));
    }

    /**
     * @return iterable<string, array{identifier: string, line: int, pattern: string}>
     */
    public static function provideLongPatterns(): iterable
    {
        yield 'trivial match' => [
            'identifier' => RegexPatternRule::IDENTIFIER_TRIVIAL_MATCH,
            'line' => 20,
            'pattern' => '/^x'.str_repeat('é', 60).'/',
        ];
        yield 'optimization' => [
            'identifier' => RegexPatternRule::IDENTIFIER_OPTIMIZATION,
            'line' => 28,
            'pattern' => '/[0-9]+xy'.str_repeat('é', 60).'/u',
        ];
    }

    /**
     * The cut never falls inside an escape sequence of the display form: a
     * "\xE2" that would run past the 50th character is left out whole, one
     * that ends on it is kept whole. The "\." before it is one sequence
     * read in one step.
     */
    #[Test]
    #[DataProvider('provideEscapesAtTheCut')]
    public function test_quoted_pattern_cut_keeps_an_escape_sequence_whole(int $line, string $shown): void
    {
        $message = $this->errorOnLine(RegexPatternRule::IDENTIFIER_TRIVIAL_MATCH, $line)->getMessage();

        $this->assertSame($shown, self::quotedPattern(RegexPatternRule::IDENTIFIER_TRIVIAL_MATCH, $message));
    }

    /**
     * @return iterable<string, array{line: int, shown: string}>
     */
    public static function provideEscapesAtTheCut(): iterable
    {
        yield 'an escape running past the cut is left out' => [
            'line' => 36,
            'shown' => '/^\.'.str_repeat('a', 44).'...',
        ];
        yield 'an escape ending on the cut is kept' => [
            'line' => 37,
            'shown' => '/^\.'.str_repeat('a', 42).'\xE2...',
        ];
        yield 'fifty characters are shown whole' => [
            'line' => 41,
            'shown' => '/^'.str_repeat('a', 47).'/',
        ];
        yield 'fifty-one characters are cut after the fiftieth' => [
            'line' => 42,
            'shown' => '/^'.str_repeat('a', 48).'...',
        ];
        // "\\" is one sequence: its second backslash opens nothing.
        yield 'an escaped backslash ending on the cut is kept' => [
            'line' => 43,
            'shown' => '/^'.str_repeat('a', 46).'\\\\...',
        ];
        // The "\xE2" right after "\\" is read from its own backslash, and
        // runs past the cut.
        yield 'an escape right after an escaped backslash is left out' => [
            'line' => 44,
            'shown' => '/^'.str_repeat('a', 44).'\\\\...',
        ];
    }

    /**
     * An escape with a braced operand is one sequence up to its "}": a
     * "\x{202E}" under u, or a "\p{Lu}", that would run past the 50th
     * character is left out whole.
     */
    #[Test]
    #[DataProvider('provideBracedEscapesAtTheCut')]
    public function test_quoted_pattern_cut_keeps_a_braced_escape_whole(string $identifier, int $line, string $shown): void
    {
        $message = $this->errorOnLine($identifier, $line)->getMessage();

        $this->assertSame($shown, self::quotedPattern($identifier, $message));
    }

    /**
     * @return iterable<string, array{identifier: string, line: int, shown: string}>
     */
    public static function provideBracedEscapesAtTheCut(): iterable
    {
        yield 'a code point under u' => [
            'identifier' => RegexPatternRule::IDENTIFIER_OPTIMIZATION,
            'line' => 45,
            'shown' => '/^'.str_repeat('a', 41).'...',
        ];
        yield 'a property' => [
            'identifier' => RegexPatternRule::IDENTIFIER_OPTIMIZATION,
            'line' => 46,
            'shown' => '/[0-9]+'.str_repeat('a', 40).'...',
        ];
    }

    /**
     * An escape whose operand follows it unbraced is one sequence too: a
     * control character "\cA", a named back reference or subroutine call
     * "\k<n>", "\k'n'", "\g<n>", a relative or numbered one "\g-1", "\g1",
     * and the digits of an octal escape or a back reference ("\12" and
     * "\012" are octal with no group 12, "\10" a back reference to the
     * tenth group). Each starts at the 49th character and runs past the
     * 50th: it is left out whole, and the text stops before its backslash.
     */
    #[Test]
    #[DataProvider('provideUnbracedEscapesAtTheCut')]
    public function test_quoted_pattern_cut_keeps_an_unbraced_escape_whole(int $line, string $pattern): void
    {
        $this->assertSame('\\', $pattern[48], 'The escape starts at the 49th character.');
        $this->assertNotFalse(@preg_match($pattern, ''), 'The pattern compiles.');

        $message = $this->errorOnLine(RegexPatternRule::IDENTIFIER_OPTIMIZATION, $line)->getMessage();

        $this->assertSame(substr($pattern, 0, 48).'...', self::quotedPattern(RegexPatternRule::IDENTIFIER_OPTIMIZATION, $message));
    }

    /**
     * @return iterable<string, array{line: int, pattern: string}>
     */
    public static function provideUnbracedEscapesAtTheCut(): iterable
    {
        $a = static fn (int $count): string => str_repeat('a', $count);

        yield 'a control character' => ['line' => 51, 'pattern' => '/'.$a(47).'\cA[0-9]+/'];
        yield 'a back reference by name in angle brackets' => ['line' => 52, 'pattern' => '/(?<n>b)'.$a(40).'\k<n>[0-9]+/'];
        yield 'a back reference by name in quotes' => ['line' => 53, 'pattern' => '/(?<n>b)'.$a(40)."\\k'n'[0-9]+/"];
        yield 'a subroutine call by name' => ['line' => 54, 'pattern' => '/(?<n>b)'.$a(40).'\g<n>[0-9]+/'];
        yield 'a relative back reference' => ['line' => 55, 'pattern' => '/(b)'.$a(44).'\g-1[0-9]+/'];
        yield 'a numbered back reference after \g' => ['line' => 56, 'pattern' => '/(b)'.$a(44).'\g1[0-9]+/'];
        yield 'an octal escape of two digits' => ['line' => 57, 'pattern' => '/'.$a(47).'\12[0-9]+/'];
        yield 'an octal escape of three digits' => ['line' => 58, 'pattern' => '/'.$a(47).'\012[0-9]+/'];
        yield 'a back reference of two digits' => ['line' => 59, 'pattern' => '/(b)(c)(d)(e)(f)(g)(h)(i)(j)(k)'.$a(17).'\10[0-9]+/'];
        // An unbraced property is "\p" or "\P" and one letter.
        yield 'a one-letter property' => ['line' => 65, 'pattern' => '/'.$a(47).'\pL[0-9]+/'];
        yield 'a negated one-letter property' => ['line' => 66, 'pattern' => '/'.$a(47).'\PL[0-9]+/'];
        yield 'a one-letter property followed by a letter' => ['line' => 67, 'pattern' => '/'.$a(47).'\pZs[0-9]+/'];
    }

    /**
     * An unbraced property whose letter is the 50th character is kept
     * whole. "\pZs" is the property "\pZ" and the letter "s": PCRE reads
     * one letter after an unbraced "\p", so the cut falls after the "Z".
     *
     * Oracle: "/^\pZs$/u" matches "\u{2003}s" and not "\u{2003}".
     */
    #[Test]
    #[DataProvider('provideUnbracedPropertiesEndingOnTheCut')]
    public function test_quoted_pattern_cut_keeps_an_unbraced_property_ending_on_the_cut(int $line, string $pattern): void
    {
        $this->assertSame('\\', $pattern[47], 'The property starts at the 48th character.');
        $this->assertNotFalse(@preg_match($pattern, ''), 'The pattern compiles.');

        $message = $this->errorOnLine(RegexPatternRule::IDENTIFIER_OPTIMIZATION, $line)->getMessage();

        $this->assertSame(substr($pattern, 0, 50).'...', self::quotedPattern(RegexPatternRule::IDENTIFIER_OPTIMIZATION, $message));
    }

    /**
     * @return iterable<string, array{line: int, pattern: string}>
     */
    public static function provideUnbracedPropertiesEndingOnTheCut(): iterable
    {
        yield 'a one-letter property' => ['line' => 68, 'pattern' => '/'.str_repeat('a', 46).'\pL[0-9]+/'];
        yield 'a one-letter property followed by a letter' => ['line' => 69, 'pattern' => '/'.str_repeat('a', 46).'\pZs[0-9]+/'];
    }

    /**
     * A "\k<n>" whose ">" is the 50th character is kept whole.
     */
    #[Test]
    public function test_quoted_pattern_cut_keeps_an_unbraced_escape_ending_on_the_cut(): void
    {
        $pattern = '/(?<n>b)'.str_repeat('a', 37).'\k<n>[0-9]+/';
        $message = $this->errorOnLine(RegexPatternRule::IDENTIFIER_OPTIMIZATION, 60)->getMessage();

        $this->assertSame('>', $pattern[49]);
        $this->assertSame(substr($pattern, 0, 50).'...', self::quotedPattern(RegexPatternRule::IDENTIFIER_OPTIMIZATION, $message));
    }

    /**
     * The quoted pattern is one line with no "#" comment, its hidden
     * characters spelled in hex, and nothing appended when it is not cut;
     * it reads back with the matches of the pattern itself.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideDisplayedPatterns')]
    public function test_quoted_pattern_is_shown_as_the_console_shows_it(string $identifier, int $line, string $pattern, string $shown, array $subjects): void
    {
        $message = $this->errorOnLine($identifier, $line)->getMessage();
        $quoted = self::quotedPattern($identifier, $message);

        $this->assertSame($shown, $quoted);
        $this->assertStringNotContainsString("\n", $message);
        $this->assertStringNotContainsString("\r", $message);
        $this->assertTrue(mb_check_encoding($message, 'UTF-8'), $message);

        foreach ($subjects as $subject) {
            $this->assertSame(
                self::oracle($pattern, $subject),
                self::oracle($quoted, $subject),
                \sprintf('%s shown as %s on %s', json_encode($pattern), json_encode($quoted), json_encode($subject)),
            );
        }
    }

    /**
     * @return iterable<string, array{identifier: string, line: int, pattern: string, shown: string, subjects: list<string>}>
     */
    public static function provideDisplayedPatterns(): iterable
    {
        // Oracle: "abcd", "abcdx" 1; "ab cd", "xabcd", the source text 0.
        yield 'trivial match, a comment and a line break under x' => [
            'identifier' => RegexPatternRule::IDENTIFIER_TRIVIAL_MATCH,
            'line' => 21,
            'pattern' => "/^ab # letters\n cd/x",
            'shown' => '/^ab cd/x',
            'subjects' => ['abcd', 'abcdx', 'ab cd', 'xabcd', "ab # letters\n cd"],
        ];
        // Oracle: "\u{202E}ab" 1; "ab", "\xE2ab", "\u{202E}", "\u{202D}ab" 0.
        yield 'trivial match, a right-to-left override in byte mode' => [
            'identifier' => RegexPatternRule::IDENTIFIER_TRIVIAL_MATCH,
            'line' => 22,
            'pattern' => "/^\u{202E}ab/",
            'shown' => '/^\xE2\x80\xAEab/',
            'subjects' => ["\u{202E}ab", 'ab', "\xE2ab", "\u{202E}", "\u{202D}ab"],
        ];
        // Oracle: "ab", "ab\u{202E}" 1; "\u{202E}ab", "a" 0.
        yield 'trivial match, a right-to-left override in a comment' => [
            'identifier' => RegexPatternRule::IDENTIFIER_TRIVIAL_MATCH,
            'line' => 23,
            'pattern' => "/^ab(?#\u{202E})/",
            'shown' => '/^ab(?#\xE2\x80\xAE)/',
            'subjects' => ['ab', "ab\u{202E}", "\u{202E}ab", 'a'],
        ];
        // Oracle: "1x", "12x" 1; "1 x", "x", the source text 0.
        yield 'optimization, a comment and a line break under x' => [
            'identifier' => RegexPatternRule::IDENTIFIER_OPTIMIZATION,
            'line' => 29,
            'pattern' => "/[0-9]+ # digits\n x/x",
            'shown' => '/[0-9]+ x/x',
            'subjects' => ['1x', '12x', '1 x', 'x', "1 # digits\n x"],
        ];
        // Oracle: four overrides 1, also inside "a...b"; three overrides,
        // four left-to-right overrides 0.
        yield 'optimization, a right-to-left override under u' => [
            'identifier' => RegexPatternRule::IDENTIFIER_OPTIMIZATION,
            'line' => 30,
            'pattern' => "/\u{202E}\u{202E}\u{202E}\u{202E}/u",
            'shown' => '/\x{202E}\x{202E}\x{202E}\x{202E}/u',
            'subjects' => [str_repeat("\u{202E}", 4), 'a'.str_repeat("\u{202E}", 4).'b', str_repeat("\u{202E}", 3), str_repeat("\u{202D}", 4)],
        ];
    }

    /**
     * The optimization tip spells the optimized pattern as the console
     * shows it, on one line with no "#" comment and its hidden characters
     * spelled in its own mode, but never cut: a suggestion is only useful
     * whole. It reads back with the matches of the optimized pattern, and
     * those are the matches of the pattern it was written from.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideOptimizationTips')]
    public function test_optimization_tip_shows_the_whole_optimized_pattern_as_the_console_shows_it(int $line, string $pattern, string $optimized, string $shown, array $subjects): void
    {
        $tip = (string) $this->errorOnLine(RegexPatternRule::IDENTIFIER_OPTIMIZATION, $line)->getTip();

        $this->assertSame(1, preg_match('/^Consider using: (.*)$/s', $tip, $match), $tip);
        $this->assertSame($shown, $match[1]);
        $this->assertStringNotContainsString("\n", $tip);
        $this->assertStringNotContainsString("\r", $tip);
        $this->assertStringNotContainsString("\u{202E}", $tip);
        $this->assertTrue(mb_check_encoding($tip, 'UTF-8'), $tip);

        foreach ($subjects as $subject) {
            $this->assertSame(
                self::oracle($pattern, $subject),
                self::oracle($optimized, $subject),
                \sprintf('%s optimized as %s on %s', json_encode($pattern), json_encode($optimized), json_encode($subject)),
            );
            $this->assertSame(
                self::oracle($optimized, $subject),
                self::oracle($shown, $subject),
                \sprintf('%s shown as %s on %s', json_encode($optimized), json_encode($shown), json_encode($subject)),
            );
        }
    }

    /**
     * @return iterable<string, array{line: int, pattern: string, optimized: string, shown: string, subjects: list<string>}>
     */
    public static function provideOptimizationTips(): iterable
    {
        $letters = 'abcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyz';

        // Oracle: "1x", "12x" 1; "1 x", "x", the source text 0.
        yield 'a comment and a line break under x' => [
            'line' => 29,
            'pattern' => "/[0-9]+ # digits\n x/x",
            'optimized' => "/\\d+# digits\nx/x",
            'shown' => '/\d+ x/x',
            'subjects' => ['1x', '12x', '1 x', 'x', "1 # digits\n x"],
        ];
        // Oracle: four overrides 1, also inside "a...b"; three overrides,
        // four left-to-right overrides 0.
        yield 'a right-to-left override under u' => [
            'line' => 30,
            'pattern' => "/\u{202E}\u{202E}\u{202E}\u{202E}/u",
            'optimized' => "/\u{202E}{4}/u",
            'shown' => '/\x{202E}{4}/u',
            'subjects' => [str_repeat("\u{202E}", 4), 'a'.str_repeat("\u{202E}", 4).'b', str_repeat("\u{202E}", 3), str_repeat("\u{202D}", 4)],
        ];
        // Longer than the 50 characters a message quotes: the tip keeps all
        // of it. Oracle: "1", the override, the letters and "x" 1, also
        // after "12" and before "y"; without the override, with the spaces
        // of the source, or without a digit 0.
        yield 'a long pattern, a comment and a right-to-left override under x' => [
            'line' => 31,
            'pattern' => "/[0-9]+ # digits\n\u{202E}{$letters} # letters\n x/x",
            'optimized' => "/\\d+# digits\n\\xE2\\x80\\xAE{$letters}# letters\nx/x",
            'shown' => "/\\d+ \\xE2\\x80\\xAE{$letters} x/x",
            'subjects' => ["1\u{202E}{$letters}x", "12\u{202E}{$letters}xy", "1{$letters}x", "1 \u{202E}{$letters} x", "\u{202E}{$letters}x"],
        ];
    }

    protected function getRule(): Rule
    {
        return new RegexPatternRule(config: [
            'checks' => [
                'lint' => ['enabled' => false],
                'redos' => ['enabled' => false],
                'optimizations' => ['enabled' => true],
            ],
        ]);
    }

    private function errorOnLine(string $identifier, int $line): Error
    {
        foreach ($this->gatherAnalyserErrors([self::FIXTURE]) as $error) {
            if ($line === $error->getLine() && $identifier === $error->getIdentifier()) {
                return $error;
            }
        }

        $this->fail(\sprintf('No %s error on line %d: ', $identifier, $line).json_encode(array_map(
            static fn (Error $error): array => [$error->getLine(), $error->getIdentifier(), $error->getMessage()],
            $this->gatherAnalyserErrors([self::FIXTURE]),
        ), \JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * The pattern the message quotes: before " is " in the trivial-match
     * message, between the quotes in the optimization message.
     */
    private static function quotedPattern(string $identifier, string $message): string
    {
        $format = RegexPatternRule::IDENTIFIER_TRIVIAL_MATCH === $identifier
            ? '/^preg_match\(\) with (.*?) is (?:str_starts_with|str_ends_with|str_contains|in_array)\(\$subject, .*\)\.$/s'
            : '/^Regex pattern can be optimized: "(.*)"$/s';
        self::assertSame(1, preg_match($format, $message, $match), $message);

        return $match[1];
    }

    /**
     * What preg_match() answers, JIT off; a compilation failure or an engine
     * error is its message.
     */
    private static function oracle(string $pattern, string $subject): int|string
    {
        $jit = (string) \ini_get('pcre.jit');
        \ini_set('pcre.jit', '0');
        set_error_handler(static fn (): bool => true);

        try {
            $result = preg_match($pattern, $subject);
        } finally {
            restore_error_handler();
            \ini_set('pcre.jit', $jit);
        }

        return false === $result ? 'error: '.preg_last_error_msg() : $result;
    }
}
