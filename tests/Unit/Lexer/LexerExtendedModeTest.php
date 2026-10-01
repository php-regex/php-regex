<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Lexer;

use PhpRegex\Parser\Lexer;
use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Parser\Token\TokenType;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LexerExtendedModeTest extends TestCase
{
    #[Test]
    public function test_bracket_in_extended_comment_does_not_open_a_character_class(): void
    {
        $tokens = (new Lexer())->tokenize("a # [ x\nb", 'x')->getTokens();

        $types = array_map(static fn ($token) => $token->type, $tokens);
        $this->assertNotContains(TokenType::CharClassOpen, $types);

        $reconstructed = implode('', array_map(static fn ($token) => $token->value, $tokens));
        $this->assertSame("a # [ x\nb", $reconstructed);
    }

    #[Test]
    public function test_extended_comment_runs_to_end_of_pattern(): void
    {
        $tokens = (new Lexer())->tokenize('a # [ unterminated', 'x')->getTokens();

        $types = array_map(static fn ($token) => $token->type, $tokens);
        $this->assertNotContains(TokenType::CharClassOpen, $types);
    }

    #[Test]
    public function test_hash_is_literal_without_the_x_flag(): void
    {
        $tokens = (new Lexer())->tokenize('a#[b]', '')->getTokens();

        $types = array_map(static fn ($token) => $token->type, $tokens);
        $this->assertContains(TokenType::CharClassOpen, $types);
    }

    #[Test]
    public function test_hash_inside_a_character_class_is_literal_under_x(): void
    {
        $tokens = (new Lexer())->tokenize('[a#b]c', 'x')->getTokens();

        $types = array_map(static fn ($token) => $token->type, $tokens);
        $this->assertContains(TokenType::CharClassClose, $types);
    }

    /**
     * Patterns PCRE compiles happily; RegexParser used to reject the ones
     * whose /x comment holds an unbalanced '[' or '('.
     */
    #[Test]
    #[DataProvider('provideExtendedPatterns')]
    public function test_extended_patterns_are_accepted(string $pattern): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), 'PCRE must accept the pattern');

        $result = Regex::create()->validate($pattern);

        $this->assertTrue($result->isValid, $result->error ?? '');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideExtendedPatterns(): iterable
    {
        yield 'opening bracket in comment' => ["/a # [ x\nb/x"];
        yield 'closing bracket in comment' => ["/a # ] x\nb/x"];
        yield 'both brackets in comment' => ["/a # [ or ]\nb/x"];
        yield 'unbalanced parenthesis in comment' => ["/a # (unbalanced\nb/x"];
        yield 'comment without trailing newline' => ['/a # trailing/x'];
        yield 'escaped hash is not a comment' => ['/a\\#b/x'];
        yield 'hash inside character class' => ['/[a#b]/x'];
        yield 'drupal token scanner' => ["/\n      \\[             # [ - pattern start\n      ([^\\s\\[\\]:]+)  # match \$type not containing whitespace : [ or ]\n      :              # : - separator\n      ([^\\[\\]]+)     # match \$name not containing [ or ]\n      \\]             # ] - pattern end\n      /x"];
        yield 'inline (?x) before a comment' => ["/(?x)a # [ x\nb/"];
        yield 'inline (?x) mid-pattern' => ["/a(?x)b # [ x\nc/"];
        yield 'scoped (?x:...) comment' => ["/(?x:a # [ x\nb)c/"];
        yield 'inline (?x) inside a group' => ["/((?x)a # [ x\nb)c/"];
    }

    /**
     * "(?x)" holds until the end of the enclosing group and crosses "|",
     * "(?x:...)" stops at its own ')', and "(?-x)" turns the mode back off.
     */
    #[Test]
    #[DataProvider('provideInlineExtendedPatterns')]
    public function test_inline_x_matches_pcre(string $pattern): void
    {
        $compiled = Regex::create()->parse($pattern)->accept(new PatternPrinter());

        foreach (['ab', 'a b', 'abc d', 'a bc d', 'cd', 'c d', 'a#b', 'ab c'] as $subject) {
            $this->assertSame(
                @preg_match($pattern, $subject),
                @preg_match($compiled, $subject),
                \sprintf('%s and its recompiled form %s disagree on %s', $pattern, $compiled, var_export($subject, true)),
            );
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInlineExtendedPatterns(): iterable
    {
        yield 'global x' => ['/a b/x'];
        yield 'inline x' => ['/(?x)a b/'];
        yield 'scoped x' => ['/(?x:a b)c d/'];
        yield 'inline x crosses alternation' => ['/(?:(?x)a b|c d)/'];
        yield 'inline x ends with its group' => ['/((?x)a b)c d/'];
        yield 'inline x then (?-x)' => ['/(?x)a(?-x)b c/'];
        yield 'reset with (?^x)' => ['/(?^x)a b/'];
        yield 'reset drops x' => ['/(?x)(?^i)a b/'];
        yield 'escaped space stays literal' => ['/(?x)a\\ b/'];
        yield 'escaped hash stays literal' => ['/(?x)a\\#b/'];
        yield 'space inside a class is literal' => ['/(?x)[a b]/'];
        yield 'comment after inline x' => ["/(?x)a # comment\nb/"];
    }
}
