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

namespace PHPRegex\Tests\Unit\Automata;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Transform\AutoPossession;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A group repeated more than once is compiled once per round: PCRE2 reads
 * the repeat that ends a round against the atomic group that opens the
 * next, and makes it possessive as it does a repeat written before the
 * group. "/(?:(?>(?:b)?)a+){2}/" finds nothing in "aa", where
 * "/(?:(?>b?)a+){2}/" finds it, with and without the JIT, from PCRE2 10.39
 * to 10.49.
 */
final class RepeatBeforeTheNextRoundTest extends TestCase
{
    /**
     * @param bool      $madePossessive whether the engine runs the pattern otherwise than written on "aa"
     * @param list<int> $repeats        where the repeats the library names start
     */
    #[Test]
    #[DataProvider('provideRounds')]
    public function test_a_repeat_before_the_group_of_the_next_round_is_named(string $pattern, bool $madePossessive, array $repeats): void
    {
        // Oracle, PHP 8.4.28 / PCRE2 10.49, and 10.39 to 10.44: under
        // "(*NO_AUTO_POSSESS)" the engine runs each repeat as written.
        $asWritten = '/(*NO_AUTO_POSSESS)'.substr($pattern, 1);
        $this->assertSame($madePossessive, preg_match($pattern, 'aa') !== preg_match($asWritten, 'aa'));
        $this->assertSame($madePossessive, preg_match(PcreInterpreter::pattern($pattern), 'aa') !== preg_match(PcreInterpreter::pattern($asWritten), 'aa'));

        $tree = RegexParser::create(['cache' => null])->parse($pattern)->pattern;

        $this->assertSame($repeats, array_keys(AutoPossession::repeatsBeforeSkippableGroups($tree)));
        $this->assertSame([] !== $repeats, null !== AutoPossession::repeatBeforeSkippableGroup($tree));
    }

    /**
     * @return iterable<string, array{pattern: string, madePossessive: bool, repeats: list<int>}>
     */
    public static function provideRounds(): iterable
    {
        yield 'a group counted twice' => ['pattern' => '/(?:(?>(?:b)?)a+){2}/', 'madePossessive' => true, 'repeats' => [13]];
        yield 'a group counted with no upper bound' => ['pattern' => '/(?:(?>(?:b)?)a+){2,}/', 'madePossessive' => true, 'repeats' => [13]];
        yield 'the two in a group of the round' => ['pattern' => '/(?:c?(?:(?>(?:b)?)a+)){2}/', 'madePossessive' => true, 'repeats' => [3, 18]];
        yield 'a group read once' => ['pattern' => '/(?:(?>(?:b)?)a+)?/', 'madePossessive' => false, 'repeats' => []];
        yield 'a round that holds no repeat of one character' => ['pattern' => '/(?:(?>(?:b)?)(?:a)+){2}/', 'madePossessive' => false, 'repeats' => []];
        yield 'a round that holds a letter counted, which runs one way' => ['pattern' => '/(?:(?>(?:b)?)a{2}){2}/', 'madePossessive' => false, 'repeats' => []];
        yield 'a round that holds no group the engine may skip' => ['pattern' => '/(?>(?:b)?)(?:a+){2}/', 'madePossessive' => false, 'repeats' => []];
        // "+", "*" and a count from one are compiled once: the engine
        // reads no further than the end of the round.
        yield 'a group repeated with a plus' => ['pattern' => '/(?:(?>(?:b)?)a+)+/', 'madePossessive' => false, 'repeats' => []];
        yield 'a group repeated with a star' => ['pattern' => '/(?:(?>(?:b)?)a+)*/', 'madePossessive' => false, 'repeats' => []];
        yield 'a group counted from one' => ['pattern' => '/(?:(?>(?:b)?)a+){1,}/', 'madePossessive' => false, 'repeats' => []];
        // Read wider than the engine tells on "aa": the second round may be
        // skipped, and a letter that must be there stops the engine.
        yield 'a group counted up to twice' => ['pattern' => '/(?:(?>(?:b)?)a+){0,2}/', 'madePossessive' => false, 'repeats' => [13]];
        yield 'a letter before the atomic group of the next round' => ['pattern' => '/(?:x?(?:c(?>(?:b)?))?a+){2}/', 'madePossessive' => false, 'repeats' => [3, 21]];
    }

    /**
     * The strings both patterns match whole are no proof of what the
     * engine finds: the automata refuse the pattern.
     */
    #[Test]
    public function test_the_automata_refuse_a_repeat_before_the_group_of_the_next_round(): void
    {
        // Oracle, PHP 8.4.28 / PCRE2 10.49, and 10.39 to 10.44.
        $this->assertSame([0, 1], [preg_match('/^(?:(?>(?:b)?)a+){2}$/', 'aa'), preg_match('/^(?:(?>b?)a+){2}$/', 'aa')]);
        $this->assertSame([0, 1], [preg_match(PcreInterpreter::pattern('/^(?:(?>(?:b)?)a+){2}$/'), 'aa'), preg_match(PcreInterpreter::pattern('/^(?:(?>b?)a+){2}$/'), 'aa')]);

        $solver = new LanguageSolver(RegexParser::create(['cache' => null]));

        try {
            $solver->equivalent('/(?:(?>(?:b)?)a+){2}/', '/(?:(?>b?)a+){2}/');

            $this->fail('The two patterns are called equivalent.');
        } catch (ComplexityException $refusal) {
            $this->assertStringContainsString('as if the group could not be skipped', $refusal->getMessage());
        }

        // The match solver proved the same of a possessive repeat of a
        // group, which it reads: it refuses it now, and still proves the
        // pair a letter that must be there parts the rounds of.
        $this->assertSame([0, 1], [preg_match('/(?:(?:b)?+a+){2}/', 'aa'), preg_match('/(?:b?+a+){2}/', 'aa')]);
        $this->assertSame([0, 1], [preg_match(PcreInterpreter::pattern('/(?:(?:b)?+a+){2}/'), 'aa'), preg_match(PcreInterpreter::pattern('/(?:b?+a+){2}/'), 'aa')]);

        try {
            $solver->matchEquivalent('/(?:(?:b)?+a+){2}/', '/(?:b?+a+){2}/');

            $this->fail('The two patterns are proven to write the same matches.');
        } catch (ComplexityException $refusal) {
            $this->assertStringContainsString('as if the group could not be skipped', $refusal->getMessage());
        }

        foreach (['', 'ac', 'acac', 'aacbac', 'bacbaac', 'aca', 'acbc', 'aacaac'] as $subject) {
            $this->assertSame(preg_match('/(?:(?:b)?+a+c){2}/', $subject, $kept), preg_match('/(?:b?+a+c){2}/', $subject, $dropped), 'Oracle: '.$subject);
            $this->assertSame($kept, $dropped, 'Oracle: '.$subject);
        }
        $this->assertTrue($solver->matchEquivalent('/(?:(?:b)?+a+c){2}/', '/(?:b?+a+c){2}/')->isEquivalent);

        // Read once, the group stands before the repeat alone: the two
        // match the same strings, and the engine finds them.
        $this->assertSame([1, 1], [preg_match('/^(?:(?>(?:b)?)a+)?$/', 'aa'), preg_match('/^(?:(?>b?)a+)?$/', 'aa')]);
        $this->assertTrue($solver->equivalent('/(?:(?>(?:b)?)a+)?/', '/(?:(?>b?)a+)?/')->isEquivalent);
    }

    /**
     * Taking the group of a repeat out makes a repeat of one character,
     * which the engine then reads against the atomic group of the next
     * round: the group decides.
     */
    #[Test]
    public function test_the_group_of_a_repeat_before_the_group_of_the_next_round_decides(): void
    {
        // Oracle, PHP 8.4.28 / PCRE2 10.49, and 10.39 to 10.44.
        $this->assertSame([1, 0], [preg_match('/(?:(?>(?:b)?)(?:a)+){2}/', 'aa'), preg_match('/(?:(?>(?:b)?)a+){2}/', 'aa')]);
        $this->assertSame([1, 0], [preg_match(PcreInterpreter::pattern('/(?:(?>(?:b)?)(?:a)+){2}/'), 'aa'), preg_match(PcreInterpreter::pattern('/(?:(?>(?:b)?)a+){2}/'), 'aa')]);
        $this->assertSame([1, 1], [preg_match('/(?:(?>(?:b)?)(?:a)+)?/', 'aa'), preg_match('/(?:(?>(?:b)?)a+)?/', 'aa')]);

        $counted = RegexParser::create(['cache' => null])->parse('/(?:(?>(?:b)?)(?:a)+){2}/')->pattern;
        $this->assertNull(AutoPossession::repeatBeforeSkippableGroup($counted), 'As written, the round holds no repeat of one character.');
        $this->assertTrue(AutoPossession::groupDecides($counted, self::groupAt($counted, 13)));

        $once = RegexParser::create(['cache' => null])->parse('/(?:(?>(?:b)?)(?:a)+)?/')->pattern;
        $this->assertFalse(AutoPossession::groupDecides($once, self::groupAt($once, 13)));
    }

    /**
     * A node built by hand may hold a quantifier whose count is not read:
     * it is taken to compile its group more than once.
     */
    #[Test]
    public function test_a_quantifier_whose_count_is_not_read_is_taken_to_run_again(): void
    {
        // Oracle, PHP 8.4.28 / PCRE2 10.49: the engine reads "{two}" as
        // text, so that only a tree built by hand holds it as a quantifier.
        $this->assertSame(1, preg_match('/^a{two}$/', 'a{two}'));

        $skipped = new GroupNode(new QuantifierNode(new GroupNode(new LiteralNode('b', 0, 0), GroupType::NonCapturing), '?', QuantifierType::Greedy, 0, 0), GroupType::Atomic, null, null, 0, 6);
        $round = new GroupNode(new SequenceNode([$skipped, new QuantifierNode(new LiteralNode('a', 6, 7), '+', QuantifierType::Greedy, 6, 8)], 0, 8), GroupType::NonCapturing);

        $unread = new QuantifierNode($round, '{two}', QuantifierType::Greedy, 0, 0);
        $this->assertNull($unread->bounds);
        $this->assertSame($skipped, AutoPossession::repeatBeforeSkippableGroup($unread));
        $this->assertSame([6], array_keys(AutoPossession::repeatsBeforeSkippableGroups($unread)));

        $once = new QuantifierNode($round, '?', QuantifierType::Greedy, 0, 0);
        $this->assertNull(AutoPossession::repeatBeforeSkippableGroup($once));
        $this->assertSame([], AutoPossession::repeatsBeforeSkippableGroups($once));
    }

    private static function groupAt(NodeInterface $node, int $offset): GroupNode
    {
        $pending = [$node];
        while (null !== $next = array_shift($pending)) {
            if ($next instanceof GroupNode && $offset === $next->getStartPosition()) {
                return $next;
            }

            array_push($pending, ...$next->getChildren());
        }

        self::fail(\sprintf('No group starts at offset %d.', $offset));
    }
}
