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

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Explain\HtmlExplainer;
use PhpRegex\Explain\TextExplainer;
use PhpRegex\Linter\PatternLinter;
use PhpRegex\Linter\Rule\RuleViolation;
use PhpRegex\Parser\Analysis\LiteralExtractor;
use PhpRegex\Parser\Analysis\MetricsCollector;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Parser\Node\ScriptRunNode;
use PhpRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "(*sr:...)" still matches what it holds: a script run only asks that the
 * characters come from one script. What reads the pattern reads inside it.
 */
final class ScriptRunContentTest extends TestCase
{
    #[Test]
    public function test_the_linter_reads_inside_a_script_run(): void
    {
        $this->assertSame($this->issueIds('/[aa]/'), $this->issueIds('/(*sr:[aa])/'));
        $this->assertNotSame([], $this->issueIds('/(*sr:[aa])/'));
    }

    #[Test]
    public function test_the_metrics_count_what_a_script_run_holds(): void
    {
        $counts = $this->tree('/(*sr:a+)/')->accept(new MetricsCollector())['counts'];

        $this->assertSame(1, $counts['QuantifierNode'] ?? 0);
        $this->assertSame(1, $counts['LiteralNode'] ?? 0);
    }

    #[Test]
    public function test_the_explanation_says_what_a_script_run_holds(): void
    {
        $this->assertStringContainsString("'a' (one or more times)", $this->tree('/(*sr:a+)/')->accept(new TextExplainer()));
        $this->assertStringContainsString('one or more times', $this->tree('/(*sr:a+)/')->accept(new HtmlExplainer()));
    }

    #[Test]
    public function test_an_atomic_script_run_is_named_so(): void
    {
        $this->assertSame(
            "Regex matches\n  Atomic script run: every character from one script\n    'a'\n    'b'\n  End script run",
            $this->tree('/(*asr:ab)/')->accept(new TextExplainer()),
        );
        $this->assertStringContainsString('<strong>Atomic script run</strong>', $this->tree('/(*asr:ab)/')->accept(new HtmlExplainer()));
        $this->assertStringContainsString('<strong>Script run</strong>', $this->tree('/(*sr:ab)/')->accept(new HtmlExplainer()));
    }

    /**
     * The parser always gives a script run its content; one built without it
     * is explained by its name alone.
     */
    #[Test]
    public function test_a_script_run_without_content_is_named_alone(): void
    {
        $node = new ScriptRunNode('', 0, 6);

        $this->assertSame('Script run: every character from one script', $node->accept(new TextExplainer()));
        $this->assertSame('<li><strong>Script run</strong>: every character from one script</li>', $node->accept(new HtmlExplainer()));
    }

    #[Test]
    public function test_the_literals_of_a_script_run_are_those_it_holds(): void
    {
        $this->assertEquals($this->tree('/abc/')->accept(new LiteralExtractor()), $this->tree('/(*sr:abc)/')->accept(new LiteralExtractor()));
    }

    private function tree(string $pattern): RegexNode
    {
        return RegexParser::create(['cache' => null, 'pcre_version' => '10.49'])->parse($pattern);
    }

    /**
     * @return list<string>
     */
    private function issueIds(string $pattern): array
    {
        $linter = new PatternLinter();
        $this->tree($pattern)->accept($linter);

        return array_values(array_map(static fn (RuleViolation $issue): string => $issue->id, $linter->getIssues()));
    }
}
