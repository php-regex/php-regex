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
        $this->assertSame(
            'preg_match(): Compilation failed: range out of order in character class at offset 16',
            EngineProbe::warning($pattern),
        );

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
