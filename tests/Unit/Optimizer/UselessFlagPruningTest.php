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

namespace PHPRegex\Tests\Unit\Optimizer;

use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\ClassSetOperationNode;
use PHPRegex\Parser\Node\ClassSetOperator;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The optimizer drops `s` when no dot can see it and `m` when no `^`/`$`
 * can. Both decisions must look inside every node that holds a sub-pattern:
 * a dot inside a DEFINE body or a script run still reads `s`.
 */
final class UselessFlagPruningTest extends TestCase
{
    /**
     * @param non-empty-string $pattern
     */
    #[DataProvider('provideDotInsideDefine')]
    public function test_optimize_keeps_s_when_the_dot_is_inside_define(string $pattern, string $subject): void
    {
        // Oracle: the flag changes the verdict, so dropping it changes the pattern.
        $this->assertSame(1, preg_match($pattern, $subject));
        $this->assertSame(0, preg_match(substr($pattern, 0, -1), $subject));

        $optimized = Regex::create()->optimize($pattern)->optimized;

        $this->assertStringEndsWith('/s', $optimized);
        $this->assertSame(1, preg_match($optimized, $subject));
    }

    /**
     * @return iterable<string, array{pattern: non-empty-string, subject: string}>
     */
    public static function provideDotInsideDefine(): iterable
    {
        yield 'dot reached through a named subroutine' => ['pattern' => '/(?(DEFINE)(?<d>.))a(?&d)/s', 'subject' => "a\n"];
        yield 'dot reached through a numbered subroutine' => ['pattern' => '/(?(DEFINE)(.))a(?1)/s', 'subject' => "a\n"];
    }

    /**
     * @param non-empty-string $pattern
     */
    #[DataProvider('provideDotInsideScriptRun')]
    public function test_optimize_keeps_s_when_the_dot_is_inside_a_script_run(string $pattern, string $subject): void
    {
        $this->assertSame(1, preg_match($pattern, $subject));
        $this->assertSame(0, preg_match(substr($pattern, 0, -1), $subject));

        $optimized = Regex::create()->optimize($pattern)->optimized;

        $this->assertStringEndsWith('/s', $optimized);
        $this->assertSame(1, preg_match($optimized, $subject));
    }

    /**
     * @return iterable<string, array{pattern: non-empty-string, subject: string}>
     */
    public static function provideDotInsideScriptRun(): iterable
    {
        yield 'short script run' => ['pattern' => '/(*sr:a.)/s', 'subject' => "a\n"];
        yield 'long script run' => ['pattern' => '/(*script_run:a.)/s', 'subject' => "a\n"];
        yield 'short atomic script run' => ['pattern' => '/(*asr:a.)/s', 'subject' => "a\n"];
        yield 'long atomic script run' => ['pattern' => '/(*atomic_script_run:a.)/s', 'subject' => "a\n"];
    }

    /**
     * @param non-empty-string $pattern
     */
    #[DataProvider('provideAnchorInsideScriptRun')]
    public function test_optimize_keeps_m_when_the_anchor_is_inside_a_script_run(string $pattern, string $subject): void
    {
        $this->assertSame(1, preg_match($pattern, $subject));
        $this->assertSame(0, preg_match(substr($pattern, 0, -1), $subject));

        $optimized = Regex::create()->optimize($pattern)->optimized;

        $this->assertStringEndsWith('/m', $optimized);
        $this->assertSame(1, preg_match($optimized, $subject));
    }

    /**
     * @return iterable<string, array{pattern: non-empty-string, subject: string}>
     */
    public static function provideAnchorInsideScriptRun(): iterable
    {
        yield 'dollar inside a short script run' => ['pattern' => '/(*sr:a$)/m', 'subject' => "a\nx"];
        yield 'caret inside a long script run' => ['pattern' => '/(*script_run:^a)/m', 'subject' => "x\na"];
        yield 'caret inside an atomic script run' => ['pattern' => '/(*asr:^a)/m', 'subject' => "x\na"];
    }

    /**
     * @param non-empty-string $pattern
     */
    #[DataProvider('provideUselessFlags')]
    public function test_optimize_still_prunes_a_flag_nothing_reads(string $pattern, string $expected): void
    {
        $this->assertSame($expected, Regex::create()->optimize($pattern)->optimized);
    }

    /**
     * @return iterable<string, array{pattern: non-empty-string, expected: string}>
     */
    public static function provideUselessFlags(): iterable
    {
        yield 's without a dot' => ['pattern' => '/abc/s', 'expected' => '/abc/'];
        yield 'm without an anchor' => ['pattern' => '/abc/m', 'expected' => '/abc/'];
        yield 's and m inside a script run with neither' => ['pattern' => '/(*sr:abc)/sm', 'expected' => '/(*sr:abc)/'];
        yield 'escaped dot inside define' => ['pattern' => '/(?(DEFINE)(?<d>\.))a(?&d)/s', 'expected' => '/(?(DEFINE)(?<d>\.))a(?&d)/'];
    }

    /**
     * Built by hand so every container node is covered, including those the
     * parser never fills with a dot or an anchor today: a future node type
     * that holds children must be walked through getChildren().
     */
    #[DataProvider('provideContainers')]
    public function test_flag_helpers_reach_every_node_with_children(\Closure $wrap): void
    {
        $dot = $wrap(new DotNode(0, 1));
        $anchor = $wrap(new AnchorNode('^', 0, 1));
        \assert($dot instanceof NodeInterface && $anchor instanceof NodeInterface);

        $withDot = (new RegexNode($dot, 's', '/', 0, 1))->accept(new Rewriter());
        $withAnchor = (new RegexNode($anchor, 'm', '/', 0, 1))->accept(new Rewriter());

        $this->assertInstanceOf(RegexNode::class, $withDot);
        $this->assertInstanceOf(RegexNode::class, $withAnchor);
        $this->assertSame('s', $withDot->flags);
        $this->assertSame('m', $withAnchor->flags);
    }

    /**
     * @return iterable<string, array{wrap: \Closure(NodeInterface): NodeInterface}>
     */
    public static function provideContainers(): iterable
    {
        yield 'define' => ['wrap' => static fn (NodeInterface $n): NodeInterface => new DefineNode($n, 0, 1)];
        yield 'script run' => ['wrap' => static fn (NodeInterface $n): NodeInterface => new ScriptRunNode('', 0, 1, $n)];
        yield 'atomic script run' => ['wrap' => static fn (NodeInterface $n): NodeInterface => new ScriptRunNode('', 0, 1, $n, true)];
        yield 'extended char class' => ['wrap' => static fn (NodeInterface $n): NodeInterface => new ExtendedCharClassNode($n, 0, 1)];
        yield 'class set operation, right operand' => ['wrap' => static fn (NodeInterface $n): NodeInterface => new ClassSetOperationNode(ClassSetOperator::Union, new LiteralNode('a', 0, 1), $n, '+', 0, 1)];
        yield 'class set operation, left operand' => ['wrap' => static fn (NodeInterface $n): NodeInterface => new ClassSetOperationNode(ClassSetOperator::Union, $n, new LiteralNode('a', 0, 1), '+', 0, 1)];
        yield 'conditional, yes branch' => ['wrap' => static fn (NodeInterface $n): NodeInterface => new ConditionalNode(new LiteralNode('1', 0, 1), $n, new LiteralNode('', 0, 0), 0, 1)];
        yield 'conditional, no branch' => ['wrap' => static fn (NodeInterface $n): NodeInterface => new ConditionalNode(new LiteralNode('1', 0, 1), new LiteralNode('', 0, 0), $n, 0, 1)];
        yield 'positive lookahead' => ['wrap' => static fn (NodeInterface $n): NodeInterface => new GroupNode($n, GroupType::LookaheadPositive)];
        yield 'negative lookbehind' => ['wrap' => static fn (NodeInterface $n): NodeInterface => new GroupNode($n, GroupType::LookbehindNegative)];
        yield 'char class' => ['wrap' => static fn (NodeInterface $n): NodeInterface => new CharClassNode($n, false, 0, 1)];
        yield 'nested define in a sequence' => ['wrap' => static fn (NodeInterface $n): NodeInterface => new SequenceNode([new LiteralNode('a', 0, 1), new DefineNode(new SequenceNode([$n], 0, 1), 0, 1)], 0, 1)];
    }
}
