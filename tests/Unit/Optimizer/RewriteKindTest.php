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

use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Optimizer\RewriteKind;
use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\Exception\InternalFailureException;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An optimization says which kinds of rewrite made it, as RewriteKind
 * cases in the order first made, and whether the automata checked the
 * result; $changes is the message of each kind.
 */
final class RewriteKindTest extends TestCase
{
    /**
     * Each kind is a rewrite the rewriter names: its message is the one
     * OptimizationResult::$changes held, its value that message's name in
     * snake case.
     */
    #[Test]
    public function test_each_kind_names_a_rewrite_of_the_rewriter(): void
    {
        $constants = (new \ReflectionClass(Rewriter::class))->getConstants();
        $named = [];
        foreach (RewriteKind::cases() as $kind) {
            $constant = strtoupper((string) preg_replace('/(?<=[a-z])(?=[A-Z])/', '_', $kind->name));
            $this->assertSame($constants[$constant] ?? null, $kind->message(), $kind->name);
            $this->assertSame(strtolower($constant), $kind->value, $kind->name);
            $this->assertSame($kind, RewriteKind::named($kind->message()));
            $named[] = $kind->message();
        }

        $this->assertCount(16, RewriteKind::cases());
        $this->assertSame(array_values(array_filter($constants, is_string(...))), $named, 'Every rewrite has its kind, in the order the rewriter lists them.');
    }

    #[Test]
    public function test_a_message_no_kind_has_is_refused(): void
    {
        $this->expectException(InternalFailureException::class);

        RewriteKind::named('Rewrote the pattern.');
    }

    /**
     * @param array{possessive?: bool, verify_with_automata?: bool} $options
     * @param list<RewriteKind>                                     $kinds
     */
    #[Test]
    #[DataProvider('provideRewrites')]
    public function test_an_optimization_lists_the_kinds_of_its_rewrites_and_whether_they_were_proven(string $pattern, array $options, string $optimized, array $kinds, bool $proven, string $subject): void
    {
        // The engine agrees on the subject: both match it, or neither.
        $this->assertSame(@preg_match($pattern, $subject, $before), @preg_match($optimized, $subject, $after));
        $this->assertSame($before, $after);

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, OptimizerOptions::fromArray($options));

        $this->assertSame($optimized, $result->optimized);
        $this->assertSame($kinds, $result->kinds);
        $this->assertSame(array_map(static fn (RewriteKind $kind): string => $kind->message(), $kinds), $result->changes);
        $this->assertSame($proven, $result->proven);
    }

    /**
     * @return iterable<string, array{pattern: string, options: array{possessive?: bool, verify_with_automata?: bool}, optimized: string, kinds: list<RewriteKind>, proven: bool, subject: string}>
     */
    public static function provideRewrites(): iterable
    {
        yield 'a rewrite the automata proved' => ['pattern' => '/[0-9]+/', 'options' => [], 'optimized' => '/\d+/', 'kinds' => [RewriteKind::Digits], 'proven' => true, 'subject' => 'ab12'];
        yield 'the same rewrite, unchecked' => ['pattern' => '/[0-9]+/', 'options' => ['verify_with_automata' => false], 'optimized' => '/\d+/', 'kinds' => [RewriteKind::Digits], 'proven' => false, 'subject' => 'ab12'];
        yield 'a possessive rewrite, checked whatever the options' => ['pattern' => '/a+b/', 'options' => ['possessive' => true, 'verify_with_automata' => false], 'optimized' => '/a++b/', 'kinds' => [RewriteKind::Possessive], 'proven' => true, 'subject' => 'aaab'];
        yield 'several kinds, in order' => ['pattern' => '/(?:[0-9]){1}x|aaaa|a|b/s', 'options' => [], 'optimized' => '/\dx|a{4}|a|b/', 'kinds' => [RewriteKind::Digits, RewriteKind::GroupRemoved, RewriteKind::CountOfOne, RewriteKind::RunCounted, RewriteKind::UnusedFlag], 'proven' => true, 'subject' => '7x'];
        yield 'several kinds, unchecked' => ['pattern' => '/(?:[0-9]){1}x|aaaa|a|b/s', 'options' => ['verify_with_automata' => false], 'optimized' => '/\dx|a{4}|a|b/', 'kinds' => [RewriteKind::Digits, RewriteKind::GroupRemoved, RewriteKind::CountOfOne, RewriteKind::RunCounted, RewriteKind::UnusedFlag], 'proven' => false, 'subject' => '7x'];
        yield 'an escape a rebuilt class does not need' => ['pattern' => '/\(([^\)]+)\)|\[(\d+)\]: /', 'options' => [], 'optimized' => '/\(([^)]+)\)|\[(\d+)\]: /', 'kinds' => [RewriteKind::Respelled], 'proven' => true, 'subject' => '(ab)'];
        // The possessive step refused beside the lookahead, the rewrite is
        // made again without it, and the flag dropped is proven.
        yield 'a rewrite made again without the possessive step' => ['pattern' => '/\d+x(?!y)/s', 'options' => [], 'optimized' => '/\d+x(?!y)/', 'kinds' => [RewriteKind::UnusedFlag], 'proven' => true, 'subject' => '12xz'];
        yield 'a pattern left as written' => ['pattern' => '/\d+/', 'options' => [], 'optimized' => '/\d+/', 'kinds' => [], 'proven' => false, 'subject' => 'ab12'];
        yield 'a pattern the engine refuses' => ['pattern' => '/[0-9]{2,1}/', 'options' => [], 'optimized' => '/[0-9]{2,1}/', 'kinds' => [], 'proven' => false, 'subject' => '12'];
    }

    /**
     * Built with its kinds, a result spells $changes from them; built with
     * messages alone, it keeps them, with no kind.
     */
    #[Test]
    public function test_a_result_spells_its_changes_from_its_kinds(): void
    {
        $result = new OptimizationResult('/[0-9]a{1}/', '/\da/', [], [RewriteKind::Digits, RewriteKind::CountOfOne], true);

        $this->assertSame([RewriteKind::Digits->message(), RewriteKind::CountOfOne->message()], $result->changes);
        $this->assertTrue($result->proven);

        $result = new OptimizationResult('/a/', '/b/', ['Changed a to b.']);

        $this->assertSame(['Changed a to b.'], $result->changes);
        $this->assertSame([], $result->kinds);
        $this->assertFalse($result->proven);
        $this->assertSame(['original' => '/a/', 'optimized' => '/b/', 'changes' => ['Changed a to b.']], $result->jsonSerialize());
    }
}
