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
 * One option of a command, declared once: how the command line spells it
 * and the line the help gives it.
 *
 * @internal
 */
final class Option
{
    /**
     * @param string       $placeholder what the help shows for the value, as "<n>"
     * @param list<string> $aliases     the short spellings, read only on their own: "-j 4", never "-j=4"
     * @param ?string      $missing     the message when the value is missing, "Missing value for --name." when null
     * @param bool         $hidden      whether the command reads it without the help listing it
     * @param bool         $global      whether the application reads it before the command: the command's help
     *                                  lists it, the command itself never reads it
     */
    private function __construct(
        public readonly string $name,
        public readonly OptionValue $value,
        public readonly string $placeholder,
        public readonly string $description,
        public readonly array $aliases,
        private readonly ?string $missing = null,
        public readonly bool $hidden = false,
        public readonly bool $global = false,
    ) {}

    /**
     * @param list<string> $aliases
     */
    public static function flag(string $name, string $description = '', array $aliases = []): self
    {
        return new self($name, OptionValue::None, '', $description, $aliases);
    }

    /**
     * An option whose value comes after "=" or a space; the value after the
     * space is neither empty nor starts with "-".
     *
     * @param list<string> $aliases
     */
    public static function value(string $name, string $placeholder, string $description = '', array $aliases = []): self
    {
        return new self($name, OptionValue::Required, $placeholder, $description, $aliases);
    }

    /**
     * The same option taking the argument after the space as its value,
     * whatever it is.
     */
    public function takingAnyValue(): self
    {
        return $this->with(OptionValue::Any);
    }

    /**
     * The same option taking its value after "=" only.
     */
    public function attachedOnly(): self
    {
        return $this->with(OptionValue::Attached);
    }

    /**
     * The same option, the command line missing its value reported with
     * this message.
     */
    public function whenMissing(string $message): self
    {
        return new self($this->name, $this->value, $this->placeholder, $this->description, $this->aliases, $message, $this->hidden, $this->global);
    }

    /**
     * The same option, read without the help listing it.
     */
    public function hide(): self
    {
        return new self($this->name, $this->value, $this->placeholder, $this->description, $this->aliases, $this->missing, true, $this->global);
    }

    /**
     * The same option, read by the application before the command.
     */
    public function readByTheApplication(): self
    {
        return new self($this->name, $this->value, $this->placeholder, $this->description, $this->aliases, $this->missing, $this->hidden, true);
    }

    /**
     * Every spelling read on its own, the aliases first.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return [...$this->aliases, $this->name];
    }

    /**
     * The option as the help writes it: "-j, --jobs <n>", "--rule=<id>".
     */
    public function label(): string
    {
        return match ($this->value) {
            OptionValue::None => implode(', ', $this->names()),
            OptionValue::Attached => $this->name.'='.$this->placeholder,
            OptionValue::Required, OptionValue::Any => implode(', ', $this->names()).' '.$this->placeholder,
        };
    }

    public function missingValue(): string
    {
        return $this->missing ?? \sprintf('Missing value for %s.', $this->name);
    }

    private function with(OptionValue $value): self
    {
        return new self($this->name, $value, $this->placeholder, $this->description, $this->aliases, $this->missing, $this->hidden, $this->global);
    }
}
