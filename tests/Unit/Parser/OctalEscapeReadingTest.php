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

namespace PhpRegex\Tests\Unit\Parser;

use PhpRegex\Parser\Analysis\LengthRangeCalculator;
use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharLiteralType;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "\NN" is a back reference when at least NN groups opened before it, or
 * when it is below 10 or starts with 8 or 9; otherwise PCRE reads an octal
 * escape of up to three octal digits and the digits left as text
 * (pcre2_compile.c, "s <= bracount": the groups met so far, not the
 * pattern's total). PHP decides every match below.
 */
final class OctalEscapeReadingTest extends TestCase
{
    #[Test]
    public function test_a_number_past_the_groups_opened_so_far_is_an_octal_escape(): void
    {
        $groups = str_repeat('(a)', 11);
        $pattern = '/^\\11'.$groups.'$/';
        $this->assertSame(1, preg_match($pattern, "\t".str_repeat('a', 11)));

        $escape = $this->nodesOf($pattern)[1];
        $this->assertInstanceOf(CharLiteralNode::class, $escape);
        $this->assertSame(CharLiteralType::OctalLegacy, $escape->type);
        $this->assertSame(9, $escape->codePoint);
    }

    #[Test]
    public function test_a_number_within_the_groups_opened_so_far_is_a_reference(): void
    {
        $pattern = '/^'.str_repeat('(a)', 11).'\\11$/';
        $this->assertSame(1, preg_match($pattern, str_repeat('a', 12)));

        $this->assertInstanceOf(BackrefNode::class, $this->nodesOf($pattern)[12]);
    }

    #[Test]
    public function test_digits_past_the_octal_ones_are_text_and_take_the_quantifier(): void
    {
        $this->assertSame(1, preg_match('/^\\1000*$/', '@'));
        $this->assertSame(1, preg_match('/^\\1000*$/', '@000'));

        $nodes = $this->nodesOf('/^\\1000*$/');
        $escape = $nodes[1];
        $this->assertInstanceOf(SequenceNode::class, $escape);
        $this->assertInstanceOf(CharLiteralNode::class, $escape->children[0]);
        $this->assertSame(0x40, $escape->children[0]->codePoint);
        $this->assertInstanceOf(QuantifierNode::class, $escape->children[1]);
        $this->assertInstanceOf(LiteralNode::class, $escape->children[1]->node);
        $this->assertSame('0', $escape->children[1]->node->value);
    }

    #[Test]
    public function test_a_reference_too_big_to_read_is_text_before_pcre2_10_45(): void
    {
        // PCRE2 10.44 reads no reference past 214748363: "\8" or "\9" is then
        // the digit, and the rest text (PHP 8.2 matches "a800000000b").
        // 10.45 refuses the number, as it does for any past 65535.
        foreach (['/a\\800000000b/', '/a\\914748364b/', '/(a)\\9147483640{2}/'] as $pattern) {
            $old = Regex::create(['cache' => null, 'pcre_version' => '10.44'])->validate($pattern);
            $this->assertTrue($old->isValid, $pattern.': '.$old->error);
            $this->assertFalse(Regex::create(['cache' => null, 'pcre_version' => '10.45'])->validate($pattern)->isValid, $pattern);
        }

        $this->assertFalse(Regex::create(['cache' => null, 'pcre_version' => '10.44'])->validate('/a\\89999999b/')->isValid);

        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.44']);
        $this->assertSame([11, 11], $regex->parse('/^a\\800000000b$/')->accept(new LengthRangeCalculator()));
        $this->assertSame([12, 12], $regex->parse('/^(a)\\9147483640{2}$/')->accept(new LengthRangeCalculator()));
        $digits = $regex->parse('/a\\800000000b/')->pattern;
        $this->assertInstanceOf(SequenceNode::class, $digits);
        $split = $digits->children[1];
        $this->assertInstanceOf(SequenceNode::class, $split);
        $this->assertSame([1, 3, 3, 11], [$split->children[0]->getStartPosition(), $split->children[0]->getEndPosition(), $split->children[1]->getStartPosition(), $split->children[1]->getEndPosition()]);

        // Written back as the digits it matches, which every release reads alike.
        $this->assertSame('/^a800000000b$/', $regex->parse('/^a\\800000000b$/')->accept(new PatternPrinter()));
    }

    #[Test]
    public function test_the_quantifier_takes_the_last_of_several_digits_left(): void
    {
        // "\10000*" is "@", "0", then "0*".
        $this->assertSame(1, preg_match('/^\\10000*$/', '@0'));
        $this->assertSame(0, preg_match('/^\\10000*$/', '@'));

        $escape = $this->nodesOf('/^\\10000*$/')[1];
        $this->assertInstanceOf(SequenceNode::class, $escape);
        $text = $escape->children[1];
        $this->assertInstanceOf(SequenceNode::class, $text);
        $this->assertInstanceOf(LiteralNode::class, $text->children[0]);
        $this->assertSame('0', $text->children[0]->value);
        $this->assertInstanceOf(QuantifierNode::class, $text->children[1]);
    }

    #[Test]
    public function test_a_body_read_apart_counts_the_groups_opened_before_it(): void
    {
        $pattern = '/'.str_repeat('(a)', 11).'(*pla:\\11)/';

        $body = Regex::create(['cache' => null])->parse($pattern)->pattern;
        $this->assertInstanceOf(SequenceNode::class, $body);
        $this->assertStringContainsString('BackrefNode', $this->classesUnder($body));
    }

    #[Test]
    #[DataProvider('provideReferences')]
    public function test_a_reference_stays_a_reference(string $pattern): void
    {
        $this->assertStringContainsString('BackrefNode', $this->classesUnder(Regex::create(['cache' => null])->parse($pattern)->pattern));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideReferences(): iterable
    {
        yield 'below ten' => ['pattern' => '/(a)\\1/'];
        yield 'starting with 8' => ['pattern' => '/\\81/'];
        yield 'within a branch reset' => ['pattern' => '/(?|(a)|(b))(c)\\2/'];
    }

    #[Test]
    #[DataProvider('provideBehaviour')]
    public function test_every_reading_behaves_as_the_pattern(string $pattern, string $subject, int $length): void
    {
        $this->assertSame(1, preg_match($pattern, $subject), $pattern);

        $regex = Regex::create(['cache' => null]);
        $ast = $regex->parse($pattern);

        $this->assertTrue($regex->validate($pattern)->isValid, $pattern);
        $this->assertSame(1, preg_match($ast->accept(new PatternPrinter()), $subject), $pattern);
        $this->assertSame([$length, $length], $ast->accept(new LengthRangeCalculator()), $pattern);
        $this->assertSame(1, preg_match($pattern, $regex->generate($pattern)), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, length: int}>
     */
    public static function provideBehaviour(): iterable
    {
        yield 'three digits' => ['pattern' => '/^\\101B$/', 'subject' => 'AB', 'length' => 2];
        yield 'two digits' => ['pattern' => '/^x\\12y$/', 'subject' => "x\ny", 'length' => 3];
        yield 'one octal digit then a digit' => ['pattern' => '/^(a)\\18$/', 'subject' => "a\x018", 'length' => 3];
        yield 'octal then text' => ['pattern' => '/^\\1000$/', 'subject' => '@0', 'length' => 2];
        yield 'within a branch reset count' => ['pattern' => '/^(?|(a)|(b))\\12$/', 'subject' => "a\n", 'length' => 2];
    }

    #[Test]
    public function test_an_octal_escape_past_a_byte_is_refused_without_utf(): void
    {
        // "octal value is greater than \377 in 8-bit non-UTF-8 mode at offset 5".
        $this->assertSame(5, Regex::create(['cache' => null])->validate('/^\\400/')->offset);
        $this->assertTrue(Regex::create(['cache' => null])->validate('/^\\400/u')->isValid);
    }

    /**
     * @return array<\PhpRegex\Parser\Node\NodeInterface>
     */
    private function nodesOf(string $pattern): array
    {
        $node = Regex::create(['cache' => null])->parse($pattern)->pattern;
        $this->assertInstanceOf(SequenceNode::class, $node);

        return $node->children;
    }

    private function classesUnder(NodeInterface $node): string
    {
        return print_r($node, true);
    }
}
