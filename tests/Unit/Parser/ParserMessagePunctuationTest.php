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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\Exception\RecursionLimitException;
use PHPRegex\Parser\Exception\SyntaxErrorException;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\TestUtils\PcreMessageCodes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every error the parser reports is a sentence, its last character a period,
 * whatever follows the position it names.
 */
final class ParserMessagePunctuationTest extends TestCase
{
    /**
     * @param non-empty-string $message
     */
    #[Test]
    #[DataProvider('provideRefusedPatterns')]
    public function test_a_syntax_error_ends_with_a_period(string $regex, string $message): void
    {
        $this->assertNotNull(PcreMessageCodes::warningOf($regex), 'Oracle: PHP refuses '.$regex);

        try {
            RegexParser::create(['cache' => null])->parse($regex);
            $this->fail('No error for '.$regex);
        } catch (SyntaxErrorException $error) {
            $this->assertStringStartsWith($message, $error->getMessage());
            $this->assertStringEndsWith('.', $error->getMessage(), $regex);
        }
    }

    /**
     * @return iterable<string, array{regex: string, message: non-empty-string}>
     */
    public static function provideRefusedPatterns(): iterable
    {
        yield 'a quantifier with nothing before it' => ['regex' => '/*/', 'message' => 'Quantifier without target at position'];
        yield 'a quantifier on a lazy one, past a comment' => ['regex' => '/a?(?#c)*/', 'message' => 'Quantifier without target at position'];
        yield 'a second quantifier past a comment' => ['regex' => '/a*(?#c)++/', 'message' => 'Quantifier without target at position'];
        yield 'a quantifier on an option setting' => ['regex' => '/(?i)*/', 'message' => 'Quantifier without target at position'];
        yield 'a quantifier after the non-atomic lookbehind' => ['regex' => '/(?<*+a)/', 'message' => 'Quantifier without target at position'];
        yield 'an unknown option letter' => ['regex' => '/(?z)/', 'message' => 'Invalid group modifier syntax at position'];
        yield 'a condition that is no lookaround' => ['regex' => '/(?(?a)b)/', 'message' => 'Invalid conditional condition at position'];
        yield 'a quantifier on an anchor' => ['regex' => '/^*/', 'message' => 'Quantifier "*" cannot be applied to assertion or verb "^" at position'];
        yield 'a malformed \g reference' => ['regex' => '/\g{1-}/', 'message' => 'Invalid \g reference syntax: \g{1-} at position'];
    }

    #[Test]
    public function test_the_recursion_limit_ends_with_a_period(): void
    {
        try {
            RegexParser::create(['cache' => null, 'max_recursion_depth' => 3])->parse('/((((a))))/');
            $this->fail('No error past the recursion limit.');
        } catch (RecursionLimitException $error) {
            $this->assertSame('Recursion limit of 3 exceeded.', $error->getMessage());
        }
    }
}
