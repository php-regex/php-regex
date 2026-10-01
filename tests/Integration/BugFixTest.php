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

namespace PHPRegex\Tests\Integration;

use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BugFixTest extends TestCase
{
    private Regex $regexService;

    protected function setUp(): void
    {
        $this->regexService = Regex::create();
    }

    #[Test]
    public function test_parser_handles_hyphen_as_range_end(): void
    {
        // [a-] should be parsed as LiteralNode('a'), LiteralNode('-')
        $ast = $this->regexService->parse('/[a-]/');
        $charClass = $ast->pattern;

        $this->assertInstanceOf(CharClassNode::class, $charClass);
        $this->assertInstanceOf(AlternationNode::class, $charClass->expression);
        $this->assertCount(2, $charClass->expression->alternatives);
        $this->assertInstanceOf(LiteralNode::class, $charClass->expression->alternatives[0]);
        $this->assertSame('a', $charClass->expression->alternatives[0]->value);
        $this->assertInstanceOf(LiteralNode::class, $charClass->expression->alternatives[1]);
        $this->assertSame('-', $charClass->expression->alternatives[1]->value);
    }

    #[Test]
    public function test_parser_handles_hyphen_range(): void
    {
        // [a-z] should be parsed as Range(a, z)
        $ast = $this->regexService->parse('/[a-z]/');
        $charClass = $ast->pattern;

        $this->assertInstanceOf(CharClassNode::class, $charClass);
        $this->assertInstanceOf(RangeNode::class, $charClass->expression);
        $range = $charClass->expression;
        $this->assertInstanceOf(LiteralNode::class, $range->start);
        $this->assertInstanceOf(LiteralNode::class, $range->end);
        $this->assertSame('a', $range->start->value);
        $this->assertSame('z', $range->end->value);
    }

    #[Test]
    public function test_re_do_s_analyzer_detects_dot_overlap(): void
    {
        // (a|.)*$ should be CRITICAL: without the end anchor the loop
        // matches at once and never backtracks.
        $analysis = $this->regexService->redos('/(a|.)*$/');
        $this->assertSame(RedosSeverity::Critical, $analysis->severity);
    }

    #[Test]
    public function test_re_do_s_analyzer_detects_char_class_overlap(): void
    {
        // ([a-z]|[0-9])* -> disjoint branches, should not be critical
        $analysis = $this->regexService->redos('/([a-z]|[0-9])*$/');
        $this->assertNotSame(RedosSeverity::Critical, $analysis->severity);
        $this->assertNotSame(RedosSeverity::High, $analysis->severity);

        // ([a-z]|[a-f])*$ -> Critical (overlap)
        // With overlap detection, this should remain critical.
        // Given the request to "fix bugs", false positive is acceptable for v1.0 safety.

        $analysis = $this->regexService->redos('/([a-z]|[a-f])*$/');
        $this->assertSame(RedosSeverity::Critical, $analysis->severity);
    }
}
