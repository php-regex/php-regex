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

use PHPRegex\Cli\Command\AnalyzeCommand;
use PHPRegex\Cli\Command\ClearCacheCommand;
use PHPRegex\Cli\Command\CommandInterface;
use PHPRegex\Cli\Command\CompareCommand;
use PHPRegex\Cli\Command\DebugCommand;
use PHPRegex\Cli\Command\DiagramCommand;
use PHPRegex\Cli\Command\ExplainCommand;
use PHPRegex\Cli\Command\GraphCommand;
use PHPRegex\Cli\Command\HelpCommand;
use PHPRegex\Cli\Command\HighlightCommand;
use PHPRegex\Cli\Command\LintCommand;
use PHPRegex\Cli\Command\LintOutputRenderer;
use PHPRegex\Cli\Command\ParseCommand;
use PHPRegex\Cli\Command\RedosCommand;
use PHPRegex\Cli\Command\SelfUpdateCommand;
use PHPRegex\Cli\Command\TranspileCommand;
use PHPRegex\Cli\Command\ValidateCommand;
use PHPRegex\Cli\Command\VersionCommand;
use PHPRegex\Cli\SelfUpdate\SelfUpdater;
use PHPRegex\Linter\Config\LintArgumentParser;
use PHPRegex\Linter\Config\LintConfigLoader;
use PHPRegex\Linter\Config\LintDefaultsBuilder;
use PHPRegex\Linter\Config\LintExtractorFactory;

/**
 * Builds the CLI with every command it ships.
 *
 * The binary is one line long because of this: the wiring belongs where it
 * can be read, and where a test can ask the application what it knows.
 */
final class ApplicationFactory
{
    public static function create(Output $output): Application
    {
        $help = new HelpCommand();
        $application = new Application(new GlobalOptionsParser(), $output, $help);

        foreach (self::commands($help) as $command) {
            $application->register($command);
        }

        return $application;
    }

    /**
     * @return array<int, CommandInterface>
     */
    public static function commands(HelpCommand $help): array
    {
        return [
            $help,
            new VersionCommand(),
            new SelfUpdateCommand(new SelfUpdater()),
            new ParseCommand(),
            new ExplainCommand(),
            new AnalyzeCommand(),
            new DebugCommand(new LintConfigLoader(), new LintDefaultsBuilder()),
            new CompareCommand(),
            new RedosCommand(),
            new DiagramCommand(),
            new GraphCommand(),
            new HighlightCommand(),
            new TranspileCommand(),
            new ValidateCommand(),
            new ClearCacheCommand(),
            new LintCommand(
                $help,
                new LintConfigLoader(),
                new LintDefaultsBuilder(),
                new LintArgumentParser(),
                new LintExtractorFactory(),
                new LintOutputRenderer(),
            ),
        ];
    }
}
