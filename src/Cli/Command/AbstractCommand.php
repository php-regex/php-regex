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

use PHPRegex\Cli\Input;
use PHPRegex\Cli\Output;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;

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
     * Read a command line of one pattern and the options named here. A flag
     * stands alone; a valued option is "--name=value" or "--name value". An
     * argument starting with "--" is an option until "--" ends them, and the
     * first other argument is the pattern.
     *
     * @param array<int, string> $args
     * @param list<string>       $flags
     * @param list<string>       $valued
     *
     * @return array{pattern: string, options: array<string, string|true>, error: ?string}
     */
    protected function readArguments(array $args, array $flags = [], array $valued = []): array
    {
        $pattern = null;
        $options = [];
        $endOfOptions = false;
        $count = \count($args);

        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];

            if ($endOfOptions || !str_starts_with($arg, '--')) {
                $pattern ??= $arg;

                continue;
            }

            if ('--' === $arg) {
                $endOfOptions = true;

                continue;
            }

            $parts = explode('=', $arg, 2);
            $name = $parts[0];
            $value = $parts[1] ?? null;

            if (null === $value && \in_array($name, $flags, true)) {
                $options[$name] = true;

                continue;
            }

            if (!\in_array($name, $valued, true)) {
                return ['pattern' => '', 'options' => [], 'error' => 'Unknown option: '.$arg];
            }

            if (null === $value) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return ['pattern' => '', 'options' => [], 'error' => \sprintf('Missing value for %s.', $name)];
                }
                $i++;
            }

            $options[$name] = $value;
        }

        if (null === $pattern || '' === $pattern) {
            return ['pattern' => '', 'options' => [], 'error' => 'Missing pattern.'];
        }

        return ['pattern' => $pattern, 'options' => $options, 'error' => null];
    }

    /**
     * Report a command line the command cannot use, with its usage.
     */
    protected function usageError(Output $output, string $message, string $usage): int
    {
        $output->write($output->error('Error: '.$message."\n"));
        $output->write($usage);

        return self::INVALID;
    }

    /**
     * Whether a ReDoS analysis is a problem the exit code reports: a risk the
     * confirmed mode confirmed, at the threshold or above and of high
     * severity or more, the verdict the lint command counts as an error. A
     * theoretical finding is a warning.
     */
    protected function isConfirmedRedos(RedosAnalysis $analysis, ?RedosSeverity $threshold): bool
    {
        return $analysis->isConfirmed()
            && $analysis->exceedsThreshold($threshold ?? RedosSeverity::High)
            && $analysis->exceedsThreshold(RedosSeverity::High);
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
