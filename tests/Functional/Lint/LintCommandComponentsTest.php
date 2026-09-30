<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Functional\Lint;

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use RegexParser\Cli\Command\LintOutputRenderer;
use RegexParser\Cli\Output;
use RegexParser\Lint\Command\LintArgumentParser;
use RegexParser\Lint\Command\LintArguments;
use RegexParser\Lint\Command\LintConfigLoader;
use RegexParser\Lint\Command\LintDefaultsBuilder;
use RegexParser\Lint\Command\LintExtractorFactory;
use RegexParser\Lint\Extraction\PatternFunctionRegistry;
use RegexParser\Lint\Extraction\PhpParserExtractionStrategy;
use RegexParser\Lint\Extraction\TokenBasedExtractionStrategy;
use RegexParser\Lint\Formatter\OutputConfiguration;
use RegexParser\Lint\RegexPatternExtractor;

final class LintCommandComponentsTest extends TestCase
{
    public function test_defaults_builder_extracts_known_keys(): void
    {
        $builder = new LintDefaultsBuilder();

        $defaults = $builder->build([
            'paths' => ['src'],
            'exclude' => ['vendor'],
            'jobs' => 2,
            'minSavings' => 3,
            'format' => 'json',
            'rules' => [
                'redos' => false,
                'validation' => true,
                'optimization' => false,
            ],
        ]);

        $this->assertSame(['src'], $defaults['paths']);
        $this->assertSame(['vendor'], $defaults['exclude']);
        $this->assertSame(2, $defaults['jobs']);
        $this->assertSame(3, $defaults['minSavings']);
        $this->assertSame('json', $defaults['format']);
        $this->assertFalse($defaults['checkRedos']);
        $this->assertTrue($defaults['checkValidation']);
        $this->assertFalse($defaults['checkOptimizations']);
    }

    public function test_argument_parser_handles_options_and_paths(): void
    {
        $parser = new LintArgumentParser();

        $result = $parser->parse([
            '--quiet',
            '--no-redos',
            '--no-validate',
            '--no-optimize',
            '--format', 'json',
            '--exclude=vendor',
            '--min-savings=2',
            '--jobs=2',
            'src',
        ]);

        $arguments = $result->arguments;
        $this->assertInstanceOf(LintArguments::class, $arguments);

        $this->assertSame(['src'], $arguments->paths);
        $this->assertSame(['vendor'], $arguments->exclude);
        $this->assertSame(2, $arguments->minSavings);
        $this->assertSame(OutputConfiguration::VERBOSITY_QUIET, $arguments->verbosity);
        $this->assertSame('json', $arguments->format);
        $this->assertTrue($arguments->quiet);
        $this->assertFalse($arguments->checkRedos);
        $this->assertFalse($arguments->checkValidation);
        $this->assertFalse($arguments->checkOptimizations);
        $this->assertSame(2, $arguments->jobs);
    }

    public function test_argument_parser_reports_errors_for_invalid_options(): void
    {
        $parser = new LintArgumentParser();

        $result = $parser->parse(['--format']);
        $this->assertSame('Missing value for --format.', $result->error);

        $result = $parser->parse(['--exclude']);
        $this->assertSame('Missing value for --exclude.', $result->error);

        $result = $parser->parse(['--min-savings']);
        $this->assertSame('Missing value for --min-savings.', $result->error);

        $result = $parser->parse(['--jobs']);
        $this->assertSame('Missing value for --jobs.', $result->error);

        $result = $parser->parse(['--jobs=0']);
        $this->assertSame('The --jobs value must be a positive integer.', $result->error);

        $result = $parser->parse(['--jobs=-1']);
        $this->assertSame('The --jobs value must be a positive integer.', $result->error);

        $result = $parser->parse(['--unknown']);
        $this->assertSame('Unknown option: --unknown', $result->error);
    }

    public function test_argument_parser_supports_help_flag(): void
    {
        $parser = new LintArgumentParser();

        $result = $parser->parse(['--help']);

        $this->assertTrue($result->help);
        $this->assertNotInstanceOf(LintArguments::class, $result->arguments);
    }

    public function test_argument_parser_handles_debug_option(): void
    {
        $parser = new LintArgumentParser();

        $result = $parser->parse(['--debug', 'src']);

        $arguments = $result->arguments;
        $this->assertInstanceOf(LintArguments::class, $arguments);

        $this->assertSame(OutputConfiguration::VERBOSITY_DEBUG, $arguments->verbosity);
    }

    public function test_argument_parser_handles_format_option_with_space(): void
    {
        $parser = new LintArgumentParser();

        $result = $parser->parse(['--format', 'json', 'src']);

        $arguments = $result->arguments;
        $this->assertInstanceOf(LintArguments::class, $arguments);

        $this->assertSame('json', $arguments->format);
    }

    public function test_argument_parser_handles_jobs_short_option(): void
    {
        $parser = new LintArgumentParser();

        $result = $parser->parse(['-j', '3', 'src']);

        $arguments = $result->arguments;
        $this->assertInstanceOf(LintArguments::class, $arguments);

        $this->assertSame(3, $arguments->jobs);
    }

    public function test_config_loader_merges_files_and_normalizes_values(): void
    {
        $cwd = getcwd();
        $tempDir = sys_get_temp_dir().'/regex-parser-lint-config-'.uniqid('', true);
        if (false === @mkdir($tempDir) && !is_dir($tempDir)) {
            $this->markTestSkipped('Unable to create temp directory.');
        }

        $distConfig = [
            'paths' => ['src'],
            'rules' => ['validation' => false],
            'jobs' => 2,
        ];
        $jsonConfig = [
            'exclude' => ['vendor'],
            'format' => 'json',
            'rules' => ['validation' => true, 'optimization' => false],
        ];

        copy(__DIR__.'/../../Fixtures/Config/dist_config.json', $tempDir.'/regex.dist.json');
        copy(__DIR__.'/../../Fixtures/Config/user_config.json', $tempDir.'/regex.json');

        try {
            if (false === @chdir($tempDir)) {
                $this->markTestSkipped('Unable to change directory.');
            }

            $loader = new LintConfigLoader();
            $result = $loader->load();

            $this->assertNull($result->error);
            $this->assertSame(['src'], $result->config['paths']);
            $this->assertSame(['vendor'], $result->config['exclude']);
            $this->assertSame('json', $result->config['format']);
            $this->assertSame(2, $result->config['jobs']);
            $this->assertSame(['validation' => true, 'optimization' => false], $result->config['rules']);
            $expectedFiles = array_map(realpath(...), [$tempDir.'/regex.dist.json', $tempDir.'/regex.json']);
            $actualFiles = array_map(realpath(...), $result->files);
            $this->assertSame($expectedFiles, $actualFiles);
        } finally {
            if (is_dir($tempDir)) {
                @unlink($tempDir.'/regex.dist.json');
                @unlink($tempDir.'/regex.json');
                @rmdir($tempDir);
            }
            if (false !== $cwd) {
                @chdir($cwd);
            }
        }
    }

    public function test_config_loader_reports_invalid_json(): void
    {
        $cwd = getcwd();
        $tempDir = sys_get_temp_dir().'/regex-parser-lint-badjson-'.uniqid('', true);
        if (false === @mkdir($tempDir) && !is_dir($tempDir)) {
            $this->markTestSkipped('Unable to create temp directory.');
        }

        copy(__DIR__.'/../../Fixtures/Config/invalid_json.json', $tempDir.'/regex.json');

        try {
            if (false === @chdir($tempDir)) {
                $this->markTestSkipped('Unable to change directory.');
            }

            $loader = new LintConfigLoader();
            $result = $loader->load();

            $this->assertNotNull($result->error);
            $this->assertStringContainsString('Invalid JSON', $result->error ?? '');
        } finally {
            if (is_dir($tempDir)) {
                @unlink($tempDir.'/regex.json');
                @rmdir($tempDir);
            }
            if (false !== $cwd) {
                @chdir($cwd);
            }
        }
    }

    public function test_output_renderer_renders_summary_and_banner(): void
    {
        $renderer = new LintOutputRenderer();
        $output = new Output(false, false);

        $emptyBuffer = $this->captureOutput(static function () use ($renderer, $output): void {
            $renderer->renderSummary($output, ['errors' => 0, 'warnings' => 0, 'optimizations' => 0], true);
        });
        $this->assertStringContainsString('No regex patterns found', $emptyBuffer);

        $errorsBuffer = $this->captureOutput(static function () use ($renderer, $output): void {
            $renderer->renderSummary($output, ['errors' => 2, 'warnings' => 1, 'optimizations' => 0], false);
        });
        $this->assertStringContainsString('invalid patterns', $errorsBuffer);

        $warningsBuffer = $this->captureOutput(static function () use ($renderer, $output): void {
            $renderer->renderSummary($output, ['errors' => 0, 'warnings' => 1, 'optimizations' => 3], false);
        });
        $this->assertStringContainsString('warnings found', $warningsBuffer);

        $passBuffer = $this->captureOutput(static function () use ($renderer, $output): void {
            $renderer->renderSummary($output, ['errors' => 0, 'warnings' => 0, 'optimizations' => 2], false);
        });
        $this->assertStringContainsString('No issues found', $passBuffer);

        $configPath = getcwd();
        $banner = $renderer->renderBanner($output, 2, [$configPath.'/regex.json']);
        $this->assertStringContainsString('RegexParser', $banner);
        $this->assertStringContainsString('Configuration : ', $banner);
    }

    public function test_extractor_factory_falls_back_without_php_parser(): void
    {
        if (class_exists(ParserFactory::class, false)) {
            $this->markTestSkipped('PhpParser ParserFactory already loaded; fallback branch not applicable.');
        }

        class_exists(LintExtractorFactory::class);
        class_exists(RegexPatternExtractor::class);
        class_exists(TokenBasedExtractionStrategy::class);
        // The factory builds the default pattern functions: load what they need.
        PatternFunctionRegistry::defaults();

        $autoloaders = spl_autoload_functions() ?: [];
        foreach ($autoloaders as $loader) {
            spl_autoload_unregister($loader);
        }

        try {
            $factory = new LintExtractorFactory();
            $extractor = $factory->create();

            $property = new \ReflectionProperty(RegexPatternExtractor::class, 'extractor');
            $strategy = $property->getValue($extractor);
        } finally {
            foreach ($autoloaders as $loader) {
                spl_autoload_register($loader);
            }
        }

        // Asserted once the autoloaders are back: the assertion's own classes
        // may not be loaded yet when this test runs first.
        $this->assertInstanceOf(TokenBasedExtractionStrategy::class, $strategy);
    }

    public function test_extractor_factory_prefers_phpstan_when_available(): void
    {
        $factory = new LintExtractorFactory();
        $extractor = $factory->create();

        $property = new \ReflectionProperty(RegexPatternExtractor::class, 'extractor');
        $strategy = $property->getValue($extractor);

        $this->assertInstanceOf(PhpParserExtractionStrategy::class, $strategy);
    }

    public function test_token_based_extraction_strategy_extracts_from_file(): void
    {
        $strategy = new TokenBasedExtractionStrategy();
        $tempFile = tempnam(sys_get_temp_dir(), 'test_php_');
        if (false === $tempFile) {
            $this->markTestSkipped('Unable to create temp file.');
        }
        copy(__DIR__.'/../../Fixtures/Lint/token_based_test.php', $tempFile);

        try {
            $result = $strategy->extract([$tempFile]);
            $this->assertIsArray($result);
            $this->assertCount(1, $result);
            $this->assertSame('/foo/', $result[0]->pattern);
        } finally {
            @unlink($tempFile);
        }
    }

    private function captureOutput(callable $callback): string
    {
        ob_start();
        $callback();

        return (string) ob_get_clean();
    }
}
