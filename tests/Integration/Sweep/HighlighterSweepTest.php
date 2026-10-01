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

namespace PhpRegex\Tests\Integration\Sweep;

use PhpRegex\Explain\Highlighter\AbstractHighlighter;
use PhpRegex\Parser\Node\CalloutNode;
use PhpRegex\Parser\Node\LimitMatchNode;
use PhpRegex\Parser\Node\ScriptRunNode;
use PhpRegex\Parser\Node\VersionConditionNode;
use PHPUnit\Framework\TestCase;

/**
 * A sweep of patterns through Highlighter.
 *
 * These cases were written to reach branches rather than to describe a
 * behaviour, and they were spread over eight files named after the metric
 * they served. They are grouped by what they exercise instead.
 */
final class HighlighterSweepTest extends TestCase
{
    public function test_highlighter_visitor_limit_match(): void
    {
        $visitor = new class extends AbstractHighlighter {
            protected function wrap(string $content, string $type): string
            {
                return "<span class=\"{$type}\">{$content}</span>";
            }

            protected function escape(string $string): string
            {
                return htmlspecialchars($string, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
            }
        };
        $node = new LimitMatchNode(1000, 0, 16);
        $result = $node->accept($visitor);
        $this->assertNotEmpty($result);
    }

    public function test_highlighter_visitor_callout(): void
    {
        $visitor = new class extends AbstractHighlighter {
            protected function wrap(string $content, string $type): string
            {
                return "<span class=\"{$type}\">{$content}</span>";
            }

            protected function escape(string $string): string
            {
                return htmlspecialchars($string, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
            }
        };
        $node = new CalloutNode(1, false, 0, 4);
        $result = $node->accept($visitor);
        $this->assertNotEmpty($result);
    }

    public function test_highlighter_visitor_script_run(): void
    {
        $visitor = new class extends AbstractHighlighter {
            protected function wrap(string $content, string $type): string
            {
                return "<span class=\"{$type}\">{$content}</span>";
            }

            protected function escape(string $string): string
            {
                return htmlspecialchars($string, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
            }
        };
        $node = new ScriptRunNode('Latin', 0, 18);
        $result = $node->accept($visitor);
        $this->assertNotEmpty($result);
    }

    public function test_highlighter_visitor_version_condition(): void
    {
        $visitor = new class extends AbstractHighlighter {
            protected function wrap(string $content, string $type): string
            {
                return "<span class=\"{$type}\">{$content}</span>";
            }

            protected function escape(string $string): string
            {
                return htmlspecialchars($string, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
            }
        };
        $node = new VersionConditionNode('>=', '10.0', 0, 18);
        $result = $node->accept($visitor);
        $this->assertNotEmpty($result);
    }
}
