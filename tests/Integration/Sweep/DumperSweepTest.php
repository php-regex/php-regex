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

namespace PhpRegex\Tests\Integration\Sweep;

use PhpRegex\Parser\Node\LimitMatchNode;
use PhpRegex\Parser\Node\ScriptRunNode;
use PhpRegex\Parser\Node\VersionConditionNode;
use PhpRegex\Parser\Printer\NodeDumper;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

/**
 * A sweep of patterns through Dumper.
 *
 * These cases were written to reach branches rather than to describe a
 * behaviour, and they were spread over eight files named after the metric
 * they served. They are grouped by what they exercise instead.
 */
final class DumperSweepTest extends TestCase
{
    private Regex $regexService;

    protected function setUp(): void
    {
        $this->regexService = Regex::create();
    }

    public function test_dumper_visitor_script_run(): void
    {
        $visitor = new NodeDumper();
        $node = new ScriptRunNode('Latin', 0, 18);
        $result = $node->accept($visitor);
        $this->assertNotEmpty($result);
    }

    public function test_dumper_visitor_limit_match(): void
    {
        $visitor = new NodeDumper();
        $node = new LimitMatchNode(1000, 0, 16);
        $result = $node->accept($visitor);
        $this->assertNotEmpty($result);
    }

    public function test_dumper_visitor_version_condition(): void
    {
        $visitor = new NodeDumper();
        $node = new VersionConditionNode('>=', '10.0', 0, 18);
        $result = $node->accept($visitor);
        $this->assertNotEmpty($result);
    }

    public function test_dumper_group_types(): void
    {
        $dumper = new NodeDumper();

        // Non-capturing group
        $ast = $this->regexService->parse('/(?:abc)/');
        $result = $ast->accept($dumper);
        $this->assertStringContainsString('Group', $result);

        // Named group
        $ast = $this->regexService->parse('/(?<name>abc)/');
        $result = $ast->accept($dumper);
        $this->assertStringContainsString('Group', $result);

        // Atomic group
        $ast = $this->regexService->parse('/(?>abc)/');
        $result = $ast->accept($dumper);
        $this->assertStringContainsString('Group', $result);
    }

    public function test_dumper_assertion_types(): void
    {
        $dumper = new NodeDumper();

        $patterns = [
            '/(?=abc)/',   // Positive lookahead
            '/(?!abc)/',   // Negative lookahead
            '/(?<=abc)/',  // Positive lookbehind
            '/(?<!abc)/',  // Negative lookbehind
        ];

        foreach ($patterns as $pattern) {
            $ast = $this->regexService->parse($pattern);
            $result = $ast->accept($dumper);
            // Assertions are represented as Groups with specific types
            $this->assertStringContainsString('Group', $result);
        }
    }
}
