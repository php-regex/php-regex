<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit\NodeVisitor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\ErrorCode;
use RegexParser\Exception\SemanticErrorException;
use RegexParser\NodeVisitor\ValidatorNodeVisitor;
use RegexParser\Regex;
use RegexParser\Tests\TestUtils\ValidatorErrorCodes;

/**
 * A pattern with two errors is reported at the one PCRE meets first, and PCRE
 * does not meet them in pattern order. It reads the whole pattern first, then
 * measures its lookbehinds, and only then resolves the references to groups
 * by number or by name: a reference to a missing group loses to any other
 * error, wherever that error stands. A relative reference ("\g{-3}") is
 * resolved while reading, like any other syntax.
 *
 * The offsets are PCRE2 10.48's; where 10.40 reports another, both are
 * listed.
 */
final class ErrorPrecedenceTest extends TestCase
{
    /**
     * @param list<int> $offsets
     */
    #[Test]
    #[DataProvider('providePatternsWithTwoErrors')]
    public function test_validate_reports_the_error_pcre_meets_first(string $pattern, array $offsets): void
    {
        $this->assertFalse(@preg_match($pattern, ''), \sprintf('%s should not compile.', $pattern));

        $result = Regex::create()->validate($pattern);

        $this->assertFalse($result->isValid);
        $this->assertContains(
            $result->offset,
            $offsets,
            \sprintf('%s reported at offset %s (%s), PCRE2 reports %s.', $pattern, var_export($result->offset, true), var_export($result->errorCode, true), implode(' or ', $offsets)),
        );
    }

    #[Test]
    public function test_a_pattern_over_the_length_limit_is_refused_for_its_length(): void
    {
        // The length limit is the library's own guard: it is checked first,
        // and the pattern is not read further for an earlier error.
        $result = Regex::create(['cache' => null, 'max_pattern_length' => 10])->validate('/\\y'.str_repeat('a', 30).'/');

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('exceeds maximum length', (string) $result->error);
    }

    #[Test]
    public function test_validating_a_define_on_its_own_reports_at_once(): void
    {
        // Only a walk from the pattern root waits for the late passes.
        $define = Regex::create()->parse('/(?(DEFINE)a|b)/')->pattern;

        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('more than one branch');

        $define->accept(new ValidatorNodeVisitor());
    }

    #[Test]
    public function test_validating_a_lookbehind_on_its_own_reports_at_once(): void
    {
        // Only a walk from the pattern root waits for the late passes: a
        // lookbehind handed to the validator alone is judged where it stands.
        $bounded = Regex::create()->parse('/(?<=ab)/')->pattern;
        $bounded->accept(new ValidatorNodeVisitor());

        $lookbehind = Regex::create()->parse('/(?<=a+)/')->pattern;

        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Lookbehind is unbounded');

        $lookbehind->accept(new ValidatorNodeVisitor());
    }

    /**
     * @param list<int> $offsets
     */
    #[Test]
    #[DataProvider('provideEscapesBeforeASyntaxError')]
    public function test_validate_reports_an_escape_error_met_before_a_syntax_error(string $pattern, array $offsets): void
    {
        $this->assertFalse(@preg_match($pattern, ''), \sprintf('%s should not compile.', $pattern));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid);
        // The escape is judged by the AST validator, so its code is one the
        // validator emits; a syntax code here would mean the later error won.
        $this->assertInstanceOf(ErrorCode::class, $result->errorCode, \sprintf('%s: %s', $pattern, (string) $result->error));
        $this->assertContains($result->errorCode->value, ValidatorErrorCodes::VALUES, \sprintf('%s: %s', $pattern, (string) $result->error));
        $this->assertContains(
            $result->offset,
            $offsets,
            \sprintf('%s reported at offset %s (%s), PCRE2 reports %s.', $pattern, var_export($result->offset, true), var_export($result->errorCode, true), implode(' or ', $offsets)),
        );
    }

    /**
     * @param list<int> $offsets
     */
    #[Test]
    #[DataProvider('provideSyntaxErrorsBeforeALexicalOne')]
    public function test_validate_reports_a_syntax_error_met_before_one_found_while_tokenizing(string $pattern, array $offsets): void
    {
        $this->assertFalse(@preg_match($pattern, ''), \sprintf('%s should not compile.', $pattern));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid);
        // The class left open is found while tokenizing; the syntax error
        // before it must win, so the code is never the unclosed class's.
        $this->assertInstanceOf(ErrorCode::class, $result->errorCode, \sprintf('%s: %s', $pattern, (string) $result->error));
        $this->assertNotSame(ErrorCode::from('regex.charclass.unclosed'), $result->errorCode, \sprintf('%s: %s', $pattern, (string) $result->error));
        $this->assertContains(
            $result->offset,
            $offsets,
            \sprintf('%s reported at offset %s (%s), PCRE2 reports %s.', $pattern, var_export($result->offset, true), var_export($result->errorCode, true), implode(' or ', $offsets)),
        );
    }

    /**
     * The whole pattern is tokenized before it is parsed, so a class left
     * open or a lone "\c" at the end used to hide a syntax error before it,
     * one PCRE meets first.
     *
     * @return iterable<string, array{pattern: string, offsets: list<int>}>
     */
    public static function provideSyntaxErrorsBeforeALexicalOne(): iterable
    {
        yield 'nothing to repeat before a class left open' => ['pattern' => '/+[^/', 'offsets' => [1, 0]];
        yield 'unmatched parenthesis before a class left open' => ['pattern' => '/)^U[/', 'offsets' => [1, 0]];
        yield 'k without a name before a class left open' => ['pattern' => '/\\k[9/', 'offsets' => [2]];
        yield 'k without a name before a lone c' => ['pattern' => '/\\k<\\f\\c/', 'offsets' => [3]];
        yield 'duplicate name before a class left open' => ['pattern' => '/a(?<n>x)(?<n>y)[/', 'offsets' => [13]];
        yield 'nothing to repeat before a lone c' => ['pattern' => '/+\\c/', 'offsets' => [1, 0]];
    }

    /**
     * PCRE reads the pattern once, left to right: an escape it refuses stops
     * it before a group left open, a class left open or a quantifier with
     * nothing to repeat further on.
     *
     * @return iterable<string, array{pattern: string, offsets: list<int>}>
     */
    public static function provideEscapesBeforeASyntaxError(): iterable
    {
        yield 'unknown escape before a group left open' => ['pattern' => '/\\y(/', 'offsets' => [2, 1]];
        yield 'malformed property before a group left open' => ['pattern' => '/\\p1(/', 'offsets' => [3]];
        yield 'unsupported escape before a lone \\c' => ['pattern' => '/\\L\\c/', 'offsets' => [2]];
        yield 'unknown escape before a class left open' => ['pattern' => '/\\y[/', 'offsets' => [2, 1]];
        yield 'escape without its brace before a group left open' => ['pattern' => '/\\o(/', 'offsets' => [2]];
        yield 'unsupported escape before an unmatched parenthesis' => ['pattern' => '/a\\U)/', 'offsets' => [3]];
        yield 'unknown escape inside a class left open' => ['pattern' => '/[a\\y/', 'offsets' => [4, 3]];
        yield 'single byte under UTF before a group left open' => ['pattern' => '/\\C(/u', 'offsets' => [2]];
        yield 'unknown escape after quoted text' => ['pattern' => '/\\Qa\\E\\y(/', 'offsets' => [7, 6]];
        yield 'unknown escape after a comment' => ['pattern' => '/(?#c)\\y(/', 'offsets' => [7, 6]];
        yield 'character type invalid in a class before a group left open' => ['pattern' => '/[\\R(/', 'offsets' => [3, 2]];
        yield 'character type invalid in a class before a class left open' => ['pattern' => '/a[\\X[/', 'offsets' => [4, 3]];
        yield 'unknown one-letter property before a group left open' => ['pattern' => '/\\Pf(/', 'offsets' => [3]];
        yield 'unknown braced property before a group left open' => ['pattern' => '/\\p{Foo}(/', 'offsets' => [7]];
        yield 'code point outside UTF mode before a group left open' => ['pattern' => '/\\N{U+41}(/', 'offsets' => [8, 2]];
    }

    /**
     * @return iterable<string, array{pattern: string, offsets: list<int>}>
     */
    public static function providePatternsWithTwoErrors(): iterable
    {
        yield 'unknown escape after a missing numbered group' => ['pattern' => '/\\7\\X\\y/x', 'offsets' => [6, 5]];
        yield 'unknown escape after a missing group, UTF' => ['pattern' => '/\\2\\J/u', 'offsets' => [4, 3]];
        yield 'unknown escape after a missing condition group' => ['pattern' => '/(?(2)a)\\y/', 'offsets' => [9, 8]];
        yield 'unknown escape after a missing named group' => ['pattern' => '/\\k<nm>\\y/', 'offsets' => [8, 7]];
        yield 'unknown escape after a missing called group' => ['pattern' => '/(?2)\\y/', 'offsets' => [6, 5]];
        yield 'unknown escape after a missing named call' => ['pattern' => '/(?&nm)\\y/', 'offsets' => [8, 7]];
        yield 'unknown escape after a missing named condition' => ['pattern' => '/(?(<nm>)a)\\y/', 'offsets' => [12, 11]];
        yield 'unknown escape after a missing group in a lookbehind' => ['pattern' => '/(?<=\\3)\\y/', 'offsets' => [9, 8]];
        yield 'unknown escape after a missing recursion condition' => ['pattern' => '/(?(R2)a)\\y/', 'offsets' => [10, 9]];
        yield 'unknown escape after a missing named recursion condition' => ['pattern' => '/(?(R&nm)a)\\y/', 'offsets' => [12, 11]];
        yield 'unknown escape after an unbounded lookbehind' => ['pattern' => '/(?<=a+)\\y/', 'offsets' => [9, 8]];
        yield 'reversed class range after a missing group' => ['pattern' => '/(a)\\3[z-a]/', 'offsets' => [9, 8]];
        yield 'reversed repeat count after a missing group' => ['pattern' => '/\\3a{3,2}/', 'offsets' => [7]];
        yield 'unbounded lookbehind after a missing group' => ['pattern' => '/\\2x(?<=a+)/', 'offsets' => [3]];
        yield 'missing group in a lookbehind after a missing group' => ['pattern' => '/\\2(?<=\\1)/', 'offsets' => [8, 7]];
        yield 'first of two missing groups, numbered first' => ['pattern' => '/\\2\\k<zz>/', 'offsets' => [2, 1]];
        yield 'first of two missing groups, named first' => ['pattern' => '/\\k<zz>\\2/', 'offsets' => [3]];
        yield 'first of two missing groups, braced \\g' => ['pattern' => '/\\3a\\g{2}/', 'offsets' => [2, 1]];
        yield 'reversed count after a conditional with three branches' => ['pattern' => '/(x)(?(1)a|b|c)ba{2,1}/', 'offsets' => [20]];
        yield 'unbounded lookbehind before a conditional with three branches' => ['pattern' => '/(?<=a+)(?(1)a|b|c)/', 'offsets' => [0]];
        yield 'missing group before a conditional with three branches' => ['pattern' => '/(a)(?&x)(?(1)a|b|c)/', 'offsets' => [6]];
        yield 'unknown escape after a define with two branches' => ['pattern' => '/(?(DEFINE)a|b)\\y/', 'offsets' => [16, 15]];
        yield 'missing group before a forward relative reference' => ['pattern' => '/(x)\\5b(?+3)/', 'offsets' => [5, 4]];
        yield 'unbounded lookbehind before a forward relative reference' => ['pattern' => '/(x)(?<=a+)b(?+3)/', 'offsets' => [3]];
        yield 'unknown escape after a forward relative reference' => ['pattern' => '/(x)\\g{+2}\\y/', 'offsets' => [11, 10]];
        yield 'relative reference is read before a missing group' => ['pattern' => '/\\5\\g{-3}/', 'offsets' => [4]];
    }
}
