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

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The parser reads an item from the type of the token it starts on: each
 * type that opens an item gives its own node, and a token that opens none is
 * refused with the error that names it.
 */
final class AtomDispatchTest extends TestCase
{
    /**
     * @param class-string<NodeInterface> $expected
     */
    #[Test]
    #[DataProvider('provideItems')]
    public function test_the_first_token_decides_the_item(string $pattern, string $expected, int $index): void
    {
        $node = RegexParser::create(['cache' => null, 'php_version' => 80400, 'pcre_version' => '10.47'])->parse($pattern)->pattern;
        if ($node instanceof SequenceNode) {
            $node = $node->children[$index];
        }

        $this->assertInstanceOf($expected, $node);
    }

    /**
     * @return iterable<string, array{pattern: string, expected: class-string<NodeInterface>, index: int}>
     */
    public static function provideItems(): iterable
    {
        yield 'literal' => ['pattern' => '/a/', 'expected' => LiteralNode::class, 'index' => 0];
        yield 'escaped literal' => ['pattern' => '/\./', 'expected' => LiteralNode::class, 'index' => 0];
        yield 'character type' => ['pattern' => '/\d/', 'expected' => CharTypeNode::class, 'index' => 0];
        yield 'unicode property' => ['pattern' => '/\pL/u', 'expected' => UnicodePropNode::class, 'index' => 0];
        yield 'control character' => ['pattern' => '/\cA/', 'expected' => ControlCharNode::class, 'index' => 0];
        yield 'code point' => ['pattern' => '/\x{41}/', 'expected' => CharLiteralNode::class, 'index' => 0];
        yield 'named code point' => ['pattern' => '/\N{U+41}/u', 'expected' => CharLiteralNode::class, 'index' => 0];
        yield 'octal' => ['pattern' => '/\o{101}/', 'expected' => CharLiteralNode::class, 'index' => 0];
        yield 'legacy octal' => ['pattern' => '/\101/', 'expected' => CharLiteralNode::class, 'index' => 0];
        yield 'anchor' => ['pattern' => '/^a/', 'expected' => AnchorNode::class, 'index' => 0];
        yield 'assertion' => ['pattern' => '/\ba/', 'expected' => AssertionNode::class, 'index' => 0];
        yield 'back reference' => ['pattern' => '/(a)\1/', 'expected' => BackrefNode::class, 'index' => 1];
        yield 'dot' => ['pattern' => '/./', 'expected' => DotNode::class, 'index' => 0];
        yield 'g reference' => ['pattern' => '/(a)\g<1>/', 'expected' => SubroutineNode::class, 'index' => 1];
        yield 'keep' => ['pattern' => '/a\K/', 'expected' => KeepNode::class, 'index' => 1];
        yield 'group' => ['pattern' => '/(a)/', 'expected' => GroupNode::class, 'index' => 0];
        yield 'group with modifier' => ['pattern' => '/(?:a)/', 'expected' => GroupNode::class, 'index' => 0];
        yield 'class' => ['pattern' => '/[a]/', 'expected' => CharClassNode::class, 'index' => 0];
        yield 'verb' => ['pattern' => '/(*FAIL)/', 'expected' => PcreVerbNode::class, 'index' => 0];
        yield 'comment' => ['pattern' => '/(?#c)a/', 'expected' => CommentNode::class, 'index' => 0];
        yield 'callout' => ['pattern' => '/(?C1)a/', 'expected' => CalloutNode::class, 'index' => 0];
        yield 'quoted text' => ['pattern' => '/\Qa\E/', 'expected' => LiteralNode::class, 'index' => 0];
        yield 'extended class' => ['pattern' => '/(?[[a]])/', 'expected' => ExtendedCharClassNode::class, 'index' => 0];
    }

    /**
     * The positions are those PCRE2 10.47 gives: past the fault for a
     * quantifier with nothing to repeat.
     */
    #[Test]
    #[DataProvider('provideRefusals')]
    public function test_a_token_that_opens_no_item_is_refused(string $pattern, ErrorCode $code, int $position): void
    {
        try {
            RegexParser::create(['cache' => null, 'php_version' => 80400, 'pcre_version' => '10.47'])->parse($pattern);
            $this->fail('Expected the pattern to be refused.');
        } catch (ParserException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($position, $e->position);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, position: int}>
     */
    public static function provideRefusals(): iterable
    {
        yield 'quantifier first' => ['pattern' => '/*a/', 'code' => ErrorCode::QuantifierNothingToRepeat, 'position' => 1];
        yield 'quantifier after an alternation' => ['pattern' => '/a|+/', 'code' => ErrorCode::QuantifierNothingToRepeat, 'position' => 3];
        yield 'k without a name' => ['pattern' => '/\kx/', 'code' => ErrorCode::BackrefInvalidSyntax, 'position' => 2];
        yield 'unclosed verb' => ['pattern' => '/(*MARK:a/', 'code' => ErrorCode::VerbUnclosed, 'position' => 8];
    }
}
