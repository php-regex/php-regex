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

namespace PhpRegex\Cli;

use PhpRegex\Cli\Command\AnalyzeCommand;
use PhpRegex\Cli\Command\ClearCacheCommand;
use PhpRegex\Cli\Command\CompareCommand;
use PhpRegex\Cli\Command\DebugCommand;
use PhpRegex\Cli\Command\DiagramCommand;
use PhpRegex\Cli\Command\ExplainCommand;
use PhpRegex\Cli\Command\GraphCommand;
use PhpRegex\Cli\Command\HelpCommand;
use PhpRegex\Cli\Command\HighlightCommand;
use PhpRegex\Cli\Command\LintCommand;
use PhpRegex\Cli\Command\LintOutputRenderer;
use PhpRegex\Cli\Command\ParseCommand;
use PhpRegex\Cli\Command\RedosCommand;
use PhpRegex\Cli\Command\SelfUpdateCommand;
use PhpRegex\Cli\Command\TranspileCommand;
use PhpRegex\Cli\Command\ValidateCommand;
use PhpRegex\Cli\Command\VersionCommand;
use PhpRegex\Cli\SelfUpdate\SelfUpdater;
use PhpRegex\Linter\Config\LintArgumentParser;
use PhpRegex\Linter\Config\LintConfigLoader;
use PhpRegex\Linter\Config\LintDefaultsBuilder;
use PhpRegex\Linter\Config\LintExtractorFactory;

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
     * @return array<int, \PhpRegex\Cli\Command\CommandInterface>
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
