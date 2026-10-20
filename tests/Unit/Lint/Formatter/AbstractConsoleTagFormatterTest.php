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

use PHPRegex\Laravel\Output\LaravelConsoleFormatter;
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Formatter\AbstractConsoleTagFormatter;
use PHPRegex\Linter\Formatter\LinkFormatter;
use PHPRegex\Linter\Formatter\RelativePathHelper;
use PHPRegex\Linter\Formatter\ReportSpelling;
use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Symfony\Output\SymfonyConsoleFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * The report the framework consoles print, written with console tags: the
 * text it quotes is escaped as Symfony Console's OutputFormatter::escape()
 * does, without the linter depending on Symfony Console.
 */
final class AbstractConsoleTagFormatterTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../../Fixtures/Lint/';

    /**
     * @param class-string<AbstractConsoleTagFormatter> $formatterClass
     */
    #[Test]
    #[DataProvider('provideRenderings')]
    public function test_the_report_renders_byte_for_byte(string $formatterClass, bool $decorated, string $fixture): void
    {
        $formatter = new $formatterClass(
            new AnalysisService(RegexParser::create(['cache' => null])),
            new LinkFormatter(null, new RelativePathHelper('/project')),
            $decorated,
        );

        $this->assertSame(file_get_contents(self::FIXTURES.$fixture), $formatter->format(self::report()));
    }

    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function provideRenderings(): iterable
    {
        foreach ([SymfonyConsoleFormatter::class, LaravelConsoleFormatter::class] as $class) {
            yield $class.' decorated' => [$class, true, 'console-tag-report.decorated.txt'];
            yield $class.' plain' => [$class, false, 'console-tag-report.plain.txt'];
        }
    }

    #[Test]
    #[DataProvider('provideTagLikeText')]
    public function test_quoted_text_is_escaped_as_symfony_console_escapes_it(string $text): void
    {
        $formatter = new SymfonyConsoleFormatter(
            new AnalysisService(RegexParser::create(['cache' => null])),
            new LinkFormatter(null, new RelativePathHelper('/project')),
        );
        $report = new LintReport([[
            'file' => '/project/src/Foo.php',
            'line' => 1,
            'pattern' => '/a/',
            'issues' => [new LintIssue(LintSeverity::Warning, 'm', '/project/src/Foo.php', 1, hint: $text)],
            'optimizations' => [],
        ]], ['errors' => 0, 'warnings' => 1, 'optimizations' => 0]);

        $this->assertStringContainsString(
            // The hint is spelled for display first (a NUL or an escape
            // character becomes "\xHH"), then escaped as Symfony escapes it
            // from 6.4.44: every bracket, "\<" included, which 6.4.43 and
            // earlier left as it was.
            "         <fg=gray>\u{21B3} ".self::escapedAsSymfony6444(ReportSpelling::displayText($text)).'</>'.\PHP_EOL,
            $formatter->format($report),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTagLikeText(): iterable
    {
        yield 'a tag' => ['<foo>'];
        yield 'an escaped opening bracket' => ['\<'];
        yield 'a closing tag' => ['</>'];
        yield 'a style tag' => ['<fg=red>x</>'];
        yield 'a lone closing bracket' => ['x > y'];
        yield 'a trailing backslash' => ['a\\'];
        yield 'two trailing backslashes' => ['a\\\\'];
        yield 'a trailing backslash after a tag' => ['<b>\\'];
        yield 'a NUL before a trailing backslash' => ["a\0b\\"];
        yield 'backslashes only' => ['\\\\\\'];
        yield 'a backslash inside' => ['a\\b'];
        yield 'plain text' => ['plain'];
        yield 'multibyte text' => ["caf\u{E9} <\u{2192}>"];
    }

    /**
     * The file field is one line: a line feed, a carriage return or a tab
     * in a file name is spelled "\xHH", so that a name cannot draw a line
     * of a report of its own.
     *
     * @param class-string<AbstractConsoleTagFormatter> $formatterClass
     */
    #[Test]
    #[DataProvider('provideFileFieldsPerFormatter')]
    public function test_the_file_field_is_one_line(string $formatterClass, string $file, int $line, string $expected): void
    {
        $formatter = new $formatterClass(
            new AnalysisService(RegexParser::create(['cache' => null])),
            new LinkFormatter(null, new RelativePathHelper('/project')),
            false,
        );
        $report = new LintReport([[
            'file' => $file,
            'line' => $line,
            'pattern' => '/a/',
            'issues' => [new LintIssue(LintSeverity::Warning, 'm', $file, $line)],
            'optimizations' => [],
        ]], ['errors' => 0, 'warnings' => 1, 'optimizations' => 0]);

        $output = $formatter->format($report);

        $this->assertStringContainsString($expected, $output);
        $this->assertStringNotContainsString($file, $output);
    }

    /**
     * @return iterable<string, array{formatterClass: class-string<AbstractConsoleTagFormatter>, file: string, line: int, expected: string}>
     */
    public static function provideFileFieldsPerFormatter(): iterable
    {
        foreach ([SymfonyConsoleFormatter::class, LaravelConsoleFormatter::class] as $class) {
            foreach (self::provideFileFields() as $name => $row) {
                yield $class.', '.$name => ['formatterClass' => $class] + $row;
            }
        }
    }

    /**
     * File names holding the bytes that lay text out, each with the file
     * field it is shown as.
     *
     * @return iterable<string, array{file: string, line: int, expected: string}>
     */
    public static function provideFileFields(): iterable
    {
        yield 'a line feed and a line of its own' => ['file' => "src/x.php\n    \u{2714} FAKE.php", 'line' => 2, 'expected' => "src/x.php\\x0A    \u{2714} FAKE.php:2"];
        yield 'a tab' => ['file' => "src/t\tab.php", 'line' => 2, 'expected' => 'src/t\x09ab.php:2'];
        yield 'a carriage return before a line feed' => ['file' => "src/c\r\n.php", 'line' => 2, 'expected' => 'src/c\x0D\x0A.php:2'];
        yield 'a lone carriage return' => ['file' => "src/c\r.php", 'line' => 2, 'expected' => 'src/c\x0D.php:2'];
        yield 'a line feed, no line' => ['file' => "src/x.php\n    \u{2714} FAKE.php", 'line' => 0, 'expected' => "src/x.php\\x0A    \u{2714} FAKE.php"];
    }

    /**
     * The location field is one line too; a message keeps its line
     * breaks, which lay it out.
     *
     * @param class-string<AbstractConsoleTagFormatter> $formatterClass
     */
    #[Test]
    #[DataProvider('provideFormatterClasses')]
    public function test_the_location_field_is_one_line_and_a_message_keeps_its_line_breaks(string $formatterClass): void
    {
        $formatter = new $formatterClass(
            new AnalysisService(RegexParser::create(['cache' => null])),
            new LinkFormatter(null, new RelativePathHelper('/project')),
            false,
        );
        $report = new LintReport([[
            'file' => '/project/src/Foo.php',
            'line' => 1,
            'pattern' => '/a/',
            'location' => "route a\nb\tc\r\nd",
            'issues' => [new LintIssue(LintSeverity::Warning, "first\nsecond", '/project/src/Foo.php', 1)],
            'optimizations' => [],
        ]], ['errors' => 0, 'warnings' => 1, 'optimizations' => 0]);

        $output = $formatter->format($report);

        $this->assertStringContainsString('route a\x0Ab\x09c\x0D\x0Ad', $output);
        $this->assertStringNotContainsString("route a\n", $output);
        $this->assertMatchesRegularExpression("/first\n[^\n]*second/", (string) (new OutputFormatter(false))->format($output));
        $this->assertStringNotContainsString('first\x0A', $output);
    }

    /**
     * @return iterable<string, array{formatterClass: class-string<AbstractConsoleTagFormatter>}>
     */
    public static function provideFormatterClasses(): iterable
    {
        yield 'symfony' => ['formatterClass' => SymfonyConsoleFormatter::class];
        yield 'laravel' => ['formatterClass' => LaravelConsoleFormatter::class];
    }

    #[Test]
    public function test_a_pattern_that_does_not_parse_is_quoted_escaped_without_highlighting(): void
    {
        $formatter = new LaravelConsoleFormatter(
            new AnalysisService(RegexParser::create(['cache' => null])),
            new LinkFormatter(null, new RelativePathHelper('/project')),
        );
        $report = new LintReport([[
            'file' => '/project/src/Foo.php',
            'line' => 1,
            'pattern' => '/(<a>/',
            'issues' => [],
            'optimizations' => [],
        ]], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $this->assertStringContainsString(
            '<fg=white>'.OutputFormatter::escape('/(<a>/').'</>'.\PHP_EOL,
            $formatter->format($report),
        );
    }

    /**
     * A route requirement is a pattern body with no delimiter: it is shown
     * as written, whatever its first character, as the CLI shows it.
     *
     * @param class-string<AbstractConsoleTagFormatter> $formatterClass
     */
    #[Test]
    #[DataProvider('provideRequirementsPerFormatter')]
    public function test_a_route_requirement_is_shown_as_written(string $formatterClass, bool $decorated, string $requirement): void
    {
        $formatter = new $formatterClass(
            new AnalysisService(RegexParser::create(['cache' => null])),
            new LinkFormatter(null, new RelativePathHelper('/project')),
            $decorated,
        );
        $report = new LintReport([[
            'file' => 'Route: blog_show',
            'line' => 0,
            'pattern' => $requirement,
            'issues' => [new LintIssue(LintSeverity::Warning, 'm', 'Route: blog_show', 0)],
            'optimizations' => [],
        ]], ['errors' => 0, 'warnings' => 1, 'optimizations' => 0]);

        $this->assertStringContainsString('<fg=white>'.OutputFormatter::escape($requirement).'</>'.\PHP_EOL, $formatter->format($report));
    }

    /**
     * @return iterable<string, array{formatterClass: class-string<AbstractConsoleTagFormatter>, decorated: bool, requirement: string}>
     */
    public static function provideRequirementsPerFormatter(): iterable
    {
        foreach ([SymfonyConsoleFormatter::class, LaravelConsoleFormatter::class] as $class) {
            foreach ([false, true] as $decorated) {
                foreach (['\\d+', 'en|fr', '[a-z]+'] as $requirement) {
                    yield \sprintf('%s, %s, %s', $class, $decorated ? 'decorated' : 'plain', $requirement) => ['formatterClass' => $class, 'decorated' => $decorated, 'requirement' => $requirement];
                }
            }
        }
    }

    #[Test]
    public function test_the_linter_does_not_depend_on_symfony_console(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            \dirname(__DIR__, 4).'/src/Linter',
            \FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($files as $file) {
            $this->assertInstanceOf(\SplFileInfo::class, $file);
            $this->assertStringNotContainsString(
                'Symfony\Component\Console',
                (string) file_get_contents($file->getPathname()),
                $file->getPathname(),
            );
        }
    }

    private static function report(): LintReport
    {
        return new LintReport([
            [
                'file' => '/project/src/Foo.php',
                'line' => 12,
                'column' => 5,
                'pattern' => '/<foo>\d+/',
                'location' => 'Route <app_home> \\',
                'issues' => [
                    new LintIssue(LintSeverity::Error, "Bad <tag> at \\<\nLine 3: detail <x>\\", '/project/src/Foo.php', 12),
                    new LintIssue(LintSeverity::Warning, 'Prefer </> over <fg=red>', '/project/src/Foo.php', 12, hint: 'Use <bar> instead\\'),
                    new LintIssue(LintSeverity::Info, 'note \\\\', '/project/src/Foo.php', 12),
                ],
                'optimizations' => [[
                    'file' => '/project/src/Foo.php',
                    'line' => 12,
                    'optimization' => new OptimizationResult('/a|b/', '/[ab]/'),
                    'savings' => 2,
                ]],
            ],
            [
                'file' => '/project/src/Bar.php',
                'line' => 3,
                'pattern' => '#<b>#',
                'issues' => [new LintIssue(LintSeverity::Warning, 'x > y', '/project/src/Bar.php', 3)],
                'optimizations' => [],
            ],
        ], ['errors' => 1, 'warnings' => 2, 'optimizations' => 1]);
    }

    /**
     * OutputFormatter::escape() as Symfony writes it from 6.4.44, and in the
     * 7.4 and 8 releases of the same fix: a backslash before each bracket, and a trailing run of backslashes turned into as
     * many NUL bytes, the formatter's own marker.
     */
    private static function escapedAsSymfony6444(string $text): string
    {
        $text = str_replace(['<', '>'], ['\\<', '\\>'], $text);
        if (!str_ends_with($text, '\\')) {
            return $text;
        }

        $length = \strlen($text);
        $text = str_replace("\0", '', rtrim($text, '\\'));

        return $text.str_repeat("\0", $length - \strlen($text));
    }
}
