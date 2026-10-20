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

use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\NodeVisitorInterface;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\Finding;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosConfidence;
use PHPRegex\Redos\RedosProfiler;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\TestCase;

final class RedosProfileNodeVisitorEdgeCasesTest extends TestCase
{
    public function test_get_result_includes_message_without_suggested_rewrite(): void
    {
        $visitor = new RedosProfiler();
        $this->invokePrivate($visitor, 'addVulnerability', [
            RedosSeverity::Low,
            'Test risk',
            new LiteralNode('a', 0, 0),
            null,
            RedosConfidence::Low,
            null,
        ]);

        $result = $visitor->getResult();

        $this->assertSame(['Test risk'], $result['recommendations']);
    }

    public function test_get_result_includes_hint_for_suggested_rewrite(): void
    {
        $visitor = new RedosProfiler();
        $this->invokePrivate($visitor, 'addVulnerability', [
            RedosSeverity::Medium,
            'Test risk with suggestion',
            new LiteralNode('a', 0, 0),
            'Use possessive quantifiers',
            RedosConfidence::Medium,
            null,
        ]);

        $result = $visitor->getResult();

        $this->assertSame(['Test risk with suggestion Suggested (verify behavior): Use possessive quantifiers'], $result['recommendations']);
    }

    public function test_large_bounded_quantifier_adds_low_risk(): void
    {
        $visitor = new RedosProfiler();
        $quantifier = new QuantifierNode(new LiteralNode('a', 0, 0), '{1,2001}', QuantifierType::Greedy, 0, 0);

        $severity = $quantifier->accept($visitor);

        $this->assertSame(RedosSeverity::Low, $severity);
    }

    public function test_star_height_critical_when_child_returns_high(): void
    {
        $visitor = new RedosProfiler();
        $highNode = new class implements NodeInterface {
            public function getChildren(): array
            {
                return [];
            }

            public function accept(NodeVisitorInterface $visitor): RedosSeverity|string
            {
                if ($visitor instanceof RedosProfiler) {
                    return RedosSeverity::High;
                }

                return '';
            }

            public function getStartPosition(): int
            {
                return 0;
            }

            public function getEndPosition(): int
            {
                return 0;
            }
        };

        $quantifier = new QuantifierNode($highNode, '*', QuantifierType::Greedy, 0, 0);
        $severity = $quantifier->accept($visitor);

        $this->assertSame(RedosSeverity::Critical, $severity);
    }

    public function test_safe_nodes_return_safe_severity(): void
    {
        $visitor = new RedosProfiler();
        $nodes = [
            new AssertionNode('A', 0, 0),
            new KeepNode(0, 0),
            new RangeNode(new LiteralNode('a', 0, 0), new LiteralNode('z', 0, 0), 0, 0),
            new UnicodePropNode('L', true, 0, 0),
            new PosixClassNode('alpha', 0, 0),
            new CommentNode('note', 0, 0),
            new LimitMatchNode(100, 0, 0),
            new CalloutNode('callout', true, 0, 0),
        ];

        foreach ($nodes as $node) {
            $this->assertSame(RedosSeverity::Safe, $node->accept($visitor));
        }
    }

    public function test_conditional_and_define_delegate_to_children(): void
    {
        $visitor = new RedosProfiler();
        $conditional = new ConditionalNode(new LiteralNode('a', 0, 0), new LiteralNode('b', 0, 0), new LiteralNode('c', 0, 0), 0, 0);
        $define = new DefineNode(new LiteralNode('a', 0, 0), 0, 0);

        $this->assertSame(RedosSeverity::Safe, $conditional->accept($visitor));
        $this->assertSame(RedosSeverity::Safe, $define->accept($visitor));
    }

    public function test_overlapping_alternatives_handles_unknown_sets(): void
    {
        $visitor = new RedosProfiler();
        $alternation = new AlternationNode([new DotNode(0, 0), new LiteralNode('a', 0, 0)], 0, 0);

        $result = $this->invokePrivate($visitor, 'hasOverlappingAlternatives', [$alternation]);

        $this->assertIsBool($result);
    }

    public function test_overlapping_alternatives_does_not_assume_overlap_for_unknown_sets(): void
    {
        $visitor = new RedosProfiler();
        // Create an alternation with unknown set (e.g., UnicodePropNode) and a literal
        // Should not trigger overlap since unknown doesn't mean overlap
        $alternation = new AlternationNode([
            new LiteralNode('a', 0, 0),
            new UnicodePropNode('Unknown', false, 0, 0),
        ], 0, 0);

        $result = $this->invokePrivate($visitor, 'hasOverlappingAlternatives', [$alternation]);

        $this->assertFalse($result, 'Unknown sets should not cause assumed overlap');
    }

    public function test_prefix_signature_recurses_through_group(): void
    {
        $visitor = new RedosProfiler();
        $group = new GroupNode(new DotNode(0, 0), GroupType::NonCapturing, null, null, 0, 0);

        $signature = $this->invokePrivate($visitor, 'getPrefixSignature', [$group]);

        $this->assertSame('DOT', $signature);
    }

    /**
     * A script run matches what its body matches: nothing only when the body
     * can, and nothing at all when it has none.
     */
    public function test_a_script_run_matches_nothing_only_when_its_body_can(): void
    {
        $visitor = new RedosProfiler();

        $this->assertTrue($this->invokePrivate($visitor, 'nullableStatus', [new ScriptRunNode('sr', 0, 6)]));
        $this->assertFalse($this->invokePrivate($visitor, 'nullableStatus', [new ScriptRunNode('sr', 0, 7, new LiteralNode('a', 0, 1))]));
        $this->assertTrue($this->invokePrivate($visitor, 'nullableStatus', [new ScriptRunNode('sr', 0, 8, new QuantifierNode(new LiteralNode('a', 0, 1), '*', QuantifierType::Greedy, 0, 2))]));
    }

    /**
     * A DEFINE block is analysed on its own, outside the loops around it,
     * with the nesting of its own loops counted from none.
     */
    public function test_the_loops_of_a_define_block_nest_from_none(): void
    {
        $analyzer = new RedosAnalyzer();
        $messages = static fn (string $pattern): array => array_map(
            static fn (Finding $finding): string => $finding->severity->value.' '.$finding->pattern.': '.$finding->message,
            $analyzer->analyze($pattern)->findings,
        );

        $unbounded = $messages('/(?(DEFINE)(?<x>(a+)+))b/');
        $this->assertContains('critical a+: Nested unbounded quantifiers detected. This allows exponential backtracking. Consider using atomic groups (?>...) or possessive quantifiers (*+, ++).', $unbounded);

        $bounded = $messages('/(?(DEFINE)(?<x>(?:a{1,3}){1,3}))b/');
        $this->assertContains('low a{1,3}: Nested bounded quantifiers detected. May cause polynomial backtracking. Consider simplifying the pattern or using atomic groups (?>...).', $bounded);

        // A loop of the block counts as no loop of the pattern around it.
        $outside = $messages('/^(?:c(?(DEFINE)(?<x>a+)))+$/');
        $this->assertNotContains('critical a+: Nested unbounded quantifiers detected. This allows exponential backtracking. Consider using atomic groups (?>...) or possessive quantifiers (*+, ++).', $outside);
    }

    /**
     * A lookaround runs on its own, atomic once it holds: what its body may
     * read in two ways is not tried again by the loop around it. Its own
     * loops nest from none, and a lookahead holding an unbounded repeat in
     * a loop reads the subject again at each step, quadratic, not
     * exponential.
     *
     * Engine (PHP 8.4.26 / PCRE2 10.49, without the JIT): /^a(?:(?<=a|a)a)+$/
     * on "a" x n . "!" takes 0.68, 1.38, 2.82, 5.56 ms from n = 16,000;
     * /^(?:(?=a+)a)+$/ 1.07, 4.15, 16.2, 73.8 ms from n = 2,000.
     */
    public function test_a_lookaround_runs_on_its_own(): void
    {
        $profile = static function (string $pattern): array {
            $profiler = new RedosProfiler();
            RegexParser::create()->parse($pattern)->accept($profiler);
            $result = $profiler->getResult();

            return [$result['severity'], array_map(
                static fn (Finding $finding): string => $finding->severity->value.' '.$finding->pattern.': '.$finding->message,
                $result['findings'],
            )];
        };

        [$severity, $findings] = $profile('/^a(?:(?<=a|a)a)+$/');
        $this->assertSame(RedosSeverity::Medium, $severity);
        $this->assertSame(['medium (?:(?<=a|a)a)+: Unbounded quantifier detected. May cause backtracking on non-matching input. Consider making it possessive (*+) or using atomic groups (?>...).'], $findings);

        foreach (['/^(?:(?!b|b)a)+$/', '/^(?:(?=a|a)a)+$/', '/^(?:(?<!b|b)a)+$/'] as $pattern) {
            [$severity, $findings] = $profile($pattern);
            $this->assertSame(RedosSeverity::Medium, $severity, $pattern);
            $this->assertCount(1, $findings, $pattern);
        }

        // Its bounded repeats nest in none of the loops around it, only in
        // its own.
        $this->assertSame([], $profile('/^(?:(?<=a{2})b){3}$/')[1]);
        $this->assertSame(['low a{2}: Nested bounded quantifiers detected. May cause polynomial backtracking. Consider simplifying the pattern or using atomic groups (?>...).'], $profile('/^(?:(?=(?:a{2}){2})a){3}$/')[1]);

        // An atomic group around it hides nothing of its body, which
        // exhausts the backtrack limit on "a" x 30 . "!b".
        $this->assertSame(RedosSeverity::Critical, $profile('/^(?>(?=(a+)+b))a/')[0]);

        $reread = static fn (string $lookaround): string => 'medium '.$lookaround.': A lookaround in a repeated group runs again at each repetition, and the unbounded repeat inside it reads the subject again from there: the cost grows with the square of the length. Move the lookaround out of the loop, or bound what it reads.';
        [$severity, $findings] = $profile('/^(?:(?=a+)a)+$/');
        $this->assertSame(RedosSeverity::Medium, $severity);
        $this->assertContains($reread('(?=a+)'), $findings);
        $this->assertNotContains('critical a+: Nested unbounded quantifiers detected. This allows exponential backtracking. Consider using atomic groups (?>...) or possessive quantifiers (*+, ++).', $findings);

        // A negative lookahead reads as far; so does a lookahead a lookbehind
        // holds; an atomic group around it changes nothing (1.10, 4.21,
        // 16.4, 70.1 ms from n = 2,000).
        $this->assertContains($reread('(?!a+b)'), $profile('/^(?:(?!a+b)a)+$/')[1]);
        $this->assertContains($reread('(?<=a(?=a+))'), $profile('/^a(?:(?<=a(?=a+))a)+$/')[1]);
        $this->assertContains($reread('(?=a+)'), $profile('/^(?:(?>(?=a+)a))+$/')[1]);

        // Outside a loop, or bounded, the lookahead reads once, or little.
        $this->assertNotContains($reread('(?=a+)'), $profile('/^(?=a+)a+$/')[1]);
        $this->assertNotContains($reread('(?=a{2})'), $profile('/^(?:(?=a{2})a)+$/')[1]);

        // A non-atomic lookaround is backtracked into: the loop around it
        // tries its alternatives again.
        $this->assertSame(RedosSeverity::Critical, $profile('/^(?:(*napla:a|a)a)+$/')[0]);

        // What the body itself may read in two ways stays its own risk.
        [$severity, $findings] = $profile('/^(?:(?=(a+)+b)a)+$/');
        $this->assertSame(RedosSeverity::Critical, $severity);
        $this->assertContains('critical a+: Nested unbounded quantifiers detected. This allows exponential backtracking. Consider using atomic groups (?>...) or possessive quantifiers (*+, ++).', $findings);
    }

    public function test_capture_length_and_quantifier_bounds_helpers(): void
    {
        $visitor = new RedosProfiler();

        // A control character is one character: its capture has one length.
        $fixedCapture = new SequenceNode([new GroupNode(new ControlCharNode('A', 1, 0, 3), GroupType::Capturing), new BackrefNode('\\1', 4, 6)], 0, 6);
        $this->assertFalse($this->invokePrivate($visitor, 'hasBackrefLoop', [$fixedCapture]));

        $variableCapture = new SequenceNode([new GroupNode(new QuantifierNode(new LiteralNode('a', 1, 2), '+', QuantifierType::Greedy, 1, 3), GroupType::Capturing), new BackrefNode('\\1', 4, 6)], 0, 6);
        $this->assertTrue($this->invokePrivate($visitor, 'hasBackrefLoop', [$variableCapture]));

        $exact = $this->invokePrivate($visitor, 'quantifierBounds', ['{3}']);
        $this->assertSame([3, 3], $exact);

        $fallback = $this->invokePrivate($visitor, 'quantifierBounds', ['invalid']);
        $this->assertSame([0, null], $fallback);
    }

    /**
     * @param array<int, mixed> $args
     */
    private function invokePrivate(object $target, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionClass($target);
        $refMethod = $ref->getMethod($method);

        return $refMethod->invokeArgs($target, $args);
    }
}
