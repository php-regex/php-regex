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

namespace PHPRegex\Linter\Extraction;

/**
 * A condition the code tests before a read of a variable, as far as PHP
 * evaluates it on the value of that variable alone: the variable, a byte
 * of it ($v[0], $v[-1]), str_starts_with(), str_ends_with(),
 * str_contains(), strpos(), substr(), strlen(), a string, an integer,
 * true, false or null, the comparisons, "!", "&&" and "||". Anything else
 * is unknown, and so is what PHP 7 and PHP 8 do not agree on:
 * substr() past the end ("" on PHP 8, false on PHP 7), strpos() of an
 * empty needle (0 on PHP 8, false on PHP 7), "==" between two texts of
 * which one holds a digit ("1 " == "1" holds on PHP 8 only); and so is
 * what code may run otherwise: substr(), strpos() and strlen() of a text
 * past ASCII, which mbstring.func_overload reads by character on PHP 7,
 * and an empty needle given to str_starts_with(), str_ends_with() or
 * str_contains(), which some functions of those names written for PHP 7
 * find nowhere.
 *
 * A byte past the end is "", as PHP reads it with a warning; a handler
 * that throws there stops before the read as well.
 *
 * @internal
 */
final class ValueCondition
{
    /**
     * The functions of PHP the condition evaluates, each with how many
     * arguments it is read with. Each takes every argument by value.
     */
    public const FUNCTIONS = [
        'str_starts_with' => [2 => true], 'str_ends_with' => [2 => true], 'str_contains' => [2 => true],
        'strpos' => [2 => true], 'substr' => [2 => true, 3 => true], 'strlen' => [1 => true],
    ];

    /**
     * The comparisons the condition evaluates.
     */
    private const COMPARISONS = ['===' => true, '!==' => true, '==' => true, '!=' => true, '<' => true, '<=' => true, '>' => true, '>=' => true];

    private const UNKNOWN = 0;

    private const LITERAL = 1;

    private const VARIABLE = 2;

    private const BYTE = 3;

    private const CALL = 4;

    private const NOT = 5;

    private const AND = 6;

    private const OR = 7;

    private const COMPARE = 8;

    /**
     * @param list<self>   $operands
     * @param list<string> $names    the lowercase functions a call may reach, in the order PHP tries them
     */
    private function __construct(
        private readonly int $kind,
        private readonly string|int|bool|null $value = null,
        private readonly array $operands = [],
        private readonly array $names = [],
        private readonly int $offset = 0,
    ) {}

    public static function unknown(): self
    {
        return new self(self::UNKNOWN);
    }

    public static function literal(string|int|bool|null $value): self
    {
        return new self(self::LITERAL, $value);
    }

    public static function variable(string $name): self
    {
        return new self(self::VARIABLE, $name);
    }

    /**
     * $name[$offset], an integer offset written as a literal.
     */
    public static function byte(string $name, int $offset): self
    {
        return new self(self::BYTE, $name, offset: $offset);
    }

    /**
     * A call to a function by its name, read when it is one of FUNCTIONS
     * given as many arguments as it is read with.
     *
     * @param non-empty-list<string> $names     the lowercase functions the call may reach, in the order PHP tries them
     * @param list<self>             $arguments
     */
    public static function call(array $names, array $arguments): self
    {
        $function = $names[\count($names) - 1];

        return isset(self::FUNCTIONS[$function][\count($arguments)]) ? new self(self::CALL, $function, $arguments, $names) : self::unknown();
    }

    public static function not(self $operand): self
    {
        return new self(self::NOT, operands: [$operand]);
    }

    public static function and(self $left, self $right): self
    {
        return new self(self::AND, operands: [$left, $right]);
    }

    public static function or(self $left, self $right): self
    {
        return new self(self::OR, operands: [$left, $right]);
    }

    /**
     * A comparison of COMPARISONS, written with its operator.
     */
    public static function compare(string $operator, self $left, self $right): self
    {
        return isset(self::COMPARISONS[$operator]) ? new self(self::COMPARE, $operator, [$left, $right]) : self::unknown();
    }

    /**
     * The variables the condition reads, each once.
     *
     * @return list<string>
     */
    public function variables(): array
    {
        $names = [];
        $this->collect($names);

        return array_keys($names);
    }

    /**
     * Whether the condition holds where the variable holds the value, as
     * PHP casts its result to a boolean; null when it is not known.
     *
     * @param \Closure(list<string>): bool $native whether a call by the names reaches the function of PHP
     */
    public function holds(string $name, string $value, \Closure $native): ?bool
    {
        $result = $this->evaluate($name, $value, $native);

        return null === $result ? null : (bool) $result[0];
    }

    /**
     * @param array<string, true> $names
     */
    private function collect(array &$names): void
    {
        if ((self::VARIABLE === $this->kind || self::BYTE === $this->kind) && \is_string($this->value)) {
            $names[$this->value] = true;
        }

        foreach ($this->operands as $operand) {
            $operand->collect($names);
        }
    }

    /**
     * What PHP computes, in a list of one; null when it is not known.
     *
     * @param \Closure(list<string>): bool $native
     *
     * @return array{string|int|bool|null}|null
     */
    private function evaluate(string $name, string $value, \Closure $native): ?array
    {
        switch ($this->kind) {
            case self::LITERAL:
                return [$this->value];

            case self::VARIABLE:
                return $name === $this->value ? [$value] : null;

            case self::BYTE:
                if ($name !== $this->value) {
                    return null;
                }

                $at = $this->offset < 0 ? \strlen($value) + $this->offset : $this->offset;

                return [$at >= 0 && $at < \strlen($value) ? $value[$at] : ''];

            case self::CALL:
                return $native($this->names) ? $this->called($name, $value, $native) : null;

            case self::NOT:
                $operand = $this->operands[0]->holds($name, $value, $native);

                return null === $operand ? null : [!$operand];

            case self::AND:
                $left = $this->operands[0]->holds($name, $value, $native);
                if (false === $left) {
                    return [false];
                }

                $right = $this->operands[1]->holds($name, $value, $native);
                if (false === $right) {
                    return [false];
                }

                return true === $left && true === $right ? [true] : null;

            case self::OR:
                $left = $this->operands[0]->holds($name, $value, $native);
                if (true === $left) {
                    return [true];
                }

                $right = $this->operands[1]->holds($name, $value, $native);
                if (true === $right) {
                    return [true];
                }

                return false === $left && false === $right ? [false] : null;

            case self::COMPARE:
                $left = $this->operands[0]->evaluate($name, $value, $native);
                $right = $this->operands[1]->evaluate($name, $value, $native);
                if (null === $left || null === $right) {
                    return null;
                }

                $compared = self::compared((string) $this->value, $left[0], $right[0]);

                return null === $compared ? null : [$compared];

            default:
                return null;
        }
    }

    /**
     * What the function of PHP returns for arguments of the types it
     * takes; null for any other, which strict_types may refuse.
     *
     * @param \Closure(list<string>): bool $native
     *
     * @return array{string|int|bool|null}|null
     */
    private function called(string $name, string $value, \Closure $native): ?array
    {
        $arguments = [];
        foreach ($this->operands as $operand) {
            $argument = $operand->evaluate($name, $value, $native);
            if (null === $argument) {
                return null;
            }

            $arguments[] = $argument[0];
        }

        [$first, $second] = $arguments + [null, null];
        $third = $arguments[2] ?? null;

        // mbstring.func_overload, on PHP 7, may read these three by
        // character: a text past ASCII is not told then.
        foreach (\in_array($this->value, ['strlen', 'substr', 'strpos'], true) ? $arguments : [] as $argument) {
            if (\is_string($argument) && '' !== trim($argument, "\x00..\x7F")) {
                return null;
            }
        }

        if ('strlen' === $this->value) {
            return \is_string($first) ? [\strlen($first)] : null;
        }

        if ('substr' === $this->value) {
            if (!\is_string($first) || !\is_int($second) || (3 === \count($arguments) && !\is_int($third))) {
                return null;
            }

            $part = \is_int($third) ? substr($first, $second, $third) : substr($first, $second);

            return '' === $part ? null : [$part];
        }

        // An empty needle is found by PHP 8 and its polyfills, and by none
        // of the functions of that name PHP 7 code sometimes declares, nor
        // by strpos() on PHP 7.
        if (!\is_string($first) || !\is_string($second) || '' === $second) {
            return null;
        }

        return match ($this->value) {
            'str_starts_with' => [str_starts_with($first, $second)],
            'str_ends_with' => [str_ends_with($first, $second)],
            'str_contains' => [str_contains($first, $second)],
            default => [strpos($first, $second)],
        };
    }

    /**
     * A comparison as PHP 7 and PHP 8 both compute it; null when they may
     * not agree, or when the types are no pair the condition compares.
     */
    private static function compared(string $operator, string|int|bool|null $left, string|int|bool|null $right): ?bool
    {
        if ('===' === $operator || '!==' === $operator) {
            return ($left === $right) === ('===' === $operator);
        }

        if ('==' === $operator || '!=' === $operator) {
            $equal = self::looselyEqual($left, $right);

            return null === $equal ? null : $equal === ('==' === $operator);
        }

        if (!\is_int($left) || !\is_int($right)) {
            return null;
        }

        return match ($operator) {
            '<' => $left < $right,
            '<=' => $left <= $right,
            '>' => $left > $right,
            default => $left >= $right,
        };
    }

    /**
     * "==" where PHP 7 and PHP 8 agree: two equal values of one type, two
     * integers, two booleans, two texts of which neither holds a digit
     * (a numeric text holds one).
     */
    private static function looselyEqual(string|int|bool|null $left, string|int|bool|null $right): ?bool
    {
        if (\is_string($left) && \is_string($right)) {
            if ($left === $right) {
                return true;
            }

            return false === strpbrk($left, '0123456789') && false === strpbrk($right, '0123456789') ? false : null;
        }

        return (\is_int($left) && \is_int($right)) || (\is_bool($left) && \is_bool($right)) ? $left === $right : null;
    }
}
