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

namespace PHPRegex\Tests\Unit\Transpiler;

use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\EngineProbe;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Transpiler\Exception\TranspileException;
use PHPRegex\Transpiler\Target\JavaScript\CaptureSemantics;
use PHPRegex\Transpiler\Transpiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CaptureSemanticsWorkTest extends TestCase
{
    use PhpUnitForwardCompat;

    #[Test]
    #[DataProvider('provideSharedReferences')]
    public function test_shared_predecessors_complete(string $target, bool $empty): void
    {
        $body = $empty ? '()' : '(a)';
        $spelled = $body;
        for ($number = 1; $number < 28; $number++) {
            $reference = '\\g{'.$number.'}';
            $body .= '('.$reference.($empty ? '' : '|').$reference.')';
            $spelled .= '(\\'.$number.($empty ? '' : '|').'\\'.$number.')';
        }
        $pattern = '/'.$body.'(?:\\g{28})+/';
        $spelled .= '(?:\\28)+';
        $this->assertSame($empty ? 1 : 0, preg_match($pattern, ''));

        // The targets get the same groups and references, which Node 24
        // matches on "" exactly as preg_match() does, for both variants.
        $this->assertSame('html' === $target ? '[\\s\\S]*(?:'.$spelled.')[\\s\\S]*' : $spelled, (new Transpiler())->transpile($pattern, $target)->pattern);
    }

    /**
     * Nested repetitions each ask whether what they repeat may match empty:
     * that is asked once per construct, not once per repetition around it,
     * so a pattern PCRE compiles is never refused for the work.
     */
    #[Test]
    #[DataProvider('provideTargets')]
    public function test_nested_repetitions_ask_once_whether_each_construct_matches_empty(string $target): void
    {
        $pattern = '/'.str_repeat('(?:'.str_repeat('a?', 10), 150).str_repeat('b)+', 150).'/';
        // PCRE compiles it: no "b" in the subject, no match.
        $this->assertSame(0, preg_match($pattern, 'x'));

        $spelled = substr($pattern, 1, -1);
        $this->assertSame('html' === $target ? '[\\s\\S]*(?:'.$spelled.')[\\s\\S]*' : $spelled, (new Transpiler())->transpile($pattern, $target)->pattern);
    }

    /**
     * @return iterable<string, array{target: string}>
     */
    public static function provideTargets(): iterable
    {
        yield 'javascript' => ['target' => 'javascript'];
        yield 'html' => ['target' => 'html'];
    }

    /**
     * @return iterable<string, array{target: string, empty: bool}>
     */
    public static function provideSharedReferences(): iterable
    {
        foreach (['javascript', 'html'] as $target) {
            foreach ([true, false] as $empty) {
                yield $target.($empty ? ' empty' : ' consuming') => ['target' => $target, 'empty' => $empty];
            }
        }
    }

    #[Test]
    public function test_cycle_dependent_results_are_not_reused(): void
    {
        $pattern = '/(\\2a)(\\1)/';
        $this->assertSame(0, preg_match($pattern, ''));
        $ast = RegexParser::create(['cache' => null])->parse($pattern);
        $analysis = new CaptureSemantics();
        (new \ReflectionMethod($analysis, 'number'))->invoke($analysis, $ast->pattern);
        $groups = (new \ReflectionProperty($analysis, 'groups'))->getValue($analysis);
        $this->assertIsArray($groups);
        $matchesEmpty = new \ReflectionMethod($analysis, 'matchesEmpty');

        // Group 2 provisionally reads empty while group 1 is active, but
        // neither group can match empty when read independently.
        $this->assertFalse($matchesEmpty->invoke($analysis, $groups[1]));
        $this->assertFalse($matchesEmpty->invoke($analysis, $groups[2]));
    }

    /**
     * Reading the pattern reads "\1" and "\2" of group 2 while group 2 is
     * being read, where "\2" answers "may match empty" for that reading
     * only: asked afterwards, each reference reads its group again, and
     * neither may match empty, as group 2 needs an "a".
     */
    #[Test]
    public function test_an_answer_met_inside_a_cycle_is_not_kept(): void
    {
        $pattern = '/(\\2)(\\1\\2a)/';
        $this->assertSame(0, preg_match($pattern, ''));
        $this->assertSame(0, preg_match($pattern, 'a'));
        $ast = RegexParser::create(['cache' => null])->parse($pattern);
        $analysis = new CaptureSemantics();
        (new \ReflectionMethod($analysis, 'number'))->invoke($analysis, $ast->pattern);
        $groups = (new \ReflectionProperty($analysis, 'groups'))->getValue($analysis);
        $this->assertIsArray($groups);
        $this->assertInstanceOf(GroupNode::class, $groups[2]);
        $this->assertInstanceOf(SequenceNode::class, $groups[2]->child);
        $matchesEmpty = new \ReflectionMethod($analysis, 'matchesEmpty');

        $this->assertFalse($matchesEmpty->invoke($analysis, $ast->pattern));
        [$first, $second] = $groups[2]->child->children;
        $this->assertFalse($matchesEmpty->invoke($analysis, $first));
        $this->assertFalse($matchesEmpty->invoke($analysis, $second));
    }

    /**
     * The parser reads a reference to a group the pattern does not have,
     * which PCRE refuses: with no group to read, it is taken to match empty.
     */
    #[Test]
    public function test_a_reference_to_a_missing_group_is_taken_to_match_empty(): void
    {
        $pattern = '/(a)\\5/';
        $this->assertStringContainsString('reference to non-existent subpattern', (string) EngineProbe::warning($pattern));
        $ast = RegexParser::create(['cache' => null])->parse($pattern);
        $this->assertInstanceOf(SequenceNode::class, $ast->pattern);
        $reference = $ast->pattern->children[1];
        $this->assertInstanceOf(BackrefNode::class, $reference);
        $analysis = new CaptureSemantics();
        (new \ReflectionMethod($analysis, 'number'))->invoke($analysis, $ast->pattern);

        $this->assertTrue((new \ReflectionMethod($analysis, 'matchesEmpty'))->invoke($analysis, $reference));
    }

    /**
     * A ring of groups, each reading the next twice and the last reading
     * the first, doubles the readings at each group: past the work limit
     * the transpiler says so instead of walking them, and short of it the
     * reference to a group that may not have matched is refused as before.
     */
    #[Test]
    #[DataProvider('provideReferenceRings')]
    public function test_a_reference_ring_past_the_work_limit_is_refused(int $groups, string $target, string $message): void
    {
        $body = '';
        for ($number = 1; $number <= $groups; $number++) {
            $next = $number < $groups ? $number + 1 : 1;
            $body .= '(\\g{'.$next.'}\\g{'.$next.'})';
        }
        $pattern = '/(?:'.$body.')?/';
        // PCRE compiles it, and the optional group lets "" match.
        $this->assertSame(1, preg_match($pattern, ''));

        $this->expectException(TranspileException::class);
        $this->expectExceptionMessageIsOrContains($message);
        (new Transpiler())->transpile($pattern, $target);
    }

    /**
     * @return iterable<string, array{groups: int, target: string, message: string}>
     */
    public static function provideReferenceRings(): iterable
    {
        foreach (['javascript', 'html'] as $target) {
            // 55,261 readings.
            yield $target.' nine groups' => ['groups' => 9, 'target' => $target, 'message' => 'A backreference to a group that may not have matched cannot be carried into JavaScript'];
            // 122,841 readings.
            yield $target.' ten groups' => ['groups' => 10, 'target' => $target, 'message' => 'Capture emptiness analysis exceeded its work limit.'];
        }
    }

    /**
     * Through the transpiler, both patterns read a group before it is set,
     * which is refused whatever the emptiness analysis answers.
     */
    #[Test]
    #[DataProvider('provideCyclePatterns')]
    public function test_cycle_patterns_are_refused_by_the_transpiler(string $pattern, string $target): void
    {
        $this->assertSame(0, preg_match($pattern, ''));
        $this->assertSame(0, preg_match($pattern, 'a'));

        $this->expectException(TranspileException::class);
        $this->expectExceptionMessageIsOrContains('A backreference to a group that may not have matched cannot be carried into JavaScript');
        (new Transpiler())->transpile($pattern, $target);
    }

    /**
     * @return iterable<string, array{pattern: string, target: string}>
     */
    public static function provideCyclePatterns(): iterable
    {
        foreach (['javascript', 'html'] as $target) {
            yield $target.' reference before its group' => ['pattern' => '/(\\2a)(\\1)/', 'target' => $target];
            yield $target.' reference inside its group' => ['pattern' => '/(\\2)(\\1\\2a)/', 'target' => $target];
        }
    }

    #[Test]
    public function test_exhausted_work_budget_refuses_analysis(): void
    {
        // The engine: "a" is no match on "", a match on "a".
        $this->assertSame(0, preg_match('/a/', ''));
        $this->assertSame(1, preg_match('/a/', 'a'));
        $ast = RegexParser::create(['cache' => null])->parse('/a/');
        $limit = (new \ReflectionClass(CaptureSemantics::class))->getConstant('MAX_EMPTINESS_STEPS');
        $this->assertSame(100_000, $limit);

        // The last step the budget allows is still taken.
        $analysis = new CaptureSemantics();
        (new \ReflectionProperty($analysis, 'emptinessSteps'))->setValue($analysis, $limit - 1);
        $this->assertFalse((new \ReflectionMethod($analysis, 'matchesEmpty'))->invoke($analysis, $ast->pattern));

        $analysis = new CaptureSemantics();
        (new \ReflectionProperty($analysis, 'emptinessSteps'))->setValue($analysis, $limit);

        $this->expectException(TranspileException::class);
        $this->expectExceptionMessageIsOrContains('Capture emptiness analysis exceeded its work limit.');
        (new \ReflectionMethod($analysis, 'matchesEmpty'))->invoke($analysis, $ast->pattern);
    }
}
