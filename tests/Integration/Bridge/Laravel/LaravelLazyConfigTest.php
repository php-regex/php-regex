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

namespace RegexParser\Tests\Integration\Bridge\Laravel;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\Attributes\WithConfig;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RegexParser\Bridge\Laravel\RegexParserServiceProvider;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * A setting the lint cannot use costs the lint, not the application: an
 * unknown ReDoS threshold is refused when regex:lint runs, while every other
 * artisan command keeps working. A 1.x key still in a published config is
 * named by regex:lint with the key that replaces it. The automata settings
 * are the defaults of regex:compare, whose options win.
 */
#[WithConfig('regex-parser.cache.directory', null)]
#[WithConfig('regex-parser.cache.store', null)]
final class LaravelLazyConfigTest extends TestCase
{
    private string $sourceDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDir = sys_get_temp_dir().'/regex-parser-laravel-lazy-'.bin2hex(random_bytes(6));
        mkdir($this->sourceDir, 0o700, true);
        file_put_contents($this->sourceDir.'/Pattern.php', "<?php\n\npreg_match('/^[a-z]+\$/', \$subject);\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->sourceDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->sourceDir);

        parent::tearDown();
    }

    #[Test]
    #[WithConfig('regex-parser.redos.threshold', 'severe')]
    public function test_an_unknown_threshold_leaves_artisan_list_working(): void
    {
        $status = Artisan::call('list');

        $this->assertSame(0, $status);
        $this->assertStringContainsString('regex:lint', Artisan::output());
    }

    #[Test]
    #[WithConfig('regex-parser.redos.threshold', 'severe')]
    public function test_an_unknown_threshold_leaves_other_regex_commands_working(): void
    {
        $status = Artisan::call('regex:explain', ['pattern' => '/^[a-z]+$/']);

        $this->assertSame(0, $status);
    }

    #[Test]
    #[WithConfig('regex-parser.redos.threshold', 'severe')]
    public function test_the_lint_command_refuses_an_unknown_threshold(): void
    {
        $status = Artisan::call('regex:lint', $this->lintArguments());

        $this->assertNotSame(0, $status);
        $this->assertStringContainsString('"severe"', Artisan::output());
    }

    #[Test]
    #[WithConfig('regex-parser.exclude_paths', ['vendor'])]
    public function test_the_lint_command_names_the_replacement_of_a_stale_key(): void
    {
        $status = Artisan::call('regex:lint', $this->lintArguments());
        $output = Artisan::output();

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('exclude_paths', $output);
        $this->assertStringContainsString('"exclude"', $output);
    }

    #[Test]
    #[WithConfig('regex-parser.analysis.ignore_patterns', ['foo'])]
    public function test_a_stale_key_keeps_the_json_report_parseable(): void
    {
        $status = Artisan::call('regex:lint', $this->lintArguments() + ['--format' => 'json']);
        $output = Artisan::output();

        $this->assertSame(0, $status, $output);
        $this->assertIsArray(json_decode($output, true), 'regex:lint did not print JSON: '.$output);
    }

    #[Test]
    #[WithConfig('regex-parser.analysis.ignore_patterns', ['foo'])]
    #[WithConfig('regex-parser.php_version', '8.2')]
    public function test_other_formats_say_the_target_and_the_stale_keys_on_stderr(): void
    {
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        $this->assertIsResource($stdout);
        $this->assertIsResource($stderr);
        $output = new class($stdout, new StreamOutput($stderr)) extends StreamOutput implements ConsoleOutputInterface {
            /**
             * @param resource $stream
             */
            public function __construct($stream, private OutputInterface $errorOutput)
            {
                parent::__construct($stream);
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
                throw new \LogicException('Not used by the lint command.');
            }
        };

        $status = Artisan::call('regex:lint', $this->lintArguments() + ['--format' => 'json'], $output);

        rewind($stdout);
        rewind($stderr);
        $report = (string) stream_get_contents($stdout);
        $errors = (string) stream_get_contents($stderr);

        $this->assertSame(0, $status, $report.$errors);
        $this->assertIsArray(json_decode($report, true), 'stdout is not JSON: '.$report);
        $this->assertStringContainsString('Target: PHP 8.2, PCRE2 10.40 (config regex-parser.php_version)', $errors);
        $this->assertStringContainsString('analysis.ignore_patterns', $errors);
    }

    #[Test]
    #[WithConfig('regex-parser.php_version', 8.2)]
    public function test_a_php_version_that_is_no_string_or_int_stops_the_lint(): void
    {
        $status = Artisan::call('regex:lint', $this->lintArguments());

        $this->assertNotSame(0, $status);
        $this->assertStringContainsString('php_version', Artisan::output());
    }

    #[Test]
    #[WithConfig('regex-parser.pcre_version', 10.42)]
    public function test_a_pcre_release_that_is_no_string_stops_the_lint(): void
    {
        $status = Artisan::call('regex:lint', $this->lintArguments() + ['--format' => 'json']);
        $json = json_decode(Artisan::output(), true);

        $this->assertNotSame(0, $status);
        $this->assertIsArray($json);
        $this->assertIsString($json['error'] ?? null);
        $this->assertStringContainsString('pcre_version', $json['error']);
    }

    #[Test]
    #[WithConfig('regex-parser.automata.minimization_algorithm', 'moore')]
    public function test_the_compare_option_wins_over_the_config(): void
    {
        $status = Artisan::call('regex:compare', ['pattern1' => '/a/', 'pattern2' => '/a/', '--minimizer' => 'hopcroft', '--format' => 'json']);
        $json = json_decode(Artisan::output(), true);

        $this->assertSame(0, $status);
        $this->assertIsArray($json);
        $this->assertSame(['minimizer' => 'hopcroft', 'determinizer' => 'subset-indexed'], $json['algorithms'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function lintArguments(): array
    {
        return [
            'paths' => [$this->sourceDir],
            '--no-routes' => true,
            '--no-validators' => true,
            '--jobs' => '1',
        ];
    }

    /**
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [RegexParserServiceProvider::class];
    }
}
