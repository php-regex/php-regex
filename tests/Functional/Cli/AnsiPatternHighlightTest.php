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

namespace PHPRegex\Tests\Functional\Cli;

use PHPRegex\Cli\ApplicationFactory;
use PHPRegex\Cli\Output;
use PHPRegex\Explain\Highlighter\ConsoleHighlighter;
use PHPRegex\Tests\Support\EngineProbe;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * On a terminal that takes colours, the commands that echo the pattern
 * echo it highlighted, as the console highlighter colours it; without
 * colours they echo it as written, or not at all.
 */
final class AnsiPatternHighlightTest extends TestCase
{
    private const PATTERN = '/a+/';

    #[Test]
    #[DataProvider('provideCommands')]
    public function test_the_pattern_is_echoed_highlighted_on_a_terminal(string $command, bool $echoedWithoutColours): void
    {
        $highlighted = Regex::create(['cache' => null])->parse(self::PATTERN)->accept(new ConsoleHighlighter());

        [$code, $written] = $this->runRegex([$command, self::PATTERN], true);

        $this->assertSame(0, $code, $written);
        $this->assertStringContainsString($highlighted, $written);
    }

    #[Test]
    #[DataProvider('provideCommands')]
    public function test_the_pattern_is_echoed_as_written_without_colours(string $command, bool $echoedWithoutColours): void
    {
        $highlighted = Regex::create(['cache' => null])->parse(self::PATTERN)->accept(new ConsoleHighlighter());

        [$code, $written] = $this->runRegex([$command, self::PATTERN], false);

        $this->assertSame(0, $code, $written);
        $this->assertStringNotContainsString($highlighted, $written);
        $this->assertSame($echoedWithoutColours, str_contains($written, self::PATTERN));
    }

    #[Test]
    public function test_validate_escapes_controls_before_styling_and_aligns_the_caret(): void
    {
        foreach ([false, true] as $terminal) {
            foreach (["\e]52;c;YQ==\x07", "\r", "\t", "\u{202E}", "\x9B"] as $control) {
                $pattern = '/a'.$control.'(/';
                $this->assertFalse(EngineProbe::compiles($pattern), 'PCRE refuses the unclosed group.');
                $this->assertTrue(EngineProbe::compiles('/a'.$control.'/'), 'PCRE compiles the closed one.');
                [$code, $written] = $this->runRegex(['validate', $pattern], $terminal);
                $this->assertSame(1, $code);
                $plain = (string) preg_replace('/\\x1B\\[[0-9;]*m/', '', $written);
                $this->assertStringNotContainsString($control, $plain);
                $this->assertSame(1, preg_match('/(Line 1: [^\\n]*)\\n( *)\\^/', $plain, $matches));
                $this->assertSame(mb_strlen($matches[1]), strlen($matches[2]));

                [$code, $written] = $this->runRegex(['validate', '/a'.$control.'/'], $terminal);
                $this->assertSame(0, $code);
                $plain = (string) preg_replace('/\\x1B\\[[0-9;]*m/', '', $written);
                $this->assertStringNotContainsString($control, $plain);
            }
        }
    }

    /**
     * PCRE refuses the unbounded lookbehind at its opening parenthesis, past
     * the tab: the caret stays under it once the tab is spelled "\t".
     */
    #[Test]
    public function test_validate_keeps_the_caret_under_an_interior_fault_after_an_escape(): void
    {
        $pattern = "/a\t(?<=a+)b/";
        // PCRE2 10.39 to 10.42 say "not fixed length", 10.43 and later "not limited".
        $this->assertFalse(EngineProbe::compiles($pattern));

        foreach ([false, true] as $terminal) {
            [$code, $written] = $this->runRegex(['validate', $pattern], $terminal);
            $plain = (string) preg_replace('/\x1B\[[0-9;]*m/', '', $written);

            $this->assertSame(1, $code);
            $this->assertStringContainsString("Line 1: a\\t(?<=a+)b\n".str_repeat(' ', 8 + 1 + 2).'^', $plain);
        }
    }

    /**
     * Without "u" PCRE reads a hidden character byte by byte, under it as
     * one code point: the echoed pattern spells it the way PCRE reads it.
     */
    #[Test]
    #[DataProvider('provideHiddenCharacterModes')]
    public function test_validate_spells_a_hidden_character_in_the_mode_of_the_pattern(string $pattern, string $spelled): void
    {
        $this->assertSame(1, preg_match($pattern, "a\u{202E}b"));

        [$code, $written] = $this->runRegex(['validate', $pattern], false);

        $this->assertSame(0, $code);
        $this->assertStringContainsString($spelled, $written);
        $this->assertStringNotContainsString("\u{202E}", $written);
    }

    /**
     * @return iterable<string, array{pattern: string, spelled: string}>
     */
    public static function provideHiddenCharacterModes(): iterable
    {
        yield 'bytes' => ['pattern' => "/a\u{202E}b/", 'spelled' => '/a\\xE2\\x80\\xAEb/'];
        yield 'code points' => ['pattern' => "/a\u{202E}b/u", 'spelled' => '/a\\x{202E}b/u'];
    }

    /**
     * A pattern that parses but fails validation, a range out of order,
     * prints its caret snippet in display form in every command that
     * shows it.
     */
    #[Test]
    #[DataProvider('provideSnippetCommands')]
    public function test_the_caret_snippet_of_an_invalid_pattern_is_escaped(string $command): void
    {
        $pattern = "/\e]52;c;YQ==\x07[z-a]/";
        // The offset PCRE2 names moved between releases (15 in 10.44, 16 in 10.49).
        $this->assertStringStartsWith('preg_match(): Compilation failed: range out of order in character class', (string) EngineProbe::warning($pattern));

        [, $written] = $this->runRegex([$command, $pattern], false);

        $this->assertStringNotContainsString("\e", $written);
        $this->assertStringNotContainsString("\x07", $written);
        $this->assertStringContainsString('\\x1B]52;c;YQ==\\x07[z-a]', $written);
    }

    /**
     * @return iterable<string, array{command: string}>
     */
    public static function provideSnippetCommands(): iterable
    {
        yield 'validate' => ['command' => 'validate'];
        yield 'analyze' => ['command' => 'analyze'];
        yield 'parse' => ['command' => 'parse'];
    }

    /**
     * A pattern past the library's length limit is no verdict: its error
     * and the hint each take a line of their own, indented.
     */
    #[Test]
    public function test_validate_writes_the_limit_error_and_its_hint_on_lines_of_their_own(): void
    {
        $pattern = '/a(?#'.str_repeat('x', 100_000)."\e)/";
        $this->assertSame(1, preg_match($pattern, 'a'), 'The limit is the library\'s, not PCRE\'s.');

        [$code, $written] = $this->runRegex(['validate', $pattern], false);

        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression('/\n  Regex pattern exceeds the maximum length of 100000 bytes[^\n]*\n  A limit of this library, not of PCRE[^\n]*\n/', $written);
    }

    #[Test]
    public function test_analyze_ends_the_caret_line(): void
    {
        [, $written] = $this->runRegex(['analyze', '/[z-a]/'], false);

        $this->assertMatchesRegularExpression('/\nLine 1: \[z-a\]\n {9,}\^\n  \S/', $written);
    }

    #[Test]
    public function test_parse_reports_the_recompiled_pattern_spelled_out(): void
    {
        [, $written] = $this->runRegex(['parse', "/a\e]0;t\x07b/"], false);

        $this->assertStringContainsString("Parse: OK\n", $written);
        $this->assertStringContainsString('Recompiled: /a\x1B]0;t\x07b/', $written);
    }

    /**
     * Parse colours the pattern on a terminal unless spelling it out changed
     * it; without colours it is echoed as spelled.
     */
    #[Test]
    #[DataProvider('provideParseEchoes')]
    public function test_parse_colours_only_a_pattern_written_as_it_reads(string $pattern, bool $terminal, bool $coloured, string $echoed): void
    {
        [, $written] = $this->runRegex(['parse', $pattern], $terminal);
        // The echo runs from the label to the parse status, on the label's
        // line without colours and on the next one with them.
        $this->assertSame(1, preg_match('/Pattern(.*?)Parse/s', $written, $echo));

        $this->assertSame($coloured, str_contains($echo[1], "\e[38;2;"), $echo[1]);
        $this->assertStringContainsString($echoed, (string) preg_replace('/\x1B\[[0-9;]*m/', '', $echo[1]));
    }

    /**
     * @return iterable<string, array{pattern: string, terminal: bool, coloured: bool, echoed: string}>
     */
    public static function provideParseEchoes(): iterable
    {
        yield 'a terminal, a plain pattern' => ['pattern' => '/a+/', 'terminal' => true, 'coloured' => true, 'echoed' => 'a+'];
        yield 'a terminal, a control' => ['pattern' => "/a\e/", 'terminal' => true, 'coloured' => false, 'echoed' => '/a\x1B/'];
        yield 'no colours, a plain pattern' => ['pattern' => '/a+/', 'terminal' => false, 'coloured' => false, 'echoed' => '/a+/'];
        yield 'no colours, a control' => ['pattern' => "/a\e/", 'terminal' => false, 'coloured' => false, 'echoed' => '/a\x1B/'];
    }

    #[Test]
    public function test_highlight_neutralizes_comment_controls_with_and_without_ansi(): void
    {
        $pattern = "/(?#café\\d\x1B]0;title\x07\r\x08\x7F\u{9B})a/";
        $this->assertSame(1, preg_match($pattern, 'a'), 'PCRE reads the comment as nothing.');
        foreach ([true, false] as $terminal) {
            [$code, $written] = $this->runRegex(['highlight', $pattern], $terminal);
            $plain = (string) preg_replace('/\\x1B\\[[0-9;]*m/', '', $written);

            $this->assertSame(0, $code);
            // Without colours the whole pattern is spelled in its mode, bytes
            // without "u"; coloured, each token is spelled on its own.
            $this->assertStringContainsString('(?#café\\d\\x1B]0;title\\x07\\r\\x08\\x7F'.($terminal ? '\\x{9B}' : '\\xC2\\x9B').')a', $plain);
            $this->assertSame(0, preg_match('/[\\x00-\\x08\\x0B-\\x1F\\x7F]/', $plain));
        }
    }

    /**
     * A backslash before a raw control escapes that control: PCRE matches
     * the ESC byte. Spelled out, it stays one escape, "\x1B", not an
     * escaped backslash followed by "x1B".
     */
    #[Test]
    public function test_highlight_spells_an_escaped_control_as_one_escape_with_and_without_ansi(): void
    {
        $pattern = "/a\\\x1Bb/";
        $this->assertSame(1, preg_match($pattern, "a\x1Bb"));
        $this->assertSame(1, preg_match('/a\x1Bb/', "a\x1Bb"));
        foreach ([true, false] as $terminal) {
            [$code, $written] = $this->runRegex(['highlight', $pattern], $terminal);
            $plain = (string) preg_replace('/\x1B\[[0-9;]*m/', '', $written);

            $this->assertSame(0, $code);
            $this->assertStringContainsString('a\x1Bb', $plain);
            $this->assertStringNotContainsString('a\\\\x1Bb', $plain);
        }
    }

    /**
     * Explain echoes the pattern beside its banner alone, which a run
     * without colours leaves out.
     *
     * @return iterable<string, array{command: string, echoedWithoutColours: bool}>
     */
    public static function provideCommands(): iterable
    {
        yield 'validate' => ['command' => 'validate', 'echoedWithoutColours' => true];
        yield 'analyze' => ['command' => 'analyze', 'echoedWithoutColours' => true];
        yield 'explain' => ['command' => 'explain', 'echoedWithoutColours' => false];
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string} the exit code, and what was written to stdout and stderr
     */
    private function runRegex(array $arguments, bool $terminal): array
    {
        $errors = fopen('php://memory', 'w+');
        $this->assertIsResource($errors);

        $application = ApplicationFactory::create(new Output($terminal, false, '#', '-', $errors));

        $level = ob_get_level();
        ob_start();

        try {
            $exitCode = $application->run(['regex', ...($terminal ? ['--ansi'] : ['--no-ansi', '--no-visuals']), ...$arguments]);
            $written = (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        rewind($errors);
        $written .= (string) stream_get_contents($errors);
        fclose($errors);

        return [$exitCode, $written];
    }
}
