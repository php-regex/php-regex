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

use PHPRegex\Linter\Config\TargetConstraint;
use PHPRegex\Parser\Text\Ascii;

/**
 * An expression of a PHP file as the in-file resolution reads it: a
 * literal, a concatenation, a reference to a constant, a local variable or
 * a property, an array, a text known at run time only, one of several
 * values, a value with another to fall back on, a sprintf() or an
 * implode() call, or something it does not follow.
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
    public const HOLE = 'hole';
    public const SET = 'set';
    public const FALLBACK = 'fallback';
    public const FORMAT = 'format';
    public const JOIN = 'join';

    /**
     * @param string                             $kind         one of the constants above
     * @param string                             $value        the literal's value, the name of the constant,
     *                                                         variable or property, or the operator of a
     *                                                         fallback, "?:" or "??"
     * @param int                                $offset       where the expression starts in the file
     * @param int                                $line         the line it starts on
     * @param list<self>                         $parts        the operands of a concatenation; the two sides of a
     *                                                         fallback; the format of a sprintf() and its
     *                                                         arguments; the separator of an implode(), when it is
     *                                                         given one, and its items
     * @param list<array{0: self|null, 1: self}> $items        the key, if any, and the value of each array item
     * @param string|null                        $class        the lowercase class a class constant or a property
     *                                                         belongs to; null when no class of the file is known
     * @param bool                               $lateStatic   a class constant read through static::
     * @param list<string>                       $names        the constant keys a name may reach, in the order PHP
     *                                                         tries them; for a hole, a sprintf() or an implode(),
     *                                                         the lowercase functions its call may reach
     * @param int|null                           $scope        the function body a variable belongs to; null at file
     *                                                         scope
     * @param string                             $written      the reference as the source writes it: "self::RE";
     *                                                         for a hole, the operand as written
     * @param string|null                        $file         the file a literal or a concatenation of another file
     *                                                         is written in; null for one of the file being read
     * @param int|null                           $column       its column on its line, one-based, in bytes, known
     *                                                         with the file
     * @param TargetConstraint|null              $guard        the versions the guards of that file keep around it;
     *                                                         null when none holds there, or for one of the file
     *                                                         being read, whose guards the reader applies
     * @param bool                               $quoted       a hole that is a preg_quote() call
     * @param string|null                        $delimiter    the delimiter that call is given, when a string
     *                                                         literal gives it
     * @param list<self>                         $alternatives the values a set may take, in the order the source
     *                                                         gives them
     * @param int|null                           $integer      the integer a scalar that is no string is
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
        public readonly ?string $file = null,
        public readonly ?int $column = null,
        public readonly ?TargetConstraint $guard = null,
        public readonly bool $quoted = false,
        public readonly ?string $delimiter = null,
        public readonly array $alternatives = [],
        public readonly ?int $integer = null,
    ) {}

    public static function literal(string $value, int $offset, int $line, ?string $file = null, ?int $column = null, ?TargetConstraint $guard = null): self
    {
        return new self(self::LITERAL, $value, $offset, $line, file: $file, column: $column, guard: $guard);
    }

    /**
     * The same expression, its literals and concatenations placed in the
     * file they are read from, with their column and the versions the
     * file's guards keep around them: what another file reads through a
     * constant is reported there, and judged as there.
     *
     * @param string       $content the file's text, the columns are counted in
     * @param GuardRegions $guards  the version guards of the file
     */
    public function inFile(string $file, string $content, GuardRegions $guards): self
    {
        return match ($this->kind) {
            self::LITERAL => new self(self::LITERAL, $this->value, $this->offset, $this->line, file: $file, column: PendingPattern::column($content, $this->offset), guard: $guards->at($this->offset)),
            // A concatenation is reported whole, where it starts: its parts
            // need no place of their own.
            self::CONCAT => new self(self::CONCAT, offset: $this->offset, line: $this->line, parts: $this->parts, file: $file, column: PendingPattern::column($content, $this->offset), guard: $guards->at($this->offset)),
            self::ARRAY => new self(self::ARRAY, items: array_map(static fn (array $item): array => [$item[0]?->inFile($file, $content, $guards), $item[1]->inFile($file, $content, $guards)], $this->items)),
            self::SET => new self(self::SET, alternatives: array_map(static fn (self $alternative): self => $alternative->inFile($file, $content, $guards), $this->alternatives)),
            default => $this,
        };
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
     * The backing value of an enum case, Kind::Re->value: a class constant
     * under the name the resolver declares the case by, "Re->value", the
     * only form a call reads the case's string through. The case alone is an
     * object, and reads nothing.
     *
     * @param self $case the fetch of the case, Kind::Re, as a class constant
     */
    public static function enumCaseValue(self $case): self
    {
        if (self::CLASS_CONSTANT !== $case->kind) {
            return self::unknown();
        }

        return new self(self::CLASS_CONSTANT, self::enumCaseName($case->value), class: $case->class, lateStatic: $case->lateStatic, written: $case->written.'->value');
    }

    /**
     * The name an enum case is declared under among the class constants:
     * its "->value" read, which no constant is named as.
     */
    public static function enumCaseName(string $case): string
    {
        return $case.'->value';
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
     * and, as an array key, an int. An integer keeps its value: joined to
     * a string, it reads as PHP writes it, in decimal.
     */
    public static function nonString(?int $integer = null): self
    {
        return new self(self::NON_STRING, integer: $integer);
    }

    /**
     * A text known at run time only, where a string is read: what
     * preg_quote() returns, which reads as literal characters, or, not
     * quoted, anything.
     *
     * @param string|null  $delimiter the second argument of preg_quote(), when a string literal gives it
     * @param string       $written   the operand as the source writes it
     * @param list<string> $names     the lowercase functions the call may reach, in the order PHP tries
     *                                them: one the file declares is not the function of PHP
     */
    public static function hole(bool $quoted, ?string $delimiter, string $written, array $names = []): self
    {
        return new self(self::HOLE, names: $names, written: $written, quoted: $quoted, delimiter: $delimiter);
    }

    /**
     * One of several values, whichever path the code takes: the arms of a
     * ternary or of a match.
     *
     * @param list<self> $alternatives
     * @param int        $offset       where the expression starts, -1 when unknown
     */
    public static function setOf(array $alternatives, int $offset = -1, int $line = 0): self
    {
        return new self(self::SET, offset: $offset, line: $line, alternatives: $alternatives);
    }

    /**
     * A value, and the one PHP takes when it passes over the first: "A ?:
     * B" passes over an empty A, "A ?? B" over a null one.
     *
     * @param bool $coalescing whether the operator is "??"
     */
    public static function fallback(self $first, self $second, bool $coalescing, int $offset, int $line): self
    {
        return new self(self::FALLBACK, $coalescing ? '??' : '?:', $offset, $line, parts: [$first, $second]);
    }

    /**
     * A call to sprintf(): its format, then its arguments.
     *
     * @param non-empty-list<self> $parts
     * @param list<string>         $names the lowercase functions the call may reach, in the order PHP tries them
     */
    public static function format(array $parts, array $names, int $offset, int $line): self
    {
        return new self(self::FORMAT, offset: $offset, line: $line, parts: $parts, names: $names);
    }

    /**
     * A call to implode() or join(): its separator and its items, or its
     * items alone, which PHP joins with nothing.
     *
     * @param non-empty-list<self> $parts
     * @param list<string>         $names the lowercase functions the call may reach, in the order PHP tries them
     */
    public static function join(array $parts, array $names, int $offset, int $line): self
    {
        return new self(self::JOIN, offset: $offset, line: $line, parts: $parts, names: $names);
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
        return \in_array($this->kind, [self::CONCAT, self::SET, self::FALLBACK, self::FORMAT, self::JOIN], true) || $this->isReference();
    }
}
