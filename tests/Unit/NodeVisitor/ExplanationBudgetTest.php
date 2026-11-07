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
use PHPRegex\Explain\TextExplainer;
use PHPRegex\Explain\RenderingBudget;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ResourceLimitException;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExplanationBudgetTest extends TestCase
{
    #[Test]
    public function test_the_budget_accepts_its_boundary_and_refuses_the_next_byte(): void
    {
        $budget = new RenderingBudget();
        $budget->reserve(8 * 1024 * 1024);
        $budget->reserve(0);
        $this->expectException(ResourceLimitException::class);
        $budget->reserve(1);
    }

    #[Test]
    public function test_nested_wide_patterns_are_bounded_and_visitors_can_be_reused(): void
    {
        $parser = RegexParser::create(['cache' => null]);
        $large = $parser->parse('/'.str_repeat('(?:', 100).str_repeat('.', 50000).str_repeat(')', 100).'/');
        $small = $parser->parse('/a/');
        foreach ([new AsciiTreeRenderer(), new TextExplainer()] as $visitor) {
            $expected = $small->accept($visitor);
            try {
                $large->accept($visitor);
                self::fail('Expected a rendering resource limit.');
            } catch (ResourceLimitException $exception) {
                self::assertSame(ErrorCode::RenderingOutputTooLarge, $exception->errorCode);
            }
            self::assertSame($expected, $small->accept($visitor));
        }
    }
}
