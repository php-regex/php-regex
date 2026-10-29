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

namespace PHPRegex\Tests\Unit\Lint\Formatter;

use PHPRegex\Linter\Formatter\AnsiMarkup;
use PHPRegex\Linter\Formatter\ConsoleBadge;
use PHPRegex\Linter\Formatter\ConsoleLayout;
use PHPRegex\Linter\Formatter\ConsoleStyle;
use PHPRegex\Linter\Formatter\TagMarkup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * The two markups of the console reports, ANSI codes for `regex lint` and
 * console tags for the bridges, and the parts of the report both lay out
 * alike.
 */
final class ConsoleMarkupTest extends TestCase
{
    /**
     * @return iterable<string, array{style: ConsoleStyle, ansi: string, tag: string}>
     */
    public static function provideStyles(): iterable
    {
        yield 'accent' => ['style' => ConsoleStyle::Accent, 'ansi' => "\033[36m", 'tag' => 'fg=cyan'];
        yield 'bold accent' => ['style' => ConsoleStyle::AccentBold, 'ansi' => "\033[36m\033[1m", 'tag' => 'fg=cyan;options=bold'];
        yield 'error' => ['style' => ConsoleStyle::Error, 'ansi' => "\033[31m", 'tag' => 'fg=red'];
        yield 'bold error' => ['style' => ConsoleStyle::ErrorBold, 'ansi' => "\033[31m\033[1m", 'tag' => 'fg=red;options=bold'];
        yield 'success' => ['style' => ConsoleStyle::Success, 'ansi' => "\033[32m", 'tag' => 'fg=green'];
        yield 'bold success' => ['style' => ConsoleStyle::SuccessBold, 'ansi' => "\033[32m\033[1m", 'tag' => 'fg=green;options=bold'];
        yield 'warning' => ['style' => ConsoleStyle::Warning, 'ansi' => "\033[33m", 'tag' => 'fg=yellow'];
        yield 'bold warning' => ['style' => ConsoleStyle::WarningBold, 'ansi' => "\033[33m\033[1m", 'tag' => 'fg=yellow;options=bold'];
        yield 'plain' => ['style' => ConsoleStyle::Plain, 'ansi' => "\033[37m", 'tag' => 'fg=white'];
        yield 'muted' => ['style' => ConsoleStyle::Muted, 'ansi' => "\033[90m", 'tag' => 'fg=gray'];
    }

    #[Test]
    #[DataProvider('provideStyles')]
    public function test_a_style_wraps_the_text_in_its_codes_or_its_tag(ConsoleStyle $style, string $ansi, string $tag): void
    {
        $this->assertSame($ansi."a<b\033[0m", (new AnsiMarkup(true))->style('a<b', $style));
        $this->assertSame('a<b', (new AnsiMarkup(false))->style('a<b', $style));
        $this->assertSame('<'.$tag.'>a</>', (new TagMarkup())->style('a', $style));
    }

    /**
     * @return iterable<string, array{badge: ConsoleBadge, ansi: string, plain: string, tag: string}>
     */
    public static function provideBadges(): iterable
    {
        yield 'fail' => ['badge' => ConsoleBadge::Fail, 'ansi' => "\033[41m\033[37m\033[1m FAIL \033[0m", 'plain' => 'FAIL', 'tag' => '<bg=red;fg=white;options=bold> FAIL </>'];
        yield 'warn' => ['badge' => ConsoleBadge::Warn, 'ansi' => "\033[43m\033[30m\033[1m WARN \033[0m", 'plain' => 'WARN', 'tag' => '<bg=yellow;fg=black;options=bold> WARN </>'];
        yield 'info' => ['badge' => ConsoleBadge::Info, 'ansi' => "\033[100m\033[37m\033[1m INFO \033[0m", 'plain' => 'INFO', 'tag' => '<bg=gray;fg=white;options=bold> INFO </>'];
        yield 'tip' => ['badge' => ConsoleBadge::Tip, 'ansi' => "\033[46m\033[30m\033[1m TIP \033[0m", 'plain' => 'TIP ', 'tag' => '<bg=cyan;fg=black;options=bold> TIP </>'];
        yield 'pass' => ['badge' => ConsoleBadge::Pass, 'ansi' => "\033[42m\033[37m\033[1m PASS \033[0m", 'plain' => 'PASS', 'tag' => '<bg=green;fg=white;options=bold> PASS </>'];
        yield 'pass with warnings' => ['badge' => ConsoleBadge::PassWithWarnings, 'ansi' => "\033[43m\033[30m\033[1m PASS \033[0m", 'plain' => 'PASS', 'tag' => '<bg=yellow;fg=black;options=bold> PASS </>'];
    }

    #[Test]
    #[DataProvider('provideBadges')]
    public function test_a_badge_is_its_label_in_its_colors(ConsoleBadge $badge, string $ansi, string $plain, string $tag): void
    {
        $this->assertSame($ansi, (new AnsiMarkup(true))->badge($badge));
        $this->assertSame($plain, (new AnsiMarkup(false))->badge($badge));
        $this->assertSame($tag, (new TagMarkup())->badge($badge));
    }

    /**
     * The tag markup escapes text as the console reads it; ANSI codes need
     * no escaping.
     */
    #[Test]
    #[DataProvider('provideEscapes')]
    public function test_text_is_escaped_for_the_markup_that_reads_tags(string $text): void
    {
        $this->assertSame($text, (new AnsiMarkup(true))->escape($text));
        $escaped = (new TagMarkup())->escape($text);
        $this->assertSame($text, str_replace("\0", '\\', (new OutputFormatter())->format('<fg=white>'.$escaped.'</>') ?? ''));
        $this->assertSame(OutputFormatter::escape($text), $escaped);
    }

    /**
     * @return iterable<string, array{text: string}>
     */
    public static function provideEscapes(): iterable
    {
        yield 'a tag' => ['text' => '/<info>a/'];
        yield 'a trailing backslash' => ['text' => 'C:\\dir\\'];
        yield 'plain text' => ['text' => 'abc'];
        yield 'nothing' => ['text' => ''];
    }

    /**
     * The NUL bytes that stand for trailing backslashes are the only ones
     * the escaped text ends with: a NUL inside is kept, unless trailing
     * backslashes follow, when the console would read it as one of them.
     */
    #[Test]
    public function test_a_nul_byte_is_kept_unless_trailing_backslashes_follow(): void
    {
        $this->assertSame("a\0b", (new TagMarkup())->escape("a\0b"));
        $this->assertSame("ab\0\0", (new TagMarkup())->escape("a\0b\\"));
    }

    #[Test]
    public function test_a_muted_run_is_one_tag_or_a_code_per_segment(): void
    {
        $this->assertSame("\033[90m↳\033[0m \033[90mabc\033[0m", (new AnsiMarkup(true))->muted('↳', 'abc'));
        $this->assertSame('↳ abc', (new AnsiMarkup(false))->muted('↳', 'abc'));
        $this->assertSame('<fg=gray>↳ abc</>', (new TagMarkup())->muted('↳', 'abc'));
    }

    #[Test]
    public function test_layout_marks_an_issue_by_its_level(): void
    {
        $layout = new ConsoleLayout(new TagMarkup());

        $this->assertSame('<bg=red;fg=white;options=bold> FAIL </>', $layout->issueBadge('error'));
        $this->assertSame('<bg=yellow;fg=black;options=bold> WARN </>', $layout->issueBadge('warning'));
        $this->assertSame('<bg=gray;fg=white;options=bold> INFO </>', $layout->issueBadge('info'));
        $this->assertSame('<bg=gray;fg=white;options=bold> INFO </>', $layout->issueBadge('style'));
    }

    /**
     * The first line of a message stands beside its badge; each line under
     * it is muted, the first behind an arrow, its "Line N:" blanked out.
     */
    #[Test]
    public function test_layout_writes_an_issue_and_the_lines_under_it(): void
    {
        $tags = new ConsoleLayout(new TagMarkup());
        $ansi = new ConsoleLayout(new AnsiMarkup(false));

        $this->assertSame("    B <fg=white>One \\<b\\></>\n", $tags->issue('B', 'One <b>'));
        $this->assertSame("    B <fg=white>One</>\n         <fg=gray>↳         a\\<</>\n         <fg=gray>  ^</>\n", $tags->issue('B', "One\nLine 1: a<\n^"));
        $this->assertSame("    B One\n         ↳         a<\n           ^\n", $ansi->issue('B', "One\nLine 1: a<\n^"));
    }

    #[Test]
    public function test_layout_writes_a_hint_and_a_tip(): void
    {
        $this->assertSame("         <fg=gray>↳ Use \\<a\\></>\n", (new ConsoleLayout(new TagMarkup()))->hints(['Use <a>']));
        $this->assertSame("         \033[90m↳ Use <a>\033[0m\n", (new ConsoleLayout(new AnsiMarkup(true)))->hints(['Use <a>']));
        $this->assertSame("    <bg=cyan;fg=black;options=bold> TIP </>\n", (new ConsoleLayout(new TagMarkup()))->tip());
    }

    /**
     * @param array{errors: int, warnings: int, optimizations: int} $stats
     */
    #[Test]
    #[DataProvider('provideSummaries')]
    public function test_layout_sums_the_run_up(array $stats, string $expected): void
    {
        $this->assertSame($expected, (new ConsoleLayout(new TagMarkup()))->summary($stats));
    }

    /**
     * @return iterable<string, array{stats: array{errors: int, warnings: int, optimizations: int}, expected: string}>
     */
    public static function provideSummaries(): iterable
    {
        yield 'a failure' => ['stats' => ['errors' => 2, 'warnings' => 1, 'optimizations' => 3], 'expected' => "\n  <bg=red;fg=white;options=bold> FAIL </> <fg=red;options=bold>2 invalid patterns</><fg=gray>, 1 warnings, 3 optimizations.</>\n"];
        yield 'a pass with warnings' => ['stats' => ['errors' => 0, 'warnings' => 1, 'optimizations' => 3], 'expected' => "\n  <bg=yellow;fg=black;options=bold> PASS </> <fg=yellow;options=bold>1 warnings found</><fg=gray>, 3 optimizations available.</>\n"];
        yield 'a pass' => ['stats' => ['errors' => 0, 'warnings' => 0, 'optimizations' => 0], 'expected' => "\n  <bg=green;fg=white;options=bold> PASS </> <fg=green;options=bold>No issues found</><fg=gray>, 0 optimizations available.</>\n"];
    }
}
