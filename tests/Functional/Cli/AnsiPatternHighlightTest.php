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
