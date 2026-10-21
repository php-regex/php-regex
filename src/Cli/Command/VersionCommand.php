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
use PHPRegex\Toolkit\Regex;

/**
 * @internal
 */
final class VersionCommand extends AbstractCommand
{
    public function getName(): string
    {
        return 'version';
    }

    public function getAliases(): array
    {
        return ['--version', '-v'];
    }

    public function getDescription(): string
    {
        return 'Display version information';
    }

    /**
     * The options of the command, which its help lists.
     */
    public static function options(): Options
    {
        return new Options(Arguments::None, [], "Usage: regex version\n");
    }

    public function run(Input $input, Output $output): int
    {
        $error = self::options()->parse($input->args)->error;
        if (null !== $error) {
            return $this->usageError($output, $error, self::options()->usage);
        }

        $style = new ConsoleStyle($output, $input->globalOptions->visuals);
        if ($style->visualsEnabled()) {
            $style->renderBanner('version');
            $output->write('  '.$output->dim('Repository: https://github.com/php-regex/php-regex')."\n");

            return self::SUCCESS;
        }

        $version = Regex::VERSION;
        $output->write('PHPRegex '.$output->color($version, Output::GREEN)." by Younes ENNAJI\n");
        $output->write("https://github.com/php-regex/php-regex\n");

        return self::SUCCESS;
    }
}
