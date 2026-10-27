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

use PHPRegex\Explain\RailroadSvgRenderer;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A box of the railroad diagram holds the text it matches: a literal that
 * stands alone, as a branch, the body of a group, what a quantifier repeats
 * or the end of a range, is drawn as its text, as the same literal is in a
 * run of a sequence, and a character written as an escape as written.
 */
final class RailroadTextLabelTest extends TestCase
{
    /**
     * @param list<string> $labels
     */
    #[Test]
    #[DataProvider('provideLabels')]
    public function test_a_literal_is_drawn_as_its_text_wherever_it_stands(string $pattern, string $subject, array $labels): void
    {
        // Oracle: the pattern matches the text its boxes show.
        $this->assertSame(1, preg_match($pattern, $subject));

        $svg = (string) RegexParser::create(['cache' => null])->parse($pattern)->accept(new RailroadSvgRenderer());
        $this->assertNotSame('', $svg);
        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($svg));

        $shown = [];
        foreach ($document->getElementsByTagName('text') as $element) {
            if ('label' === $element->getAttribute('class')) {
                $shown[] = $element->textContent;
            }
        }

        $this->assertSame($labels, $shown);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, labels: list<string>}>
     */
    public static function provideLabels(): iterable
    {
        yield 'a branch of one character' => ['pattern' => '/(a|bc)+d/', 'subject' => 'abcd', 'labels' => ['a', 'bc', 'd']];
        yield 'the body of a group' => ['pattern' => '/(x)y/', 'subject' => 'xy', 'labels' => ['x', 'y']];
        yield 'a literal repeated' => ['pattern' => '/a{2}b/', 'subject' => 'aab', 'labels' => ['a', 'b']];
        yield 'a branch left empty' => ['pattern' => '/a|/', 'subject' => '', 'labels' => ['a', '(empty)']];
        yield 'a backslash alone' => ['pattern' => '/(\\\\)/', 'subject' => '\\', 'labels' => ['\\\\']];
        yield 'an escape alone, as written' => ['pattern' => '/(\x41)/', 'subject' => 'A', 'labels' => ['\x41']];
        yield 'an escape repeated, as written' => ['pattern' => '/\x41+/', 'subject' => 'AA', 'labels' => ['\x41']];
        yield 'the ends of a range' => ['pattern' => '/[a-z]/', 'subject' => 'q', 'labels' => ['Range', 'a', 'z']];
        yield 'the members of a class' => ['pattern' => '/[ab\x63]/', 'subject' => 'c', 'labels' => ['a', 'b', '\x63']];
    }
}
