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
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How each format lays a construct out: the HTML shows it as written beside
 * its meaning, the text gives each line the indentation of its level.
 */
final class ExplainLayoutTest extends TestCase
{
    #[Test]
    #[DataProvider('provideWrittenConstructs')]
    public function test_the_html_shows_a_construct_as_written_beside_its_meaning(string $pattern, string $item): void
    {
        $this->assertStringContainsString($item, (string) RegexParser::create()->parse($pattern)->accept(new HtmlExplainer()));
    }

    /**
     * @return iterable<string, array{pattern: string, item: string}>
     */
    public static function provideWrittenConstructs(): iterable
    {
        yield 'a character type' => ['pattern' => '/\d/', 'item' => '<li><span title="Character Type: A digit: [0-9]">Character Type: <strong>\d</strong> (A digit: [0-9])</span></li>'];
        yield 'an assertion' => ['pattern' => '/\b/', 'item' => '<li><span title="Assertion: a word boundary">Assertion: <strong>\b</strong> (a word boundary)</span></li>'];
        yield 'a non-word boundary' => ['pattern' => '/\B/', 'item' => 'Assertion: <strong>\B</strong> (a non-word boundary)'];
        yield 'a control character' => ['pattern' => '/\cA/', 'item' => 'Control Character: <strong>\cA</strong> (the character 0x01)'];
        yield 'a property' => ['pattern' => '/\p{Lu}/u', 'item' => 'Unicode Property: <strong>\p{Lu}</strong> (An uppercase letter (category))'];
        yield 'a negated property' => ['pattern' => '/\P{Lu}/u', 'item' => 'Unicode Property: <strong>\P{Lu}</strong> (Any character except an uppercase letter (category))'];
        yield 'a property negated inside its braces' => ['pattern' => '/\p{^Lu}/u', 'item' => 'Unicode Property: <strong>\P{Lu}</strong>'];
        yield 'a backreference' => ['pattern' => '/(a)\1/', 'item' => 'Backreference: <strong>\1</strong> (whatever the capturing group &quot;1&quot; matched)'];
        yield 'a named backreference' => ['pattern' => '/(?<n>a)\k<n>/', 'item' => 'Backreference: <strong>\k&lt;n&gt;</strong> (whatever the capturing group &quot;n&quot; matched)'];
        yield 'a relative backreference' => ['pattern' => '/(a)\g{-1}/', 'item' => 'Backreference: <strong>\g{-1}</strong> (whatever the capturing group 1 back from here matched)'];
        yield 'a call to a group' => ['pattern' => '/(a)(?1)/', 'item' => 'Subroutine Call: <strong>(?1)</strong> (recurses to group 1)'];
        yield 'a call to the pattern' => ['pattern' => '/a(?R)?/', 'item' => 'Subroutine Call: <strong>(?R)</strong> (recurses to the entire pattern)'];
        yield 'a call by name' => ['pattern' => '/(?<n>a)(?&n)/', 'item' => 'Subroutine Call: <strong>(?&amp;n)</strong> (recurses to group n)'];
        yield 'a call by name, Python style' => ['pattern' => '/(?<n>a)(?P>n)/', 'item' => 'Subroutine Call: <strong>(?P&gt;n)</strong>'];
        yield 'a call in Oniguruma style' => ['pattern' => '/(a)\g<1>/', 'item' => 'Subroutine Call: <strong>\g&lt;1&gt;</strong> (recurses to group 1)'];
        yield 'a callout with a number' => ['pattern' => '/(?C12)a/', 'item' => 'Callout: <strong>(?C12)</strong> (passes control to user function with argument 12)'];
        yield 'a named character' => ['pattern' => '/\N{U+41}/u', 'item' => '<strong>\N{U+41}</strong> (Unicode named character: U+41)'];
        yield 'a dot' => ['pattern' => '/./', 'item' => 'Wildcard: <strong>.</strong> (any character but a newline (\n))'];
        yield 'an anchor' => ['pattern' => '/^/', 'item' => 'Anchor: <strong>^</strong> (the start of the subject)'];
        yield 'a condition' => ['pattern' => '/(a)(?(1)b)/', 'item' => "<li><strong>IF</strong> (the capturing group &quot;1&quot; has matched) <strong>THEN:</strong>\n<ul><li><span title=\"&#039;b&#039;\"><strong>&#039;b&#039;</strong></span></li></ul>\n</li>"];
        yield 'a condition with an else branch' => ['pattern' => '/(a)(?(1)b|c)/', 'item' => "<strong>ELSE:</strong>\n<ul><li><span title=\"&#039;c&#039;\"><strong>&#039;c&#039;</strong></span></li></ul>\n</li></ul>\n</div>"];
    }

    #[Test]
    public function test_a_lookaround_condition_is_a_block_under_its_if(): void
    {
        $this->assertSame(
            "Regex matches\n  IF\n    Positive lookahead\n      'a'\n    End group\n  THEN\n    'b'\n  ELSE\n    'c'",
            RegexParser::create()->parse('/(?(?=a)b|c)/')->accept(new TextExplainer()),
        );
    }

    #[Test]
    public function test_a_setting_after_a_condition_holds_to_the_end_of_the_pattern(): void
    {
        $text = (string) RegexParser::create()->parse('/(?(?=a)a)(?i)b/')->accept(new TextExplainer());

        $this->assertStringContainsString("\n  Inline flags 'i' (from here to the end of the pattern)\n", $text);
    }

    #[Test]
    public function test_a_character_type_the_table_does_not_hold_is_named(): void
    {
        $this->assertSame('Character Type: unknown (\K)', (new CharTypeNode('K', 0, 2))->accept(new TextExplainer()));
    }

    #[Test]
    public function test_an_empty_sequence_takes_no_line(): void
    {
        $group = new GroupNode(new SequenceNode([], 1, 1), GroupType::Capturing, null, null, 0, 2);

        $this->assertSame("Capturing group\nEnd group", $group->accept(new TextExplainer()));
    }

    /**
     * A node explained on its own stands where no flag is in force: no u, no
     * m, no D, no s.
     */
    #[Test]
    public function test_a_lone_node_is_explained_with_no_flag_in_force(): void
    {
        $explainer = new TextExplainer();

        $this->assertSame("'\\xC3\\xA9'", (new LiteralNode('é', 0, 2))->accept($explainer));
        $this->assertSame('Anchor: the end of the subject, or just before a newline (\n) that ends it', (new AnchorNode('$', 0, 1))->accept($explainer));
        $this->assertSame('Character Type: A digit: [0-9]', (new CharTypeNode('d', 0, 2))->accept($explainer));
        $this->assertSame('Wildcard: any character but a newline (\n)', (new DotNode(0, 1))->accept($explainer));
    }
}
