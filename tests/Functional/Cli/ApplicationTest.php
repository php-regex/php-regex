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

use PHPRegex\Cli\Application;
use PHPRegex\Cli\Command\CommandInterface;
use PHPRegex\Cli\Command\JsonCommandInterface;
use PHPRegex\Cli\GlobalOptionsParser;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Output;
use PHPRegex\Linter\LintException;
use PHPRegex\Parser\Internal\JsonDocument;
use PHPRegex\Parser\Internal\JsonEncodingFailure;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    public function test_register_adds_aliases(): void
    {
        $output = OutputFactory::create();
        $help = new DummyCommand('help');
        $app = new Application(new GlobalOptionsParser(), $output, $help);

        $command = new DummyCommand('lint', ['check']);
        $app->register($command);

        $buffer = '';
        $exitCode = $this->runApp($app, ['regex', 'check'], $buffer);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $command->runs);
        $this->assertInstanceOf(Input::class, $command->lastInput);
        $this->assertSame('check', $command->lastInput->command);
    }

    public function test_run_with_help_option_invokes_help_command(): void
    {
        $output = OutputFactory::create();
        $help = new DummyCommand('help');
        $app = new Application(new GlobalOptionsParser(), $output, $help);

        $buffer = '';
        $exitCode = $this->runApp($app, ['regex', '--help'], $buffer);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $help->runs);
    }

    public function test_run_with_missing_command_shows_help(): void
    {
        $output = OutputFactory::create();
        $help = new DummyCommand('help');
        $app = new Application(new GlobalOptionsParser(), $output, $help);

        $buffer = '';
        $exitCode = $this->runApp($app, ['regex'], $buffer);

        $this->assertSame(2, $exitCode);
        $this->assertSame(1, $help->runs);
    }

    public function test_run_with_unknown_command_outputs_error_and_help(): void
    {
        $output = OutputFactory::create();
        $help = new DummyCommand('help');
        $app = new Application(new GlobalOptionsParser(), $output, $help);

        $buffer = '';
        $exitCode = $this->runApp($app, ['regex', 'unknown'], $buffer);

        $this->assertSame(2, $exitCode);
        $this->assertSame(1, $help->runs);
        $this->assertStringContainsString('Unknown command', $buffer);
    }

    public function test_run_with_pattern_uses_highlight_command(): void
    {
        $output = OutputFactory::create();
        $help = new DummyCommand('help');
        $highlight = new DummyCommand('highlight');
        $app = new Application(new GlobalOptionsParser(), $output, $help);
        $app->register($highlight);

        $buffer = '';
        $exitCode = $this->runApp($app, ['regex', '/a+/'], $buffer);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $highlight->runs);
        $this->assertInstanceOf(Input::class, $highlight->lastInput);
        $this->assertSame('/a+/', $highlight->lastInput->args[0]);
    }

    public function test_run_reports_global_option_errors(): void
    {
        $output = OutputFactory::create();
        $help = new DummyCommand('help');
        $app = new Application(new GlobalOptionsParser(), $output, $help);

        $buffer = '';
        $exitCode = $this->runApp($app, ['regex', '--php-version', '--help'], $buffer);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Missing value for --php-version', $buffer);
        $this->assertSame(0, $help->runs);
    }

    /**
     * During a JSON run PHP's own diagnostics go to stderr, stdout holding
     * the document; the setting is put back once the run is over. A text run
     * leaves it alone.
     *
     * @param list<string> $options
     */
    #[Test]
    #[DataProvider('provideDisplayErrorsRuns')]
    public function test_json_run_sends_php_diagnostics_to_stderr(array $options, string $duringRun): void
    {
        $probe = new DisplayErrorsProbeCommand();
        $app = new Application(new GlobalOptionsParser(), OutputFactory::create(), new DummyCommand('help'));
        $app->register($probe);
        $original = \ini_get('display_errors');
        ini_set('display_errors', '1');

        try {
            $buffer = '';
            $this->runApp($app, ['regex', 'probe', ...$options], $buffer);
            $afterRun = \ini_get('display_errors');
        } finally {
            ini_set('display_errors', false === $original ? '' : $original);
        }

        $this->assertSame($duringRun, $probe->displayErrors);
        $this->assertSame('1', $afterRun);
    }

    /**
     * @return iterable<string, array{options: list<string>, duringRun: string}>
     */
    public static function provideDisplayErrorsRuns(): iterable
    {
        yield 'JSON asked' => ['options' => ['--json'], 'duringRun' => 'stderr'];
        yield 'text' => ['options' => [], 'duringRun' => '1'];
    }

    /**
     * The first argument after the binary counts: --json there, before the
     * command name, asks for JSON, and the envelope says where options go.
     */
    #[Test]
    public function test_json_as_the_first_argument_asks_for_the_envelope(): void
    {
        $app = new Application(new GlobalOptionsParser(), OutputFactory::create(), new DummyCommand('help'));

        $buffer = '';
        $exitCode = $this->runApp($app, ['regex', '--json', 'lnt'], $buffer);

        $this->assertSame(2, $exitCode);
        $this->assertSame(
            ['error' => 'Unknown command: --json. Options go after the command name.', 'stage' => 'usage'],
            json_decode($buffer, true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * A JSON run leaves display_errors off when it is off: there is nothing
     * PHP would print, on stdout or anywhere else.
     */
    #[Test]
    #[DataProvider('provideDisplayErrorsOff')]
    public function test_json_run_leaves_display_errors_off(string $setting): void
    {
        $probe = new DisplayErrorsProbeCommand();
        $app = new Application(new GlobalOptionsParser(), OutputFactory::create(), new DummyCommand('help'));
        $app->register($probe);
        $original = \ini_get('display_errors');
        ini_set('display_errors', $setting);

        try {
            $buffer = '';
            $this->runApp($app, ['regex', 'probe', '--json'], $buffer);
            $afterRun = \ini_get('display_errors');
        } finally {
            ini_set('display_errors', false === $original ? '' : $original);
        }

        $this->assertSame($setting, $probe->displayErrors);
        $this->assertSame($setting, $afterRun);
    }

    /**
     * @return iterable<string, array{setting: string}>
     */
    public static function provideDisplayErrorsOff(): iterable
    {
        yield 'zero' => ['setting' => '0'];
        yield 'empty, as php -d display_errors=off leaves it' => ['setting' => ''];
        yield 'Off' => ['setting' => 'Off'];
        yield '256, zero once cast to the mode PHP keeps' => ['setting' => '256'];
    }

    /**
     * A failure no command caught, in a JSON run that has not printed its
     * document yet: the envelope, stage "internal", exit code 1.
     */
    #[Test]
    #[DataProvider('provideUncaughtFailures')]
    public function test_json_run_reports_an_uncaught_failure_as_the_internal_envelope(\RuntimeException $failure): void
    {
        $app = new Application(new GlobalOptionsParser(), OutputFactory::create(), new DummyCommand('help'));
        $app->register(new FailingJsonCommand($failure));

        $buffer = '';
        $exitCode = $this->runApp($app, ['regex', 'fail', '--json'], $buffer);

        $this->assertSame(1, $exitCode);
        $this->assertStringEndsWith("}\n", $buffer);
        $this->assertSame(
            ['error' => $failure->getMessage(), 'stage' => 'internal'],
            json_decode($buffer, true, flags: \JSON_THROW_ON_ERROR),
        );
        $this->assertNull(Application::reportFatalError());
    }

    /**
     * @return iterable<string, array{failure: \RuntimeException}>
     */
    public static function provideUncaughtFailures(): iterable
    {
        yield 'a worker of a parallel lint run died' => [
            'failure' => new LintException('Parallel analysis failed: RuntimeException: Invalid worker output.'),
        ];
        yield 'a value has no JSON form' => [
            'failure' => new JsonEncodingFailure('Failed to encode JSON: Inf and NaN cannot be JSON encoded'),
        ];
    }

    /**
     * A text run reports nothing of its own: the failure goes on to PHP, as
     * it always did.
     */
    #[Test]
    public function test_text_run_lets_an_uncaught_failure_through(): void
    {
        $app = new Application(new GlobalOptionsParser(), OutputFactory::create(), new DummyCommand('help'));
        $app->register(new FailingJsonCommand(new LintException('Parallel analysis failed.')));

        [$failure, $buffer] = $this->runFailingApp($app, ['regex', 'fail']);

        $this->assertInstanceOf(LintException::class, $failure);
        $this->assertSame('Parallel analysis failed.', $failure->getMessage());
        $this->assertSame('', $buffer);
    }

    /**
     * Once the document is printed, a second one would break stdout: the
     * failure goes on to PHP, and stdout keeps the one document.
     */
    #[Test]
    public function test_json_run_that_printed_its_document_lets_a_later_failure_through(): void
    {
        $app = new Application(new GlobalOptionsParser(), OutputFactory::create(), new DummyCommand('help'));
        $app->register(new FailingJsonCommand(new LintException('Late failure.'), writesDocumentFirst: true));

        [$failure, $buffer] = $this->runFailingApp($app, ['regex', 'fail', '--json']);

        $this->assertInstanceOf(LintException::class, $failure);
        $this->assertSame(['ok' => true], json_decode($buffer, true, flags: \JSON_THROW_ON_ERROR));
        $this->assertNull(Application::reportFatalError());
    }

    public function test_resolve_ansi_honors_forced_value_and_fallback(): void
    {
        $output = OutputFactory::create();
        $help = new DummyCommand('help');
        $app = new Application(new GlobalOptionsParser(), $output, $help);

        $method = new \ReflectionMethod(Application::class, 'shouldUseAnsi');

        $this->assertTrue($method->invoke($app, true));
        $this->assertFalse($method->invoke($app, false));

        $fallback = $method->invoke($app, null);
        $this->assertIsBool($fallback);
    }

    public function test_run_with_empty_command_name_shows_help(): void
    {
        $output = OutputFactory::create();
        $help = new DummyCommand('help');
        $app = new Application(new GlobalOptionsParser(), $output, $help);

        $buffer = '';
        $exitCode = $this->runApp($app, ['regex', ''], $buffer);

        $this->assertSame(2, $exitCode);
        $this->assertSame(1, $help->runs);
    }

    public function test_run_with_php_version_option_sets_regex_options(): void
    {
        $output = OutputFactory::create();
        $help = new DummyCommand('help');
        $command = new DummyCommand('test');
        $app = new Application(new GlobalOptionsParser(), $output, $help);
        $app->register($command);

        $buffer = '';
        $exitCode = $this->runApp($app, ['regex', '--php-version=8.1', 'test'], $buffer);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $command->runs);
        $this->assertInstanceOf(Input::class, $command->lastInput);
        $this->assertSame(['php_version' => '8.1'], $command->lastInput->regexOptions);
    }

    public function test_run_with_pcre_version_option_sets_regex_options(): void
    {
        $output = OutputFactory::create();
        $help = new DummyCommand('help');
        $command = new DummyCommand('test');
        $app = new Application(new GlobalOptionsParser(), $output, $help);
        $app->register($command);

        $buffer = '';
        $exitCode = $this->runApp($app, ['regex', '--php-version=8.4', '--pcre-version', '10.42', 'test'], $buffer);

        $this->assertSame(0, $exitCode);
        $this->assertInstanceOf(Input::class, $command->lastInput);
        $this->assertSame(['php_version' => '8.4', 'pcre_version' => '10.42'], $command->lastInput->regexOptions);
    }

    /**
     * Runs a command that throws a LintException, and gives the exception
     * and what stdout received before it.
     *
     * @param array<int, string> $argv
     *
     * @return array{?LintException, string}
     */
    private function runFailingApp(Application $app, array $argv): array
    {
        $level = ob_get_level();
        ob_start();
        $failure = null;

        try {
            $app->run($argv);
        } catch (LintException $e) {
            $failure = $e;
        } finally {
            $buffer = (string) ob_get_contents();
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return [$failure, $buffer];
    }

    /**
     * @param array<int, string> $argv
     */
    private function runApp(Application $app, array $argv, string &$buffer): int
    {
        ob_start();
        $exitCode = $app->run($argv);
        $buffer = (string) ob_get_clean();

        return $exitCode;
    }
}

final class DummyCommand implements CommandInterface
{
    public int $runs = 0;

    public ?Input $lastInput = null;

    /**
     * @param array<int, string> $aliases
     */
    public function __construct(
        private readonly string $name,
        private readonly array $aliases = [],
        private readonly int $exitCode = 0,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getAliases(): array
    {
        return $this->aliases;
    }

    public function getDescription(): string
    {
        return 'dummy';
    }

    public function run(Input $input, Output $output): int
    {
        $this->runs++;
        $this->lastInput = $input;

        return $this->exitCode;
    }
}

/**
 * A command with a JSON mode that records the display_errors setting it
 * runs under.
 */
final class DisplayErrorsProbeCommand implements JsonCommandInterface
{
    public string|false|null $displayErrors = null;

    public function getName(): string
    {
        return 'probe';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return 'probe';
    }

    public function run(Input $input, Output $output): int
    {
        $this->displayErrors = \ini_get('display_errors');

        return self::SUCCESS;
    }
}

/**
 * A command with a JSON mode that throws what it is given, after printing
 * its document when asked to.
 */
final readonly class FailingJsonCommand implements JsonCommandInterface
{
    public function __construct(private \RuntimeException $failure, private bool $writesDocumentFirst = false) {}

    public function getName(): string
    {
        return 'fail';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return 'fail';
    }

    public function run(Input $input, Output $output): int
    {
        if ($this->writesDocumentFirst) {
            $output->writeDocument(JsonDocument::encode(['ok' => true]));
        }

        throw $this->failure;
    }
}
