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
use PHPRegex\Linter\Formatter\CheckstyleFormatter;
use PHPRegex\Linter\Formatter\ConsoleFormatter;
use PHPRegex\Linter\Formatter\GithubFormatter;
use PHPRegex\Linter\Formatter\JsonFormatter;
use PHPRegex\Linter\Formatter\JunitFormatter;
use PHPRegex\Linter\Formatter\LinkFormatter;
use PHPRegex\Linter\Formatter\OutputConfiguration;
use PHPRegex\Linter\Formatter\OutputFormatterInterface;
use PHPRegex\Linter\Formatter\RelativePathHelper;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Symfony\Output\SymfonyConsoleFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * How each report format spells the pattern. The machine formats (JSON,
 * Checkstyle, JUnit) carry the pattern exactly as it was found, through the
 * format's own escaping; only bytes that are not UTF-8 are written "\xHH".
 * The formats a person reads (console, GitHub annotations) carry the
 * display form: the characters that move or hide text escaped in the
 * pattern's mode, an x pattern on one line.
 */
final class PatternSpellingFormatterTest extends TestCase
{
    /**
     * The decoded "pattern" of the JSON report is the pattern itself.
     */
    #[Test]
    #[DataProvider('provideJsonPatterns')]
    public function test_json_carries_the_exact_pattern(string $pattern, string $expected): void
    {
        $output = (new JsonFormatter())->format(self::report($pattern));

        $decoded = json_decode($output, true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $results = $decoded['results'] ?? null;
        $this->assertIsArray($results, $output);
        $first = $results[0] ?? null;
        $this->assertIsArray($first, $output);
        $this->assertSame($expected, $first['pattern'] ?? null);
    }

    /**
     * Each pattern repeats "(a+)" under "+": a nested quantifier, so the
     * report holds the pattern.
     *
     * @return iterable<string, array{pattern: string, expected: string}>
     */
    public static function provideJsonPatterns(): iterable
    {
        yield 'x pattern with a comment and a line break' => ['pattern' => "/(a+)+b # c\n d/x", 'expected' => "/(a+)+b # c\n d/x"];
        yield 'tab' => ['pattern' => "/(a+)+b\tc/", 'expected' => "/(a+)+b\tc/"];
        yield 'next line (C1) under u' => ['pattern' => "/(a+)+b\u{85}/u", 'expected' => "/(a+)+b\u{85}/u"];
        yield 'next line (C1), byte mode' => ['pattern' => "/(a+)+b\u{85}/", 'expected' => "/(a+)+b\u{85}/"];
        yield 'right-to-left override under u' => ['pattern' => "/(a+)+b\u{202E}/u", 'expected' => "/(a+)+b\u{202E}/u"];
        yield 'invalid UTF-8 then a line break' => ['pattern' => "/(a+)+\xFF\nb/", 'expected' => "/(a+)+\\xFF\nb/"];
    }

    /**
     * The snippet a Checkstyle or JUnit report quotes holds the pattern's
     * text as it is, once the XML is read.
     */
    #[Test]
    #[DataProvider('provideXmlSnippets')]
    public function test_xml_formats_carry_the_exact_pattern_text(OutputFormatterInterface $formatter, string $pattern, string $expected): void
    {
        $output = $formatter->format(self::report($pattern));
        if ('' === $output) {
            $this->fail('The report is empty.');
        }

        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($output), $output);
        $text = $formatter instanceof CheckstyleFormatter
            ? (string) $document->getElementsByTagName('error')->item(0)?->getAttribute('message')
            : (string) $document->getElementsByTagName('failure')->item(0)?->textContent;

        $this->assertStringContainsString($expected, $text);
    }

    /**
     * "(?<n>x)(?<n>y)" repeats a group name: the report quotes the pattern
     * in its snippet.
     *
     * @return iterable<string, array{formatter: OutputFormatterInterface, pattern: string, expected: string}>
     */
    public static function provideXmlSnippets(): iterable
    {
        foreach (['checkstyle' => new CheckstyleFormatter(), 'junit' => new JunitFormatter()] as $name => $formatter) {
            yield $name.', tab' => ['formatter' => $formatter, 'pattern' => "/a\t(?<n>x)(?<n>y)/", 'expected' => "a\t(?<n>x)(?<n>y)"];
            yield $name.', next line (C1)' => ['formatter' => $formatter, 'pattern' => "/a\u{85}(?<n>x)(?<n>y)/", 'expected' => "a\u{85}(?<n>x)(?<n>y)"];
            yield $name.', right-to-left override under u' => ['formatter' => $formatter, 'pattern' => "/a\u{202E}(?<n>x)(?<n>y)/u", 'expected' => "a\u{202E}(?<n>x)(?<n>y)"];
            yield $name.', invalid UTF-8' => ['formatter' => $formatter, 'pattern' => "/a\xFF(?<n>x)(?<n>y)/", 'expected' => 'a\xFF(?<n>x)(?<n>y)'];
        }
    }

    /**
     * No raw C1 control or bidirectional override reaches the console or
     * an annotation: each is spelled in the pattern's mode.
     */
    #[Test]
    #[DataProvider('provideDisplayedHiddenCharacters')]
    public function test_display_formats_escape_hidden_characters(string $name, string $pattern, string $character, string $spelling): void
    {
        $output = self::plain($name, self::formatters()[$name]->format(self::report($pattern)));

        $this->assertStringNotContainsString($character, $output);
        $this->assertMatchesRegularExpression($spelling, $output);
    }

    /**
     * @return iterable<string, array{name: string, pattern: string, character: string, spelling: string}>
     */
    public static function provideDisplayedHiddenCharacters(): iterable
    {
        foreach (array_keys(self::formatters()) as $name) {
            yield $name.', next line (C1), byte mode' => ['name' => $name, 'pattern' => "/a\u{85}(?<n>x)(?<n>y)/", 'character' => "\u{85}", 'spelling' => '/\\\\xC2\\\\x85/i'];
            yield $name.', next line (C1) under u' => ['name' => $name, 'pattern' => "/a\u{85}(?<n>x)(?<n>y)/u", 'character' => "\u{85}", 'spelling' => '/\\\\x\{0*85\}/i'];
            yield $name.', right-to-left override under u' => ['name' => $name, 'pattern' => "/a\u{202E}(?<n>x)(?<n>y)/u", 'character' => "\u{202E}", 'spelling' => '/\\\\x\{0*202E\}/i'];
            yield $name.', left-to-right isolate, byte mode' => ['name' => $name, 'pattern' => "/a\u{2066}(?<n>x)(?<n>y)/", 'character' => "\u{2066}", 'spelling' => '/\\\\xE2\\\\x81\\\\xA6/i'];
        }
    }

    /**
     * The console shows an x pattern on one line, without its comment, and
     * the line reads back with the pattern's matches.
     *
     * Oracle (PCRE2 10.49, JIT off): "aabc" and "abc" match; "ab", "a b c",
     * "abc # match (foo)" without the final "c" do not.
     */
    #[Test]
    #[DataProvider('provideConsoleFormatters')]
    public function test_console_shows_an_x_pattern_on_one_line(string $name): void
    {
        $pattern = "/(a+)+b # match (foo)\n c/x";
        $output = self::plain($name, self::formatters()[$name]->format(self::report($pattern)));

        $this->assertSame(1, preg_match('/→ (.*)$/mu', $output, $match), $output);
        $shown = $match[1];

        $this->assertStringNotContainsString('match (foo)', $shown);
        $this->assertStringNotContainsString('\n', $shown);
        foreach (['aabc', 'abc', 'ab', 'a b c', 'ab # match (foo)'] as $subject) {
            $this->assertSame(self::oracle($pattern, $subject), self::oracle($shown, $subject), $shown.' on '.$subject);
        }
    }

    /**
     * An invalid pattern shows its message, then the caret snippet under
     * it: the snippet is added to the message, never in its place.
     */
    #[Test]
    #[DataProvider('provideConsoleFormatters')]
    public function test_console_shows_the_message_above_the_caret_snippet(string $name): void
    {
        $output = self::plain($name, self::formatters()[$name]->format(self::report('/a(?<n>x)(?<n>y)/')));

        $this->assertMatchesRegularExpression('/Duplicate group name "n" at position \d+\.\n[^\n]*a\(\?<n>x\)\(\?<n>y\)\n *\^/', $output);
    }

    /**
     * The diff of an optimization that spans lines spells each line in the
     * pattern's mode, the lines it removes as the ones it adds: under u the
     * optimizer keeps the right-to-left overrides of the pattern raw, and
     * none reaches the terminal raw.
     */
    #[Test]
    public function test_console_spells_each_line_of_a_multiline_optimization(): void
    {
        $analysis = new AnalysisService(RegexParser::create(['cache' => null]));
        $report = (new LintService($analysis, new PatternSourceCollection([])))->analyze(
            [new PatternOccurrence("/[0-9]+ # digits\n\u{202E}\u{202E}\u{202E}\u{202E}x/xu", 'file.php', 1, 'php:preg_match()')],
            new LintRequest(['.'], [], 0, checkRedos: false, checkOptimizations: true),
        );

        $output = self::formatters()['console']->format($report);

        $this->assertStringNotContainsString("\u{202E}", $output);
        $this->assertStringContainsString('\x{202E}\x{202E}\x{202E}\x{202E}x/xu', $output);
        $this->assertStringContainsString('\x{202E}{4}x/xu', $output);
    }

    /**
     * The location a result names (a route, a validation rule) is text a
     * person reads: an escape character and a right-to-left override in it
     * are spelled, never written raw.
     */
    #[Test]
    #[DataProvider('provideDisplayFormatters')]
    public function test_display_formats_spell_hidden_characters_of_a_location(string $name): void
    {
        $report = self::report('/(?<n>x)(?<n>y)/');
        $results = $report->results;
        $this->assertNotSame([], $results);
        foreach ($results as $r => $result) {
            $results[$r]['location'] = "route \x1B[2J\u{202E}home";
        }

        $output = self::plain($name, self::formatters()[$name]->format(new LintReport($results, $report->stats)));

        $this->assertStringContainsString('route \x1B[2J\x{202E}home', $output);
        $this->assertStringNotContainsString("\x1B", $output);
        $this->assertStringNotContainsString("\u{202E}", $output);
    }

    /**
     * @return iterable<string, array{name: string}>
     */
    public static function provideDisplayFormatters(): iterable
    {
        foreach (array_keys(self::formatters()) as $name) {
            yield $name => ['name' => $name];
        }
    }

    /**
     * An error the run stops on is an annotation of its own: its message
     * spells an escape character and a right-to-left override.
     */
    #[Test]
    public function test_github_error_spells_hidden_characters_of_its_message(): void
    {
        $this->assertSame('::error::No file \x1B[2J\x{202E}x.php', (new GithubFormatter())->formatError("No file \x1B[2J\u{202E}x.php"));
    }

    /**
     * @return iterable<string, array{name: string}>
     */
    public static function provideConsoleFormatters(): iterable
    {
        yield 'console' => ['name' => 'console'];
        yield 'symfony console' => ['name' => 'symfony console'];
    }

    /**
     * @return array<string, OutputFormatterInterface>
     */
    private static function formatters(): array
    {
        return [
            'github' => new GithubFormatter(),
            'console' => new ConsoleFormatter(null, new OutputConfiguration(ansi: false)),
            'symfony console' => new SymfonyConsoleFormatter(
                new AnalysisService(RegexParser::create(['cache' => null])),
                new LinkFormatter(null, new RelativePathHelper('/project')),
                false,
            ),
        ];
    }

    /**
     * The text a person reads: console tags rendered away.
     */
    private static function plain(string $name, string $output): string
    {
        return 'symfony console' === $name ? (string) (new OutputFormatter(false))->format($output) : $output;
    }

    private static function report(string $pattern): LintReport
    {
        $analysis = new AnalysisService(RegexParser::create(['cache' => null]), redosThreshold: 'low', redosEnabled: true);
        $lint = new LintService($analysis, new PatternSourceCollection([]));

        return $lint->analyze(
            [new PatternOccurrence($pattern, 'file.php', 1, 'php:preg_match()')],
            new LintRequest(['.'], [], 0, checkRedos: true, checkOptimizations: false),
        );
    }

    /**
     * What preg_match() answers, JIT off; a compilation failure or an engine
     * error is its message.
     */
    private static function oracle(string $pattern, string $subject): int|string
    {
        $jit = (string) \ini_get('pcre.jit');
        \ini_set('pcre.jit', '0');
        $warning = null;
        set_error_handler(static function (int $errno, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $result = preg_match($pattern, $subject);
        } finally {
            restore_error_handler();
            \ini_set('pcre.jit', $jit);
        }

        return false === $result ? 'error: '.($warning ?? preg_last_error_msg()) : $result;
    }
}
