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

use PHPRegex\Parser\Text\Ascii;

/**
 * An expression of a PHP file as the in-file resolution reads it: a
 * literal, a concatenation, a reference to a constant, a local variable or
 * a property, an array, or something it does not follow.
 *
 * Both extraction strategies translate their own syntax tree into this
 * form, so the rules deciding what a name proves are written once, in
 * InFileValueResolver.
 *
 * @internal
 */
final class ValueExpr
{
    public const LITERAL = 'literal';
    public const CONCAT = 'concat';
    public const CLASS_CONSTANT = 'class-constant';
    public const CONSTANT = 'constant';
    public const VARIABLE = 'variable';
    public const PROPERTY = 'property';
    public const ARRAY = 'array';
    public const NON_STRING = 'non-string';
    public const UNKNOWN = 'unknown';

    /**
     * @param string                             $kind       one of the constants above
     * @param string                             $value      the literal's value, or the name of the constant,
     *                                                       variable or property
     * @param int                                $offset     where the expression starts in the file
     * @param int                                $line       the line it starts on
     * @param list<self>                         $parts      the operands of a concatenation
     * @param list<array{0: self|null, 1: self}> $items      the key, if any, and the value of each array item
     * @param string|null                        $class      the lowercase class a class constant or a property
     *                                                       belongs to; null when no class of the file is known
     * @param bool                               $lateStatic a class constant read through static::
     * @param list<string>                       $names      the constant keys a name may reach, in the order PHP
     *                                                       tries them
     * @param int|null                           $scope      the function body a variable belongs to; null at file
     *                                                       scope
     * @param string                             $written    the reference as the source writes it: "self::RE"
     */
    private function __construct(
        public readonly string $kind,
        public readonly string $value = '',
        public readonly int $offset = -1,
        public readonly int $line = 0,
        public readonly array $parts = [],
        public readonly array $items = [],
        public readonly ?string $class = null,
        public readonly bool $lateStatic = false,
        public readonly array $names = [],
        public readonly ?int $scope = null,
        public readonly string $written = '',
    ) {}

    public static function literal(string $value, int $offset, int $line): self
    {
        return new self(self::LITERAL, $value, $offset, $line);
    }

    /**
     * @param list<self> $parts
     */
    public static function concat(array $parts, int $offset, int $line): self
    {
        return new self(self::CONCAT, offset: $offset, line: $line, parts: $parts);
    }

    public static function classConstant(?string $class, string $name, bool $lateStatic, string $written): self
    {
        return new self(self::CLASS_CONSTANT, $name, class: $class, lateStatic: $lateStatic, written: $written);
    }

    /**
     * @param list<string> $names
     */
    public static function constant(array $names, string $written): self
    {
        return new self(self::CONSTANT, names: $names, written: $written);
    }

    /**
     * @param int $offset where the variable is read, -1 when unknown
     */
    public static function variable(?int $scope, string $name, int $offset = -1): self
    {
        return new self(self::VARIABLE, $name, $offset, scope: $scope, written: '$'.$name);
    }

    public static function property(?string $class, string $name): self
    {
        return new self(self::PROPERTY, $name, class: $class, written: '$this->'.$name);
    }

    /**
     * @param list<array{0: self|null, 1: self}> $items
     */
    public static function arrayOf(array $items): self
    {
        return new self(self::ARRAY, items: $items);
    }

    /**
     * A scalar that is no string, a number, true or false: never a pattern,
     * and, as an array key, an int.
     */
    public static function nonString(): self
    {
        return new self(self::NON_STRING);
    }

    public static function unknown(): self
    {
        return new self(self::UNKNOWN);
    }

    /**
     * The key of a constant: the namespace in lowercase, as PHP compares
     * it, the name as written. "App\Sub\RE" gives "app\sub\RE".
     */
    public static function constantKey(string $name): string
    {
        $name = ltrim($name, '\\');
        $separator = strrpos($name, '\\');

        return false === $separator ? $name : Ascii::lower(substr($name, 0, $separator)).substr($name, $separator);
    }

    /**
     * The key of a class: its fully qualified name in lowercase.
     */
    public static function classKey(string $name): string
    {
        return Ascii::lower(ltrim($name, '\\'));
    }

    /**
     * Whether the call reads the pattern through a name: the pattern is
     * then reported where the name's value is written.
     */
    public function isReference(): bool
    {
        return \in_array($this->kind, [self::CLASS_CONSTANT, self::CONSTANT, self::VARIABLE, self::PROPERTY], true);
    }

    /**
     * Whether the in-file resolution may give the expression a value the
     * call site does not spell out.
     */
    public function isResolvable(): bool
    {
        return self::CONCAT === $this->kind || $this->isReference();
    }
}
