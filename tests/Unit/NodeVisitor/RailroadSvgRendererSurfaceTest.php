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
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The railroad renderer answers a whole pattern with its diagram, as a
 * string: the layout it computes on the way, node by node, is its own.
 */
final class RailroadSvgRendererSurfaceTest extends TestCase
{
    #[Test]
    public function test_the_renderer_returns_a_string_for_every_node(): void
    {
        $ast = Regex::create(['cache' => null])->parse('/a(b|c)+/');

        $this->assertStringContainsString('<svg', $ast->accept(new RailroadSvgRenderer()));
        $this->assertSame('', $ast->pattern->accept(new RailroadSvgRenderer()));
        $this->assertStringContainsString('@extends AbstractNodeVisitor<string>', (string) (new \ReflectionClass(RailroadSvgRenderer::class))->getDocComment());
    }

    #[Test]
    public function test_the_renderer_declares_no_visit_method_but_the_pattern_one(): void
    {
        $declared = array_values(array_filter(
            array_map(static fn (\ReflectionMethod $method): string => $method->getName(), (new \ReflectionClass(RailroadSvgRenderer::class))->getMethods(\ReflectionMethod::IS_PUBLIC)),
            static fn (string $name): bool => str_starts_with($name, 'visit') && RailroadSvgRenderer::class === (new \ReflectionMethod(RailroadSvgRenderer::class, $name))->getDeclaringClass()->getName(),
        ));

        $this->assertSame(['visitRegex'], $declared);
    }
}
