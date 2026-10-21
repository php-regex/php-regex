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

namespace PHPRegex\Cli\Option;

/**
 * The options of a command and the arguments it takes, declared once: the
 * command reads its command line with them and the help lists them, so the
 * two cannot drift apart.
 *
 * @internal
 */
final class Options
{
    /**
     * @param list<Option> $options in the order the help lists them
     * @param string       $usage   the usage line printed under an error of the command line
     */
    public function __construct(
        private readonly Arguments $arguments,
        public readonly array $options,
        public readonly string $usage = '',
    ) {}

    /**
     * Read the command line. A value follows its option after "=" or, as
     * the option allows, after a space; an alias stands on its own. Reading
     * stops at the first problem: an option nobody declared, a value
     * missing, an argument too many, or no pattern for a command that needs
     * one.
     *
     * @param array<int, string> $args
     */
    public function parse(array $args): ParsedOptions
    {
        $args = array_values($args);
        $spellings = $this->spellings();
        $options = [];
        $arguments = [];
        $endOfOptions = false;
        $count = \count($args);

        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];

            if (!$endOfOptions) {
                if ('--' === $arg && $this->arguments->endsOptions()) {
                    $endOfOptions = true;

                    continue;
                }

                $option = $this->read($spellings, $args, $i);
                if ($option instanceof ParsedOption) {
                    $options[] = $option;

                    continue;
                }

                if (\is_string($option)) {
                    return new ParsedOptions($options, $arguments, $option);
                }

                if ($this->arguments->isOption($arg)) {
                    return new ParsedOptions($options, $arguments, 'Unknown option: '.$arg);
                }
            }

            if (!$this->arguments->takes($arguments)) {
                return new ParsedOptions($options, $arguments, 'Unexpected argument: '.$arg);
            }

            // An empty argument leaves the place of the pattern to the next one.
            if (Arguments::Pattern !== $this->arguments || '' !== $arg) {
                $arguments[] = $arg;
            }
        }

        if ($this->arguments->needsPattern() && '' === ($arguments[0] ?? '')) {
            return new ParsedOptions($options, $arguments, 'Missing pattern.');
        }

        return new ParsedOptions($options, $arguments);
    }

    /**
     * The rows of the help's option table: each option shown, as it is
     * spelled, with its description.
     *
     * @return list<array{string, string}>
     */
    public function help(): array
    {
        $rows = [];
        foreach ($this->options as $option) {
            if (!$option->hidden) {
                $rows[] = [$option->label(), $option->description];
            }
        }

        return $rows;
    }

    /**
     * How the help's usage line names the arguments.
     *
     * @return list<string>
     */
    public function arguments(): array
    {
        return $this->arguments->placeholders();
    }

    /**
     * The options this command line reads, under each spelling: the
     * application's own are read by the application, except in its own
     * declaration.
     *
     * @return array<string, Option>
     */
    private function spellings(): array
    {
        $spellings = [];
        foreach ($this->options as $option) {
            if ($option->global && Arguments::PassThrough !== $this->arguments) {
                continue;
            }

            foreach ($option->names() as $name) {
                $spellings[$name] = $option;
            }
        }

        return $spellings;
    }

    /**
     * The option the argument at $i gives, moving $i past its value; the
     * message when its value is missing; null when the argument is no
     * option declared here.
     *
     * @param array<string, Option> $spellings
     * @param list<string>          $args
     */
    private function read(array $spellings, array $args, int &$i): ParsedOption|string|null
    {
        $arg = $args[$i];
        $option = $spellings[$arg] ?? null;
        if (null !== $option) {
            return match ($option->value) {
                OptionValue::None => new ParsedOption($option->name, null, false),
                OptionValue::Attached => null,
                OptionValue::Required => $this->separate($option, $args, $i, static fn (string $value): bool => '' !== $value && !str_starts_with($value, '-')),
                OptionValue::Any => $this->separate($option, $args, $i, static fn (): bool => true),
            };
        }

        $equals = strpos($arg, '=');
        if (false === $equals) {
            return null;
        }

        $name = substr($arg, 0, $equals);
        $option = $spellings[$name] ?? null;
        if (null === $option || $option->name !== $name || OptionValue::None === $option->value) {
            return null;
        }

        return new ParsedOption($name, substr($arg, $equals + 1), true);
    }

    /**
     * The option with the next argument as its value, when it accepts it.
     *
     * @param list<string>           $args
     * @param \Closure(string): bool $accepts
     */
    private function separate(Option $option, array $args, int &$i, \Closure $accepts): ParsedOption|string
    {
        $value = $args[$i + 1] ?? null;
        if (null === $value || !$accepts($value)) {
            return $option->missingValue();
        }

        $i++;

        return new ParsedOption($option->name, $value, false);
    }
}
