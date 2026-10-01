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

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Generator\TestCaseGenerator;
use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\QuantifierType;
use PhpRegex\Parser\Node\RangeNode;
use PHPUnit\Framework\TestCase;

final class TestCaseGeneratorNodeVisitorEdgeCasesTest extends TestCase
{
    public function test_visit_assertion_returns_empty_cases(): void
    {
        $visitor = new TestCaseGenerator();
        $cases = (new AssertionNode('A', 0, 0))->accept($visitor);

        $this->assertSame([''], $cases['matching']);
        $this->assertSame([''], $cases['non_matching']);
    }

    public function test_visit_char_class_empty_parts_returns_non_matching(): void
    {
        $visitor = new TestCaseGenerator();
        $emptyAlt = new AlternationNode([], 0, 0);
        $class = new CharClassNode($emptyAlt, false, 0, 0);

        $cases = $class->accept($visitor);

        $this->assertSame([], $cases['matching']);
        $this->assertSame(['a'], $cases['non_matching']);
    }

    public function test_visit_range_with_non_literal_bounds_returns_defaults(): void
    {
        $visitor = new TestCaseGenerator();
        $range = new RangeNode(new CharTypeNode('d', 0, 0), new LiteralNode('z', 0, 0), 0, 0);

        $cases = $range->accept($visitor);

        $this->assertSame(['a'], $cases['matching']);
        $this->assertSame(['!'], $cases['non_matching']);
    }

    public function test_quantifier_with_max_adds_non_matching_sample(): void
    {
        $visitor = new TestCaseGenerator();
        $node = new QuantifierNode(new LiteralNode('a', 0, 0), '{1,2}', QuantifierType::Greedy, 0, 0);

        $cases = $node->accept($visitor);

        $this->assertContains('aaa', $cases['non_matching']);
    }

    public function test_parse_quantifier_range_variants(): void
    {
        $visitor = new TestCaseGenerator();
        $method = (new \ReflectionClass($visitor))->getMethod('parseQuantifierRange');

        $this->assertSame([0, null], $method->invoke($visitor, '*'));
        $this->assertSame([1, null], $method->invoke($visitor, '+'));
        $this->assertSame([0, 1], $method->invoke($visitor, '?'));
        $this->assertSame([2, null], $method->invoke($visitor, '{2,}'));
        $this->assertSame([2, 3], $method->invoke($visitor, '{2,3}'));
        $this->assertSame([2, 2], $method->invoke($visitor, '{2}'));
    }

    public function test_generate_for_char_type_variants(): void
    {
        $visitor = new TestCaseGenerator();
        $method = (new \ReflectionClass($visitor))->getMethod('generateForCharType');

        $this->assertSame('a', $method->invoke($visitor, 'S'));
        $this->assertSame('a', $method->invoke($visitor, 'w'));
        $this->assertSame('!', $method->invoke($visitor, 'W'));
        $this->assertSame(' ', $method->invoke($visitor, 'h'));
        $this->assertSame('a', $method->invoke($visitor, 'H'));
        $this->assertSame("\n", $method->invoke($visitor, 'v'));
        $this->assertSame('a', $method->invoke($visitor, 'V'));
        $this->assertSame("\r\n", $method->invoke($visitor, 'R'));
    }
}
