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

namespace PhpRegex\Tests\Unit\Parser;

use PhpRegex\Generator\SampleGenerator;
use PhpRegex\Parser\Node\CommentNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\QuantifierType;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Tests\TestUtils\PhpErrorOffset;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE skips a (?#...) comment, and a /x line comment, before it reads a
 * quantifier: "a*(?#c)+" is the possessive "a*+", "a*(?#c)*" is two
 * quantifiers in a row. Every verdict and offset below is the one
 * preg_match() gives on PCRE2 10.48.
 */
final class QuantifierModifierAfterCommentTest extends TestCase
{
    #[Test]
    #[DataProvider('provideQuantifiedItems')]
    public function test_the_quantifier_goes_on_the_item_before_the_comments(string $pattern, string $quantifier, QuantifierType $type): void
    {
        $ast = Regex::create()->parse($pattern);

        $sequence = $ast->pattern;
        $this->assertInstanceOf(SequenceNode::class, $sequence);

        $quantified = $sequence->children[0];
        $this->assertInstanceOf(QuantifierNode::class, $quantified);
        $this->assertInstanceOf(LiteralNode::class, $quantified->node);
        $this->assertSame('a', $quantified->node->value);
        $this->assertSame($quantifier, $quantified->quantifier);
        $this->assertSame($type, $quantified->type);

        $this->assertContainsOnlyInstancesOf(CommentNode::class, \array_slice($sequence->children, 1));
    }

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideMatchingPatterns')]
    public function test_the_recompiled_pattern_matches_like_the_original(string $pattern, array $subjects): void
    {
        $ast = Regex::create()->parse($pattern);
        $compiled = $ast->accept(new PatternPrinter());

        foreach ($subjects as $subject) {
            $this->assertSame(preg_match($pattern, $subject, $expected), preg_match($compiled, $subject, $actual), \sprintf('%s compiled to %s on "%s"', $pattern, $compiled, $subject));
            $this->assertSame($expected, $actual, \sprintf('%s compiled to %s on "%s"', $pattern, $compiled, $subject));
        }

        $this->assertSame(1, preg_match($pattern, $ast->accept(new SampleGenerator())));
    }

    #[Test]
    #[DataProvider('provideRejectedPatterns')]
    public function test_validate_rejects_at_the_offset_php_reports(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''), \sprintf('%s must be refused by PHP.', $pattern));

        $result = Regex::create()->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s is refused by PHP but was reported valid.', $pattern));
        // The offset is PHP's on PCRE2 10.47 and later; the running PHP
        // decides, as the library follows the PCRE2 it links.
        $this->assertSame(PhpErrorOffset::of($pattern), $result->offset, $pattern);
        if (PhpErrorOffset::runsPcre1047()) {
            $this->assertSame($offset, $result->offset, $pattern);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, quantifier: string, type: QuantifierType}>
     */
    public static function provideQuantifiedItems(): iterable
    {
        yield 'star after a comment' => ['pattern' => '/a(?#c)*/', 'quantifier' => '*', 'type' => QuantifierType::Greedy];
        yield 'plus after a quantifier and a comment is possessive' => ['pattern' => '/a*(?#c)+/', 'quantifier' => '*', 'type' => QuantifierType::Possessive];
        yield 'question mark after a quantifier and a comment is lazy' => ['pattern' => '/a*(?#c)?/', 'quantifier' => '*', 'type' => QuantifierType::Lazy];
        yield 'plus after a braced quantifier and a comment' => ['pattern' => '/a{2}(?#c)+/', 'quantifier' => '{2}', 'type' => QuantifierType::Possessive];
        yield 'modifier after two comments' => ['pattern' => '/a*(?#c)(?#d)?/', 'quantifier' => '*', 'type' => QuantifierType::Lazy];
        yield 'modifier after a quantifier that followed a comment' => ['pattern' => '/a(?#c)*(?#d)+/', 'quantifier' => '*', 'type' => QuantifierType::Possessive];
        yield 'extended: modifier after spaces' => ['pattern' => '/a(?#c) * +/x', 'quantifier' => '*', 'type' => QuantifierType::Possessive];
        yield 'extended: line comments before a lazy star' => ['pattern' => "/a (?#c)\n #d\n *?/x", 'quantifier' => '*', 'type' => QuantifierType::Lazy];
        yield 'extended: modifier after a comment and a space' => ['pattern' => '/a*(?#c) +/x', 'quantifier' => '*', 'type' => QuantifierType::Possessive];
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideMatchingPatterns(): iterable
    {
        yield '/^a*(?#c)+b$/' => ['pattern' => '/^a*(?#c)+b$/', 'subjects' => ['b', 'ab', 'aab', 'aa']];
        yield '/a*(?#c)?/' => ['pattern' => '/a*(?#c)?/', 'subjects' => ['', 'a', 'aaa']];
        yield '/^a{1,3}(?#c)?/' => ['pattern' => '/^a{1,3}(?#c)?/', 'subjects' => ['a', 'aaa', 'b']];
        yield '/^a(?#c)*(?#d)+b$/' => ['pattern' => '/^a(?#c)*(?#d)+b$/', 'subjects' => ['b', 'aab', 'a']];
        yield '/^(a)(?#c)+\\1$/' => ['pattern' => '/^(a)(?#c)+\\1$/', 'subjects' => ['aa', 'aaa', 'a']];
        yield '/^\\d(?#c){2,}$/' => ['pattern' => '/^\\d(?#c){2,}$/', 'subjects' => ['1', '12', '123']];
        yield 'extended: /^a #c\\n*$/x' => ['pattern' => "/^a #c\n*$/x", 'subjects' => ['', 'a', 'aaa', 'b']];
        yield 'extended: /^a(?#c)#d\\n*$/x' => ['pattern' => "/^a(?#c)#d\n*$/x", 'subjects' => ['', 'a', 'aaa']];
        yield 'extended: /^a(?#c)* b$/x' => ['pattern' => '/^a(?#c)* b$/x', 'subjects' => ['b', 'ab', 'aab', 'a']];
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideRejectedPatterns(): iterable
    {
        yield '/(?#c)*/' => ['pattern' => '/(?#c)*/', 'offset' => 6];
        yield '/(?#c)*+/' => ['pattern' => '/(?#c)*+/', 'offset' => 6];
        yield '/(?#c){2}?/' => ['pattern' => '/(?#c){2}?/', 'offset' => 8];
        yield '/(?i)(?#c)*/' => ['pattern' => '/(?i)(?#c)*/', 'offset' => 10];
        yield '/^(?#c)*/' => ['pattern' => '/^(?#c)*/', 'offset' => 7];
        yield '/$(?#c){2}/' => ['pattern' => '/$(?#c){2}/', 'offset' => 9];
        yield '/\\b(?#c)?/' => ['pattern' => '/\\b(?#c)?/', 'offset' => 8];
        yield '/(?#c)(?#d)+/' => ['pattern' => '/(?#c)(?#d)+/', 'offset' => 11];
        yield '/a|(?#c)*/' => ['pattern' => '/a|(?#c)*/', 'offset' => 8];
        yield '/((?#c)*)/' => ['pattern' => '/((?#c)*)/', 'offset' => 7];
        yield '/a*(?#c)*/' => ['pattern' => '/a*(?#c)*/', 'offset' => 8];
        yield '/a*(?#c){2}/' => ['pattern' => '/a*(?#c){2}/', 'offset' => 10];
        yield '/a{2}(?#c){3}/' => ['pattern' => '/a{2}(?#c){3}/', 'offset' => 12];
        yield '/a*?(?#c)+/' => ['pattern' => '/a*?(?#c)+/', 'offset' => 9];
        yield '/a*+(?#c)?/' => ['pattern' => '/a*+(?#c)?/', 'offset' => 9];
        yield '/a*(?#c)++/' => ['pattern' => '/a*(?#c)++/', 'offset' => 9];
        yield '/a*(?#c)??/' => ['pattern' => '/a*(?#c)??/', 'offset' => 9];
        yield '/a(?#c)*(?#d)*/' => ['pattern' => '/a(?#c)*(?#d)*/', 'offset' => 13];
        yield 'extended: /#c\\n*/x' => ['pattern' => "/#c\n*/x", 'offset' => 4];
        yield 'extended: /^#c\\n*/x' => ['pattern' => "/^#c\n*/x", 'offset' => 5];
        yield 'extended: /(?x)#c\\n*/' => ['pattern' => "/(?x)#c\n*/", 'offset' => 8];
        yield 'extended: /a*(?#c)+ +/x' => ['pattern' => '/a*(?#c)+ +/x', 'offset' => 10];
    }
}
