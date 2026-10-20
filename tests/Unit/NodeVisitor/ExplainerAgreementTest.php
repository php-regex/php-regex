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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Explain\HtmlExplainer;
use PHPRegex\Explain\TextExplainer;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PcreGates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The text and the HTML explanations describe each construct in the same
 * words: a character type, an anchor, an assertion, a Unicode property, a
 * literal, a quantifier and a character code.
 */
final class ExplainerAgreementTest extends TestCase
{
    use PcreGates;

    #[Test]
    #[DataProvider('provideDescriptions')]
    public function test_text_and_html_describe_a_construct_alike(string $pattern, string $description): void
    {
        $tree = RegexParser::create()->parse($pattern);
        $text = (string) $tree->accept(new TextExplainer());
        $html = html_entity_decode((string) $tree->accept(new HtmlExplainer()), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        $this->assertStringContainsString($description, $text, 'text');
        $this->assertStringContainsString($description, $html, 'html');
    }

    /**
     * @return iterable<string, array{pattern: string, description: string}>
     */
    public static function provideDescriptions(): iterable
    {
        yield 'a digit' => ['pattern' => '/\d/', 'description' => 'A digit: [0-9]'];
        yield 'a horizontal space' => ['pattern' => '/\h/', 'description' => 'A horizontal whitespace character: [ \t\xA0\u1680\u180e\u2000-\u200a\u202f\u205f\u3000]'];
        yield 'a linebreak' => ['pattern' => '/\R/', 'description' => 'Any Unicode linebreak sequence'];
        yield 'a grapheme cluster' => ['pattern' => '/\X/', 'description' => 'An extended grapheme cluster: a character with the marks that combine with it, or a \r\n pair'];
        yield 'anything but a newline' => ['pattern' => '/\N/', 'description' => 'Any character but a newline (\n), whatever s says'];
        yield 'one byte' => ['pattern' => '/\C/', 'description' => 'One code unit: a single byte, a newline included'];
        yield 'the start of the subject' => ['pattern' => '/^a/', 'description' => 'the start of the subject'];
        yield 'the start of a line' => ['pattern' => '/^a/m', 'description' => 'the start of a line: the start of the subject, or just after a newline (\n) that does not end it'];
        yield 'the end of the subject' => ['pattern' => '/a$/', 'description' => 'the end of the subject, or just before a newline (\n) that ends it'];
        yield 'the end of a line' => ['pattern' => '/a$/m', 'description' => 'the end of a line: just before a newline (\n), or at the end of the subject'];
        yield 'the end but a terminator' => ['pattern' => '/a\Z/', 'description' => 'the end of the subject, or just before a newline (\n) that ends it'];
        yield 'a word boundary' => ['pattern' => '/\ba/', 'description' => 'a word boundary'];
        yield 'the previous match' => ['pattern' => '/\Ga/', 'description' => 'where the match attempt starts: the offset the search starts at, or the end of the previous match'];
        yield 'a known property' => ['pattern' => '/\p{Lu}/u', 'description' => 'An uppercase letter (category)'];
        yield 'a negated property' => ['pattern' => '/\P{Lu}/u', 'description' => 'Any character except an uppercase letter (category)'];
        yield 'an unknown property' => ['pattern' => '/\p{Greek}/u', 'description' => 'any character matching "Greek"'];
        yield 'a negated unknown property' => ['pattern' => '/\P{Greek}/u', 'description' => 'any character non-matching "Greek"'];
        yield 'a space' => ['pattern' => '/ /', 'description' => "' ' (space)"];
        yield 'a tab' => ['pattern' => "/\t/", 'description' => "'\\t' (tab)"];
        yield 'a raw byte' => ['pattern' => "/\x01/", 'description' => "'\\x01'"];
        yield 'a printable character' => ['pattern' => '/q/', 'description' => "'q'"];
        yield 'a star' => ['pattern' => '/a*/', 'description' => 'zero or more times'];
        yield 'a lazy plus' => ['pattern' => '/a+?/', 'description' => 'one or more times (as few as possible)'];
        yield 'a possessive question mark' => ['pattern' => '/a?+/', 'description' => 'once or not at all (and do not backtrack)'];
        yield 'an exact count' => ['pattern' => '/a{3}/', 'description' => 'exactly 3 times'];
        yield 'an open count' => ['pattern' => '/a{2,}/', 'description' => 'at least 2 times'];
        yield 'a bounded count' => ['pattern' => '/a{2,5}/', 'description' => 'at least 2 but not more than 5 times'];
        yield 'a hexadecimal code' => ['pattern' => '/\x{1F600}/u', 'description' => 'Character with hexadecimal value 0x1F600'];
        yield 'a short hexadecimal code' => ['pattern' => '/\x41/', 'description' => 'Character with hexadecimal value 0x41'];
        yield 'an octal code' => ['pattern' => '/\o{101}/', 'description' => 'Character with octal value 0101'];
        yield 'a legacy octal code' => ['pattern' => '/\101/', 'description' => 'Character with octal value 0101'];
    }

    /**
     * Every line of the text explanation, a meaning, a heading or the
     * description of a repetition, is said in the same words in the HTML
     * one, on the page or in a tooltip: neither format words a construct on
     * its own.
     */
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_every_line_of_the_text_is_said_in_the_html(string $pattern): void
    {
        // An extended class needs PCRE2 10.47, a substring scan 10.45.
        $this->requiresCompile($pattern);
        $tree = RegexParser::create()->parse($pattern);
        $text = (string) $tree->accept(new TextExplainer());
        $markup = (string) $tree->accept(new HtmlExplainer());
        // What the page shows, and the tooltips it holds.
        $html = html_entity_decode(strip_tags($markup)."\n".$markup, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            // The words that only lay the text out.
            if (\in_array($line, ['EITHER', 'OR', 'ELSE', 'IF', 'THEN'], true) || str_starts_with($line, 'End ')) {
                continue;
            }

            $this->assertStringContainsString((string) LibraryPcre::replace('/^IF \((.*)\) THEN$/', 'IF ($1)', $line), $html, $pattern);
        }
    }

    /**
     * The branches of an alternation are items of one list, each holding its
     * own: "OR" does not stand inside the branch before it.
     */
    #[Test]
    public function test_the_html_lists_the_branches_of_an_alternation_side_by_side(): void
    {
        $html = (string) RegexParser::create()->parse('/(a|b|c)/')->accept(new HtmlExplainer());
        $this->assertNotSame('', $html);
        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($html));

        $branches = (new \DOMXPath($document))->query('//li[strong="EITHER:" or strong="OR:"]');
        $this->assertNotFalse($branches);
        $this->assertCount(3, $branches);
        $parents = [];
        foreach ($branches as $branch) {
            $this->assertInstanceOf(\DOMElement::class, $branch);
            $this->assertInstanceOf(\DOMElement::class, $branch->parentNode);
            $parents[] = $branch->parentNode;
            // Its own branch only: one literal.
            $this->assertSame(1, $branch->getElementsByTagName('li')->length);
        }
        $this->assertSame($parents[0], $parents[1]);
        $this->assertSame($parents[0], $parents[2]);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePatterns(): iterable
    {
        foreach ([
            '/^(?<year>\d{4})-(\d{2})$/m', '/(a|b)*c+?/', '/(?:ab){2,5}/', '/(?>a)(?=b)(?!c)(?<=d)(?<!e)/', '/(?|(a)|(b))/',
            '/(?i:a)(?i)b/', '/(?s).\N\X/u', '/[^a-z\d[:alpha:]]/', '/\x41\x{1F600}\101\o{101}\cA\N{U+41}/u', '/\Ka\b\B\A\z\Z\G/',
            '/(a)(?R)(?1)(?&n)(?<n>b)/', '/(*LIMIT_MATCH=10)a(*FAIL)|(?C1)(?C"x")(?C)b/', '/a(?#note)b/', '/(a)(?(1)b|c)(?(?=x)y)/',
            '/(?(DEFINE)(?<d>\d))(?&d)/', '/(*sr:\d+)(*asr:\w)/', '/(a)(*scs:(1)a)/', '/(?[ [a-z] - [aeiou] ])/', '/\p{Lu}\P{L}\p{Greek}/u',
            '/(a)\1\k<n>(?<n>b)\g{-2}/', '/é[à-é]/u', "/a\tb c/",
        ] as $pattern) {
            yield $pattern => ['pattern' => $pattern];
        }
    }
}
