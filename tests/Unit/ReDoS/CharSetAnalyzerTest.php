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

namespace RegexParser\Tests\Unit\ReDoS;

use PHPUnit\Framework\TestCase;
use RegexParser\Analysis\CharSetAnalyzer;
use RegexParser\Node\CharTypeNode;
use RegexParser\Node\LiteralNode;
use RegexParser\Node\RangeNode;
use RegexParser\Node\SequenceNode;

final class CharSetAnalyzerTest extends TestCase
{
    public function test_empty_literal_returns_empty_charset(): void
    {
        $analyzer = new CharSetAnalyzer();
        $set = $analyzer->firstChars(new LiteralNode('', 0, 0));

        $this->assertTrue($set->isEmpty());
    }

    public function test_unicode_mode_char_types_return_unknown(): void
    {
        $analyzer = new CharSetAnalyzer('u');
        $set = $analyzer->firstChars(new CharTypeNode('d', 0, 0));

        $this->assertTrue($set->isUnknown());
    }

    public function test_char_type_digit_range_is_supported_without_unicode_flag(): void
    {
        $analyzer = new CharSetAnalyzer();
        $set = $analyzer->firstChars(new CharTypeNode('d', 0, 0));

        $this->assertFalse($set->isUnknown());
        $this->assertTrue($set->intersects($set));
    }

    public function test_char_type_word_complement_is_supported_without_unicode_flag(): void
    {
        $analyzer = new CharSetAnalyzer();
        $set = $analyzer->firstChars(new CharTypeNode('W', 0, 0));

        $this->assertFalse($set->isUnknown());
    }

    public function test_char_type_digit_complement_is_supported_without_unicode_flag(): void
    {
        $analyzer = new CharSetAnalyzer();
        $set = $analyzer->firstChars(new CharTypeNode('D', 0, 0));

        $this->assertFalse($set->isUnknown());
    }

    public function test_range_with_empty_literal_returns_unknown(): void
    {
        $analyzer = new CharSetAnalyzer();
        $range = new RangeNode(new LiteralNode('', 0, 0), new LiteralNode('a', 0, 0), 0, 0);

        $set = $analyzer->firstChars($range);

        $this->assertTrue($set->isUnknown());
    }

    public function test_quantifier_min_returns_default_for_invalid(): void
    {
        $analyzer = new CharSetAnalyzer();
        $method = (new \ReflectionClass($analyzer))->getMethod('quantifierMin');

        $this->assertSame(1, $method->invoke($analyzer, 'invalid'));
    }

    public function test_is_optional_node_sequence_returns_true_when_all_optional(): void
    {
        $analyzer = new CharSetAnalyzer();
        $method = (new \ReflectionClass($analyzer))->getMethod('isOptionalNode');
        $sequence = new SequenceNode([
            new LiteralNode('', 0, 0),
            new LiteralNode('', 0, 0),
        ], 0, 0);

        $this->assertTrue($method->invoke($analyzer, $sequence, true));
    }
}
