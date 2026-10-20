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

namespace PHPRegex\Tests\Unit\Lint\Console;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Config\UnsupportedTargetException;
use PHPRegex\Linter\Console\ConsoleLintRequest;
use PHPRegex\Linter\Console\ConsoleLintRunner;
use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\LintSetup;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Linter\Source\PatternSourceInterface;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * The run of the bridges' regex:lint: the report and the banner on the
 * standard output, the steps, the notes and the errors on the error output,
 * as `regex lint` writes them, and the failure of each stage with its exit
 * code and, in a machine format, its envelope.
 *
 * @phpstan-import-type LintResult from LintReport
 */
final class ConsoleLintRunnerTest extends TestCase
{
    use TemporaryProject;

    /**
     * @var resource
     */
    private $standard;

    /**
     * @var resource
     */
    private $error;

    private ?PatternSourceContext $context = null;

    protected function setUp(): void
    {
        $this->standard = self::memory();
        $this->error = self::memory();
        $this->context = null;
    }

    protected function tearDown(): void
    {
        fclose($this->standard);
        fclose($this->error);
    }

    #[Test]
    public function test_console_report_goes_to_the_standard_output_and_the_steps_to_the_error_output(): void
    {
        $status = $this->lint(self::request(['/[a-z/']));

        $this->assertSame(1, $status);
        $standard = $this->standard();
        $this->assertStringStartsWith("PHPRegex 9.9.9\n\nRuntime   : PHP ".\PHP_VERSION."\nTarget    : PHP ", $standard);
        $this->assertStringContainsString("Processes : 1\n", $standard);
        $this->assertStringContainsString('→ /[a-z/', $standard);
        $this->assertStringContainsString('FAIL  1 invalid patterns', $standard);
        $this->assertMatchesRegularExpression('/  Time: [0-9.]+s \| Memory: [0-9.]+ MB \| Processes: 1\n\n$/', $standard);
        $this->assertStringNotContainsString('Scanning files', $standard);
        $this->assertStringNotContainsString('Analyzing patterns', $standard);

        $error = $this->error();
        $this->assertStringStartsWith("  [1/3] Scanning files\n  Scanned 0 files, found 1 patterns.\n\n  [2/3] Analyzing patterns\n", $error);
        $this->assertStringContainsString('[3/3] Suggesting optimizations', $error);
        $this->assertStringNotContainsString('FAIL', $error);
    }

    /**
     * Under --quiet the console writes nothing, the steps and the report
     * included; the exit code still says what was found.
     */
    #[Test]
    public function test_quiet_console_writes_nothing(): void
    {
        $status = $this->lint(self::request(['/[a-z/']), OutputInterface::VERBOSITY_QUIET);

        $this->assertSame(1, $status);
        $this->assertSame('', $this->standard());
        $this->assertSame('', $this->error());
    }

    /**
     * Outside the console format stdout holds the report alone: the notes,
     * the warnings and the target go to the error output, each note named
     * as `regex lint` names it.
     */
    #[Test]
    public function test_machine_format_writes_the_notes_warnings_and_target_on_the_error_output(): void
    {
        $project = $this->makeProject(['composer.json' => '{"name": "acme/app"}']);

        $status = $this->lint(self::request(['/a/'], format: 'JSON', projectDir: $project, warnings: ['"extraction.foo" is not read.']));

        $this->assertSame(0, $status);
        $document = JsonContract::decodeDocument($this->standard());
        JsonContract::assertShape('lint', $document);
        $lines = explode("\n", rtrim($this->error(), "\n"));
        $this->assertCount(3, $lines);
        $this->assertStringStartsWith('Note: composer.json has no require.php constraint', $lines[0]);
        $this->assertSame('Warning: "extraction.foo" is not read.', $lines[1]);
        $this->assertStringStartsWith('Target: PHP ', $lines[2]);
    }

    /**
     * The banner names the warnings of the configuration as written, never
     * read for console tags.
     */
    #[Test]
    public function test_banner_writes_the_warnings_and_notes_as_written(): void
    {
        $project = $this->makeProject(['composer.json' => '{"name": "acme/app"}']);

        $this->lint(self::request(['/a/'], projectDir: $project, warnings: ['"<info>" is not read.']));

        $this->assertStringContainsString('Note: composer.json has no require.php constraint', $this->standard());
        $this->assertStringContainsString("Warning: \"<info>\" is not read.\n\n", $this->standard());
    }

    #[Test]
    public function test_no_pattern_passes_with_the_empty_summary(): void
    {
        $status = $this->lint(self::request([]));

        $this->assertSame(0, $status);
        $this->assertStringEndsWith("\n\n   PASS  No regex patterns found.\n\n", $this->standard());
        $this->assertStringNotContainsString('GitHub star', $this->standard());
    }

    /**
     * Under -v the banner names the author, and the run ends on the footer,
     * whatever it found, as `regex lint -v` ends.
     *
     * @param list<string> $patterns
     */
    #[Test]
    #[DataProvider('provideVerboseRuns')]
    public function test_verbose_console_names_the_author_and_ends_on_the_footer(array $patterns, string $before): void
    {
        $this->lint(self::request($patterns), OutputInterface::VERBOSITY_VERBOSE);

        $this->assertStringStartsWith("PHPRegex 9.9.9 by Younes ENNAJI\n\n", $this->standard());
        $this->assertMatchesRegularExpression('/'.$before.'\n\n  If PHPRegex helps, a GitHub star is appreciated: https:\/\/github\.com\/php-regex\/php-regex\n\n$/', $this->standard());
    }

    /**
     * @return iterable<string, array{patterns: list<string>, before: string}>
     */
    public static function provideVerboseRuns(): iterable
    {
        yield 'no pattern' => ['patterns' => [], 'before' => 'No regex patterns found\.'];
        yield 'a report' => ['patterns' => ['/[a-z/'], 'before' => 'Processes: 1'];
    }

    #[Test]
    public function test_no_pattern_gives_an_empty_machine_report(): void
    {
        $status = $this->lint(self::request([], format: 'json'));

        $this->assertSame(0, $status);
        $document = JsonContract::decodeDocument($this->standard());
        $this->assertSame([], $document['results'] ?? null);
    }

    /**
     * A file a source could not read fails the run, even when no pattern
     * was found, and takes its place by file and line in the report.
     */
    #[Test]
    public function test_files_the_sources_could_not_read_are_reported(): void
    {
        $status = $this->lint(self::request([], format: 'json', unread: [self::unread('routes.php')]));

        $this->assertSame(1, $status);
        $results = JsonContract::asArray(JsonContract::decodeDocument($this->standard())['results'] ?? null);
        $this->assertCount(1, $results);
        $this->assertSame('routes.php', JsonContract::asArray($results[0])['file'] ?? null);
    }

    #[Test]
    public function test_the_lint_request_carries_what_the_bridge_mapped(): void
    {
        $this->lint(self::request(['/a/'], format: 'json', jobs: '3', exclude: ['tests'], excludeBase: '/app'));

        $this->assertInstanceOf(PatternSourceContext::class, $this->context);
        $this->assertSame([__DIR__], $this->context->paths);
        $this->assertSame(['tests'], $this->context->excludePaths);
        $this->assertSame('/app', $this->context->excludeBase);
        $this->assertSame(3, $this->context->workers);
        $this->assertSame(['/app/src'], $this->context->declarationPaths);
        $this->assertSame(['/app/vendor'], $this->context->vendorPaths);
        $this->assertFalse($this->context->isSourceEnabled('routes'));
        $this->assertTrue($this->context->isSourceEnabled('custom'));
    }

    /**
     * The format is read first: a run it cannot write stops before anything
     * else is said.
     */
    #[Test]
    public function test_an_unknown_format_is_a_usage_error_on_the_error_output(): void
    {
        $status = $this->lint(self::request(['/a/'], format: 'nope', jobs: '0'));

        $this->assertSame(2, $status);
        $this->assertSame('', $this->standard());
        $this->assertSame("Error: Invalid format 'nope'. Supported formats: console, json, github, checkstyle, junit\n", $this->error());
    }

    /**
     * Each stage that stops the run: its message, on the error output on the
     * console, as the envelope naming the stage in JSON, and its exit code.
     * --jobs is read before the paths, the paths before the target, as
     * `regex lint` reads them.
     *
     * @param array{paths?: list<string>, pathsSetting?: string, jobs?: string, setup?: \Closure(): LintSetup, failure?: \Throwable} $request
     */
    #[Test]
    #[DataProvider('provideFailures')]
    public function test_a_stage_that_fails_stops_the_run(array $request, string $message, string $stage, int $exitCode): void
    {
        $status = $this->lint(self::request(['/a/'], ...$request));

        $this->assertSame($exitCode, $status);
        $this->assertStringNotContainsString($message, $this->standard());
        $this->assertStringEndsWith("\nError: ".$message."\n", "\n".$this->error());

        $this->setUp();
        $status = $this->lint(self::request(['/a/'], ...['format' => 'json', ...$request]));

        $this->assertSame($exitCode, $status);
        $this->assertSame(['error' => $message, 'stage' => $stage], JsonContract::decodeDocument($this->standard()));
    }

    /**
     * @return iterable<string, array{request: array{paths?: list<string>, pathsSetting?: string, jobs?: string, setup?: \Closure(): LintSetup, failure?: \Throwable}, message: string, stage: string, exitCode: int}>
     */
    public static function provideFailures(): iterable
    {
        yield 'a --jobs below one' => ['request' => ['jobs' => '0', 'paths' => ['missing']], 'message' => 'The --jobs value must be a positive integer.', 'stage' => 'usage', 'exitCode' => 2];
        yield 'a --jobs that is no whole number' => ['request' => ['jobs' => '2.5'], 'message' => 'The --jobs value must be a positive integer.', 'stage' => 'usage', 'exitCode' => 2];
        yield 'a --jobs with no value' => ['request' => ['jobs' => ''], 'message' => 'The --jobs value must be a positive integer.', 'stage' => 'usage', 'exitCode' => 2];
        yield 'a path given that does not exist' => ['request' => ['paths' => ['missing'], 'setup' => self::failingSetup(new UnsupportedTargetException('No target.'))], 'message' => 'Path not found: missing', 'stage' => 'usage', 'exitCode' => 2];
        yield 'a configured path that does not exist' => ['request' => ['paths' => ['missing'], 'pathsSetting' => 'php_regex.paths'], 'message' => 'Path not found: missing (from php_regex.paths)', 'stage' => 'config', 'exitCode' => 2];
        yield 'a target the run cannot judge for' => ['request' => ['setup' => self::failingSetup(new UnsupportedTargetException('No target.'))], 'message' => 'No target.', 'stage' => 'target', 'exitCode' => 2];
        yield 'a setting the run cannot read' => ['request' => ['setup' => self::failingSetup(new InvalidRegexOptionException('"locale" is no locale.'))], 'message' => 'Invalid option: "locale" is no locale.', 'stage' => 'config', 'exitCode' => 2];
        yield 'a source that fails' => ['request' => ['failure' => new \RuntimeException('boom')], 'message' => 'Failed to collect patterns: boom', 'stage' => 'collect', 'exitCode' => 1];
    }

    /**
     * Every stage writes its error under --quiet in JSON: the envelope is
     * the document asked for.
     */
    #[Test]
    public function test_quiet_json_failure_still_prints_the_envelope(): void
    {
        $status = $this->lint(self::request(['/a/'], format: 'json', jobs: '0'), OutputInterface::VERBOSITY_QUIET);

        $this->assertSame(2, $status);
        $this->assertSame(['error' => 'The --jobs value must be a positive integer.', 'stage' => 'usage'], JsonContract::decodeDocument($this->standard()));
    }

    /**
     * The format is named in any case, folded as ASCII whatever the
     * locale: under a Turkish one, PHP 8.1's strtolower() reads "I" as
     * a dotless i.
     */
    #[Test]
    public function test_the_format_is_read_in_any_case(): void
    {
        $locale = setlocale(\LC_CTYPE, '0');
        setlocale(\LC_CTYPE, 'tr_TR.UTF-8', 'tr_TR.ISO8859-9', 'tr_TR');

        try {
            $status = $this->lint(self::request([], format: 'CHECKSTYLE'));
        } finally {
            setlocale(\LC_CTYPE, false === $locale ? 'C' : $locale);
        }

        $this->assertSame(0, $status);
        $this->assertStringStartsWith('<?xml', $this->standard());
    }

    #[Test]
    public function test_github_failure_prints_the_format_error(): void
    {
        $status = $this->lint(self::request(['/a/'], format: 'github', jobs: '0'));

        $this->assertSame(2, $status);
        $this->assertSame("::error::The --jobs value must be a positive integer.\n", $this->standard());
    }

    /**
     * @internal the source of a request records the context it is given
     */
    public function record(PatternSourceContext $context): void
    {
        $this->context = $context;
    }

    /**
     * @param \Closure(self): ConsoleLintRequest $request
     */
    private function lint(\Closure $request, int $verbosity = OutputInterface::VERBOSITY_NORMAL): int
    {
        $output = self::console($this->standard, new StreamOutput($this->error, $verbosity, false), $verbosity);

        return (new ConsoleLintRunner())->run($request($this), $output);
    }

    private function standard(): string
    {
        return self::read($this->standard);
    }

    private function error(): string
    {
        return self::read($this->error);
    }

    /**
     * @param list<string>                 $patterns
     * @param list<string>|null            $paths
     * @param list<string>                 $exclude
     * @param list<string>                 $warnings
     * @param list<LintResult>             $unread
     * @param (\Closure(): LintSetup)|null $setup    a setup that fails
     *
     * @return \Closure(self): ConsoleLintRequest
     */
    private static function request(
        array $patterns,
        string $format = 'console',
        ?array $paths = null,
        ?string $pathsSetting = null,
        ?string $jobs = '1',
        array $exclude = ['vendor'],
        ?string $excludeBase = null,
        ?string $projectDir = null,
        array $warnings = [],
        array $unread = [],
        ?\Closure $setup = null,
        ?\Throwable $failure = null,
    ): \Closure {
        return static function (self $test) use ($patterns, $format, $paths, $pathsSetting, $jobs, $exclude, $excludeBase, $projectDir, $warnings, $unread, $setup, $failure): ConsoleLintRequest {
            $occurrences = array_map(static fn (string $pattern): PatternOccurrence => new PatternOccurrence($pattern, 'test.php', 3, 'php'), $patterns);
            $source = new class($test, $occurrences, $failure) implements PatternSourceInterface {
                /**
                 * @param list<PatternOccurrence> $patterns
                 */
                public function __construct(
                    private readonly ConsoleLintRunnerTest $test,
                    private readonly array $patterns,
                    private readonly ?\Throwable $failure
                ) {}

                public function getName(): string
                {
                    return 'custom';
                }

                public function isSupported(): bool
                {
                    return true;
                }

                public function extract(PatternSourceContext $context): array
                {
                    $this->test->record($context);
                    if (null !== $this->failure) {
                        throw $this->failure;
                    }

                    return $this->patterns;
                }
            };

            return new ConsoleLintRequest(
                format: $format,
                paths: $paths ?? [__DIR__],
                pathsSetting: $pathsSetting,
                exclude: $exclude,
                excludeBase: $excludeBase,
                minSavings: 1,
                jobs: $jobs,
                disabledSources: ['routes'],
                optimizations: new OptimizerOptions(verifyWithAutomata: true),
                checkRedos: false,
                checkComplexity: false,
                reportUnmatchedIgnores: true,
                declarationPaths: ['/app/src'],
                vendorPaths: ['/app/vendor'],
                editorUrl: null,
                displayBase: null,
                setup: $setup ?? static function () use ($projectDir, $source, $warnings): LintSetup {
                    $analysis = new AnalysisService(RegexParser::create());

                    return LintSetup::forTarget(ProjectTarget::fromSources([], [], $projectDir, []), [], $analysis, new LintService($analysis, new PatternSourceCollection([$source])), $warnings);
                },
                invalidOptionPrefix: 'Invalid option: ',
                version: '9.9.9',
                unread: static fn (): array => $unread,
            );
        };
    }

    private static function failingSetup(\Throwable $failure): \Closure
    {
        return static fn (): LintSetup => throw $failure;
    }

    /**
     * @phpstan-return LintResult
     */
    private static function unread(string $file): array
    {
        return [
            'file' => $file,
            'line' => 1,
            'column' => 1,
            'fileOffset' => null,
            'source' => 'validation',
            'pattern' => null,
            'location' => 'Laravel validation rule',
            'issues' => [new LintIssue(LintSeverity::Error, 'Unreadable.', $file, 1, issueId: 'regex.lint.source.unreadable')],
            'optimizations' => [],
        ];
    }

    /**
     * A console whose standard and error outputs are kept apart in memory.
     *
     * @param resource $stream
     */
    private static function console($stream, OutputInterface $errorOutput, int $verbosity): ConsoleOutputInterface
    {
        return new class($stream, $errorOutput, $verbosity) extends StreamOutput implements ConsoleOutputInterface {
            /**
             * @param resource $stream
             */
            public function __construct(
                $stream,
                private OutputInterface $errorOutput,
                int $verbosity
            ) {
                parent::__construct($stream, $verbosity, false);
            }

            public function getErrorOutput(): OutputInterface
            {
                return $this->errorOutput;
            }

            public function setErrorOutput(OutputInterface $error): void
            {
                $this->errorOutput = $error;
            }

            public function section(): ConsoleSectionOutput
            {
                throw new \LogicException('No section in this test.');
            }
        };
    }

    /**
     * @return resource
     */
    private static function memory()
    {
        $stream = fopen('php://memory', 'w+');
        if (!\is_resource($stream)) {
            throw new \RuntimeException('No memory stream.');
        }

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private static function read($stream): string
    {
        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fseek($stream, 0, \SEEK_END);

        return $content;
    }
}
