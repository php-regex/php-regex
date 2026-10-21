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

namespace PHPRegex\Cli;

use PHPRegex\Cli\Option\Arguments;
use PHPRegex\Cli\Option\Option;
use PHPRegex\Cli\Option\Options;

/**
 * @internal
 */
final class GlobalOptionsParser
{
    /**
     * The options every command takes, read wherever they stand on the
     * command line; the rest is the command's.
     */
    public static function options(): Options
    {
        return new Options(Arguments::PassThrough, [
            Option::flag('--ansi', 'Force ANSI output'),
            Option::flag('--no-ansi', 'Disable ANSI output'),
            Option::flag('--quiet', 'Suppress output', ['-q']),
            Option::flag('--silent', 'Same as --quiet'),
            Option::flag('--no-visuals', 'Disable banner and section visuals'),
            Option::flag('--no-art')->hide(),
            Option::flag('--no-splash')->hide(),
            ...self::target(),
            Option::value('--cache-dir', '<dir>', 'Keep the Unicode class scans in <dir> for the next run'),
            Option::flag('--help', 'Display this help message'),
            Option::flag('-h')->hide(),
        ]);
    }

    /**
     * The PHP, the PCRE2 release and the locale the patterns are judged
     * for, which the help of each command that reads a pattern lists.
     *
     * @return list<Option>
     */
    public static function target(): array
    {
        return [
            Option::value('--php-version', '<ver>', 'Target PHP version for validation')->readByTheApplication(),
            Option::value('--pcre-version', '<ver>', 'Target PCRE2 release for validation')->readByTheApplication(),
            Option::value('--locale', '<name>', 'Target LC_CTYPE locale for validation')->readByTheApplication(),
        ];
    }

    /**
     * @param array<int, string> $args
     */
    public function parse(array $args): ParsedGlobalOptions
    {
        $parsed = self::options()->parse($args);
        $quiet = false;
        $ansi = null;
        $help = false;
        $visuals = true;
        $values = ['--php-version' => null, '--pcre-version' => null, '--locale' => null, '--cache-dir' => null];

        foreach ($parsed->options as $option) {
            match ($option->name) {
                '--quiet', '--silent' => $quiet = true,
                '--ansi', '--no-ansi' => $ansi = '--ansi' === $option->name,
                '--help', '-h' => $help = true,
                '--no-visuals', '--no-art', '--no-splash' => $visuals = false,
                default => $values[$option->name] = $option->value,
            };
        }

        $options = new GlobalOptions($quiet, $ansi, $help, $visuals, $values['--php-version'], $parsed->error, $values['--pcre-version'], $values['--cache-dir'], $values['--locale']);

        return new ParsedGlobalOptions($options, $parsed->arguments);
    }
}
