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

namespace PHPRegex\Tests\Functional\Cli;

use PHPRegex\Cli\Command\HelpCommand;
use PHPRegex\Cli\Command\LintArgumentParser;
use PHPRegex\Cli\Command\LintCommand;
use PHPRegex\Cli\Command\LintOutputRenderer;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Linter\Config\LintConfigLoader;
use PHPRegex\Linter\Config\LintDefaultsBuilder;
use PHPRegex\Linter\Config\LintExtractorFactory;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which presets a lint run reads: every one by default, exactly the listed
 * ones otherwise, and an unknown name is a warning on stderr, never a
 * reason to stop.
 *
 * Every pattern here is broken the same way, "missing closing parenthesis"
 * for preg_match(), so the JSON report lists exactly those the run read.
 */
final class LintExtractionPresetsTest extends TestCase
{
    use TemporaryProject;

    private const EVERY_LIBRARY = <<<'PHP_WRAP'
        <?php

        namespace App;

        use Nette\Utils\Strings;
        use Webmozart\Assert\Assert;

        final class EveryLibraryTest extends \PHPUnit\Framework\TestCase
        {
            public function test_it(string $s, \Iterator $it): void
            {
                preg_match('/(native/', $s);
                Strings::match($s, '/(nette/');
                Assert::regex($s, '/(webmozart/');
                $this->assertMatchesRegularExpression('/(phpunit/', $s);
                new \RegexIterator($it, '/(regex-iterator/');
                filter_var($s, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/(filter-var/']]);
            }
        }

        PHP_WRAP;

    #[Test]
    public function test_the_default_run_reads_every_preset(): void
    {
        $this->enterProject(['src/EveryLibraryTest.php' => self::EVERY_LIBRARY]);

        [$exitCode, $stdout] = $this->runLint(['src', '--format=json', '--jobs=1', '--no-redos']);

        $this->assertSame(1, $exitCode, $stdout);
        $this->assertSame(['/(filter-var/', '/(native/', '/(nette/', '/(phpunit/', '/(regex-iterator/', '/(webmozart/'], $this->reportedPatterns($stdout));
    }

    /**
     * interop [] means the native calls only, RegexIterator and filter_var()
     * among them.
     */
    #[Test]
    public function test_an_empty_interop_reads_the_native_calls_only(): void
    {
        $this->enterProject(['regex.json' => '{"extraction": {"interop": []}}', 'src/EveryLibraryTest.php' => self::EVERY_LIBRARY]);

        [$exitCode, $stdout] = $this->runLint(['src', '--format=json', '--jobs=1', '--no-redos']);

        $this->assertSame(1, $exitCode, $stdout);
        $this->assertSame(['/(filter-var/', '/(native/', '/(regex-iterator/'], $this->reportedPatterns($stdout));
    }

    #[Test]
    public function test_an_explicit_list_reads_exactly_those_presets(): void
    {
        $this->enterProject(['src/EveryLibraryTest.php' => self::EVERY_LIBRARY]);

        [$exitCode, $stdout] = $this->runLint(['src', '--interop=phpunit', '--format=json', '--jobs=1', '--no-redos']);

        $this->assertSame(1, $exitCode, $stdout);
        $this->assertSame(['/(filter-var/', '/(native/', '/(phpunit/', '/(regex-iterator/'], $this->reportedPatterns($stdout));
    }

    /**
     * @return iterable<string, array{files: array<string, string>, arguments: list<string>, error: string}>
     */
    public static function provideUnknownPresets(): iterable
    {
        yield 'command line flag' => ['files' => [], 'arguments' => ['--interop=nette-utils,not-a-preset'], 'error' => 'Unknown extraction preset "not-a-preset" in --interop: the presets are composer-pcre, '];
        yield 'regex.json' => ['files' => ['regex.json' => '{"extraction": {"interop": ["nette-utils", "not-a-preset"]}}'], 'arguments' => [], 'error' => 'unknown preset "not-a-preset"; expected a list of distinct presets among composer-pcre, '];
        yield 'the name laravel-str had before any tag' => ['files' => ['regex.json' => '{"extraction": {"interop": ["laravel-str", "nette-utils"]}}'], 'arguments' => [], 'error' => 'unknown preset "laravel-str"; did you mean "illuminate-support"?'];
        yield 'a list made only of an unknown name' => ['files' => [], 'arguments' => ['--interop=laravel-str'], 'error' => 'Unknown extraction preset "laravel-str" in --interop: did you mean "illuminate-support"?'];
    }

    /**
     * An unknown preset is a configuration error, exit code 2, which names
     * the preset it most likely means: left out, it would leave a list that
     * reads fewer calls than meant, the native ones only when it was the
     * only one.
     *
     * @param array<string, string> $files
     * @param list<string>          $arguments
     */
    #[Test]
    #[DataProvider('provideUnknownPresets')]
    public function test_an_unknown_preset_is_a_configuration_error(array $files, array $arguments, string $error): void
    {
        $this->enterProject([...$files, 'src/EveryLibraryTest.php' => self::EVERY_LIBRARY]);

        [$exitCode, $stdout, $stderr] = $this->runLint(['src', ...$arguments, '--format=json', '--jobs=1', '--no-redos']);

        $this->assertSame(2, $exitCode, $stdout.$stderr);
        $this->assertStringContainsString($error, stripslashes($stdout), $stdout.$stderr);
        $this->assertStringNotContainsString('"results"', $stdout);
    }

    /**
     * -q silences the status lines, never the error.
     */
    #[Test]
    public function test_quiet_still_reports_an_unknown_preset(): void
    {
        $this->enterProject(['src/EveryLibraryTest.php' => self::EVERY_LIBRARY]);

        [$exitCode, $stdout, $stderr] = $this->runLint(['src', '--interop=other-unknown,nette-utils', '--jobs=1', '--no-redos'], quiet: true);

        $this->assertSame(2, $exitCode, $stdout.$stderr);
        $this->assertStringContainsString('Error: Unknown extraction preset "other-unknown" in --interop', $stderr);
    }

    /**
     * @return iterable<string, array{files: array<string, string>, arguments: list<string>}>
     */
    public static function provideRepeatedUnknownPresets(): iterable
    {
        yield 'in regex.dist.json and regex.json' => ['files' => ['regex.dist.json' => '{"extraction": {"interop": ["x"]}}', 'regex.json' => '{"extraction": {"interop": ["x"]}}'], 'arguments' => []];
        yield 'twice on the command line' => ['files' => [], 'arguments' => ['--interop=x,x']];
    }

    /**
     * Each file that names the unknown preset is told so once, and the
     * command line once.
     *
     * @param array<string, string> $files
     * @param list<string>          $arguments
     */
    #[Test]
    #[DataProvider('provideRepeatedUnknownPresets')]
    public function test_an_unknown_preset_named_twice_is_reported_once_per_place(array $files, array $arguments): void
    {
        $this->enterProject([...$files, 'src/EveryLibraryTest.php' => self::EVERY_LIBRARY]);

        [$exitCode, $stdout, $stderr] = $this->runLint(['src', ...$arguments, '--jobs=1', '--no-redos']);

        $this->assertSame(2, $exitCode, $stdout.$stderr);
        $this->assertSame(max(1, \count($files)), substr_count(strtolower($stderr), 'preset "x"'), $stderr);
    }

    /**
     * A resolved pattern keeps the JSON shape: its position is the
     * declaration's literal, its source the first call, its location the
     * way the call reaches it. Two calls, one result.
     */
    #[Test]
    public function test_a_resolved_pattern_is_reported_once_at_its_declaration_in_json(): void
    {
        $code = <<<'PHP'
            <?php

            final class Matcher
            {
                private const RE = '/(resolved/';

                public function run(string $s): bool
                {
                    return 1 === preg_match(self::RE, $s) || [] !== preg_split(self::RE, $s);
                }
            }

            PHP;
        $this->enterProject(['src/Matcher.php' => $code]);

        [$exitCode, $stdout] = $this->runLint(['src', '--format=json', '--jobs=1', '--no-redos']);

        $this->assertSame(1, $exitCode, $stdout);
        $results = JsonContract::asArray(JsonContract::decodeDocument($stdout)['results'] ?? null);
        $this->assertCount(1, $results);
        $result = JsonContract::asArray($results[0] ?? null);
        $this->assertSame([
            'line' => 5,
            'column' => 24,
            'file_offset' => strpos($code, "'/(resolved/'"),
            'source' => 'preg_match()',
            'pattern' => '/(resolved/',
            'location' => 'via self::RE',
        ], array_intersect_key($result, array_flip(['line', 'column', 'file_offset', 'source', 'pattern', 'location'])));
    }

    /**
     * @return list<string> sorted
     */
    private function reportedPatterns(string $stdout): array
    {
        $patterns = [];
        foreach (JsonContract::asArray(JsonContract::decodeDocument($stdout)['results'] ?? null) as $result) {
            $pattern = JsonContract::asArray($result)['pattern'] ?? null;
            $this->assertIsString($pattern);
            $patterns[] = $pattern;
        }
        sort($patterns);

        return array_values(array_unique($patterns));
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function runLint(array $args, bool $quiet = false): array
    {
        $command = new LintCommand(
            new HelpCommand(),
            new LintConfigLoader(),
            new LintDefaultsBuilder(),
            new LintArgumentParser(),
            new LintExtractorFactory(),
            new LintOutputRenderer(),
        );
        // -q is a global option: the application takes it off the command
        // line and makes the output quiet.
        $output = OutputFactory::create(quiet: $quiet);

        ob_start();

        try {
            $exitCode = $command->run(new Input('lint', $args, new GlobalOptions($quiet, false, false, true, null, null), []), $output);
        } finally {
            $stdout = (string) ob_get_clean();
        }

        return [$exitCode, $stdout, OutputFactory::errors($output)];
    }
}
