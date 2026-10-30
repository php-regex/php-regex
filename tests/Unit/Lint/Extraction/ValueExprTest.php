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
        yield 'unknown' => ['expr' => ValueExpr::unknown(), 'reference' => false, 'resolvable' => false];
    }

    /**
     * A literal is read at the call; only a concatenation or a name may
     * have a value the call does not spell out.
     */
    #[Test]
    #[DataProvider('provideKinds')]
    public function test_only_a_concatenation_or_a_name_is_resolvable(ValueExpr $expr, bool $reference, bool $resolvable): void
    {
        $this->assertSame($reference, $expr->isReference());
        $this->assertSame($resolvable, $expr->isResolvable());
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
