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
 * A command line read against its declaration: the options in the order
 * given, the arguments, and what makes it unusable. Reading stops at the
 * first problem, so the options read are the ones before it: a command that
 * checks their values in order reports the problems in the order of the
 * command line.
 *
 * @internal
 */
final class ParsedOptions
{
    /**
     * @param list<ParsedOption> $options
     * @param list<string>       $arguments
     */
    public function __construct(
        public readonly array $options,
        public readonly array $arguments,
        public readonly ?string $error = null,
    ) {}

    public function has(string $name): bool
    {
        foreach ($this->options as $option) {
            if ($option->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * The value given last to the option, null when none was.
     */
    public function value(string $name): ?string
    {
        $value = null;
        foreach ($this->options as $option) {
            if ($option->name === $name) {
                $value = $option->value;
            }
        }

        return $value;
    }

    /**
     * The first argument, the empty string when there is none.
     */
    public function argument(): string
    {
        return $this->arguments[0] ?? '';
    }
}
