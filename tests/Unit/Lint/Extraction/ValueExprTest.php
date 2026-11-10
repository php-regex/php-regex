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

namespace PHPRegex\Tests\Unit\Lint\Extraction;

use PHPRegex\Linter\Extraction\ValueExpr;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The keys under which the in-file resolution files constants and classes,
 * and what each kind of expression lets it read.
 */
final class ValueExprTest extends TestCase
{
    /**
     * PHP compares a constant's namespace without case and its name with
     * case.
     *
     * @return iterable<string, array{name: string, key: string}>
     */
    public static function provideConstantKeys(): iterable
    {
        yield 'global name' => ['name' => 'RE', 'key' => 'RE'];
        yield 'global name, fully qualified' => ['name' => '\\RE', 'key' => 'RE'];
        yield 'namespaced name' => ['name' => 'App\\RE', 'key' => 'app\\RE'];
        yield 'namespaced name, fully qualified' => ['name' => '\\App\\Sub\\Re', 'key' => 'app\\sub\\Re'];
        yield 'nested namespace, mixed case' => ['name' => 'App\\Sub\\RE', 'key' => 'app\\sub\\RE'];
    }

    #[Test]
    #[DataProvider('provideConstantKeys')]
    public function test_constant_key_lowercases_the_namespace_only(string $name, string $key): void
    {
        $this->assertSame($key, ValueExpr::constantKey($name));
    }

    /**
     * @return iterable<string, array{name: string, key: string}>
     */
    public static function provideClassKeys(): iterable
    {
        yield 'global class' => ['name' => 'Matcher', 'key' => 'matcher'];
        yield 'namespaced class' => ['name' => 'App\\Matcher', 'key' => 'app\\matcher'];
        yield 'fully qualified class' => ['name' => '\\App\\Matcher', 'key' => 'app\\matcher'];
    }

    #[Test]
    #[DataProvider('provideClassKeys')]
    public function test_class_key_is_the_lowercase_name_without_leading_separator(string $name, string $key): void
    {
        $this->assertSame($key, ValueExpr::classKey($name));
    }

    /**
     * @return iterable<string, array{expr: ValueExpr, reference: bool, resolvable: bool}>
     */
    public static function provideKinds(): iterable
    {
        yield 'literal' => ['expr' => ValueExpr::literal('/a/', 0, 1), 'reference' => false, 'resolvable' => false];
        yield 'concatenation' => ['expr' => ValueExpr::concat([], 0, 1), 'reference' => false, 'resolvable' => true];
        yield 'class constant' => ['expr' => ValueExpr::classConstant('app\\matcher', 'RE', false, 'self::RE'), 'reference' => true, 'resolvable' => true];
        yield 'constant' => ['expr' => ValueExpr::constant(['RE'], 'RE'), 'reference' => true, 'resolvable' => true];
        yield 'variable' => ['expr' => ValueExpr::variable(0, 're'), 'reference' => true, 'resolvable' => true];
        yield 'property' => ['expr' => ValueExpr::property('app\\matcher', 're'), 'reference' => true, 'resolvable' => true];
        yield 'array' => ['expr' => ValueExpr::arrayOf([]), 'reference' => false, 'resolvable' => false];
        yield 'non-string' => ['expr' => ValueExpr::nonString(), 'reference' => false, 'resolvable' => false];
        yield 'integer' => ['expr' => ValueExpr::nonString(3), 'reference' => false, 'resolvable' => false];
        yield 'unknown' => ['expr' => ValueExpr::unknown(), 'reference' => false, 'resolvable' => false];
        // A hole is a text known at run time only: no name, and no value
        // the file could give it.
        yield 'quoted hole' => ['expr' => ValueExpr::hole(true, '/', "preg_quote(\$word, '/')"), 'reference' => false, 'resolvable' => false];
        yield 'quoted hole without a delimiter' => ['expr' => ValueExpr::hole(true, null, 'preg_quote($word)'), 'reference' => false, 'resolvable' => false];
        yield 'raw hole' => ['expr' => ValueExpr::hole(false, null, '$word'), 'reference' => false, 'resolvable' => false];
        // A set is no name; its alternatives may be names, as the parts of
        // a concatenation may.
        yield 'set' => ['expr' => ValueExpr::setOf([ValueExpr::literal('/a/', 0, 1), ValueExpr::constant(['RE'], 'RE')]), 'reference' => false, 'resolvable' => true];
    }

    /**
     * A literal is read at the call; only a concatenation, a set or a name
     * may have a value the call does not spell out.
     */
    #[Test]
    #[DataProvider('provideKinds')]
    public function test_only_a_concatenation_or_a_name_is_resolvable(ValueExpr $expr, bool $reference, bool $resolvable): void
    {
        $this->assertSame($reference, $expr->isReference());
        $this->assertSame($resolvable, $expr->isResolvable());
    }

    /**
     * A hole keeps what the shell reading needs of it: whether it is a
     * preg_quote() call, the delimiter that call is given when a string
     * literal gives it, and the text as the source writes it. PHP 8.4:
     * preg_quote('a/b') is "a/b", preg_quote('a/b', '/') is "a\/b" — only
     * the delimiter argument escapes the delimiter.
     */
    #[Test]
    public function test_a_hole_keeps_whether_it_is_quoted_its_delimiter_and_its_text(): void
    {
        $quoted = ValueExpr::hole(true, '/', "preg_quote(\$word, '/')");

        $this->assertSame(ValueExpr::HOLE, $quoted->kind);
        $this->assertTrue($quoted->quoted);
        $this->assertSame('/', $quoted->delimiter);
        $this->assertSame("preg_quote(\$word, '/')", $quoted->written);

        $bare = ValueExpr::hole(true, null, 'preg_quote($word)');

        $this->assertSame(ValueExpr::HOLE, $bare->kind);
        $this->assertTrue($bare->quoted);
        $this->assertNull($bare->delimiter);
        $this->assertSame('preg_quote($word)', $bare->written);

        $raw = ValueExpr::hole(false, null, '$this->build()');

        $this->assertSame(ValueExpr::HOLE, $raw->kind);
        $this->assertFalse($raw->quoted);
        $this->assertNull($raw->delimiter);
        $this->assertSame('$this->build()', $raw->written);
    }

    /**
     * @return iterable<string, array{expr: ValueExpr}>
     */
    public static function provideKindsThatAreNoHole(): iterable
    {
        yield 'literal' => ['expr' => ValueExpr::literal('/a/', 0, 1)];
        yield 'concatenation' => ['expr' => ValueExpr::concat([], 0, 1)];
        yield 'class constant' => ['expr' => ValueExpr::classConstant('app\\matcher', 'RE', false, 'self::RE')];
        yield 'constant' => ['expr' => ValueExpr::constant(['RE'], 'RE')];
        yield 'variable' => ['expr' => ValueExpr::variable(0, 're')];
        yield 'property' => ['expr' => ValueExpr::property('app\\matcher', 're')];
        yield 'array' => ['expr' => ValueExpr::arrayOf([])];
        yield 'non-string' => ['expr' => ValueExpr::nonString()];
        yield 'integer' => ['expr' => ValueExpr::nonString(3)];
        yield 'unknown' => ['expr' => ValueExpr::unknown()];
        yield 'set' => ['expr' => ValueExpr::setOf([])];
    }

    /**
     * No other kind is quoted or holds a delimiter: the fields of a hole
     * read as "no hole" on them.
     */
    #[Test]
    #[DataProvider('provideKindsThatAreNoHole')]
    public function test_a_kind_that_is_no_hole_is_not_quoted(ValueExpr $expr): void
    {
        $this->assertNotSame(ValueExpr::HOLE, $expr->kind);
        $this->assertFalse($expr->quoted);
        $this->assertNull($expr->delimiter);
    }

    /**
     * A set holds the values an expression may take, in the order the
     * source gives them; no other kind holds any.
     */
    #[Test]
    public function test_a_set_keeps_its_alternatives_in_order(): void
    {
        $first = ValueExpr::literal('/a/', 0, 1);
        $second = ValueExpr::classConstant('app\\matcher', 'RE', false, 'self::RE');
        $set = ValueExpr::setOf([$first, $second]);

        $this->assertSame(ValueExpr::SET, $set->kind);
        $this->assertSame([$first, $second], $set->alternatives);

        $this->assertSame([], ValueExpr::setOf([])->alternatives);
        $this->assertSame([], ValueExpr::literal('/a/', 0, 1)->alternatives);
        $this->assertSame([], ValueExpr::concat([$first], 0, 1)->alternatives);
        $this->assertSame([], ValueExpr::arrayOf([[null, $first]])->alternatives);
        $this->assertSame([], ValueExpr::unknown()->alternatives);
    }

    /**
     * @return iterable<string, array{integer: int}>
     */
    public static function provideIntegers(): iterable
    {
        yield 'zero' => ['integer' => 0];
        yield 'one' => ['integer' => 1];
        yield 'negative' => ['integer' => -3];
        yield 'the largest' => ['integer' => \PHP_INT_MAX];
        yield 'the smallest' => ['integer' => \PHP_INT_MIN];
    }

    /**
     * A scalar that is no string keeps the integer it is, the one PHP
     * writes in decimal when a concatenation reads it; zero is an integer
     * as any other, told from "no integer".
     */
    #[Test]
    #[DataProvider('provideIntegers')]
    public function test_a_non_string_scalar_keeps_the_integer_it_is(int $integer): void
    {
        $expr = ValueExpr::nonString($integer);

        $this->assertSame(ValueExpr::NON_STRING, $expr->kind);
        $this->assertSame($integer, $expr->integer);
        $this->assertSame('', $expr->value);
    }

    /**
     * A float, true, false: a scalar that is no string and no integer.
     */
    #[Test]
    public function test_a_non_string_scalar_that_is_no_integer_holds_none(): void
    {
        $this->assertSame(3, ValueExpr::nonString(3)->integer);

        $this->assertSame(ValueExpr::NON_STRING, ValueExpr::nonString()->kind);
        $this->assertNull(ValueExpr::nonString()->integer);
        $this->assertNull(ValueExpr::literal('3', 0, 1)->integer);
        $this->assertNull(ValueExpr::unknown()->integer);
    }

    /**
     * The two new kinds are kinds of their own.
     */
    #[Test]
    public function test_a_hole_and_a_set_are_kinds_of_their_own(): void
    {
        $kinds = [ValueExpr::LITERAL, ValueExpr::CONCAT, ValueExpr::CLASS_CONSTANT, ValueExpr::CONSTANT, ValueExpr::VARIABLE, ValueExpr::PROPERTY, ValueExpr::ARRAY, ValueExpr::NON_STRING, ValueExpr::UNKNOWN, ValueExpr::HOLE, ValueExpr::SET];

        $this->assertSame($kinds, array_values(array_unique($kinds)));
    }

    /**
     * An enum case's value is the class constant the case is declared as,
     * "Re->value", on the same class, with the same late static binding,
     * written as the source does; anything but a class constant stays
     * unknown.
     */
    #[Test]
    public function test_an_enum_case_value_names_the_case_as_the_resolver_declares_it(): void
    {
        $value = ValueExpr::enumCaseValue(ValueExpr::classConstant('app\\kind', 'Re', true, 'static::Re'));

        $this->assertSame(ValueExpr::CLASS_CONSTANT, $value->kind);
        $this->assertSame('Re->value', $value->value);
        $this->assertSame(ValueExpr::enumCaseName('Re'), $value->value);
        $this->assertSame('app\\kind', $value->class);
        $this->assertTrue($value->lateStatic);
        $this->assertSame('static::Re->value', $value->written);

        $this->assertSame(ValueExpr::UNKNOWN, ValueExpr::enumCaseValue(ValueExpr::unknown())->kind);
        $this->assertSame(ValueExpr::UNKNOWN, ValueExpr::enumCaseValue(ValueExpr::property('app\\a', 'value'))->kind);
    }
}
