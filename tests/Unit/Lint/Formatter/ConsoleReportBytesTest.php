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

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Formatter\ConsoleFormatter;
use PHPRegex\Linter\Formatter\ConsoleTagFormatter;
use PHPRegex\Linter\Formatter\LinkFormatter;
use PHPRegex\Linter\Formatter\OutputConfiguration;
use PHPRegex\Linter\Formatter\RelativePathHelper;
use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The bytes each console report writes, pinned: the diff of a rewrite on
 * one line and under x, the file label, its pen and its link, the place a
 * pattern was found, and each part in its color.
 *
 * @phpstan-import-type LintResult from LintReport
 */
final class ConsoleReportBytesTest extends TestCase
{
    /**
     * @var array<string, string|false>
     */
    private array $environment = [];

    protected function setUp(): void
    {
        // The link formatter reads the terminal from the environment: a
        // JetBrains terminal or an old Konsole gets no hyperlink.
        foreach (['TERMINAL_EMULATOR', 'KONSOLE_VERSION'] as $name) {
            $this->environment[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv(false === $value ? $name : $name.'='.$value);
        }
    }

    /**
     * @param list<LintResult> $results
     */
    #[Test]
    #[DataProvider('provideAnsiReports')]
    public function test_the_ansi_report_writes_these_bytes(array $results, string $expected): void
    {
        $formatter = new ConsoleFormatter(config: new OutputConfiguration(ansi: true));

        $this->assertSame($expected, $formatter->format(new LintReport($results, ['errors' => 0, 'warnings' => 0, 'optimizations' => 0])));
    }

    /**
     * @return iterable<string, array{results: list<LintResult>, expected: string}>
     */
    public static function provideAnsiReports(): iterable
    {
        yield 'a rewrite on one line, what changed in bold' => ['results' => [self::rewrite('/[0-9]+x/', '/\\d+x/')], 'expected' => "  \e[36m\e[1ma.php:2\e[0m\n      \e[36m\e[1m→ \e[0m\e[36m\e[1m/\e[0m\e[36m[\e[0m0-9\e[36m]\e[0m\e[33m+\e[0mx\e[36m\e[1m/\e[0m\n    \e[46m\e[30m\e[1m TIP \e[0m\n         \e[31m- \e[0m\e[90m/\e[0m\e[31m\e[1m[0-9]\e[0m\e[90m+x/\e[0m\n         \e[32m+ \e[0m\e[90m/\e[0m\e[32m\e[1m\\d\e[0m\e[90m+x/\e[0m\n\n"];
        yield 'a rewrite that changes nothing, shown plain' => ['results' => [self::rewrite('/a/', '/a/')], 'expected' => "  \e[36m\e[1ma.php:2\e[0m\n      \e[36m\e[1m→ \e[0m\e[36m\e[1m/\e[0ma\e[36m\e[1m/\e[0m\n    \e[46m\e[30m\e[1m TIP \e[0m\n         \e[31m- \e[0m/a/\n         \e[32m+ \e[0m/a/\n\n"];
        yield 'a rewrite under x on one side only, as lines' => ['results' => [self::rewrite("/a\nb/x", '/ab/')], 'expected' => "  \e[36m\e[1ma.php:2\e[0m\n      \e[36m\e[1m→ \e[0m\e[36m\e[1m/\e[0ma b\e[36m\e[1m/\e[0m\e[36mx\e[0m\n    \e[46m\e[30m\e[1m TIP \e[0m\n         \e[31m- \e[0m\e[90m/a\e[0m\e[31m\e[1m\e[0m\n         \e[31m- \e[0m\e[31mb/x\e[0m\n         \e[90m---\e[0m\n         \e[32m+ \e[0m\e[90m/a\e[0m\e[32m\e[1mb/\e[0m\n\n"];
        yield 'a block of lines changed under x' => ['results' => [self::rewrite("/\n[0-9]\n[a-zA-Z0-9_]\nz\n/x", "/\n\\d\n\\w\nz\n/x")], 'expected' => "  \e[36m\e[1ma.php:2\e[0m\n      \e[36m\e[1m→ \e[0m\e[36m\e[1m/\e[0m \e[36m[\e[0m0-9\e[36m]\e[0m \e[36m[\e[0ma-zA-Z0-9_\e[36m]\e[0m z\e[36m\e[1m/\e[0m\e[36mx\e[0m\n    \e[46m\e[30m\e[1m TIP \e[0m\n         \e[90m  \e[0m\e[90m/\e[0m\n         \e[31m- \e[0m\e[31m\e[1m[0-9]\e[0m\n         \e[31m- \e[0m\e[31m\e[1m[a-zA-Z0-9_]\e[0m\n         \e[90m---\e[0m\n         \e[32m+ \e[0m\e[32m\e[1m\\d\e[0m\n         \e[32m+ \e[0m\e[32m\e[1m\\w\e[0m\n         \e[90m  \e[0m\e[90mz\e[0m\n         \e[90m  \e[0m\e[90m/x\e[0m\n\n"];
        yield 'lines deleted under x, no separator' => ['results' => [self::rewrite("/\na\nb\nc\n/x", "/\nc\n/x")], 'expected' => "  \e[36m\e[1ma.php:2\e[0m\n      \e[36m\e[1m→ \e[0m\e[36m\e[1m/\e[0m a b c\e[36m\e[1m/\e[0m\e[36mx\e[0m\n    \e[46m\e[30m\e[1m TIP \e[0m\n         \e[90m  \e[0m\e[90m/\e[0m\n         \e[31m- \e[0m\e[31ma\e[0m\n         \e[31m- \e[0m\e[31mb\e[0m\n         \e[90m  \e[0m\e[90mc\e[0m\n         \e[90m  \e[0m\e[90m/x\e[0m\n\n"];
        yield 'the place a pattern was found, a hint' => ['results' => [[
            'file' => 'a.php',
            'line' => 2,
            'column' => 0,
            'pattern' => '/ab/i',
            'location' => 'Route app_home',
            'issues' => [new LintIssue(LintSeverity::Warning, 'W.', 'a.php', 2, hint: 'H.')],
            'optimizations' => [],
        ]], 'expected' => "  \e[36m\e[1ma.php:2\e[0m\n      \e[36m\e[1m→ \e[0m\e[36m\e[1m/\e[0mab\e[36m\e[1m/\e[0m\e[36mi\e[0m\n     \e[36m↳ Route app_home\e[0m\n    \e[43m\e[30m\e[1m WARN \e[0m \e[37mW.\e[0m\n         \e[90m↳ H.\e[0m\n\n"];
    }

    #[Test]
    public function test_the_ansi_footer_writes_these_bytes(): void
    {
        $this->assertSame("  \e[90mIf PHPRegex helps, a GitHub star is appreciated: https://github.com/php-regex/php-regex\e[0m\n\n", (new ConsoleFormatter(config: new OutputConfiguration(ansi: true)))->formatFooter());
    }

    /**
     * A location with no column links the editor at column 1.
     */
    #[Test]
    public function test_the_pen_links_column_one_when_the_column_is_unknown(): void
    {
        $formatter = new ConsoleFormatter(
            config: new OutputConfiguration(ansi: false),
            ide: 'x://%f:%l:%c',
            linkFormatter: new LinkFormatter('x://%f:%l:%c', new RelativePathHelper('/project')),
        );

        $this->assertSame("  src/a.php:3 \e]8;;x:///project/src/a.php:3:1\e\\✏️\e]8;;\e\\\n      → /a/\n\n", $formatter->format(new LintReport([[
            'file' => 'src/a.php',
            'line' => 3,
            'column' => 0,
            'pattern' => '/a/',
            'issues' => [],
            'optimizations' => [],
        ]], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0])));
    }

    /**
     * The file label names no line 0 and no column 1, and links column 1
     * when it is unknown; each rewrite gets its badge.
     */
    #[Test]
    public function test_the_tag_report_writes_these_bytes(): void
    {
        $formatter = new ConsoleTagFormatter(new AnalysisService(RegexParser::create()), new LinkFormatter('x://%f:%l:%c', new RelativePathHelper('/project')), false);

        $this->assertSame("  <fg=cyan;options=bold><href=x:///project/src/a.php:0:1>./src/a.php</></>\n      <fg=cyan;options=bold>→ </><fg=white>/a/</>\n    <bg=cyan;fg=black;options=bold> TIP </>\n         <fg=red>- /a/</>\n         <fg=green>+ /b/</>\n    <bg=cyan;fg=black;options=bold> TIP </>\n         <fg=red>- /c/</>\n         <fg=green>+ /d/</>\n\n  <fg=cyan;options=bold><href=x:///project/src/b.php:4:1>./src/b.php:4</></>\n      <fg=cyan;options=bold>→ </><fg=white>/a/</>\n\n  <fg=cyan;options=bold><href=x:///project/src/c.php:5:1>./src/c.php:5</></>\n      <fg=cyan;options=bold>→ </><fg=white>/a/</>\n\n\n  <bg=green;fg=white;options=bold> PASS </> <fg=green;options=bold>No issues found</><fg=gray>, 2 optimizations available.</>\n", $formatter->format(new LintReport([
            ['file' => '/project/src/a.php', 'line' => 0, 'pattern' => '/a/', 'issues' => [], 'optimizations' => [self::entry('/a/', '/b/'), self::entry('/c/', '/d/')]],
            ['file' => '/project/src/b.php', 'line' => 4, 'column' => 1, 'pattern' => '/a/', 'issues' => [], 'optimizations' => []],
            ['file' => '/project/src/c.php', 'line' => 5, 'pattern' => '/a/', 'issues' => [], 'optimizations' => []],
        ], ['errors' => 0, 'warnings' => 0, 'optimizations' => 2])));
    }

    /**
     * Each issue is shown, its hint only when hints are on: an issue with
     * no hint stops nothing after it.
     */
    #[Test]
    public function test_each_issue_is_shown_and_its_hint_when_hints_are_on(): void
    {
        $report = new LintReport([[
            'file' => 'a.php',
            'line' => 2,
            'pattern' => '/a/',
            'issues' => [
                new LintIssue(LintSeverity::Warning, 'First.', 'a.php', 2),
                new LintIssue(LintSeverity::Warning, 'Second.', 'a.php', 2, hint: 'Hint.'),
            ],
            'optimizations' => [],
        ]], ['errors' => 0, 'warnings' => 2, 'optimizations' => 0]);

        $shown = (new ConsoleFormatter(config: new OutputConfiguration(ansi: false)))->format($report);
        $hidden = (new ConsoleFormatter(config: new OutputConfiguration(ansi: false, showHints: false)))->format($report);

        $this->assertStringContainsString("    WARN First.\n    WARN Second.\n         ↳ Hint.\n", $shown);
        $this->assertStringContainsString("    WARN First.\n    WARN Second.\n\n", $hidden);
        $this->assertStringNotContainsString('Hint.', $hidden);
    }

    /**
     * @phpstan-return LintResult
     */
    private static function rewrite(string $original, string $optimized): array
    {
        return ['file' => 'a.php', 'line' => 2, 'pattern' => $original, 'issues' => [], 'optimizations' => [self::entry($original, $optimized)]];
    }

    /**
     * @return array{file: string, line: int, optimization: OptimizationResult, savings: int}
     */
    private static function entry(string $original, string $optimized): array
    {
        return ['file' => 'a.php', 'line' => 1, 'optimization' => new OptimizationResult($original, $optimized, []), 'savings' => 1];
    }
}
