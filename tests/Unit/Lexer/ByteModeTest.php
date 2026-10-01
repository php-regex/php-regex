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

namespace PhpRegex\Tests\Unit\Lexer;

use PhpRegex\Parser\Cache\NullCache;
use PhpRegex\Parser\Lexer;
use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Parser\Token\TokenType;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pattern that is not valid UTF-8 is read byte by byte, everywhere.
 *
 * PCRE compiles such a pattern as long as it carries no /u, so the lexer
 * has to read it too — including the runs it reads with a regex of its own,
 * which used to ask PCRE for UTF-8 and take the refusal for the end of the
 * pattern.
 */
final class ByteModeTest extends TestCase
{
    #[Test]
    public function test_a_quoted_run_holding_an_invalid_byte_is_read_whole(): void
    {
        $tokens = (new Lexer())->tokenize("\\Q\xFFabc\\E]");

        $types = array_map(static fn (object $token): string => $token->type->name, $tokens->getTokens());

        $this->assertSame(
            ['T_QUOTE_MODE_START', 'T_LITERAL', 'T_QUOTE_MODE_END', 'T_LITERAL', 'T_EOF'],
            $types,
            'The quoted run swallowed the rest of the pattern.',
        );
        $this->assertSame("\xFFabc", $tokens->getTokens()[1]->value);
    }

    #[Test]
    public function test_a_comment_holding_an_invalid_byte_is_read_whole(): void
    {
        $tokens = (new Lexer())->tokenize("(?#\xFF note)a");

        $last = $tokens->getTokens()[\count($tokens->getTokens()) - 2];

        $this->assertSame(TokenType::T_LITERAL, $last->type);
        $this->assertSame('a', $last->value, 'The comment swallowed the rest of the pattern.');
    }

    #[Test]
    #[DataProvider('provideBytePatterns')]
    public function test_a_byte_pattern_keeps_what_it_matches(string $pattern): void
    {
        // A cache of its own: an entry written by a version that dropped the
        // content would hide the bug this test is here for.
        $recompiled = Regex::create(['cache' => new NullCache()])
            ->parse($pattern)
            ->accept(new PatternPrinter());

        $this->assertNotSame('/' === $pattern[0] ? '//' : '', $recompiled, 'The pattern came back empty.');

        set_error_handler(static fn (): bool => true);
        $compiles = false !== @preg_match($recompiled, '');
        restore_error_handler();

        $this->assertTrue($compiles, \sprintf('PCRE rejects "%s", recompiled from "%s".', $recompiled, $pattern));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideBytePatterns(): iterable
    {
        yield 'a quoted run' => ['pattern' => "/\\Q\xFFabc\\E]/"];
        yield 'a quoted run in the middle' => ['pattern' => "/x\xFF\\Qy\\E/"];
        yield 'a comment' => ['pattern' => "/(?#\xFF note)a/"];
        yield 'a bare byte' => ['pattern' => "/\xFF/"];
        yield 'a byte in a class' => ['pattern' => "/[\xFE-\xFF]/"];
    }
}
