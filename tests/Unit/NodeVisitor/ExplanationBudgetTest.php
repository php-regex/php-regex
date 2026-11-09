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
use PHPRegex\Explain\RenderingBudget;
use PHPRegex\Explain\TextExplainer;
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
        $pattern = '/'.str_repeat('(?:', 100).str_repeat('.', 50000).str_repeat(')', 100).'/';
        // PCRE compiles the pattern: the explanation of a valid pattern is what is bounded.
        $this->assertSame(0, preg_match($pattern, ''));
        $this->assertSame(1, preg_match($pattern, str_repeat('a', 50000)));
        $parser = RegexParser::create(['cache' => null]);
        $large = $parser->parse($pattern);
        $small = $parser->parse('/a/');
        foreach ([new AsciiTreeRenderer(), new TextExplainer()] as $visitor) {
            $expected = $small->accept($visitor);

            try {
                $large->accept($visitor);
                $this->fail('Expected a rendering resource limit.');
            } catch (ResourceLimitException $exception) {
                $this->assertSame(ErrorCode::RenderingOutputTooLarge, $exception->errorCode);
            }
            $this->assertSame($expected, $small->accept($visitor));
        }
    }
}
