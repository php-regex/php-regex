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

namespace PhpRegex\Tests\Unit\Lint\Formatter;

use PhpRegex\Laravel\Output\LaravelConsoleFormatter;
use PhpRegex\Linter\AnalysisService;
use PhpRegex\Linter\Formatter\AbstractConsoleTagFormatter;
use PhpRegex\Linter\Formatter\LinkFormatter;
use PhpRegex\Linter\Formatter\RelativePathHelper;
use PhpRegex\Linter\LintReport;
use PhpRegex\Optimizer\OptimizationResult;
use PhpRegex\Parser\RegexParser;
use PhpRegex\Symfony\Output\SymfonyConsoleFormatter;
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
            'issues' => [['type' => 'warning', 'message' => 'm', 'hint' => $text, 'file' => '/project/src/Foo.php', 'line' => 1]],
            'optimizations' => [],
            'problems' => [],
        ]], ['errors' => 0, 'warnings' => 1, 'optimizations' => 0]);

        $this->assertStringContainsString(
            "         <fg=gray>\u{21B3} ".OutputFormatter::escape($text).'</>'.\PHP_EOL,
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
            'problems' => [],
        ]], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $this->assertStringContainsString(
            '<fg=white>'.OutputFormatter::escape('/(<a>/').'</>'.\PHP_EOL,
            $formatter->format($report),
        );
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
                    [
                        'type' => 'error',
                        'message' => "Bad <tag> at \\<\nLine 3: detail <x>\\",
                        'file' => '/project/src/Foo.php',
                        'line' => 12,
                    ],
                    [
                        'type' => 'warning',
                        'message' => 'Prefer </> over <fg=red>',
                        'hint' => 'Use <bar> instead\\',
                        'suggestedPattern' => '/<foo>[0-9]+/',
                        'file' => '/project/src/Foo.php',
                        'line' => 12,
                    ],
                    ['type' => 'info', 'message' => 'note \\\\', 'file' => '/project/src/Foo.php', 'line' => 12],
                ],
                'optimizations' => [[
                    'file' => '/project/src/Foo.php',
                    'line' => 12,
                    'optimization' => new OptimizationResult('/a|b/', '/[ab]/'),
                    'savings' => 2,
                ]],
                'problems' => [],
            ],
            [
                'file' => '/project/src/Bar.php',
                'line' => 3,
                'pattern' => null,
                'issues' => [[
                    'type' => 'warning',
                    'message' => 'x > y',
                    'pattern' => '#<b>#',
                    'file' => '/project/src/Bar.php',
                    'line' => 3,
                ]],
                'optimizations' => [],
                'problems' => [],
            ],
        ], ['errors' => 1, 'warnings' => 2, 'optimizations' => 1]);
    }
}
