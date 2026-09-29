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

use RegexParser\Cli\Input;
use RegexParser\Cli\Output;
use RegexParser\Exception\InvalidRegexOptionException;
use RegexParser\Regex;

abstract class AbstractCommand implements CommandInterface
{
    /**
     * @param array<string, mixed> $options
     */
    protected function createRegex(Output $output, array $options): ?Regex
    {
        try {
            return Regex::create($options);
        } catch (InvalidRegexOptionException $e) {
            $output->write($output->error('Invalid option: '.$e->getMessage()."\n"));

            return null;
        }
    }

    /**
     * The banner lines naming the PHP version and the PCRE2 release asked
     * for on the command line.
     *
     * @return array<string, string>
     */
    protected function targetMeta(Input $input, Output $output): array
    {
        $meta = [];
        if (null !== $input->globalOptions->phpVersion) {
            $meta['Target PHP'] = $output->warning('PHP '.$input->globalOptions->phpVersion);
        }
        if (null !== $input->globalOptions->pcreVersion) {
            $meta['Target PCRE2'] = $output->warning('PCRE2 '.$input->globalOptions->pcreVersion);
        }

        return $meta;
    }
}
