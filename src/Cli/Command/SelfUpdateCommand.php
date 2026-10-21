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

namespace PHPRegex\Cli\Command;

use PHPRegex\Cli\ConsoleStyle;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Option\Arguments;
use PHPRegex\Cli\Option\Options;
use PHPRegex\Cli\Output;
use PHPRegex\Cli\SelfUpdate\SelfUpdater;

/**
 * @internal
 */
final class SelfUpdateCommand extends AbstractCommand
{
    public function __construct(private readonly SelfUpdater $updater) {}

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

    /**
     * The options of the command, which its help lists.
     */
    public static function options(): Options
    {
        return new Options(Arguments::None, [], "Usage: regex self-update\n");
    }

    public function run(Input $input, Output $output): int
    {
        if (\in_array('--help', $input->args, true)) {
            $output->write(self::options()->usage);

            return self::SUCCESS;
        }

        $error = self::options()->parse($input->args)->error;
        if (null !== $error) {
            return $this->usageError($output, $error, self::options()->usage);
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
