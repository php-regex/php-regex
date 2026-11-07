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

use PHPRegex\Generator\Exception\SampleGenerationException;
use PHPRegex\Generator\TestCaseGenerator;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TestCaseGeneratorEdgeCasesTest extends TestCase
{
    #[Test]
    public function test_nested_repetition_exceeds_shared_byte_budget(): void
    {
        $pattern = '/^(?:a{2000}){2000}$/';
        $this->assertSame(0, preg_match($pattern, ''));
        $ast = RegexParser::create()->parse($pattern);

        $this->expectException(SampleGenerationException::class);
        $this->expectExceptionMessage('byte budget exceeded');
        $ast->accept(new TestCaseGenerator());
    }

    #[Test]
    public function test_sequence_intermediates_share_the_byte_budget(): void
    {
        $pattern = '/^'.str_repeat('a{1000}', 100).'$/';
        $this->assertSame(0, preg_match($pattern, ''));
        $ast = RegexParser::create()->parse($pattern);

        $this->expectException(SampleGenerationException::class);
        $ast->accept(new TestCaseGenerator());
    }

    #[Test]
    public function test_byte_budget_resets_after_failure_and_success(): void
    {
        $visitor = new TestCaseGenerator();
        $parser = RegexParser::create();
        try {
            $parser->parse('/^(?:a{2000}){2000}$/')->accept($visitor);
            $this->fail('Expected the byte budget to stop generation.');
        } catch (SampleGenerationException) {
            $this->assertSame(['abc'], $parser->parse('/^abc$/')->accept($visitor)['matching']);
        }

        $ast = $parser->parse('/^(?:a{500}){500}$/');
        $first = $ast->accept($visitor);
        $this->assertSame(250000, \strlen($first['matching'][0]));
        $this->assertSame($first, $ast->accept($visitor));
    }

    #[Test]
    public function test_direct_quantifier_visit_rejects_overflowing_counts(): void
    {
        $node = new QuantifierNode(new LiteralNode('aa', 0, 0), '{'.PHP_INT_MAX.'}', QuantifierType::Greedy, 0, 0);

        $this->expectException(SampleGenerationException::class);
        $node->accept(new TestCaseGenerator());
    }

    #[Test]
    public function test_visit_assertion_returns_empty_cases(): void
    {
        $visitor = new TestCaseGenerator();
        $cases = (new AssertionNode('A', 0, 0))->accept($visitor);

        $this->assertSame([''], $cases['matching']);
        $this->assertSame([''], $cases['non_matching']);
    }

    #[Test]
    public function test_visit_char_class_empty_parts_returns_non_matching(): void
    {
        $visitor = new TestCaseGenerator();
        $emptyAlt = new AlternationNode([], 0, 0);
        $class = new CharClassNode($emptyAlt, false, 0, 0);

        $cases = $class->accept($visitor);

        $this->assertSame([], $cases['matching']);
        $this->assertSame(['a'], $cases['non_matching']);
    }

    #[Test]
    public function test_visit_range_with_non_literal_bounds_returns_defaults(): void
    {
        $visitor = new TestCaseGenerator();
        $range = new RangeNode(new CharTypeNode('d', 0, 0), new LiteralNode('z', 0, 0), 0, 0);

        $cases = $range->accept($visitor);

        $this->assertSame(['a'], $cases['matching']);
        $this->assertSame(['!'], $cases['non_matching']);
    }

    #[Test]
    public function test_quantifier_with_max_adds_non_matching_sample(): void
    {
        $visitor = new TestCaseGenerator();
        $node = new QuantifierNode(new LiteralNode('a', 0, 0), '{1,2}', QuantifierType::Greedy, 0, 0);

        $cases = $node->accept($visitor);

        $this->assertContains('aaa', $cases['non_matching']);
    }

    #[Test]
    public function test_parse_quantifier_range_variants(): void
    {
        $visitor = new TestCaseGenerator();
        $method = (new \ReflectionClass($visitor))->getMethod('parseQuantifierRange');

        $this->assertSame([0, null], $method->invoke($visitor, new QuantifierNode(new LiteralNode('a', 0, 1), '*', QuantifierType::Greedy, 0, 1)));
        $this->assertSame([1, null], $method->invoke($visitor, new QuantifierNode(new LiteralNode('a', 0, 1), '+', QuantifierType::Greedy, 0, 1)));
        $this->assertSame([0, 1], $method->invoke($visitor, new QuantifierNode(new LiteralNode('a', 0, 1), '?', QuantifierType::Greedy, 0, 1)));
        $this->assertSame([2, null], $method->invoke($visitor, new QuantifierNode(new LiteralNode('a', 0, 1), '{2,}', QuantifierType::Greedy, 0, 1)));
        $this->assertSame([2, 3], $method->invoke($visitor, new QuantifierNode(new LiteralNode('a', 0, 1), '{2,3}', QuantifierType::Greedy, 0, 1)));
        $this->assertSame([2, 2], $method->invoke($visitor, new QuantifierNode(new LiteralNode('a', 0, 1), '{2}', QuantifierType::Greedy, 0, 1)));
    }

    #[Test]
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
