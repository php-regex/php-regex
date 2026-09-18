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

namespace RegexParser\Cli\Command;

use RegexParser\Cli\ConsoleStyle;
use RegexParser\Cli\Input;
use RegexParser\Cli\Output;
use RegexParser\Regex;

final readonly class VersionCommand implements CommandInterface
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

    public function run(Input $input, Output $output): int
    {
        $style = new ConsoleStyle($output, $input->globalOptions->visuals);
        if ($style->visualsEnabled()) {
            $style->renderBanner('version');
            $output->write('  '.$output->dim('Repository: https://github.com/php-regex/regex-parser')."\n");

            return 0;
        }

        $version = Regex::VERSION;
        $output->write('RegexParser '.$output->color($version, Output::GREEN)." by Younes ENNAJI\n");
        $output->write("https://github.com/php-regex/regex-parser\n");

        return 0;
    }
}
