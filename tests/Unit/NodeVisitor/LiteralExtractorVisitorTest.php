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

use PHPRegex\Parser\Analysis\LiteralExtractor;
use PHPRegex\Parser\Analysis\LiteralSet;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class LiteralExtractorVisitorTest extends TestCase
{
    private Regex $regex;

    private LiteralExtractor $visitor;

    protected function setUp(): void
    {
        $this->regex = Regex::create();
        $this->visitor = new LiteralExtractor();
    }

    public function test_simple_literal(): void
    {
        $set = $this->extract('/hello/');

        $this->assertTrue($set->complete);
        $this->assertSame(['hello'], $set->prefixes);
        $this->assertSame(['hello'], $set->suffixes);
    }

    public function test_sequence_concat(): void
    {
        $set = $this->extract('/abc/'); // Sequence(Literal(a), Literal(b), Literal(c))

        $this->assertTrue($set->complete);
        $this->assertSame(['abc'], $set->prefixes);
    }

    public function test_alternation(): void
    {
        $set = $this->extract('/(foo|bar)/');

        $this->assertTrue($set->complete);
        $this->assertSame(['foo', 'bar'], $set->prefixes);
        $this->assertSame(['foo', 'bar'], $set->suffixes);
    }

    public function test_prefix_suffix_logic(): void
    {
        $set = $this->extract('/root(a|b)tail/');

        // "root" + ("a"|"b") + "tail" -> "rootatail" | "rootbtail"
        $this->assertTrue($set->complete);
        // Prefixes are complete strings here
        $this->assertSame(['rootatail', 'rootbtail'], $set->prefixes);
        // Suffixes are also complete strings
        $this->assertSame(['rootatail', 'rootbtail'], $set->suffixes);
    }

    public function test_quantifier_fixed(): void
    {
        $set = $this->extract('/a{3}/');
        $this->assertSame(['aaa'], $set->prefixes);
        $this->assertTrue($set->complete);
    }

    public function test_quantifier_plus(): void
    {
        $set = $this->extract('/a+/');
        // Should contain 'a' as prefix, but incomplete
        $this->assertSame(['a'], $set->prefixes);
        $this->assertFalse($set->complete);
        $this->assertEmpty($set->suffixes);
    }

    public function test_quantifier_star(): void
    {
        $set = $this->extract('/a*/');
        $this->assertEmpty($set->prefixes);
        $this->assertFalse($set->complete);
    }

    public function test_anchor_does_not_break_prefix(): void
    {
        $set = $this->extract('/^root/');
        $this->assertSame(['root'], $set->prefixes);
    }

    public function test_dot_breaks_chain(): void
    {
        $set = $this->extract('/pre.fix/');
        // Prefix "pre" is valid. Suffix "fix" is valid.
        // But since "." is unknown, they are not concatenated.
        $this->assertSame(['pre'], $set->prefixes);
        $this->assertSame(['fix'], $set->suffixes);
        $this->assertFalse($set->complete);
    }

    public function test_case_insensitive(): void
    {
        $set = $this->extract('/ab/i');
        // Expect: ab, aB, Ab, AB
        $this->assertCount(4, $set->prefixes);
        $this->assertContains('ab', $set->prefixes);
        $this->assertContains('AB', $set->prefixes);
    }

    public function test_optimization_usage(): void
    {
        $pattern = '/user_(\d+)/';
        $set = $this->extract($pattern);

        $this->assertSame(['user_'], $set->prefixes);
        $this->assertFalse($set->complete);

        $prefix = $set->getLongestPrefix();
        $this->assertSame('user_', $prefix);
    }

    private function extract(string $regex): LiteralSet
    {
        $ast = $this->regex->parse($regex);

        return $ast->accept($this->visitor);
    }
}
