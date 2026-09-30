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

namespace RegexParser\Cli;

use RegexParser\Cli\Command\CommandInterface;
use RegexParser\Cli\Command\HelpCommand;

final class Application
{
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

        if (null !== $options->error) {
            return $this->handleError($options->error);
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
            return $this->handleUnknownCommand($commandName, $options);
        }

        $commandArgs = $this->extractCommandArgs($args);
        $input = $this->createInput($commandName, $commandArgs, $options);

        return $command->run($input, $this->output);
    }

    /**
     * @return array<int, CommandInterface>
     */
    public function registeredCommands(): array
    {
        return $this->registered;
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

    private function handleError(string $errorMessage): int
    {
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
