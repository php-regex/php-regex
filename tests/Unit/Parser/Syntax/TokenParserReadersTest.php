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

namespace PHPRegex\Tests\Unit\Parser\Syntax;

use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\Syntax\TokenParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The readers of the families of constructs that call the parser back hold
 * it in their closures: the parser drops them when a reading ends, so a
 * parse leaves no reference cycle for the garbage collector to find, the
 * parse failed or not.
 */
final class TokenParserReadersTest extends TestCase
{
    #[Test]
    #[DataProvider('provideReadPatterns')]
    public function test_a_reading_leaves_no_reference_cycle(string $pattern): void
    {
        gc_collect_cycles();

        $parser = new TokenParser();
        $parser->parse((new Lexer())->tokenize($pattern), '', '/', \strlen($pattern));
        unset($parser);

        $this->assertSame(0, gc_collect_cycles());
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideReadPatterns(): iterable
    {
        yield 'non-capturing group' => ['pattern' => '(?:a)'];
        yield 'named group' => ['pattern' => '(?<n>a)\k<n>'];
        yield 'branch reset' => ['pattern' => '(?|(a)|(b))'];
        yield 'option setting' => ['pattern' => '(?i:a)(?x) b'];
        yield 'conditional' => ['pattern' => '(a)?(?(1)b|c)'];
        yield 'alphabetic assertion' => ['pattern' => '(*pla:a)a'];
        yield 'verb' => ['pattern' => 'a(*COMMIT)b'];
        yield 'quantifier and call' => ['pattern' => '(a+)(?1)'];
    }

    #[Test]
    #[DataProvider('provideRefusedPatterns')]
    public function test_a_failed_reading_leaves_no_reference_cycle(string $pattern): void
    {
        gc_collect_cycles();

        $parser = new TokenParser();

        try {
            $parser->parse((new Lexer())->tokenize($pattern), '', '/', \strlen($pattern));
            $this->fail('The pattern is refused.');
        } catch (ParserException $error) {
            $this->assertNotSame('', $error->getMessage());
        }

        unset($parser, $error);

        $this->assertSame(0, gc_collect_cycles());
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRefusedPatterns(): iterable
    {
        yield 'group left open' => ['pattern' => '(?:a'];
        yield 'conditional left open' => ['pattern' => '(a)(?(1)b'];
        yield 'quantifier without target in a group' => ['pattern' => '(?:*)'];
    }
}
