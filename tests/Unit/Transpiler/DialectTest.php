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

namespace PHPRegex\Tests\Unit\Transpiler;

use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Transpiler\Dialect;
use PHPRegex\Transpiler\Exception\TranspileException;
use PHPRegex\Transpiler\Transpiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A dialect is a type: a name or an alias resolves to one case, and a name
 * no dialect answers to is a wrong argument, not a pattern the dialect
 * cannot carry.
 */
final class DialectTest extends TestCase
{
    use PhpUnitForwardCompat;

    #[Test]
    #[DataProvider('provideNames')]
    public function test_a_name_or_an_alias_resolves_to_its_dialect(string $name, Dialect $expected): void
    {
        $this->assertSame($expected, Dialect::fromName($name));
    }

    /**
     * @return iterable<string, array{name: string, expected: Dialect}>
     */
    public static function provideNames(): iterable
    {
        yield 'javascript' => ['name' => 'javascript', 'expected' => Dialect::JavaScript];
        yield 'js' => ['name' => 'js', 'expected' => Dialect::JavaScript];
        yield 'html-pattern' => ['name' => 'html-pattern', 'expected' => Dialect::HtmlPattern];
        yield 'html' => ['name' => 'html', 'expected' => Dialect::HtmlPattern];
        yield 'python' => ['name' => 'python', 'expected' => Dialect::Python];
        yield 'py' => ['name' => 'py', 'expected' => Dialect::Python];
        yield 'an alias in capitals' => ['name' => 'JS', 'expected' => Dialect::JavaScript];
        yield 'a name in mixed case' => ['name' => 'Html-Pattern', 'expected' => Dialect::HtmlPattern];
        yield 'a name between spaces' => ['name' => " python\n", 'expected' => Dialect::Python];
    }

    #[Test]
    #[DataProvider('provideUnknownNames')]
    public function test_a_name_no_dialect_answers_to_is_an_invalid_option(string $name): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('Unknown transpile dialect: %s. Expected one of: javascript, js, html-pattern, html, python, py.', $name));

        Dialect::fromName($name);
    }

    /**
     * @return iterable<string, array{name: string}>
     */
    public static function provideUnknownNames(): iterable
    {
        yield 'another language' => ['name' => 'ruby'];
        yield 'the start of a name' => ['name' => 'java'];
        yield 'a space for the dash' => ['name' => 'html pattern'];
        yield 'nothing' => ['name' => ''];
    }

    #[Test]
    public function test_each_dialect_lists_its_aliases(): void
    {
        $this->assertSame(['js'], Dialect::JavaScript->aliases());
        $this->assertSame(['html'], Dialect::HtmlPattern->aliases());
        $this->assertSame(['py'], Dialect::Python->aliases());
    }

    #[Test]
    public function test_the_names_list_each_dialect_then_its_aliases(): void
    {
        $this->assertSame(['javascript', 'js', 'html-pattern', 'html', 'python', 'py'], Dialect::names());

        foreach (Dialect::names() as $name) {
            $this->assertContains(Dialect::fromName($name), Dialect::cases());
        }
    }

    #[Test]
    #[DataProvider('provideDialects')]
    public function test_the_transpiler_takes_the_case_or_any_of_its_names(Dialect $dialect, string $literal): void
    {
        $transpiler = new Transpiler();

        foreach ([$dialect, $dialect->value, ...$dialect->aliases()] as $argument) {
            $result = $transpiler->transpile('/^a+$/', $argument);

            $this->assertSame($dialect->value, $result->target);
            $this->assertSame($literal, $result->literal);
        }
    }

    /**
     * @return iterable<string, array{dialect: Dialect, literal: string}>
     */
    public static function provideDialects(): iterable
    {
        yield 'JavaScript' => ['dialect' => Dialect::JavaScript, 'literal' => '/^a+(?=\n?$)/'];
        yield 'the HTML pattern attribute' => ['dialect' => Dialect::HtmlPattern, 'literal' => '^a+$'];
        yield 'Python' => ['dialect' => Dialect::Python, 'literal' => "r'(?a)^a+$'"];
    }

    /**
     * The dialect is resolved before the pattern is read: a name no dialect
     * answers to is reported over a pattern that does not parse, and a
     * construct the dialect cannot carry stays a TranspileException.
     */
    #[Test]
    public function test_an_unknown_dialect_is_refused_before_the_pattern_is_read(): void
    {
        try {
            (new Transpiler())->transpile('/(/', 'ruby');
            $this->fail('An unknown dialect must be refused.');
        } catch (InvalidRegexOptionException $e) {
            $this->assertStringStartsWith('Unknown transpile dialect: ruby.', $e->getMessage());
        }

        $this->expectException(ParserException::class);
        (new Transpiler())->transpile('/(/', Dialect::JavaScript);
    }

    #[Test]
    public function test_a_construct_the_dialect_cannot_carry_stays_a_transpile_exception(): void
    {
        $this->expectException(TranspileException::class);
        $this->expectExceptionMessageIsOrContains('Atomic groups are not supported in JavaScript.');

        (new Transpiler())->transpile('/(?>a|ab)c/', Dialect::JavaScript);
    }
}
