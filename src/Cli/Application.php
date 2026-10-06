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

namespace PHPRegex\Cli;

use PHPRegex\Cli\Command\CommandInterface;
use PHPRegex\Cli\Command\HelpCommand;
use PHPRegex\Cli\Command\JsonCommandInterface;
use PHPRegex\Parser\Internal\IniFlag;
use PHPRegex\Parser\Internal\JsonDocument;

/**
 * @internal
 */
final class Application
{
    /**
     * The errors that stop PHP: no document follows them.
     */
    private const FATAL_ERRORS = \E_ERROR | \E_PARSE | \E_CORE_ERROR | \E_COMPILE_ERROR | \E_USER_ERROR;

    /**
     * The bytes set aside during a JSON run for printing the envelope after
     * PHP ran out of memory.
     */
    private const MEMORY_RESERVE = 256 * 1024;

    /**
     * The output of the JSON run in progress, null between runs.
     */
    private static ?Output $jsonRunOutput = null;

    /**
     * The process the JSON run in progress started in, null between runs.
     */
    private static int|false|null $jsonRunPid = null;

    /**
     * Freed first when PHP stops a JSON run, null between runs.
     */
    private static ?string $memoryReserve = null;

    /**
     * @var array<string, CommandInterface>
     */
    private array $commands = [];

    /**
     * The commands as they were registered, without their aliases.
     *
     * @var array<int, CommandInterface>
     */
    private array $registered = [];

    public function __construct(
        private readonly GlobalOptionsParser $globalOptionsParser,
        private readonly Output $output,
        private readonly CommandInterface $helpCommand,
    ) {}

    public function register(CommandInterface $command): void
    {
        $this->registered[] = $command;
        $this->commands[$command->getName()] = $command;

        foreach ($command->getAliases() as $alias) {
            $this->commands[$alias] = $command;
        }
    }

    /**
     * @param array<int, string> $argv
     */
    public function run(array $argv): int
    {
        $parsed = $this->parseArguments($argv);
        $options = $parsed->options;
        $args = $parsed->args;

        $this->configureOutput($options);

        // The command's own options are not read yet: whether JSON was asked
        // for is read from the whole command line.
        if (!$this->asksForJson(\array_slice($argv, 1), $args)) {
            return $this->dispatch($args, $options, false);
        }

        // Where ini_get() or ini_set() is disabled the setting is left as it
        // is: the run goes on.
        $displayErrors = \function_exists('ini_get') && \function_exists('ini_set') ? \ini_get('display_errors') : false;
        if (false !== $displayErrors && IniFlag::displaysErrors($displayErrors)) {
            // PHP's own diagnostics stay off stdout, which holds the document.
            \ini_set('display_errors', 'stderr');
        }

        self::prepareFatalErrorReport($this->output);

        try {
            return $this->dispatch($args, $options, true);
        } catch (\Throwable $e) {
            // The boundary of the application: a failure no command caught
            // still leaves stdout holding one document.
            if ($this->output->hasWrittenDocument()) {
                throw $e;
            }

            $this->output->writeDocument(JsonDocument::error($e->getMessage(), JsonDocument::STAGE_INTERNAL));

            return CommandInterface::FAILURE;
        } finally {
            self::$jsonRunOutput = null;
            self::$jsonRunPid = null;
            self::$memoryReserve = null;
            if (false !== $displayErrors) {
                \ini_set('display_errors', $displayErrors);
            }
        }
    }

    /**
     * @return array<int, CommandInterface>
     */
    public function registeredCommands(): array
    {
        return $this->registered;
    }

    /**
     * A JSON run PHP stopped with a fatal error never printed its document:
     * the envelope takes its place, stage "internal", exit code 1. Nothing
     * is printed for a run that already printed its document, nor once the
     * run is over.
     *
     * The binary calls it at the shutdown of the process and exits with the
     * code it returns: library code never exits.
     *
     * @internal
     *
     * @return int|null the exit code when the envelope was printed, null otherwise
     */
    public static function reportFatalError(): ?int
    {
        // Reached at the shutdown of the process only: the tests run it in a
        // child PHP process, which a fatal error stops. A forked worker runs
        // the shutdown code of its parent too: what stops it is the parent's
        // to report, from what the worker left.
        if (null === self::$jsonRunPid || getmypid() !== self::$jsonRunPid) {
            return null;
        }

        // Memory exhausted leaves none to print with: the reserve makes room.
        self::$memoryReserve = null;

        $output = self::$jsonRunOutput;
        $error = error_get_last();
        if (null === $output || $output->hasWrittenDocument() || null === $error || 0 === ($error['type'] & self::FATAL_ERRORS)) {
            return null;
        }

        $output->writeDocument(JsonDocument::error('Fatal error: '.$error['message'], JsonDocument::STAGE_INTERNAL));

        return CommandInterface::FAILURE;
    }

    /**
     * Whether the command line asks for JSON of a command that has a JSON
     * mode, or of a command it does not name: a command without one reports
     * in text whatever the command line holds.
     *
     * @param array<int, string> $argv the command line after the binary
     * @param array<int, string> $args the same without the global options
     */
    private function asksForJson(array $argv, array $args): bool
    {
        if (!JsonRequest::in($argv)) {
            return false;
        }

        $name = $args[0] ?? null;
        if (null === $name) {
            return true;
        }

        if ($this->isRegexArgument($name)) {
            return false;
        }

        $command = $this->getCommand($name);

        return null === $command || $command instanceof JsonCommandInterface;
    }

    /**
     * Records the JSON run reportFatalError() reports on, and sets aside
     * what printing the envelope takes: a run that exhausts the memory has
     * none left for it.
     */
    private static function prepareFatalErrorReport(Output $output): void
    {
        // PHP loads a class, and allocates the engine cache of a function,
        // the first time it is used: each costs memory a run that exhausted
        // it no longer has, so the envelope's path is used once now. No run
        // is recorded yet: the report returns at once. What is left to
        // allocate fits in the reserve, freed before it is needed.
        self::reportFatalError();
        JsonDocument::error('', JsonDocument::STAGE_INTERNAL);

        self::$jsonRunOutput = $output;
        self::$jsonRunPid = getmypid();
        self::$memoryReserve = str_repeat('x', self::MEMORY_RESERVE);
    }

    /**
     * @param array<int, string> $args
     * @param bool               $json whether errors are reported as the JSON envelope
     */
    private function dispatch(array $args, GlobalOptions $options, bool $json): int
    {
        if (null !== $options->error) {
            return $this->handleError($options->error, $json);
        }

        if ($options->help) {
            return $this->showHelpFor($args, $options);
        }

        $commandName = $this->resolveCommandName($args);
        if (null === $commandName) {
            return $this->showHelpAndExit($options);
        }

        if ($this->isRegexArgument($commandName)) {
            return $this->runAsHighlight($args, $options);
        }

        $command = $this->getCommand($commandName);
        if (null === $command) {
            if ($json) {
                // An option before the command name: --json analyze /a/.
                $hint = str_starts_with($commandName, '-') ? ' Options go after the command name.' : '';

                return $this->handleError("Unknown command: {$commandName}.".$hint, true);
            }

            return $this->handleUnknownCommand($commandName, $options);
        }

        $commandArgs = $this->extractCommandArgs($args);
        $input = $this->createInput($commandName, $commandArgs, $options);

        return $command->run($input, $this->output);
    }

    /**
     * @param array<int, string> $argv
     */
    private function parseArguments(array $argv): ParsedGlobalOptions
    {
        $args = $argv;
        array_shift($args);

        return $this->globalOptionsParser->parse($args);
    }

    private function configureOutput(GlobalOptions $options): void
    {
        $this->output->setAnsi($this->shouldUseAnsi($options->ansi));
        $this->output->setQuiet($options->quiet);
    }

    private function shouldUseAnsi(?bool $forced): bool
    {
        return $forced ?? (\function_exists('posix_isatty') && posix_isatty(\STDOUT));
    }

    private function handleError(string $errorMessage, bool $json = false): int
    {
        if ($json) {
            $this->output->writeDocument(JsonDocument::error($errorMessage, JsonDocument::STAGE_USAGE));

            return CommandInterface::INVALID;
        }

        $this->output->write($this->output->error('Error: '.$errorMessage."\n"));

        return CommandInterface::INVALID;
    }

    /**
     * @param array<int, string> $args
     */
    private function showHelpFor(array $args, GlobalOptions $options): int
    {
        $targetCommand = $args[0] ?? null;

        return $this->help()->run(
            new Input('help', null !== $targetCommand ? [$targetCommand] : [], $options, []),
            $this->output,
        );
    }

    /**
     * @param array<int, string> $args
     */
    private function resolveCommandName(array $args): ?string
    {
        return $args[0] ?? null;
    }

    private function showHelpAndExit(GlobalOptions $options): int
    {
        $this->help()->run(new Input('help', [], $options, []), $this->output);

        return CommandInterface::INVALID;
    }

    /**
     * @param array<int, string> $args
     */
    private function runAsHighlight(array $args, GlobalOptions $options): int
    {
        $command = $this->getCommand('highlight');

        if (null === $command) {
            return $this->handleError('Highlight command is not registered.');
        }

        $input = new Input('highlight', $args, $options, []);

        return $command->run($input, $this->output);
    }

    private function isRegexArgument(string $value): bool
    {
        return str_starts_with($value, '/');
    }

    private function getCommand(string $name): ?CommandInterface
    {
        return $this->commands[$name] ?? null;
    }

    /**
     * Hand the help command what the application actually knows, so that the
     * list it prints cannot drift from the list it can run.
     */
    private function help(): CommandInterface
    {
        return $this->helpCommand instanceof HelpCommand
            ? $this->helpCommand->withCommands($this->registered)
            : $this->helpCommand;
    }

    /**
     * @param array<int, string> $args
     *
     * @return array<int, string>
     */
    private function extractCommandArgs(array $args): array
    {
        return \array_slice($args, 1);
    }

    private function handleUnknownCommand(string $commandName, GlobalOptions $options): int
    {
        $this->output->write($this->output->error("Unknown command: {$commandName}\n\n"));
        $this->help()->run(new Input('help', [], $options, []), $this->output);

        return CommandInterface::INVALID;
    }

    /**
     * @param array<int, string> $commandArgs
     */
    private function createInput(
        string $commandName,
        array $commandArgs,
        GlobalOptions $options,
    ): Input {
        $regexOptions = array_filter(
            ['php_version' => $options->phpVersion, 'pcre_version' => $options->pcreVersion],
            static fn (?string $version): bool => null !== $version,
        );

        return new Input($commandName, $commandArgs, $options, $regexOptions);
    }
}
