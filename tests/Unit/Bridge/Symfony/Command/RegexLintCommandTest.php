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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Command;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Formatter\FormatterRegistry;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Linter\Source\PatternSourceInterface;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Symfony\Command\LintCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

final class RegexLintCommandTest extends TestCase
{
    #[Test]
    public function test_command_succeeds_by_default_with_no_patterns(): void
    {
        $command = $this->createCommand();

        $tester = new CommandTester($command);
        $status = $tester->execute(['paths' => ['nonexistent']]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('No regex patterns found', (string) $tester->getDisplay());
    }

    #[Test]
    public function test_command_has_correct_name(): void
    {
        $command = $this->createCommand();

        $this->assertSame('regex:lint', $command->getName());
    }

    #[Test]
    public function test_command_has_all_expected_options(): void
    {
        $command = $this->createCommand();

        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasArgument('paths'));
        $this->assertTrue($definition->hasOption('exclude'));
        $this->assertTrue($definition->hasOption('min-savings'));
        $this->assertTrue($definition->hasOption('jobs'));
        $this->assertTrue($definition->hasOption('no-routes'));
        $this->assertTrue($definition->hasOption('no-validators'));
        $this->assertTrue($definition->hasOption('format'));

        $this->assertFalse($definition->hasOption('analyze-redos'));
        $this->assertFalse($definition->hasOption('optimize'));
    }

    #[Test]
    public function test_json_format_outputs_raw_json(): void
    {
        $command = $this->createCommand();

        $tester = new CommandTester($command);
        $status = $tester->execute(['paths' => ['nonexistent'], '--format' => 'json']);

        $this->assertSame(0, $status);

        $output = $tester->getDisplay();
        $this->assertStringNotContainsString('No regex patterns found', (string) $output);
        $this->assertStringNotContainsString('Regex Parser', (string) $output);

        // Should be valid JSON
        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('stats', $data);
        $this->assertArrayHasKey('results', $data);
        $this->assertSame(['errors' => 0, 'warnings' => 0, 'optimizations' => 0, 'redos_errors' => 0, 'infos' => 0, 'lint_errors' => 0, 'parser_fallbacks' => 0], $data['stats']);
        $this->assertSame([], $data['results']);
    }

    #[Test]
    public function test_invalid_format_returns_error(): void
    {
        $command = $this->createCommand();

        $tester = new CommandTester($command);
        $status = $tester->execute(['paths' => ['nonexistent'], '--format' => 'invalid']);

        $this->assertSame(2, $status);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        $this->assertStringContainsString(
            'Invalid format \'invalid\'. Supported formats: console, json, github, checkstyle, junit',
            (string) $display,
        );
    }

    #[Test]
    public function test_normalize_string_list_filters_invalid_values(): void
    {
        $command = $this->createCommand();

        // Test the private method through reflection
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('normalizeStringList');

        $this->assertSame([], $method->invoke($command, null));
        $this->assertSame([], $method->invoke($command, 'string'));
        $this->assertSame(['a', 'b'], $method->invoke($command, ['a', '', 'b', null, 123]));
    }

    #[Test]
    public function test_sort_results_by_file_and_line(): void
    {
        $command = $this->createCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('sortResultsByFileAndLine');

        $results = [
            ['file' => 'b.php', 'line' => 10],
            ['file' => 'a.php', 'line' => 5],
            ['file' => 'a.php', 'line' => 1],
        ];

        /** @var array<array{file: string, line: int}> $sorted */
        $sorted = $method->invoke($command, $results);

        $this->assertSame('a.php', $sorted[0]['file']);
        $this->assertSame(1, $sorted[0]['line']);
        $this->assertSame('a.php', $sorted[1]['file']);
        $this->assertSame(5, $sorted[1]['line']);
        $this->assertSame('b.php', $sorted[2]['file']);
        $this->assertSame(10, $sorted[2]['line']);
    }

    #[Test]
    public function test_show_banner_outputs_correct_format(): void
    {
        $command = $this->createCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('showBanner');

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->exactly(2))->method('newLine');
        // Title, runtime, target and processes.
        $io->expects($this->exactly(4))->method('writeln');

        $method->invoke($command, $io, 1, ProjectTarget::fromSources([], [], null, []));
    }

    #[Test]
    public function test_show_footer_outputs_correct_format(): void
    {
        $command = $this->createCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('showFooter');

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->exactly(2))->method('newLine');
        $io->expects($this->once())->method('writeln')
            ->with('  <fg=gray>If PHPRegex helps, a GitHub star is appreciated: https://github.com/php-regex/php-regex</>');

        $method->invoke($command, $io);
    }

    #[Test]
    public function test_constructor_uses_defaults_when_paths_are_empty(): void
    {
        $analysis = new AnalysisService(RegexParser::create());
        $lint = new LintService($analysis, new PatternSourceCollection([]));

        $command = new LintCommand(
            lint: $lint,
            analysis: $analysis,
            formatterRegistry: new FormatterRegistry(),
            defaultPaths: [],
            defaultExcludePaths: [],
            editorUrl: null,
        );

        $this->assertSame('regex:lint', $command->getName());
    }

    /**
     * The functions marked #[RegexPattern] are read in the configured paths
     * and in the project's vendor/, whatever paths the run lints; with no
     * project directory, the working directory is the project.
     */
    #[Test]
    public function test_execute_reads_declarations_in_the_configured_paths_and_vendor(): void
    {
        $source = new class implements PatternSourceInterface {
            /**
             * @var list<array<string>>
             */
            public array $declarationPaths = [];

            /**
             * @var list<array<string>>
             */
            public array $vendorPaths = [];

            public function getName(): string
            {
                return 'recording';
            }

            public function isSupported(): bool
            {
                return true;
            }

            public function extract(PatternSourceContext $context): array
            {
                $this->declarationPaths[] = $context->declarationPaths;
                $this->vendorPaths[] = $context->vendorPaths;

                return [];
            }
        };
        $analysis = new AnalysisService(RegexParser::create());
        $lint = new LintService($analysis, new PatternSourceCollection([$source]));

        foreach (['/app', null] as $projectDir) {
            $command = new LintCommand(lint: $lint, analysis: $analysis, defaultPaths: ['src', 'lib'], projectDir: $projectDir);
            (new CommandTester($command))->execute(['paths' => ['src/Controller/One.php'], '--format' => 'json']);
        }

        $this->assertSame([['src', 'lib'], ['src', 'lib']], $source->declarationPaths);
        $this->assertSame([['/app/vendor'], [getcwd().'/vendor']], $source->vendorPaths);
    }

    #[Test]
    public function test_execute_rejects_invalid_jobs_value(): void
    {
        $command = $this->createCommand();

        $tester = new CommandTester($command);
        $status = $tester->execute(['paths' => ['nonexistent'], '--jobs' => '0']);

        $this->assertSame(2, $status);
        $this->assertStringContainsString('positive integer', (string) $tester->getDisplay());
    }

    #[Test]
    public function test_execute_progress_callback_handles_empty_totals(): void
    {
        $progressSource = new class implements PatternSourceInterface {
            public function getName(): string
            {
                return 'progress';
            }

            public function isSupported(): bool
            {
                return true;
            }

            public function extract(PatternSourceContext $context): array
            {
                if (\is_callable($context->progress)) {
                    ($context->progress)(0, 0);
                    ($context->progress)(0, 2);
                    ($context->progress)(1, 2);
                    ($context->progress)(2, 2);
                }

                return [];
            }
        };

        $command = $this->createCommandWithSources([$progressSource]);
        $tester = new CommandTester($command);

        $status = $tester->execute(['paths' => ['nonexistent']]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('No regex patterns found', (string) $tester->getDisplay());
    }

    #[Test]
    public function test_execute_renders_collection_failure(): void
    {
        $failingSource = new class implements PatternSourceInterface {
            public function getName(): string
            {
                return 'fail';
            }

            public function isSupported(): bool
            {
                return true;
            }

            public function extract(PatternSourceContext $context): array
            {
                throw new \RuntimeException('boom');
            }
        };

        $command = $this->createCommandWithSources([$failingSource]);
        $tester = new CommandTester($command);

        $status = $tester->execute(['paths' => ['nonexistent']]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('boom', (string) $tester->getDisplay());
    }

    #[Test]
    public function test_execute_renders_collection_failure_for_json(): void
    {
        $failingSource = new class implements PatternSourceInterface {
            public function getName(): string
            {
                return 'fail';
            }

            public function isSupported(): bool
            {
                return true;
            }

            public function extract(PatternSourceContext $context): array
            {
                throw new \RuntimeException('boom');
            }
        };

        $command = $this->createCommandWithSources([$failingSource]);
        $tester = new CommandTester($command);

        $status = $tester->execute(['paths' => ['nonexistent'], '--format' => 'json']);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Failed to collect patterns', (string) $tester->getDisplay());
    }

    #[Test]
    public function test_execute_analyzes_patterns_with_progress(): void
    {
        $source = new class implements PatternSourceInterface {
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
                return [
                    new PatternOccurrence('/foo/', 'test.php', 12, 'php:preg_match()'),
                ];
            }
        };

        $command = $this->createCommandWithSources([$source]);
        $tester = new CommandTester($command);

        $status = $tester->execute(['paths' => ['.']]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Analyzing patterns', (string) $tester->getDisplay());
    }

    /**
     * A file read with the tokenizer because the PHP parser could not is no
     * pattern of its own: counted in the stats, never in the patterns found.
     */
    #[Test]
    public function test_execute_leaves_a_parser_fallback_out_of_the_patterns_found(): void
    {
        $source = new class implements PatternSourceInterface {
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
                return [
                    new PatternOccurrence('/foo/', 'test.php', 2, 'php:preg_match()'),
                    PatternOccurrence::parserFallback('test.php', 'Syntax error, unexpected EOF on line 3'),
                ];
            }
        };

        $tester = new CommandTester($this->createCommandWithSources([$source]));
        $status = $tester->execute(['paths' => ['.']]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('found 1 patterns.', (string) $tester->getDisplay());

        $tester = new CommandTester($this->createCommandWithSources([$source]));
        $tester->execute(['paths' => ['.'], '--format' => 'json']);

        $data = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($data);
        $this->assertIsArray($data['stats']);
        $this->assertSame(1, $data['stats']['parser_fallbacks'] ?? null);
    }

    #[Test]
    public function test_execute_analyzes_patterns_without_progress_in_json(): void
    {
        $source = new class implements PatternSourceInterface {
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
                return [
                    new PatternOccurrence('/foo/', 'test.php', 12, 'php:preg_match()'),
                ];
            }
        };

        $command = $this->createCommandWithSources([$source]);
        $tester = new CommandTester($command);

        $status = $tester->execute(['paths' => ['.'], '--format' => 'json']);

        $this->assertSame(0, $status);
        $this->assertStringNotContainsString('Analyzing patterns', (string) $tester->getDisplay());
        $this->assertIsArray(json_decode($tester->getDisplay(), true));
    }

    /**
     * A lint rule at Error fails the command: without /u, [é] matches the
     * byte \xC3 on its own.
     */
    #[Test]
    public function test_execute_fails_on_a_lint_rule_at_error(): void
    {
        $this->assertSame(1, preg_match('/^[é]$/', "\xC3"));

        $tester = new CommandTester($this->createCommandWithSources([self::sourceOf('/[é]/')]));
        $status = $tester->execute(['paths' => ['.'], '--format' => 'json']);

        $this->assertSame(1, $status);
        $issue = self::firstIssue($tester->getDisplay());
        $this->assertSame('error', $issue['severity'] ?? null);
        $this->assertSame('regex.lint.unicode.multibyteInClassWithoutU', $issue['issue_id'] ?? null);
    }

    #[Test]
    public function test_execute_passes_on_a_lint_rule_at_info(): void
    {
        $tester = new CommandTester($this->createCommandWithSources([self::sourceOf('/(a)+/')]));
        $status = $tester->execute(['paths' => ['.'], '--format' => 'json']);

        $this->assertSame(0, $status);
        $this->assertSame('info', self::firstIssue($tester->getDisplay())['severity'] ?? null);
        $data = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($data);
        $this->assertIsArray($data['stats'] ?? null);
        $this->assertSame(1, $data['stats']['infos'] ?? null);
    }

    private function createCommand(): LintCommand
    {
        $analysis = new AnalysisService(RegexParser::create());
        $lint = new LintService(
            $analysis,
            new PatternSourceCollection([]),
        );

        return new LintCommand(
            lint: $lint,
            analysis: $analysis,
            formatterRegistry: new FormatterRegistry(),
            editorUrl: null,
        );
    }

    /**
     * @return array<mixed>
     */
    private static function firstIssue(string $json): array
    {
        $data = json_decode($json, true);
        self::assertIsArray($data, $json);
        self::assertIsArray($data['results'] ?? null);
        self::assertIsArray($data['results'][0] ?? null);
        self::assertIsArray($data['results'][0]['issues'] ?? null);
        self::assertIsArray($data['results'][0]['issues'][0] ?? null);

        return $data['results'][0]['issues'][0];
    }

    private static function sourceOf(string $pattern): PatternSourceInterface
    {
        return new class($pattern) implements PatternSourceInterface {
            public function __construct(private readonly string $pattern) {}

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
                return [new PatternOccurrence($this->pattern, 'test.php', 12, 'php:preg_match()')];
            }
        };
    }

    /**
     * @param array<int, PatternSourceInterface> $sources
     */
    private function createCommandWithSources(array $sources): LintCommand
    {
        $analysis = new AnalysisService(RegexParser::create());
        $lint = new LintService(
            $analysis,
            new PatternSourceCollection($sources),
        );

        return new LintCommand(
            lint: $lint,
            analysis: $analysis,
            formatterRegistry: new FormatterRegistry(),
            editorUrl: null,
        );
    }
}
