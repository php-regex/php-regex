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
 * The lint baseline: a versioned file holding each issue's identifier, its
 * file relative to the working directory and its pattern. An issue stays
 * known when its line moves or its message is reworded. Both spellings of
 * the options are read ("--baseline <file>" and "--baseline=<file>"). A
 * baseline that is missing or unreadable is a configuration error (exit
 * 2); a pattern json_encode() cannot take is written escaped, never as an
 * empty file.
 *
 * Every run happens in a directory of its own, the working directory while
 * the test runs, so the paths the baseline holds are relative to it.
 */
final class LintCommandBaselineFormatTest extends TestCase
{
    /**
     * An unclosed class: one error, exit 1 without a baseline.
     */
    private const ERROR_SOURCE = "<?php\npreg_match('/invalid[range/', \$subject);\n";

    private const ERROR_PATTERN = '/invalid[range/';

    private string $directory = '';

    private string $previousDirectory = '';

    protected function setUp(): void
    {
        $this->previousDirectory = (string) getcwd();
        $this->directory = sys_get_temp_dir().'/regex-baseline-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->directory = (string) realpath($this->directory);
        chdir($this->directory);
    }

    protected function tearDown(): void
    {
        chdir($this->previousDirectory);
        self::removeDirectory($this->directory);
    }

    /**
     * The baseline names a file by its path relative to the working
     * directory, the same file however the path given on the command line
     * spells it: "./code" and "code/../code" are the directory "code".
     */
    #[Test]
    #[DataProvider('provideDirectorySpellings')]
    public function test_baseline_knows_a_file_whatever_the_spelling_of_its_directory(string $spelling): void
    {
        mkdir('code');
        file_put_contents('code/test.php', self::ERROR_SOURCE);

        [$generated] = $this->lint(['code', '--generate-baseline=baseline.json']);
        $this->assertSame(1, $generated);

        [$exitCode, $stdout, $stderr] = $this->lint([$spelling, '--format=json', '--baseline=baseline.json']);
        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame([], self::jsonResults($stdout));
    }

    /**
     * @return iterable<string, array{spelling: string}>
     */
    public static function provideDirectorySpellings(): iterable
    {
        yield 'the same spelling' => ['spelling' => 'code'];
        yield 'a leading dot segment' => ['spelling' => './code'];
        yield 'a parent segment' => ['spelling' => 'code/../code'];
    }

    /**
     * Two identical patterns are baselined on lines 10 and 20; a third one
     * inserted on line 5 moves them to lines 11 and 21. Each entry takes
     * the issue nearest its line: the one reported as new is the inserted
     * one, on line 5.
     */
    #[Test]
    public function test_baseline_reports_the_inserted_copy_of_an_identical_pattern(): void
    {
        $call = "preg_match('/invalid[range/', \$subject);";
        $lines = array_fill(1, 25, '// a line');
        $lines[1] = '<?php';
        $lines[10] = $call;
        $lines[20] = $call;
        file_put_contents('test.php', implode("\n", $lines)."\n");

        [$generated] = $this->lint(['test.php', '--generate-baseline=baseline.json']);
        $this->assertSame(1, $generated);
        $this->assertSame([10, 20], self::valuesOf(self::readBaseline('baseline.json'), 'line'));

        array_splice($lines, 4, 0, [$call]);
        file_put_contents('test.php', implode("\n", $lines)."\n");

        $without = self::jsonResults($this->lint(['test.php', '--format=json'])[1]);
        $this->assertSame([5, 11, 21], array_column($without, 'line'), 'The inserted copy is on line 5, the others moved down one line.');

        [$exitCode, $stdout] = $this->lint(['test.php', '--format=json', '--baseline=baseline.json']);
        $this->assertSame(1, $exitCode, $stdout);
        $this->assertSame([5], array_column(self::jsonResults($stdout), 'line'));
    }

    /**
     * A pattern baselined on line 14; the same call inserted as line 13
     * moves it to line 15. Both issues are one line away from the entry:
     * on that tie the entry takes the issue below its line, the one an
     * insertion above has moved, and the inserted copy on line 13 is the
     * one reported as new.
     */
    #[Test]
    public function test_baseline_reports_the_copy_inserted_right_above_on_a_tie(): void
    {
        $call = "preg_match('/invalid[range/', \$subject);";
        $lines = array_fill(1, 20, '// a line');
        $lines[1] = '<?php';
        $lines[14] = $call;
        file_put_contents('test.php', implode("\n", $lines)."\n");

        [$generated] = $this->lint(['test.php', '--generate-baseline=baseline.json']);
        $this->assertSame(1, $generated);
        $this->assertSame([14], self::valuesOf(self::readBaseline('baseline.json'), 'line'));

        array_splice($lines, 12, 0, [$call]);
        file_put_contents('test.php', implode("\n", $lines)."\n");

        $without = self::jsonResults($this->lint(['test.php', '--format=json'])[1]);
        $this->assertSame([13, 15], array_column($without, 'line'), 'The inserted copy is on line 13, the baselined one moved to line 15.');

        [$exitCode, $stdout] = $this->lint(['test.php', '--format=json', '--baseline=baseline.json']);
        $this->assertSame(1, $exitCode, $stdout);
        $this->assertSame([13], array_column(self::jsonResults($stdout), 'line'));
    }

    /**
     * @param list<string> $generate the arguments that write the baseline
     * @param list<string> $use      the arguments that read it
     */
    #[Test]
    #[DataProvider('provideOptionSpellings')]
    public function test_baseline_options_accept_both_spellings(array $generate, array $use): void
    {
        file_put_contents('test.php', self::ERROR_SOURCE);

        [$generated] = $this->lint(['test.php', ...$generate]);
        $this->assertSame(1, $generated);
        $this->assertFileExists('baseline.json');

        [$exitCode, $stdout, $stderr] = $this->lint(['test.php', ...$use]);
        $this->assertSame(0, $exitCode, $stdout.$stderr);
    }

    /**
     * @return iterable<string, array{generate: list<string>, use: list<string>}>
     */
    public static function provideOptionSpellings(): iterable
    {
        yield 'separate argument' => ['generate' => ['--generate-baseline', 'baseline.json'], 'use' => ['--baseline', 'baseline.json']];
        yield 'equals sign' => ['generate' => ['--generate-baseline=baseline.json'], 'use' => ['--baseline=baseline.json']];
        yield 'equals sign, then separate argument' => ['generate' => ['--generate-baseline=baseline.json'], 'use' => ['--baseline', 'baseline.json']];
    }

    #[Test]
    public function test_generated_baseline_has_a_version_and_the_real_pattern(): void
    {
        file_put_contents('test.php', self::ERROR_SOURCE);

        $this->lint(['test.php', '--generate-baseline=baseline.json']);
        $baseline = self::readBaseline('baseline.json');

        $this->assertArrayHasKey('version', $baseline);
        $this->assertContains(self::ERROR_PATTERN, self::valuesOf($baseline, 'pattern'));
        $this->assertContains('test.php', self::valuesOf($baseline, 'file'));
    }

    /**
     * The baseline file is the JSON document, opened on its first byte and
     * ended by one line feed, as a text file is.
     */
    #[Test]
    public function test_generated_baseline_is_a_document_ended_by_a_line_feed(): void
    {
        file_put_contents('test.php', self::ERROR_SOURCE);

        $this->lint(['test.php', '--generate-baseline=baseline.json']);
        $content = (string) file_get_contents('baseline.json');

        $this->assertStringStartsWith('{', $content);
        $this->assertStringEndsWith("}\n", $content);
        $this->assertStringEndsNotWith("\n\n", $content);
    }

    /**
     * A line inserted above the baselined pattern moves its issue down one
     * line: the issue is still the one the baseline knows.
     */
    #[Test]
    public function test_baseline_keeps_an_issue_whose_line_moved(): void
    {
        file_put_contents('test.php', self::ERROR_SOURCE);
        $this->lint(['test.php', '--generate-baseline=baseline.json']);

        file_put_contents('test.php', "<?php\n// a line above\npreg_match('/invalid[range/', \$subject);\n");

        [$exitCode, $stdout, $stderr] = $this->lint(['test.php', '--baseline=baseline.json']);
        $this->assertSame(0, $exitCode, $stdout.$stderr);
    }

    /**
     * A message reworded since the baseline was written still names the
     * same issue.
     */
    #[Test]
    public function test_baseline_keeps_an_issue_whose_message_was_reworded(): void
    {
        file_put_contents('test.php', self::ERROR_SOURCE);
        $this->lint(['test.php', '--generate-baseline=baseline.json']);

        $baseline = self::readBaseline('baseline.json');
        $this->assertNotSame([], self::valuesOf($baseline, 'message'), 'The baseline holds a message to reword.');
        array_walk_recursive($baseline, static function (mixed &$value, int|string $key): void {
            if ('message' === $key) {
                $value = 'A message worded another way.';
            }
        });
        file_put_contents('baseline.json', json_encode($baseline, \JSON_THROW_ON_ERROR));

        [$exitCode, $stdout, $stderr] = $this->lint(['test.php', '--baseline=baseline.json']);
        $this->assertSame(0, $exitCode, $stdout.$stderr);
    }

    /**
     * An issue the baseline does not know is reported with the column and
     * the file offset it has without a baseline.
     */
    #[Test]
    public function test_baseline_filtering_keeps_column_and_file_offset(): void
    {
        file_put_contents('test.php', self::ERROR_SOURCE);
        $this->lint(['test.php', '--generate-baseline=baseline.json']);

        file_put_contents('test.php', self::ERROR_SOURCE."preg_match('/other[class/', \$subject);\n");

        $without = self::jsonResults($this->lint(['test.php', '--format=json'])[1]);
        $with = self::jsonResults($this->lint(['test.php', '--format=json', '--baseline=baseline.json'])[1]);

        $this->assertCount(2, $without);
        $before = self::resultFor($without, '/other[class/');
        $after = self::resultFor($with, '/other[class/');

        $this->assertArrayHasKey('column', $after);
        $this->assertArrayHasKey('fileOffset', $after);
        $this->assertSame($before['column'], $after['column']);
        $this->assertSame($before['fileOffset'], $after['fileOffset']);
        $this->assertIsInt($after['fileOffset']);
        // The baselined error leaves the report: only the new one is listed.
        $this->assertCount(1, $with, (string) json_encode($with));
    }

    /**
     * A baselined error is gone from every report, the formats that list
     * the problems of a result included.
     */
    #[Test]
    public function test_a_baselined_error_is_gone_from_the_checkstyle_report(): void
    {
        file_put_contents('test.php', self::ERROR_SOURCE);
        $this->lint(['test.php', '--generate-baseline=baseline.json']);

        file_put_contents('test.php', self::ERROR_SOURCE."preg_match('/other[class/', \$subject);\n");

        [, $stdout] = $this->lint(['test.php', '--format=checkstyle', '--baseline=baseline.json']);

        if ('' === $stdout) {
            $this->fail('The checkstyle report is empty.');
        }
        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($stdout), $stdout);
        $lines = [];
        foreach ($document->getElementsByTagName('error') as $error) {
            $lines[] = $error->getAttribute('line');
        }
        $this->assertSame(['3'], $lines);
    }

    /**
     * @param string|null $content null for no file at all
     */
    #[Test]
    #[DataProvider('provideUnusableBaselines')]
    public function test_an_unusable_baseline_is_a_configuration_error(?string $content): void
    {
        file_put_contents('test.php', self::ERROR_SOURCE);
        if (null !== $content) {
            file_put_contents('baseline.json', $content);
        }

        [$exitCode, $stdout, $stderr] = $this->lint(['test.php', '--baseline=baseline.json']);
        $this->assertSame(2, $exitCode, $stdout.$stderr);
    }

    /**
     * @return iterable<string, array{content: string|null}>
     */
    public static function provideUnusableBaselines(): iterable
    {
        yield 'missing file' => ['content' => null];
        yield 'not JSON' => ['content' => '{"version": '];
        yield 'empty file' => ['content' => ''];
        yield 'JSON that is no baseline' => ['content' => '"baseline"'];
    }

    /**
     * The double-quoted literal gives the pattern the raw byte 0xFF, which
     * json_encode() refuses: the baseline holds the pattern with the byte
     * spelled "\xFF".
     */
    #[Test]
    public function test_a_pattern_json_cannot_encode_is_written_escaped(): void
    {
        file_put_contents('test.php', "<?php\npreg_match(\"/(a+)+\\xFF/\", \$subject);\n");

        $this->lint(['test.php', '--generate-baseline=baseline.json']);

        $this->assertFileExists('baseline.json');
        $this->assertNotSame('', file_get_contents('baseline.json'));
        $baseline = self::readBaseline('baseline.json');
        $this->assertContains('/(a+)+\xFF/', self::valuesOf($baseline, 'pattern'));
    }

    /**
     * A baseline that cannot be written (its directory does not exist) is
     * a configuration error: exit 2, said on stderr, and no report.
     */
    #[Test]
    public function test_a_baseline_that_cannot_be_written_is_a_configuration_error(): void
    {
        file_put_contents('test.php', self::ERROR_SOURCE);

        [$exitCode, $stdout, $stderr] = $this->lint(['test.php', '--generate-baseline=missing/baseline.json']);

        $this->assertSame(2, $exitCode, $stdout.$stderr);
        $this->assertSame("Error: Could not write the baseline to missing/baseline.json\n", $stderr);
        $this->assertStringNotContainsString(self::ERROR_PATTERN, $stdout);
        $this->assertDirectoryDoesNotExist('missing');
    }

    /**
     * The same error read as JSON, when the report asked for is JSON.
     */
    #[Test]
    public function test_a_baseline_that_cannot_be_written_is_a_json_error_under_the_json_format(): void
    {
        file_put_contents('test.php', self::ERROR_SOURCE);

        [$exitCode, $stdout] = $this->lint(['test.php', '--format=json', '--generate-baseline=missing/baseline.json']);

        $this->assertSame(2, $exitCode, $stdout);
        $this->assertSame(['error' => 'Could not write the baseline to missing/baseline.json'], json_decode($stdout, true), $stdout);
        $this->assertDirectoryDoesNotExist('missing');
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function lint(array $args): array
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
        $output = new Output(false, false, '#', '-', $errors);
        $input = new Input('lint', $args, new GlobalOptions(false, false, false, true, null, null), []);

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

    /**
     * @return array<array-key, mixed>
     */
    private static function readBaseline(string $file): array
    {
        $content = file_get_contents($file);
        self::assertIsString($content);
        $decoded = json_decode($content, true);
        self::assertIsArray($decoded, 'The baseline is a JSON object: '.$content);

        return $decoded;
    }

    /**
     * Every value held under the key, at any depth: the test does not
     * depend on where the entries sit in the file.
     *
     * @param array<array-key, mixed> $data
     *
     * @return list<mixed>
     */
    private static function valuesOf(array $data, string $key): array
    {
        $values = [];
        foreach ($data as $name => $value) {
            if ($name === $key) {
                $values[] = $value;
            }
            if (\is_array($value)) {
                $values = [...$values, ...self::valuesOf($value, $key)];
            }
        }

        return $values;
    }

    /**
     * @param list<array<string, mixed>> $results
     *
     * @return array<string, mixed>
     */
    private static function resultFor(array $results, string $pattern): array
    {
        $found = array_values(array_filter($results, static fn (array $result): bool => $pattern === ($result['pattern'] ?? null)));
        self::assertCount(1, $found, (string) json_encode($results));

        return $found[0];
    }

    private static function removeDirectory(string $directory): void
    {
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory.'/'.$entry;
            is_dir($path) && !is_link($path) ? self::removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function jsonResults(string $stdout): array
    {
        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded, $stdout);
        self::assertIsArray($decoded['results'] ?? null, $stdout);

        /** @var list<array<string, mixed>> $results */
        $results = $decoded['results'];

        return $results;
    }
}
