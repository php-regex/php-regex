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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Cli\Command\HelpCommand;
use PHPRegex\Cli\Command\LintCommand;
use PHPRegex\Cli\Command\LintOutputRenderer;
use PHPRegex\Linter\Config\LintArgumentParser;
use PHPRegex\Linter\Config\LintConfigLoader;
use PHPRegex\Linter\Config\LintDefaultsBuilder;
use PHPRegex\Linter\Config\LintExtractorFactory;
use PHPUnit\Framework\TestCase;

final class LintCommandWiringTest extends TestCase
{
    public function test_lint_command_class_instantiation(): void
    {
        $helpCommand = new HelpCommand();
        $configLoader = new LintConfigLoader();
        $defaultsBuilder = new LintDefaultsBuilder();
        $argumentParser = new LintArgumentParser();
        $extractorFactory = new LintExtractorFactory();
        $outputRenderer = new LintOutputRenderer();

        $command = new LintCommand(
            $helpCommand,
            $configLoader,
            $defaultsBuilder,
            $argumentParser,
            $extractorFactory,
            $outputRenderer,
        );

        $this->assertSame('lint', $command->getName());
        $this->assertSame([], $command->getAliases());
        $this->assertSame('Lint regex patterns in PHP source code', $command->getDescription());
    }
}
