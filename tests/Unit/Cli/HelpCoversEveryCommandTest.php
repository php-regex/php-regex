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

namespace PhpRegex\Tests\Unit\Cli;

use PhpRegex\Cli\ApplicationFactory;
use PhpRegex\Cli\Command\CommandInterface;
use PhpRegex\Cli\Command\HelpCommand;
use PhpRegex\Cli\GlobalOptions;
use PhpRegex\Cli\Input;
use PhpRegex\Cli\Output;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HelpCoversEveryCommandTest extends TestCase
{
    #[Test]
    public function test_the_summary_lists_every_command_the_cli_can_run(): void
    {
        $listing = $this->render([]);

        foreach ($this->commands() as $command) {
            $this->assertStringContainsString($command->getName(), $listing);
            $this->assertStringContainsString(
                $command->getDescription(),
                $listing,
                \sprintf('The summary does not describe "%s" the way the command does.', $command->getName()),
            );
        }
    }

    #[Test]
    public function test_every_command_has_its_own_help_page(): void
    {
        foreach ($this->commands() as $command) {
            $page = $this->render([$command->getName()]);

            $this->assertStringNotContainsString(
                'Unknown command',
                $page,
                \sprintf('"help %s" has nothing to show.', $command->getName()),
            );
            $this->assertStringContainsString($command->getDescription(), $page);
        }
    }

    /**
     * @return array<int, CommandInterface>
     */
    private function commands(): array
    {
        return ApplicationFactory::create(new Output(false, false))->registeredCommands();
    }

    /**
     * @param array<int, string> $args
     */
    private function render(array $args): string
    {
        $help = (new HelpCommand())->withCommands($this->commands());
        $output = new Output(false, false);

        ob_start();
        $help->run(new Input('help', $args, new GlobalOptions(false, null, false, false, null, null), []), $output);

        return (string) ob_get_clean();
    }
}
