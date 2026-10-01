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
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

final class RegexLintCommandTest extends TestCase
{
    public function test_command_succeeds_by_default_with_no_patterns(): void
    {
        $command = $this->createCommand();

        $tester = new CommandTester($command);
        $status = $tester->execute(['paths' => ['nonexistent']]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('No regex patterns found', (string) $tester->getDisplay());
    }

    public function test_command_has_correct_name(): void
    {
        $command = $this->createCommand();

        $this->assertSame('regex:lint', $command->getName());
    }

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
        $this->assertSame(['errors' => 0, 'warnings' => 0, 'optimizations' => 0], $data['stats']);
        $this->assertSame([], $data['results']);
    }

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

    public function test_execute_rejects_invalid_jobs_value(): void
    {
        $command = $this->createCommand();

        $tester = new CommandTester($command);
        $status = $tester->execute(['paths' => ['nonexistent'], '--jobs' => '0']);

        $this->assertSame(2, $status);
        $this->assertStringContainsString('positive integer', (string) $tester->getDisplay());
    }

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
