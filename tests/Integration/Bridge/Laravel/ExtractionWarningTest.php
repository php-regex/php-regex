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

namespace PHPRegex\Tests\Integration\Bridge\Laravel;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\Attributes\WithConfig;
use Orchestra\Testbench\TestCase;
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Linter\Extraction\InteropPresets;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * How php-regex.extraction is read: what a key holds that is no list, a
 * name that is no string, a name given twice, a name that is no preset,
 * and where the warnings go outside the console format.
 */
#[WithConfig('php-regex.cache.directory', null)]
#[WithConfig('php-regex.cache.store', null)]
#[WithConfig('php-regex.runtime_pcre_validation', false)]
final class ExtractionWarningTest extends TestCase
{
    use TemporaryProject;

    /**
     * @param array{interop: list<string>, functions: list<string>, warnings: list<string>} $expected
     */
    #[Test]
    #[DataProvider('provideExtractionSettings')]
    public function test_the_extraction_settings_are_read(mixed $extraction, array $expected): void
    {
        config(['php-regex.extraction' => $extraction]);
        $this->assertNotNull($this->app);

        $this->assertSame($expected, PHPRegexServiceProvider::extraction($this->app));
    }

    /**
     * @return iterable<string, array{extraction: mixed, expected: array{interop: list<string>, functions: list<string>, warnings: list<string>}}>
     */
    public static function provideExtractionSettings(): iterable
    {
        yield 'no section' => [
            'extraction' => 'not-a-section',
            'expected' => ['interop' => InteropPresets::DEFAULT_PRESETS, 'functions' => [], 'warnings' => []],
        ];

        yield 'one name, no list' => [
            'extraction' => ['interop' => 'nette-utils', 'functions' => 'App\grep#1'],
            'expected' => ['interop' => ['nette-utils'], 'functions' => ['App\grep#1'], 'warnings' => []],
        ];

        yield 'names that are no string, and twice the same' => [
            'extraction' => ['interop' => ['nette-utils', 42, ['x'], false, 'nette-utils', 42], 'functions' => [7, 'App\grep#1', null]],
            'expected' => [
                'interop' => ['nette-utils'],
                'functions' => ['App\grep#1'],
                'warnings' => [
                    'Extraction interop entry ignored: expected a string, got int.',
                    'Extraction interop entry ignored: expected a string, got array.',
                    'Extraction interop entry ignored: expected a string, got bool.',
                    'Extraction functions entry ignored: expected a string, got int.',
                    'Extraction functions entry ignored: expected a string, got null.',
                ],
            ],
        ];

        yield 'function specs the lint cannot read' => [
            'extraction' => ['interop' => [], 'functions' => ['x#1$', 'App\grep#1$expression', 'x#a']],
            'expected' => [
                'interop' => [],
                'functions' => ['App\grep#1$expression'],
                'warnings' => ['Malformed pattern function "x#1$", ignored.', 'Malformed pattern function "x#a", ignored.'],
            ],
        ];
    }

    /**
     * A name that is no preset is an error of the configuration, which
     * names the preset it most likely means: left out, it would leave a list
     * that reads fewer calls than meant, the native ones only when it was
     * the only one.
     */
    #[Test]
    public function test_an_unknown_preset_is_a_configuration_error(): void
    {
        config(['php-regex.extraction' => ['interop' => ['laravel-str']]]);
        $this->assertNotNull($this->app);

        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('Unknown extraction preset "laravel-str" in php-regex.extraction.interop: did you mean "illuminate-support"?');

        PHPRegexServiceProvider::extraction($this->app);
    }

    #[Test]
    public function test_other_formats_say_the_warning_on_stderr(): void
    {
        $project = $this->makeProject(['app/Check.php' => "<?php\n\npreg_match('/fine/', \$s);\n"]);
        $this->app?->setBasePath($project);
        config(['php-regex.extraction' => ['functions' => ['x#a']]]);

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

        $status = Artisan::call('regex:lint', [
            'paths' => [$project.'/app'],
            '--no-routes' => true,
            '--no-validators' => true,
            '--jobs' => '1',
            '--format' => 'json',
        ], $output);

        rewind($stdout);
        rewind($stderr);
        $report = (string) stream_get_contents($stdout);
        $errors = (string) stream_get_contents($stderr);

        $this->assertSame(0, $status, $report.$errors);
        $this->assertIsArray(json_decode($report, true), 'stdout is not JSON: '.$report);
        $this->assertStringContainsString('Warning: Malformed pattern function "x#a", ignored.', $errors);
    }

    /**
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }
}
