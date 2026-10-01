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

namespace PhpRegex\Tests\Unit;

use PhpRegex\Cli\Command\HelpCommand;
use PhpRegex\Cli\Command\LintCommand;
use PhpRegex\Cli\Command\LintOutputRenderer;
use PhpRegex\Linter\Config\LintArgumentParser;
use PhpRegex\Linter\Config\LintConfigLoader;
use PhpRegex\Linter\Config\LintDefaultsBuilder;
use PhpRegex\Linter\Config\LintExtractorFactory;
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
