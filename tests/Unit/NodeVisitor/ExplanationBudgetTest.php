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
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * The budget counts every byte written, indentation and line breaks
     * included: an output of exactly 8 MiB is written whole, one byte more
     * is refused. A hundred groups deep, each dot takes one line, and the
     * comment (text) or the callout string (tree) the last line spells out
     * makes up the bytes left.
     */
    #[Test]
    #[DataProvider('provideRenderers')]
    public function test_the_budget_counts_every_byte_of_the_output(string $renderer): void
    {
        $limit = 8 * 1024 * 1024;
        $base = \strlen(self::render($renderer, 2, 0));
        $perDot = \strlen(self::render($renderer, 3, 0)) - $base;
        $this->assertSame($base + 1, \strlen(self::render($renderer, 2, 1)), 'Each letter of the last line is one byte.');

        $dots = 2 + intdiv($limit - $base, $perDot);
        $width = $limit - $base - ($dots - 2) * $perDot;
        $pattern = self::pattern($renderer, $dots, $width);
        $this->assertSame(0, preg_match($pattern, ''), 'Oracle: PCRE compiles the pattern.');

        $this->assertSame($limit, \strlen(self::render($renderer, $dots, $width)));

        try {
            self::render($renderer, $dots, $width + 1);
            $this->fail('Expected a rendering resource limit one byte past the budget.');
        } catch (ResourceLimitException $exception) {
            $this->assertSame(ErrorCode::RenderingOutputTooLarge, $exception->errorCode);
        }
    }

    /**
     * @return iterable<string, array{renderer: string}>
     */
    public static function provideRenderers(): iterable
    {
        yield 'text explanation' => ['renderer' => 'text'];
        yield 'ASCII tree' => ['renderer' => 'tree'];
    }

    private static function pattern(string $renderer, int $dots, int $width): string
    {
        $last = 'text' === $renderer ? '(?#'.str_repeat('x', $width).')' : '(?C"'.str_repeat('x', $width).'")';

        return '/'.str_repeat('(?:', 100).str_repeat('.', $dots).$last.str_repeat(')', 100).'/';
    }

    private static function render(string $renderer, int $dots, int $width): string
    {
        $ast = RegexParser::create(['cache' => null])->parse(self::pattern($renderer, $dots, $width));

        return $ast->accept('text' === $renderer ? new TextExplainer() : new AsciiTreeRenderer());
    }
}
