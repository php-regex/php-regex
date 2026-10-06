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

namespace PHPRegex\Tests\Integration\Lint\Command;

use PHPRegex\Cli\Command\HelpCommand;
use PHPRegex\Cli\Command\LintCommand;
use PHPRegex\Cli\Command\LintOutputRenderer;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Output;
use PHPRegex\Linter\Config\LintArgumentParser;
use PHPRegex\Linter\Config\LintConfigLoader;
use PHPRegex\Linter\Config\LintDefaultsBuilder;
use PHPRegex\Linter\Config\LintExtractorFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a lint report writes is text a terminal or a CI log shows as it is:
 * the bytes of a pattern reach it only spelled, the messages that quote the
 * pattern included. A right-to-left override would reorder the line it
 * stands on, a raw byte that is not UTF-8 makes the whole report invalid
 * text.
 *
 * In a GitHub workflow command the runner reads "%0D", "%0A" and "%25" in
 * the message as a carriage return, a line feed and "%", and also "%3A" and
 * "%2C" in a property as ":" and ","; a ":" or a "," left raw in a property
 * cuts it. The text the runner reads back is the text the report meant.
 *
 * Every run happens in a directory of its own, the working directory while
 * the test runs.
 */
final class LintCommandReportBytesTest extends TestCase
{
    private string $directory = '';

    private string $previousDirectory = '';

    protected function setUp(): void
    {
        $this->previousDirectory = (string) getcwd();
        $this->directory = sys_get_temp_dir().'/regex-report-bytes-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->directory = (string) realpath($this->directory);
        chdir($this->directory);
    }

    protected function tearDown(): void
    {
        chdir($this->previousDirectory);
        foreach (array_diff(scandir($this->directory) ?: [], ['.', '..']) as $file) {
            unlink($this->directory.'/'.$file);
        }
        rmdir($this->directory);
    }

    /**
     * The pattern does not compile (an unclosed group), and the message
     * suggests another delimiter around its text: that text is spelled as
     * the pattern is.
     */
    #[Test]
    #[DataProvider('provideHiddenBytes')]
    public function test_report_spells_hidden_bytes_of_a_pattern_in_every_message(string $source, string $format, bool $ansi): void
    {
        file_put_contents('test.php', $source);

        [$exitCode, $stdout, $stderr] = $this->lint(['test.php', '--format='.$format], $ansi);
        $report = $stdout.$stderr;

        $this->assertSame(1, $exitCode, $report);
        $this->assertTrue(mb_check_encoding($report, 'UTF-8'), 'The report is valid UTF-8: '.bin2hex($report));
        $this->assertStringNotContainsString("\u{202E}", $report);
        $this->assertStringNotContainsString("\u{85}", $report);
    }

    /**
     * @return iterable<string, array{source: string, format: string, ansi: bool}>
     */
    public static function provideHiddenBytes(): iterable
    {
        $sources = [
            'a right-to-left override' => "<?php\npreg_match(\"/a\\u{202E}b(\", \$x);\n",
            'a NEL byte, not UTF-8' => "<?php\npreg_match(\"/a{1}\\x85(\", \$x);\n",
        ];

        foreach ($sources as $name => $source) {
            yield $name.', console' => ['source' => $source, 'format' => 'console', 'ansi' => false];
            yield $name.', console with colors' => ['source' => $source, 'format' => 'console', 'ansi' => true];
            yield $name.', github' => ['source' => $source, 'format' => 'github', 'ansi' => false];
        }
    }

    /**
     * The pattern holds the text "%0D%0A": GitHub must show it as written,
     * not as a line break inside the annotation.
     */
    #[Test]
    public function test_github_annotation_escapes_a_percent_sign_in_its_message(): void
    {
        file_put_contents('test.php', "<?php\npreg_match(\"/%0D%0A[a-z]{1}/\", \$x);\n");

        [, $stdout] = $this->lint(['test.php', '--format=github']);
        $annotations = self::annotations($stdout);

        $this->assertNotSame([], $annotations, $stdout);
        $this->assertStringContainsString('%250D%250A', $stdout);
        $suggested = false;
        foreach ($annotations as [, , $message]) {
            $this->assertStringNotContainsString("\r", $message, $stdout);
            $suggested = $suggested || str_contains($message, 'Suggestion: /%0D%0A[a-z]/');
        }
        $this->assertTrue($suggested, 'The optimization shows the pattern as written: '.$stdout);
    }

    /**
     * A file whose name holds ":" and ",": the "file" property reads back
     * whole, and the "line" property after it is still a property of its
     * own.
     */
    #[Test]
    public function test_github_annotation_escapes_a_colon_and_a_comma_in_its_properties(): void
    {
        file_put_contents('a:b,c.php', "<?php\npreg_match('/invalid[range/', \$x);\n");

        [, $stdout] = $this->lint(['a:b,c.php', '--format=github']);
        $annotations = self::annotations($stdout);

        $this->assertStringContainsString('file=a%3Ab%2Cc.php,', $stdout);
        $this->assertCount(1, $annotations, $stdout);
        [, $properties] = $annotations[0];
        $this->assertStringEndsWith('a:b,c.php', $properties['file'] ?? '', $stdout);
        $this->assertSame('2', $properties['line'] ?? null, $stdout);
    }

    /**
     * A NUL in a group name: the pattern does not compile, and the message
     * that quotes the name spells the NUL. The report a person reads holds
     * no raw NUL byte.
     *
     * Oracle: preg_match("/(?<a\0b>x)/", "") fails to compile, "syntax
     * error in subpattern name (missing terminator?) at offset 4".
     */
    #[Test]
    #[DataProvider('provideReadableFormats')]
    public function test_report_spells_a_nul_in_its_messages(string $format, bool $ansi): void
    {
        file_put_contents('test.php', "<?php\npreg_match(\"/(?<a\\0b>x)/\", \$s);\n");

        [$exitCode, $stdout, $stderr] = $this->lint(['test.php', '--format='.$format], $ansi);
        $report = $stdout.$stderr;

        $this->assertSame(1, $exitCode, $report);
        $this->assertStringContainsString('a\x00b', $report);
        $this->assertStringNotContainsString("\0", $report);
    }

    /**
     * A file name holding a right-to-left override and an escape sequence
     * that clears the screen: the report names the file with neither raw,
     * and holds no control byte other than tab and line feed.
     */
    #[Test]
    #[DataProvider('provideReadableFormats')]
    public function test_report_spells_the_hidden_characters_of_a_file_name(string $format, bool $ansi): void
    {
        $file = "ab\u{202E}cd\x1B[2Jx.php";
        file_put_contents($file, "<?php\npreg_match('/invalid[range/', \$x);\n");

        [$exitCode, $stdout, $stderr] = $this->lint([$file, '--format='.$format], $ansi);
        $report = $stdout.$stderr;
        if ($ansi) {
            // The colors the report asked for are the only escape sequences.
            // The colours and the progress bar's own line redraws ("\r"
            // then the bar) are the report's layout, not the file name.
            $report = (string) preg_replace(['/\x1B\[[0-9;]*m/', '/\r(?!\n)/'], ['', "\n"], $report);
        }

        $this->assertSame(1, $exitCode, $report);
        $this->assertStringContainsString('ab', $report);
        $this->assertStringNotContainsString("\u{202E}", $report);
        $this->assertSame(0, preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $report), 'A control byte in the report: '.bin2hex($report));
    }

    /**
     * @return iterable<string, array{format: string, ansi: bool}>
     */
    public static function provideReadableFormats(): iterable
    {
        yield 'console' => ['format' => 'console', 'ansi' => false];
        yield 'console with colors' => ['format' => 'console', 'ansi' => true];
        yield 'github' => ['format' => 'github', 'ansi' => false];
    }

    /**
     * The hidden characters of a file name are spelled, and a ":" and a ","
     * in it are still escaped in the "file" property: the "line" property
     * after it is still a property of its own.
     */
    #[Test]
    public function test_github_annotation_escapes_a_file_name_with_hidden_characters_and_separators(): void
    {
        $file = "a:b,\u{202E}c\x1B[2Jx.php";
        file_put_contents($file, "<?php\npreg_match('/invalid[range/', \$x);\n");

        [, $stdout] = $this->lint([$file, '--format=github']);
        $annotations = self::annotations($stdout);

        $this->assertStringContainsString('file=a%3Ab%2C', $stdout);
        $this->assertStringNotContainsString("\u{202E}", $stdout);
        $this->assertStringNotContainsString("\x1B", $stdout);
        $this->assertCount(1, $annotations, $stdout);
        [, $properties] = $annotations[0];
        $this->assertStringStartsWith('a:b,', $properties['file'] ?? '', $stdout);
        $this->assertSame('2', $properties['line'] ?? null, $stdout);
    }

    /**
     * Each workflow command of the report, read as the runner reads it: the
     * level, the properties split on "," and on the first "=", and the
     * message, each unescaped in the runner's order.
     *
     * @return list<array{string, array<string, string>, string}>
     */
    private static function annotations(string $stdout): array
    {
        $annotations = [];
        foreach (explode("\n", $stdout) as $line) {
            if (1 !== preg_match('/^::(\w+)(?: ([^:]*))?::(.*)$/', $line, $match)) {
                continue;
            }
            $properties = [];
            foreach ('' === $match[2] ? [] : explode(',', $match[2]) as $property) {
                [$name, $value] = explode('=', $property, 2) + [1 => ''];
                $properties[$name] = str_replace(['%0D', '%0A', '%3A', '%2C', '%25'], ["\r", "\n", ':', ',', '%'], $value);
            }
            $annotations[] = [$match[1], $properties, str_replace(['%0D', '%0A', '%25'], ["\r", "\n", '%'], $match[3])];
        }

        return $annotations;
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function lint(array $args, bool $ansi = false): array
    {
        $command = new LintCommand(
            new HelpCommand(),
            new LintConfigLoader(),
            new LintDefaultsBuilder(),
            new LintArgumentParser(),
            new LintExtractorFactory(),
            new LintOutputRenderer(),
        );
        $errors = fopen('php://memory', 'w+');
        $this->assertIsResource($errors);
        $output = new Output($ansi, false, '#', '-', $errors);
        $input = new Input('lint', [...$args, '--jobs=1'], new GlobalOptions(false, $ansi, false, true, null, null), []);

        ob_start();

        try {
            $exitCode = $command->run($input, $output);
        } finally {
            $stdout = (string) ob_get_clean();
        }
        rewind($errors);
        $stderr = (string) stream_get_contents($errors);
        fclose($errors);

        return [$exitCode, $stdout, $stderr];
    }
}
