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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Syntax\TokenParser;
use PHPUnit\Framework\TestCase;

final class ParserEntryPointTest extends TestCase
{
    public function test_parser_can_parse_simple_pattern(): void
    {
        $parser = new TokenParser();
        $lexer = new Lexer();

        $tokenStream = $lexer->tokenize('test');
        $ast = $parser->parse($tokenStream, '', '/', \strlen('test'));

        $this->assertSame('', $ast->flags);
        $this->assertSame('/', $ast->delimiter);
        $this->assertSame(4, $ast->getEndPosition());
    }

    public function test_parser_can_parse_with_flags(): void
    {
        $parser = new TokenParser();
        $lexer = new Lexer();

        $tokenStream = $lexer->tokenize('test');
        $ast = $parser->parse($tokenStream, 'i', '#', \strlen('test'));

        $this->assertSame('i', $ast->flags);
        $this->assertSame('#', $ast->delimiter);
        $this->assertSame(4, $ast->getEndPosition());
    }

    public function test_a_parser_read_twice_forgets_the_names_of_the_first_pattern(): void
    {
        // pcre2test 10.49: "(?(R2)a|c)()()" tests a recursion into group 2;
        // the group named R2 the first pattern held is not this pattern's.
        $parser = new TokenParser();
        $lexer = new Lexer();
        $parser->parse($lexer->tokenize('(*pla:(?<R2>a))'));

        $ast = $parser->parse($lexer->tokenize('(?(R2)a|c)()()'));

        $conditional = $ast->pattern instanceof SequenceNode ? $ast->pattern->children[0] : null;
        $this->assertInstanceOf(ConditionalNode::class, $conditional);
        $this->assertInstanceOf(SubroutineNode::class, $conditional->condition);
    }

    public function test_parser_with_custom_recursion_depth(): void
    {
        $parser = new TokenParser(100);
        $lexer = new Lexer();

        $tokenStream = $lexer->tokenize('test');
        $ast = $parser->parse($tokenStream, '', '/', \strlen('test'));

        $this->assertSame(4, $ast->getEndPosition());
    }
}
