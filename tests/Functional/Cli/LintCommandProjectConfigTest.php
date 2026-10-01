<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Functional\Cli;

use PhpRegex\Cli\Command\HelpCommand;
use PhpRegex\Cli\Command\LintCommand;
use PhpRegex\Cli\Command\LintOutputRenderer;
use PhpRegex\Cli\GlobalOptions;
use PhpRegex\Cli\Input;
use PhpRegex\Cli\Output;
use PhpRegex\Linter\Config\LintArgumentParser;
use PhpRegex\Linter\Config\LintConfigLoader;
use PhpRegex\Linter\Config\LintDefaultsBuilder;
use PhpRegex\Linter\Config\LintExtractorFactory;
use PhpRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The lint command on a project directory: a configuration it cannot use
 * is exit code 2, a JSON run reports it as JSON, and a JSON report names
 * the PHP and PCRE2 it judged for and where that came from.
 */
final class LintCommandProjectConfigTest extends TestCase
{
    use TemporaryProject;

    private const PHP_FILE = <<<'PHP'
        <?php

        preg_match('/^[a-z]+$/', 'abc');

        PHP;

    #[Test]
    #[DataProvider('provideBrokenConfigs')]
    public function test_lint_exits_2_on_a_config_error(string $json): void
    {
        $this->enterProject(['regex.json' => $json, 'src/a.php' => self::PHP_FILE]);

        [$exitCode] = $this->runLint(['src', '--format=console', '--jobs=1']);

        $this->assertSame(2, $exitCode);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideBrokenConfigs(): iterable
    {
        yield 'invalid JSON' => ['{'];
        yield 'unknown key' => ['{"unknownTop": 1}'];
        yield 'removed key' => ['{"redosMode": "confirmed"}'];
    }

    /**
     * @param list<string> $formatArguments
     */
    #[Test]
    #[DataProvider('provideJsonConfigErrors')]
    public function test_lint_prints_a_config_error_as_json_on_stdout(string $json, array $formatArguments, string $named): void
    {
        $this->enterProject(['regex.json' => $json, 'src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout] = $this->runLint(['src', ...$formatArguments, '--jobs=1']);

        $this->assertSame(2, $exitCode);
        $payload = json_decode($stdout, true);
        $this->assertIsArray($payload, 'stdout is not JSON: '.$stdout);
        $this->assertSame(['error'], array_keys($payload));
        $this->assertIsString($payload['error']);
        $this->assertStringContainsString($named, (string) $payload['error']);
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function provideJsonConfigErrors(): iterable
    {
        yield 'invalid JSON, --format=json' => ['{', ['--format=json'], 'regex.json'];
        yield 'invalid JSON, --format json' => ['{', ['--format', 'json'], 'regex.json'];
        yield 'unknown key, --format=json' => ['{"unknownTop": 1}', ['--format=json'], 'unknownTop'];
    }

    #[Test]
    public function test_lint_json_stdout_is_one_json_document(): void
    {
        // Progress and scan counts belong to the console format: a JSON run
        // that finds patterns must still print nothing but the report.
        $this->enterProject(['src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout] = $this->runLint(['src', '--format=json', '--jobs=1', '--no-redos']);

        $this->assertSame(0, $exitCode);
        json_decode($stdout, true);
        $this->assertSame(\JSON_ERROR_NONE, json_last_error(), 'stdout is not JSON: '.$stdout);
    }

    #[Test]
    public function test_lint_exits_2_on_a_usage_error(): void
    {
        $this->enterProject(['src/a.php' => self::PHP_FILE]);

        [$exitCode] = $this->runLint(['src', '--redos-threshold=bogus']);

        $this->assertSame(2, $exitCode);
    }

    #[Test]
    public function test_lint_json_names_the_target_from_composer(): void
    {
        $this->enterProject([
            'composer.json' => '{"name": "acme/app", "require": {"php": "^8.3"}}',
            'src/a.php' => self::PHP_FILE,
        ]);

        $payload = $this->lintJson(['src']);

        $this->assertSame(['php' => '8.3', 'pcre' => '10.42', 'source' => 'composer.json require.php'], $payload['target'] ?? null);
    }

    #[Test]
    public function test_lint_json_names_the_target_from_the_flag(): void
    {
        $this->enterProject([
            'composer.json' => '{"name": "acme/app", "require": {"php": "^8.3"}}',
            'src/a.php' => self::PHP_FILE,
        ]);

        $payload = $this->lintJson(['src'], '8.4');

        $this->assertSame(['php' => '8.4', 'pcre' => '10.44', 'source' => '--php-version'], $payload['target'] ?? null);
    }

    #[Test]
    public function test_lint_json_names_the_target_from_regex_json(): void
    {
        $this->enterProject([
            'regex.json' => '{"phpVersion": "8.2"}',
            'composer.json' => '{"name": "acme/app", "require": {"php": "^8.3"}}',
            'src/a.php' => self::PHP_FILE,
        ]);

        $payload = $this->lintJson(['src']);

        $this->assertSame(['php' => '8.2', 'pcre' => '10.40', 'source' => 'regex.json'], $payload['target'] ?? null);
    }

    #[Test]
    public function test_lint_json_names_the_target_when_no_pattern_is_found(): void
    {
        $this->enterProject([
            'composer.json' => '{"name": "acme/app", "require": {"php": "^8.3"}}',
            'src/a.php' => "<?php\n\necho 'no pattern here';\n",
        ]);

        $payload = $this->lintJson(['src']);

        $this->assertSame(['php' => '8.3', 'pcre' => '10.42', 'source' => 'composer.json require.php'], $payload['target'] ?? null);
    }

    /**
     * @param list<string> $args
     *
     * @return array<string, mixed>
     */
    private function lintJson(array $args, ?string $phpVersion = null): array
    {
        [$exitCode, $stdout] = $this->runLint([...$args, '--format=json', '--jobs=1', '--no-redos'], $phpVersion);

        $this->assertSame(0, $exitCode, $stdout);
        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($stdout, true);
        $this->assertIsArray($payload, 'stdout is not JSON: '.$stdout);

        return $payload;
    }

    /**
     * Runs the command the way the application builds its input.
     *
     * @param list<string> $args
     *
     * @return array{int, string}
     */
    private function runLint(array $args, ?string $phpVersion = null): array
    {
        $command = new LintCommand(
            new HelpCommand(),
            new LintConfigLoader(),
            new LintDefaultsBuilder(),
            new LintArgumentParser(),
            new LintExtractorFactory(),
            new LintOutputRenderer(),
        );
        $input = new Input(
            'lint',
            $args,
            new GlobalOptions(false, false, false, true, $phpVersion, null),
            null === $phpVersion ? [] : ['php_version' => $phpVersion],
        );

        ob_start();

        try {
            $exitCode = $command->run($input, new Output(false, false));
        } finally {
            $stdout = (string) ob_get_clean();
        }

        return [$exitCode, $stdout];
    }
}
