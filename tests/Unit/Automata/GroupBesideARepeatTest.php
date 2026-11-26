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
 * Which brackets PCRE2 reads on its way from a repeated character to a
 * group it may skip: taking those out changes which repeats it makes
 * possessive, taking the others out changes nothing.
 */
final class GroupBesideARepeatTest extends TestCase
{
    private const SUBJECTS = ['', 'a', 'aa', 'aaa', 'ab', 'aba', 'abab', 'ababa', 'abca', 'abcda', 'abda', 'aca', 'ada', 'acda', 'xaa', 'aax', 'ba', 'abaa', 'ababca'];

    /**
     * @param int $offset where the group starts in the pattern
     */
    #[Test]
    #[DataProvider('provideGroups')]
    public function test_a_group_decides_where_the_engine_reads_its_brackets(string $pattern, int $offset, string $without, ?string $toldApartOn, bool $decides): void
    {
        // Oracle, PHP 8.4.28 / PCRE2 10.49: the subject the engine tells the
        // two patterns apart on, or none of a set.
        if (null !== $toldApartOn) {
            $this->assertNotSame(preg_match($pattern, $toldApartOn), preg_match($without, $toldApartOn));
            $this->assertNotSame(preg_match(PcreInterpreter::pattern($pattern), $toldApartOn), preg_match(PcreInterpreter::pattern($without), $toldApartOn));
        } else {
            foreach (self::SUBJECTS as $subject) {
                $this->assertSame(preg_match($pattern, $subject, $with), preg_match($without, $subject, $bare), 'Oracle: '.$subject);
                $this->assertSame($with[0] ?? null, $bare[0] ?? null, 'Oracle: '.$subject);
                $this->assertSame(preg_match(PcreInterpreter::pattern($pattern), $subject), preg_match(PcreInterpreter::pattern($without), $subject), 'Oracle, interpreter: '.$subject);
            }
        }

        $ast = RegexParser::create(['cache' => null])->parse($pattern);
        $group = self::groupAt($ast->pattern, $offset);
        $this->assertInstanceOf(GroupNode::class, $group);

        $this->assertSame($decides, AutoPossession::groupDecidesBesideARepeat($ast->pattern, $group));
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int, without: string, toldApartOn: string|null, decides: bool}>
     */
    public static function provideGroups(): iterable
    {
        yield 'the group a possessive quantifier repeats, right after a repeat' => ['pattern' => '/a+(?:b)?+a/', 'offset' => 2, 'without' => '/a+b?+a/', 'toldApartOn' => 'aa', 'decides' => true];
        yield 'the same behind a letter' => ['pattern' => '/a+x(?:b)?+a/', 'offset' => 3, 'without' => '/a+xb?+a/', 'toldApartOn' => null, 'decides' => false];
        yield 'the group of a repeat, right before a possessive group' => ['pattern' => '/(?:a)+(?:b)?+a/', 'offset' => 0, 'without' => '/a+(?:b)?+a/', 'toldApartOn' => 'aa', 'decides' => true];
        yield 'the same with a letter between' => ['pattern' => '/(?:a)+x(?:b)?+a/', 'offset' => 0, 'without' => '/a+x(?:b)?+a/', 'toldApartOn' => null, 'decides' => false];
        // Taking the outer group out leaves a repeat of a group: no
        // repeat of one character is made, and "c?" reads no further back.
        yield 'a repeated group around a group' => ['pattern' => '/(?:(?:ab))+c?(?>(?:d)?)a/', 'offset' => 0, 'without' => '/(?:ab)+c?(?>(?:d)?)a/', 'toldApartOn' => null, 'decides' => false];
        yield 'a group counted, which makes no repeat PCRE2 could make possessive' => ['pattern' => '/(?:a){2}(?:b)?+a/', 'offset' => 0, 'without' => '/a{2}(?:b)?+a/', 'toldApartOn' => null, 'decides' => false];
        yield 'a group no repeat stands before' => ['pattern' => '/(?:c)x(?:b)?+a/', 'offset' => 0, 'without' => '/cx(?:b)?+a/', 'toldApartOn' => null, 'decides' => false];
    }

    /**
     * A node built by hand may hold a quantifier whose count is not read:
     * it is taken to run its group again, so that the repeat inside it
     * stands before the atomic group that opens the next round.
     */
    #[Test]
    public function test_a_quantifier_whose_count_is_not_read_may_run_twice(): void
    {
        // Oracle, PHP 8.4.28 / PCRE2 10.49: the engine reads "{two}" as
        // text, so that only a tree built by hand holds it as a quantifier;
        // counted twice, the group tells "aa" apart with and without the
        // brackets, and read once it does not.
        $this->assertSame(1, preg_match('/^a{two}$/', 'a{two}'));
        $this->assertSame([0, 1], [preg_match('/(?:(?>(?:b)?)a+){2}/', 'aa'), preg_match('/(?:(?>b?)a+){2}/', 'aa')]);
        $this->assertSame([1, 1], [preg_match('/(?:(?>(?:b)?)a+)?/', 'aa'), preg_match('/(?:(?>b?)a+)?/', 'aa')]);

        $inner = new GroupNode(new LiteralNode('b', 0, 0), GroupType::NonCapturing);
        $round = new GroupNode(new SequenceNode([
            new GroupNode(new QuantifierNode($inner, '?', QuantifierType::Greedy, 0, 0), GroupType::Atomic),
            new QuantifierNode(new LiteralNode('a', 0, 0), '+', QuantifierType::Greedy, 0, 0),
        ], 0, 0), GroupType::NonCapturing);

        $counted = new QuantifierNode($round, '{2}', QuantifierType::Greedy, 0, 0);
        $this->assertTrue(AutoPossession::groupDecidesBesideARepeat($counted, $inner));

        $once = new QuantifierNode($round, '?', QuantifierType::Greedy, 0, 0);
        $this->assertFalse(AutoPossession::groupDecidesBesideARepeat($once, $inner));

        $unread = new QuantifierNode($round, '{two}', QuantifierType::Greedy, 0, 0);
        $this->assertNull($unread->bounds);
        $this->assertTrue(AutoPossession::groupDecidesBesideARepeat($unread, $inner));
    }

    private static function groupAt(NodeInterface $node, int $offset): ?GroupNode
    {
        if ($node instanceof GroupNode && $offset === $node->getStartPosition()) {
            return $node;
        }

        foreach ($node->getChildren() as $child) {
            $found = self::groupAt($child, $offset);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }
}
