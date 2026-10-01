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

use PHPRegex\Explain\AsciiTreeRenderer;
use PHPRegex\Explain\Highlighter\ConsoleHighlighter;
use PHPRegex\Explain\Highlighter\HtmlHighlighter;
use PHPRegex\Explain\HtmlExplainer;
use PHPRegex\Explain\MermaidRenderer;
use PHPRegex\Explain\RailroadSvgRenderer;
use PHPRegex\Explain\TextExplainer;
use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Generator\TestCaseGenerator;
use PHPRegex\Parser\Analysis\ComplexityScorer;
use PHPRegex\Parser\Analysis\MetricsCollector;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\NodeVisitorInterface;
use PHPRegex\Parser\Printer\NodeDumper;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What each display visitor shows of "(?[ \d - ( [3] & ![a] ) ^ \x61 | [b] ])",
 * every operator in it: the expected texts are in the fixture, read once and
 * checked by hand.
 */
final class ExtendedCharClassDisplayTest extends TestCase
{
    private const PATTERN = '/(?[ \d - ( [3] & ![a] ) ^ \x61 | [b] ])/';

    /**
     * @param NodeVisitorInterface<string> $visitor
     */
    #[Test]
    #[DataProvider('provideDisplays')]
    public function test_the_display_shows_every_operation(string $name, NodeVisitorInterface $visitor): void
    {
        /** @var array<string, string> $expected */
        $expected = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/extended-class-display.json'), true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame($expected[$name], $this->tree()->accept($visitor));
    }

    /**
     * @return iterable<string, array{string, NodeVisitorInterface<string>}>
     */
    public static function provideDisplays(): iterable
    {
        yield 'dump' => ['Dumper', new NodeDumper()];
        yield 'ASCII tree' => ['AsciiTree', new AsciiTreeRenderer()];
        yield 'explanation' => ['Explain', new TextExplainer()];
        yield 'HTML explanation' => ['HtmlExplain', new HtmlExplainer()];
        yield 'Mermaid graph' => ['Mermaid', new MermaidRenderer()];
        yield 'HTML highlight' => ['Html', new HtmlHighlighter()];
    }

    #[Test]
    public function test_the_console_highlight_colours_operators_and_parentheses(): void
    {
        $highlighted = $this->tree()->accept(new ConsoleHighlighter());
        $this->assertIsString($highlighted);

        $parts = [];
        preg_match_all('/\e\[([\d;]+)m([^\e]*)\e\[0m/', $highlighted, $tokens, \PREG_SET_ORDER);
        foreach ($tokens as [, $colour, $text]) {
            $parts[] = $colour.' '.$text;
        }

        $group = '38;2;197;134;192';
        $meta = '38;2;86;156;214';
        foreach (['(?[', '(', ')', '])'] as $parenthesis) {
            $this->assertContains($group.' '.$parenthesis, $parts);
        }
        foreach (['-', '&', '!', '^', '|'] as $operator) {
            $this->assertContains($meta.' '.$operator, $parts);
        }
        $this->assertSame('(?[ \d - ( [3] & ![a] ) ^ \x61 | [b] ])', preg_replace('/\e\[[\d;]*+m/', '', $highlighted));
    }

    #[Test]
    public function test_a_class_after_other_text_keeps_its_layout(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.45']);
        $highlighted = $regex->parse('/xy(?[ \\d - [3] ])z/')->accept(new ConsoleHighlighter());
        $this->assertIsString($highlighted);

        $this->assertSame('xy(?[ \\d - [3] ])z', preg_replace('/\e\[[\d;]*+m/', '', $highlighted));
    }

    #[Test]
    public function test_a_lone_operand_is_explained_in_line(): void
    {
        $explained = Regex::create(['cache' => null, 'pcre_version' => '10.45'])->parse('/(?[ \\d ])/')->accept(new TextExplainer());

        $this->assertStringContainsString("\n  Extended character class: one character of Character Type: A digit: [0-9]", $explained);
    }

    #[Test]
    public function test_the_railroad_diagram_labels_every_operation(): void
    {
        $svg = $this->tree()->accept(new RailroadSvgRenderer());
        $this->assertIsString($svg);
        preg_match_all('/<text[^>]*>([^<]*)<\/text>/', $svg, $labels);

        $this->assertSame([
            'ExtendedCharClass', 'ClassSetOperation (union)', 'ClassSetOperation', '(symmetric_difference)',
            'ClassSetOperation', '(difference)', 'CharType (\d)', 'ClassSetOperation', '(intersection)',
            "Literal ('3')", 'ClassSetOperation', '(complement)', "Literal ('a')", 'CharLiteral (\x61)',
            "Literal ('b')", 'CharClass', 'CharClass', 'CharClass',
        ], $labels[1]);
    }

    #[Test]
    public function test_the_counts_see_every_operand(): void
    {
        $this->assertSame(14, $this->tree()->accept(new ComplexityScorer()));
        $this->assertSame([
            'counts' => [
                'RegexNode' => 1,
                'ExtendedCharClassNode' => 1,
                'ClassSetOperationNode' => 5,
                'CharTypeNode' => 1,
                'CharClassNode' => 3,
                'LiteralNode' => 3,
                'CharLiteralNode' => 1,
            ],
            'total' => 15,
            'maxDepth' => 9,
        ], $this->tree()->accept(new MetricsCollector()));
    }

    #[Test]
    public function test_test_cases_are_the_first_printable_members_and_non_members(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.45']);
        if (false === @preg_match('/(?[ \d ])/', '')) {
            $this->assertSame(['matching' => [], 'non_matching' => []], $regex->parse('/(?[ [ab] - [a] ])/')->accept(new TestCaseGenerator()));

            return;
        }

        // Printable ASCII is scanned, from the space to the tilde.
        $this->assertSame(['matching' => ['b'], 'non_matching' => [' ', '!', '"']], $regex->parse('/(?[ [ab] - [a] ])/')->accept(new TestCaseGenerator()));
        $this->assertSame(['matching' => [], 'non_matching' => [' ', '!', '"']], $regex->parse('/(?[ [\x1f\x7f] ])/')->accept(new TestCaseGenerator()));
    }

    #[Test]
    public function test_samples_come_from_the_engine_not_from_the_left_operand(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.45']);
        if (false === @preg_match('/(?[ \d ])/', '')) {
            $this->assertContains($regex->parse('/(?[ [ab] - [a] ])/')->accept(new SampleGenerator()), ['a', 'b']);

            return;
        }

        $difference = $regex->parse('/(?[ [ab] - [a] ])/');
        $slash = $regex->parse('#(?[ [/a] - [a] ])#');
        $generator = new SampleGenerator();
        for ($seed = 0; $seed < 8; $seed++) {
            $generator->setSeed($seed);
            $this->assertSame('b', $difference->accept($generator));
            $this->assertSame('/', $slash->accept($generator));
        }
    }

    private function tree(): RegexNode
    {
        return Regex::create(['cache' => null, 'pcre_version' => '10.45'])->parse(self::PATTERN);
    }
}
