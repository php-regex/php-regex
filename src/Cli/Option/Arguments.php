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
 * What a command line holds besides its options, and so how it reads: which
 * argument is an option, whether "--" ends them, and how many arguments the
 * command takes.
 *
 * @internal
 */
enum Arguments
{
    /**
     * One pattern. An argument starting with "-" is an option until "--"
     * ends them; an empty argument leaves the pattern to the next one.
     */
    case Pattern;

    /**
     * One pattern, which may start with a single "-": only an argument
     * starting with "--" is an option, until "--" ends them. An empty
     * argument is the pattern, and then a missing one.
     */
    case LoosePattern;

    /**
     * Patterns, as many as given: the command counts them. Only an argument
     * starting with "--" is an option, until "--" ends them.
     */
    case Patterns;

    /**
     * Paths, as many as given. An argument starting with "-" is an option,
     * "--" included.
     */
    case Paths;

    /**
     * At most one command name. An argument starting with "-" is an option.
     */
    case Command;

    /**
     * None: an argument starting with "-" is an option, any other one is
     * unexpected.
     */
    case None;

    /**
     * The application's own options, read wherever they stand; every other
     * argument is kept, in order, for the command.
     */
    case PassThrough;

    /**
     * How the help's usage line names the arguments.
     *
     * @return list<string>
     */
    public function placeholders(): array
    {
        return match ($this) {
            self::Pattern, self::LoosePattern => ['<pattern>'],
            self::Patterns => ['<pattern1>', '<pattern2>'],
            self::Paths => ['<path>'],
            self::Command => ['[command]'],
            self::None, self::PassThrough => [],
        };
    }

    /**
     * Whether an argument the declaration does not name is an option it does
     * not know, rather than an argument.
     */
    public function isOption(string $argument): bool
    {
        return match ($this) {
            self::LoosePattern, self::Patterns => str_starts_with($argument, '--'),
            self::PassThrough => false,
            default => str_starts_with($argument, '-'),
        };
    }

    /**
     * Whether "--" ends the options, every argument after it read as an
     * argument.
     */
    public function endsOptions(): bool
    {
        return self::Pattern === $this || self::LoosePattern === $this || self::Patterns === $this;
    }

    /**
     * Whether the command needs its pattern: a command line without one
     * cannot be used.
     */
    public function needsPattern(): bool
    {
        return self::Pattern === $this || self::LoosePattern === $this;
    }

    /**
     * Whether the argument, coming after the ones already read, is one the
     * command takes.
     *
     * @param list<string> $read
     */
    public function takes(array $read): bool
    {
        return match ($this) {
            self::Pattern, self::LoosePattern, self::Command => [] === $read,
            self::None => false,
            default => true,
        };
    }
}
