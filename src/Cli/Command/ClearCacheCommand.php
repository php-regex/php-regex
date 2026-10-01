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

namespace PhpRegex\Cli\Command;

use PhpRegex\Cli\ConsoleStyle;
use PhpRegex\Cli\Input;
use PhpRegex\Cli\Output;
use PhpRegex\Parser\Cache\RemovableCacheInterface;
use PhpRegex\Parser\Exception\InvalidRegexOptionException;
use PhpRegex\Parser\ParserOptions;

final class ClearCacheCommand extends AbstractCommand implements CommandInterface
{
    public function getName(): string
    {
        return 'clear-cache';
    }

    public function getAliases(): array
    {
        return ['cache:clear'];
    }

    public function getDescription(): string
    {
        return 'Clear the regex parser cache';
    }

    public function run(Input $input, Output $output): int
    {
        try {
            $cache = ParserOptions::fromArray($input->regexOptions)->cache;
        } catch (InvalidRegexOptionException $e) {
            $output->write($output->error('Invalid option: '.$e->getMessage()."\n"));

            return self::INVALID;
        }

        $style = new ConsoleStyle($output, $input->globalOptions->visuals);
        $style->renderBanner('clear-cache');
        $style->renderSection('Cache', 1, 1);

        if ($cache instanceof RemovableCacheInterface) {
            $cache->clear();
            $output->write('  '.$output->badge('PASS', Output::WHITE, Output::BG_GREEN).' '.$output->success('Cache cleared.')."\n");
        } else {
            $output->write('  '.$output->badge('INFO', Output::BLACK, Output::BG_YELLOW).' '.$output->warning('No clearable cache configured.')."\n");
        }

        return self::SUCCESS;
    }
}
