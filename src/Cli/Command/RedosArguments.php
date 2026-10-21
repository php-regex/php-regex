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

use PHPRegex\Cli\GlobalOptionsParser;
use PHPRegex\Cli\Option\Arguments;
use PHPRegex\Cli\Option\Option;
use PHPRegex\Cli\Option\Options;
use PHPRegex\Cli\Option\ParsedOption;
use PHPRegex\Parser\Text\Ascii;

/**
 * The command line of `regex redos`: the patterns to benchmark, the subject
 * to run them on, and the settings to run them under.
 *
 * @internal
 */
final class RedosArguments
{
    /**
     * @param ?string $jit       "0" or "1", the pcre.jit to set; null keeps it
     * @param ?int    $timeLimit the max_execution_time to set, 0 for none; null keeps it
     */
    public function __construct(
        public readonly string $pattern,
        public readonly ?string $safePattern = null,
        public readonly ?string $input = null,
        public readonly ?string $inputFile = null,
        public readonly int $repeat = 1,
        public readonly string $prefix = '',
        public readonly string $suffix = '',
        public readonly int $iterations = 1,
        public readonly int $warmup = 0,
        public readonly ?string $jit = null,
        public readonly ?int $backtrackLimit = null,
        public readonly ?int $recursionLimit = null,
        public readonly ?int $timeLimit = null,
        public readonly string $format = 'console',
        public readonly bool $showInput = false,
    ) {}

    /**
     * The options of the command, which its help lists.
     */
    public static function options(): Options
    {
        return new Options(Arguments::Pattern, [
            Option::value('--safe', '<pattern>', 'Safe pattern to compare against'),
            Option::value('--input', '<string>', 'Input string to benchmark (auto-generated when omitted)'),
            Option::value('--input-file', '<path>', 'Read input from a file'),
            self::number('--repeat', '<n>', 'Repeat input N times'),
            Option::value('--prefix', '<string>', 'Prefix for the input'),
            Option::value('--suffix', '<string>', 'Suffix for the input'),
            self::number('--iterations', '<n>', 'Number of iterations'),
            self::number('--warmup', '<n>', 'Warmup iterations'),
            self::number('--jit', '<0|1>', 'Override pcre.jit'),
            self::number('--backtrack-limit', '<n>', 'Override pcre.backtrack_limit'),
            self::number('--recursion-limit', '<n>', 'Override pcre.recursion_limit'),
            self::number('--time-limit', '<n>', 'Set max_execution_time in seconds'),
            Option::value('--format', '<format>', 'Output format (console, json)'),
            Option::flag('--json', 'Same as --format=json'),
            Option::flag('--show-input', 'Print full input string'),
            ...GlobalOptionsParser::target(),
        ], "Usage: regex redos <pattern> [--safe <pattern>] [--input <string> | --input-file <path>] [--repeat <n>] [--prefix <string>] [--suffix <string>] [--iterations <n>] [--warmup <n>] [--jit 0|1] [--backtrack-limit <n>] [--recursion-limit <n>] [--time-limit <sec>] [--format=json] [--show-input]\n");
    }

    /**
     * The arguments the command line gives, or the message saying why it
     * cannot be used.
     *
     * @param array<int, string> $args
     */
    public static function parse(array $args): self|string
    {
        $parsed = self::options()->parse($args);
        $text = ['--safe' => null, '--input' => null, '--input-file' => null, '--prefix' => '', '--suffix' => '', '--jit' => null];
        $numbers = ['--repeat' => 1, '--iterations' => 1, '--warmup' => 0, '--backtrack-limit' => null, '--recursion-limit' => null, '--time-limit' => null];
        $format = 'console';
        $showInput = false;

        foreach ($parsed->options as $option) {
            $value = (string) $option->value;
            if ('--json' === $option->name) {
                $format = 'json';
            } elseif ('--format' === $option->name) {
                $format = Ascii::lower($value);
            } elseif ('--show-input' === $option->name) {
                $showInput = true;
            } elseif ('--jit' === $option->name) {
                if (!\in_array($value, ['0', '1'], true)) {
                    return self::invalid($option);
                }
                $text['--jit'] = $value;
            } elseif (\array_key_exists($option->name, $numbers)) {
                $number = self::integer($value, \in_array($option->name, ['--warmup', '--time-limit'], true) ? 0 : 1);
                if (null === $number) {
                    return self::invalid($option);
                }
                $numbers[$option->name] = $number;
            } else {
                $text[$option->name] = $value;
            }
        }

        if (null !== $parsed->error) {
            return $parsed->error;
        }

        if (null !== $text['--input'] && null !== $text['--input-file']) {
            return 'Use only one of --input or --input-file.';
        }

        if (!\in_array($format, ['console', 'json'], true)) {
            return 'Invalid value for --format.';
        }

        return new self(
            $parsed->argument(),
            $text['--safe'],
            $text['--input'],
            $text['--input-file'],
            $numbers['--repeat'],
            $text['--prefix'],
            $text['--suffix'],
            $numbers['--iterations'],
            $numbers['--warmup'],
            $text['--jit'],
            $numbers['--backtrack-limit'],
            $numbers['--recursion-limit'],
            $numbers['--time-limit'],
            $format,
            $showInput,
        );
    }

    /**
     * An option whose value is a number: a value missing, after a space, is
     * reported as one missing or invalid.
     */
    private static function number(string $name, string $placeholder, string $description): Option
    {
        return Option::value($name, $placeholder, $description)->whenMissing(\sprintf('Missing or invalid value for %s.', $name));
    }

    /**
     * The message refusing the value: after a space it may as well be
     * missing.
     */
    private static function invalid(ParsedOption $option): string
    {
        return \sprintf($option->attached ? 'Invalid value for %s.' : 'Missing or invalid value for %s.', $option->name);
    }

    private static function integer(string $value, int $min): ?int
    {
        if ('' === $value || !Ascii::isDigit($value)) {
            return null;
        }

        $parsed = (int) $value;

        return $parsed < $min ? null : $parsed;
    }
}
