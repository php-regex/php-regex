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

use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Optimizer\PossessiveRewrites;
use PHPRegex\Optimizer\RepeatRewrites;
use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Optimizer\RewriteState;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What follows a non-atomic lookaround backtracks into it, as it does into
 * a scan substring: "^(*napla:(a+b?))\1\1" gives an "a" of group 1 back to
 * the references after it on "aa", where "(a++b?)" gives none. And the
 * engine reads what follows from where the lookaround stands, not from
 * where the repetition ends: of what follows the end of a non-atomic
 * lookaround the possessive rewrite knows nothing, and a repetition that
 * may end one stays greedy, unless the lookaround itself ends an atomic
 * group or lookaround, which nothing after it backtracks into.
 *
 * A repetition whose follower is read where the repetition ends is made
 * possessive as anywhere else: outside the lookaround, or inside it before
 * a character it never gives back.
 */
final class PossessiveNonAtomicLookaroundTest extends TestCase
{
    /**
     * The characters of the subjects each pair is run on, every string of
     * them up to five long.
     */
    private const ALPHABET = ['a', 'b', 'c', 'x'];

    /**
     * Past a non-atomic lookahead the engine reads from where the
     * lookahead starts: the "x" after "(*napla:x(a+b?))" is no character
     * the repetition gives back, yet the reference after it reads the "a"
     * group 1 gave back on "xaaba".
     */
    #[Test]
    #[DataProvider('provideNonAtomicLookaroundsBeforeWhatReadsTheirGroups')]
    public function test_a_repetition_ending_a_non_atomic_lookaround_is_left_as_written_before_what_reads_its_group(string $pattern, string $possessive, string $subject): void
    {
        $this->assertNotSame(self::matched($pattern, $subject), self::matched($possessive, $subject), \sprintf('Oracle: %s and %s match alike on "%s".', $pattern, $possessive, $subject));

        $ast = Regex::create(['cache' => null])->parse($pattern);
        $this->assertSame($ast, $ast->accept(new Rewriter(possessive: true)), 'The rewriter leaves the pattern as written.');
    }

    /**
     * @return iterable<string, array{pattern: string, possessive: string, subject: string}>
     */
    public static function provideNonAtomicLookaroundsBeforeWhatReadsTheirGroups(): iterable
    {
        yield 'a non-atomic lookahead' => ['pattern' => '/^(*napla:x(a+b?))x[ab-]*\1$/', 'possessive' => '/^(*napla:x(a++b?))x[ab-]*\1$/', 'subject' => 'xaaba'];
        yield 'a non-atomic lookahead, its short form' => ['pattern' => '/^(?*x(a+b?))x[ab-]*\1$/', 'possessive' => '/^(?*x(a++b?))x[ab-]*\1$/', 'subject' => 'xaaba'];
        yield 'a non-atomic lookahead in a lookahead, before more of it' => ['pattern' => '/^(?=(?*x(a+b?))x[ab-]*\1$)/', 'possessive' => '/^(?=(?*x(a++b?))x[ab-]*\1$)/', 'subject' => 'xaaba'];
    }

    /**
     * Nothing is known of what follows the end of a non-atomic lookaround:
     * these stay greedy too, though the engine matches them alike.
     */
    #[Test]
    #[DataProvider('provideRepetitionsThatMayEndANonAtomicLookaround')]
    public function test_a_repetition_that_may_end_a_non_atomic_lookaround_stays_greedy(string $pattern, string $possessive): void
    {
        foreach (self::subjects() as $subject) {
            $this->assertSame(self::matched($pattern, $subject), self::matched($possessive, $subject), \sprintf('Oracle: %s and %s differ on "%s".', $pattern, $possessive, $subject));
        }

        $ast = Regex::create(['cache' => null])->parse($pattern);
        $this->assertSame($ast, $ast->accept(new Rewriter(possessive: true)), 'The rewriter leaves the pattern as written.');
        $this->assertSame($pattern, (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(possessive: true))->optimized);
    }

    /**
     * @return iterable<string, array{pattern: string, possessive: string}>
     */
    public static function provideRepetitionsThatMayEndANonAtomicLookaround(): iterable
    {
        yield 'no reference after the lookahead' => ['pattern' => '/(*napla:xa+)x/', 'possessive' => '/(*napla:xa++)x/'];
        yield 'an item that may match nothing before its end' => ['pattern' => '/(*napla:x(a+b?))x[ab]/', 'possessive' => '/(*napla:x(a++b?))x[ab]/'];
        yield 'a non-atomic lookahead ending the pattern' => ['pattern' => '/x(?*a+)/', 'possessive' => '/x(?*a++)/'];
        yield 'a group around it in a lookahead' => ['pattern' => '/^(?=((?*x(a+b?))))x[ab]/', 'possessive' => '/^(?=((?*x(a++b?))))x[ab]/'];
        yield 'a repeat around it in a lookahead' => ['pattern' => '/^(?=(?:(?*x(a+b?))){2})x[ab]/', 'possessive' => '/^(?=(?:(?*x(a++b?))){2})x[ab]/'];
        yield 'an item after it in a lookahead' => ['pattern' => '/^(?=(?*x(a+b?))x)x[ab]/', 'possessive' => '/^(?=(?*x(a++b?))x)x[ab]/'];
    }

    /**
     * A non-atomic lookaround that ends an atomic one is followed by
     * nothing: the rewriter offers the possessive there. The check of the
     * result, which knows nothing of what follows the end of a non-atomic
     * lookaround, does not prove it, and the pattern stays as written.
     */
    #[Test]
    #[DataProvider('provideNonAtomicLookaroundsEndingAnAtomicOne')]
    public function test_a_repetition_ending_a_non_atomic_lookaround_that_ends_an_atomic_one_is_offered_and_not_proven(string $pattern, string $possessive): void
    {
        foreach (self::subjects() as $subject) {
            $this->assertSame(self::matched($pattern, $subject), self::matched($possessive, $subject), \sprintf('Oracle: %s and %s differ on "%s".', $pattern, $possessive, $subject));
        }

        $rewriter = new Rewriter(possessive: true);
        $this->assertSame($possessive, Regex::create(['cache' => null])->parse($pattern)->accept($rewriter)->accept(new PatternPrinter()));
        $this->assertSame([Rewriter::POSSESSIVE], $rewriter->changes());

        $this->assertSame($pattern, (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(possessive: true))->optimized);
    }

    /**
     * @return iterable<string, array{pattern: string, possessive: string}>
     */
    public static function provideNonAtomicLookaroundsEndingAnAtomicOne(): iterable
    {
        yield 'a lookahead that is the whole pattern' => ['pattern' => '/(?=(?*a+))/', 'possessive' => '/(?=(?*a++))/'];
        yield 'after an item of a lookahead' => ['pattern' => '/(?=x(?*a+))x/', 'possessive' => '/(?=x(?*a++))x/'];
        yield 'a negative lookahead' => ['pattern' => '/(?!x(?*a+b?))x/', 'possessive' => '/(?!x(?*a++b?))x/'];
    }

    /**
     * The rewrite spells a non-atomic lookaround in its short form, as
     * every rewrite of a pattern that holds one does.
     *
     * @param list<string> $changes
     */
    #[Test]
    #[DataProvider('provideRepetitionsReadWhereTheyEnd')]
    public function test_a_repetition_beside_a_non_atomic_lookaround_is_made_possessive(string $pattern, string $optimized, array $changes): void
    {
        foreach (self::subjects() as $subject) {
            $this->assertSame(self::matched($pattern, $subject), self::matched($optimized, $subject), \sprintf('Oracle: %s and %s differ on "%s".', $pattern, $optimized, $subject));
            $this->assertSame(self::matched(self::interpreted($pattern), $subject), self::matched(self::interpreted($optimized), $subject), \sprintf('Oracle: %s and %s differ on "%s" without the JIT.', $pattern, $optimized, $subject));
        }

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(possessive: true));

        $this->assertSame($optimized, $result->optimized);
        $this->assertSame($changes, $result->changes);
    }

    /**
     * @return iterable<string, array{pattern: string, optimized: string, changes: list<string>}>
     */
    public static function provideRepetitionsReadWhereTheyEnd(): iterable
    {
        yield 'after a non-atomic lookahead' => ['pattern' => '/(*napla:x)xa+b/', 'optimized' => '/(?*x)xa++b/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'after a non-atomic lookahead, its short form' => ['pattern' => '/(?*x)xa+b/', 'optimized' => '/(?*x)xa++b/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'after a non-atomic lookbehind' => ['pattern' => '/(*naplb:x)a+b/', 'optimized' => '/(?<*x)a++b/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'before a character and a non-atomic lookahead' => ['pattern' => '/xa+b(*napla:c)/', 'optimized' => '/xa++b(?*c)/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'in a non-atomic lookahead, before a character' => ['pattern' => '/(*napla:xa+b)x/', 'optimized' => '/(?*xa++b)x/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'in a group of a non-atomic lookahead, before a character' => ['pattern' => '/(*napla:x(a+)b)x/', 'optimized' => '/(?*x(a++)b)x/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'in a non-atomic lookahead and after it' => ['pattern' => '/(*napla:xa+b)xa+b/s', 'optimized' => '/(?*xa++b)xa++b/', 'changes' => [Rewriter::POSSESSIVE, Rewriter::UNUSED_FLAG]];
        // The one that may end the lookahead stays greedy, the other does not.
        yield 'after a non-atomic lookahead a repetition may end' => ['pattern' => '/(*napla:xa+)xa+b/', 'optimized' => '/(?*xa+)xa++b/', 'changes' => [Rewriter::POSSESSIVE]];
    }

    #[Test]
    #[DataProvider('provideNonAtomicLookarounds')]
    public function test_a_repetition_ending_a_non_atomic_lookaround_stays_greedy(string $pattern, string $possessive, string $subject): void
    {
        $this->assertNotSame(self::matched($pattern, $subject), self::matched($possessive, $subject), \sprintf('Oracle: %s and %s match alike on "%s".', $pattern, $possessive, $subject));

        $this->assertSame(QuantifierType::Greedy, self::firstRepetition($pattern)->type);
        $ast = Regex::create(['cache' => null])->parse($pattern);
        $this->assertSame($ast, $ast->accept(new Rewriter(possessive: true)), 'The rewriter leaves the pattern as written.');
    }

    /**
     * @return iterable<string, array{pattern: string, possessive: string, subject: string}>
     */
    public static function provideNonAtomicLookarounds(): iterable
    {
        yield 'a non-atomic lookahead' => ['pattern' => '/^(*napla:(a+b?))\1\1/', 'possessive' => '/^(*napla:(a++b?))\1\1/', 'subject' => 'aa'];
        yield 'a non-atomic lookahead, its long name' => ['pattern' => '/^(*non_atomic_positive_lookahead:(a+b?))\1\1/', 'possessive' => '/^(*non_atomic_positive_lookahead:(a++b?))\1\1/', 'subject' => 'aa'];
        yield 'a non-atomic lookahead, its short form' => ['pattern' => '/^(?*(a+b?))\1\1/', 'possessive' => '/^(?*(a++b?))\1\1/', 'subject' => 'aa'];
        yield 'a non-atomic lookahead in a non-atomic lookbehind' => ['pattern' => '/^(*naplb:(*napla:(a+b?)))\1\1/', 'possessive' => '/^(*naplb:(*napla:(a++b?)))\1\1/', 'subject' => 'aa'];
        yield 'a non-atomic lookahead in a non-atomic lookbehind, short forms' => ['pattern' => '/^(?<*(?*(a+b?)))\1\1/', 'possessive' => '/^(?<*(?*(a++b?)))\1\1/', 'subject' => 'aa'];
    }

    #[Test]
    #[DataProvider('provideAtomicLookarounds')]
    public function test_a_repetition_ending_an_atomic_lookaround_is_made_possessive(string $pattern, string $possessive): void
    {
        foreach (['', 'a', 'aa', 'ab', 'aab', 'aaaa', 'aaab', 'abab', 'aabaab', 'baa'] as $subject) {
            $this->assertSame(self::matched($pattern, $subject), self::matched($possessive, $subject), \sprintf('Oracle: %s and %s differ on "%s".', $pattern, $possessive, $subject));
        }

        $this->assertSame(QuantifierType::Possessive, self::firstRepetition($pattern)->type);
    }

    /**
     * @return iterable<string, array{pattern: string, possessive: string}>
     */
    public static function provideAtomicLookarounds(): iterable
    {
        yield 'a lookahead' => ['pattern' => '/^(?=(a+b?))\1\1/', 'possessive' => '/^(?=(a++b?))\1\1/'];
        yield 'a lookahead, its name' => ['pattern' => '/^(*pla:(a+b?))\1\1/', 'possessive' => '/^(*pla:(a++b?))\1\1/'];
        yield 'a non-atomic lookahead in a lookbehind' => ['pattern' => '/^(?<=(?*(a+b?)))\1\1/', 'possessive' => '/^(?<=(?*(a++b?)))\1\1/'];
        yield 'a non-atomic lookahead ending a lookahead' => ['pattern' => '/^(?=a(?*(a+b?)))\1\1/', 'possessive' => '/^(?=a(?*(a++b?)))\1\1/'];
        yield 'a lookahead in a non-atomic lookbehind' => ['pattern' => '/^(*naplb:(?=(a+b?)))\1\1/', 'possessive' => '/^(*naplb:(?=(a++b?)))\1\1/'];
    }

    /**
     * The first repetition of the pattern as the possessive rewrite returns
     * the items of its sequence.
     */
    private static function firstRepetition(string $pattern): QuantifierNode
    {
        $ast = Regex::create(['cache' => null])->parse($pattern);
        $state = new RewriteState();
        $state->start($ast, false);
        $items = self::possessified($ast->pattern, new PossessiveRewrites($state, new RepeatRewrites($state, 4)));
        $repetition = $items[0] ?? null;
        self::assertInstanceOf(QuantifierNode::class, $repetition);

        return $repetition;
    }

    /**
     * The items of the first sequence that starts with a repetition, as the
     * possessive rewrite returns them, the way down recorded as the
     * rewriter records it.
     *
     * @return list<NodeInterface>|null
     */
    private static function possessified(NodeInterface $node, PossessiveRewrites $rewrites): ?array
    {
        if ($node instanceof SequenceNode && $node->children[0] instanceof QuantifierNode) {
            return $rewrites->possessify($node->children, $node);
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $index => $child) {
                $rewrites->enter($node->children, $index + 1);
                $items = self::possessified($child, $rewrites);
                $rewrites->leave();
                if (null !== $items) {
                    return $items;
                }
            }

            return null;
        }

        if (!$node instanceof GroupNode) {
            return null;
        }

        $rewrites->enterGroup($node);
        $items = self::possessified($node->child, $rewrites);
        $rewrites->leaveGroup($node);

        return $items;
    }

    /**
     * The pattern as PCRE2's interpreter runs it, whatever pcre.jit says.
     */
    private static function interpreted(string $pattern): string
    {
        return $pattern[0].'(*NO_JIT)'.substr($pattern, 1);
    }

    /**
     * @return list<string>
     */
    private static function subjects(): array
    {
        $subjects = [''];
        for ($from = 0, $length = 1; $length <= 5; $length++) {
            $to = \count($subjects);
            for ($i = $from; $i < $to; $i++) {
                foreach (self::ALPHABET as $character) {
                    $subjects[] = $subjects[$i].$character;
                }
            }
            $from = $to;
        }

        return $subjects;
    }

    /**
     * What preg_match() answers, with the text and the offset of the match
     * and of each group.
     *
     * @return array{int|false, array<array-key, mixed>}
     */
    private static function matched(string $pattern, string $subject): array
    {
        $result = preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE);

        return [$result, $matches];
    }
}
