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

namespace PhpRegex\Cli;

final class GlobalOptionsParser
{
    /**
     * @param array<int, string> $args
     */
    public function parse(array $args): ParsedGlobalOptions
    {
        $quiet = false;
        $ansi = null;
        $help = false;
        $visuals = true;
        $phpVersion = null;
        $pcreVersion = null;
        $error = null;
        $remaining = [];

        for ($i = 0; $i < \count($args); $i++) {
            $arg = $args[$i];

            if ($this->isQuietOption($arg)) {
                $quiet = true;

                continue;
            }

            if ($this->isAnsiOption($arg)) {
                $ansi = '--ansi' === $arg;

                continue;
            }

            if ($this->isHelpOption($arg)) {
                $help = true;

                continue;
            }

            if ($this->isVisualsOption($arg)) {
                $visuals = false;

                continue;
            }

            if ($this->isValuedOption('--pcre-version', $arg, $args, $i, $pcreVersion, $error)
                || $this->isValuedOption('--php-version', $arg, $args, $i, $phpVersion, $error)) {
                if (null !== $error) {
                    break;
                }

                continue;
            }

            $remaining[] = $arg;
        }

        $options = new GlobalOptions($quiet, $ansi, $help, $visuals, $phpVersion, $error, $pcreVersion);

        return new ParsedGlobalOptions($options, $remaining);
    }

    private function isQuietOption(string $arg): bool
    {
        return '-q' === $arg || '--quiet' === $arg || '--silent' === $arg;
    }

    private function isAnsiOption(string $arg): bool
    {
        return '--ansi' === $arg || '--no-ansi' === $arg;
    }

    private function isHelpOption(string $arg): bool
    {
        return '--help' === $arg || '-h' === $arg;
    }

    private function isVisualsOption(string $arg): bool
    {
        return '--no-visuals' === $arg || '--no-art' === $arg || '--no-splash' === $arg;
    }

    /**
     * @param array<int, string> $args
     */
    private function isValuedOption(string $name, string $arg, array $args, int &$i, ?string &$value, ?string &$error): bool
    {
        if (str_starts_with($arg, $name.'=')) {
            $value = substr($arg, \strlen($name) + 1);

            return true;
        }

        if ($name !== $arg) {
            return false;
        }

        $next = $args[$i + 1] ?? '';

        if ('' === $next || str_starts_with($next, '-')) {
            $error = \sprintf('Missing value for %s.', $name);

            return true;
        }

        $value = $next;
        $i++;

        return true;
    }
}
