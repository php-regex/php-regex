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
use PHPRegex\Parser\RegexParser;
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
}
