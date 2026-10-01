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

namespace PhpRegex\Cli\Command;

use PhpRegex\Cli\ConsoleStyle;
use PhpRegex\Cli\Input;
use PhpRegex\Cli\Output;
use PhpRegex\Cli\SelfUpdate\SelfUpdater;

final readonly class SelfUpdateCommand implements CommandInterface
{
    public function __construct(private SelfUpdater $updater) {}

    public function getName(): string
    {
        return 'self-update';
    }

    public function getAliases(): array
    {
        return ['selfupdate'];
    }

    public function getDescription(): string
    {
        return 'Update the CLI phar to the latest release';
    }

    public function run(Input $input, Output $output): int
    {
        if (\in_array('--help', $input->args, true)) {
            $output->write("Usage: regex self-update\n");

            return self::SUCCESS;
        }

        $style = new ConsoleStyle($output, $input->globalOptions->visuals);
        $style->renderBanner('self-update');
        $style->renderSection('Updating', 1, 1);

        try {
            $this->updater->run($output);
        } catch (\RuntimeException $e) {
            $output->write('  '.$output->badge('FAIL', Output::WHITE, Output::BG_RED).' '.$output->error('Self-update failed: '.$e->getMessage())."\n");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
