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

namespace PHPRegex\Tests\Integration\Bridge\PHPUnit;

use PHPRegex\PHPUnit\Constraint\CompilesPattern;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;

/**
 * The CompilesPattern constraint: the truth is the running engine's alone,
 * so what the engine takes passes whatever the library's own parse limits
 * would say, and what it refuses fails carrying the PCRE message and the
 * offset it names — never a skip.
 */
final class CompilesPatternTest extends TestCase
{
    #[DataProvider('provideCompilablePatterns')]
    #[Test]
    public function test_compiles_pattern_accepts_each_spelling_the_engine_takes(string $pattern): void
    {
        $this->assertThat($pattern, new CompilesPattern());
    }

    #[DataProvider('provideRefusedPatterns')]
    #[Test]
    public function test_compiles_pattern_refuses_with_the_engine_message_and_offset(string $pattern, string $fragment, string $offset): void
    {
        $failure = $this->failingMessage(function () use ($pattern): void {
            $this->assertThat($pattern, new CompilesPattern());
        });

        $this->assertStringContainsString($fragment, $failure);
        $this->assertStringContainsString($offset, $failure);
    }

    /**
     * A literal run past roughly thirty-two thousand characters trips
     * PCRE2's own compiled-size cap, so the length lives in x-mode
     * layout the engine drops: the pattern is long for the parser's
     * limits and nothing at all for the engine's compiler.
     */
    #[Test]
    public function test_compiles_pattern_passes_beyond_the_librarys_parse_limits(): void
    {
        $wide = '/'.str_repeat(' ', 100_000).'a/x';

        $this->assertThat($wide, new CompilesPattern());
    }

    /**
     * The operand is the pattern, and a non-string one is refused with what
     * arrived, never run against the engine.
     */
    #[Test]
    public function test_compiles_pattern_refuses_a_non_string_operand(): void
    {
        $failure = $this->failingMessage(function (): void {
            $this->assertThat(['a'], new CompilesPattern());
        });

        $this->assertStringContainsString('the pattern must be a string, array given', $failure);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideCompilablePatterns(): iterable
    {
        // The four bracket delimiters count too: "(" opens and ")" closes.
        yield 'bracket delimiters' => ['pattern' => '(a|b)'];
        // A non-slash punctuation delimiter, the body free of it.
        yield 'hash delimiter' => ['pattern' => '#\d+#'];
        yield 'slash delimiter' => ['pattern' => '/[a-z]+/'];
        // Delimiters pair with modifiers on the closing side alone.
        yield 'tilde delimiter with flags' => ['pattern' => '~x~iu'];
        // The empty pattern compiles: it matches everywhere.
        yield 'empty pattern' => ['pattern' => '//'];
    }

    /**
     * @return iterable<string, array{pattern: string, fragment: string, offset: string}>
     */
    public static function provideRefusedPatterns(): iterable
    {
        // A class escape cannot end a range.
        yield 'escaped endpoint inside a range' => ['pattern' => '/[a-\d]/', 'fragment' => 'invalid range', 'offset' => 'at offset 5'];
        // The offset names where the missing close should have been.
        yield 'unbalanced group' => ['pattern' => '/(a/', 'fragment' => 'missing closing parenthesis', 'offset' => 'at offset 2'];
        // The reversed bounds are refused before any quantifying happens.
        yield 'quantifier bounds out of order' => ['pattern' => '/x{2,1}/', 'fragment' => 'numbers out of order', 'offset' => 'at offset 5'];
        // Nothing repeatable precedes the quantifier.
        yield 'quantifier on nothing' => ['pattern' => '/*a/', 'fragment' => 'quantifier does not follow', 'offset' => 'at offset 1'];
        // The group the backreference names is defined only later.
        yield 'backreference to a later group' => ['pattern' => '/\2(a)/', 'fragment' => 'reference to non-existent subpattern', 'offset' => 'at offset 2'];
    }

    /**
     * The failure message of an assertion that had to fail, so its wording
     * can be pinned; fail() escapes the catch by throwing the parent class.
     *
     * @param callable(): mixed $assertion
     */
    private function failingMessage(callable $assertion): string
    {
        try {
            $assertion();
            self::fail('Expected the assertion to fail.');
        } catch (ExpectationFailedException $failure) {
            return $failure->getMessage();
        }
    }
}
