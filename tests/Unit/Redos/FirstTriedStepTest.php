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

namespace PHPRegex\Tests\Unit\Redos;

use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\FirstTriedStep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The first step a match attempt tries, read from the tree: the call to
 * the whole pattern when nothing that consumes stands before it, the
 * recursion the engine then enters with no progress made. A step that
 * consumes or may consume first answers false; a step that lets the
 * attempt through, true. The engine's own answer to such a recursion
 * depends on its JIT stack, so the shape is read here, not matched.
 */
final class FirstTriedStepTest extends TestCase
{
    #[Test]
    #[DataProvider('patterns')]
    public function test_the_first_tried_step_is_read_from_the_tree(string $pattern, bool|string $expected): void
    {
        $step = RegexParser::create()->parse($pattern)->accept(new FirstTriedStep());

        if (\is_string($expected)) {
            $this->assertInstanceOf(SubroutineNode::class, $step);
            $this->assertSame($expected, $step->reference);
        } else {
            $this->assertSame($expected, $step);
        }
    }

    /**
     * Without its JIT the engine answers a recursion it enters with no
     * progress made by failing the match, and a recursion it skips by
     * matching: the rows read under "U" agree with it on "a".
     */
    #[Test]
    #[DataProvider('ungreedyPatterns')]
    public function test_the_ungreedy_rows_agree_with_the_engine(string $pattern, bool|string $expected): void
    {
        $result = @preg_match('/(*NO_JIT)'.substr($pattern, 1), 'a');

        $this->assertSame(\is_string($expected), false === $result, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, expected: bool|string}>
     */
    public static function patterns(): iterable
    {
        yield 'recursion first' => ['pattern' => '/(?R)a|b/', 'expected' => 'R'];
        yield 'call to group zero first' => ['pattern' => '/(?0)a|b/', 'expected' => '0'];
        yield 'literal first' => ['pattern' => '/a(?R)|b/', 'expected' => false];
        yield 'call to another group first' => ['pattern' => '/(?1)|(a)/', 'expected' => false];
        yield 'empty literal lets the attempt through' => ['pattern' => '/(?:)(?R)|b/', 'expected' => 'R'];
        yield 'greedy repeat is read through its body' => ['pattern' => '/x*(?R)|b/', 'expected' => false];
        yield 'lazy repeat lets the attempt through' => ['pattern' => '/x*?(?R)|b/', 'expected' => 'R'];
        yield 'zero repeat lets the attempt through' => ['pattern' => '/x{0}(?R)|b/', 'expected' => 'R'];
        yield 'capturing group around the recursion' => ['pattern' => '/((?R))a|b/', 'expected' => 'R'];
        yield 'comment lets the attempt through' => ['pattern' => '/(?#note)(?R)|b/', 'expected' => 'R'];
        yield 'define block lets the attempt through' => ['pattern' => '/(?(DEFINE)(a))(?R)|b/', 'expected' => 'R'];
        yield 'keep lets the attempt through' => ['pattern' => '/\K(?R)|b/', 'expected' => 'R'];
        yield 'opening option lets the attempt through' => ['pattern' => '/(*UTF)(?R)|b/', 'expected' => 'R'];
        yield from self::ungreedyPatterns();
        yield 'nothing but transparent steps' => ['pattern' => '/(?#one)(?#two)/', 'expected' => true];
        yield 'lookaround is a step of its own' => ['pattern' => '/(?=a)(?R)|b/', 'expected' => false];
    }

    /**
     * @return iterable<string, array{pattern: string, expected: bool|string}>
     */
    public static function ungreedyPatterns(): iterable
    {
        yield 'root ungreedy skips recursion' => ['pattern' => '/(?R)?(a+)+$/U', 'expected' => false];
        yield 'bare ungreedy skips recursion' => ['pattern' => '/(?U)(?R)?(a+)+$/', 'expected' => false];
        yield 'scoped ungreedy skips recursion' => ['pattern' => '/(?U:(?R)?(a+)+$)/', 'expected' => false];
        yield 'bare cancellation tries recursion' => ['pattern' => '/(?-U)(?R)?a/U', 'expected' => 'R'];
        yield 'scoped cancellation tries recursion' => ['pattern' => '/(?-U:(?R)?a)/U', 'expected' => 'R'];
        yield 'ungreedy reverses lazy suffix' => ['pattern' => '/(?R)??a/U', 'expected' => 'R'];
        yield 'possessive stays greedy under ungreedy' => ['pattern' => '/(?R)?+a/U', 'expected' => 'R'];
        yield 'required repeat still enters body' => ['pattern' => '/(?R)+a/U', 'expected' => 'R'];
        yield 'group restores outer options' => ['pattern' => '/(?:(?U))(?R)?a/', 'expected' => 'R'];
        yield 'empty scope restores outer options' => ['pattern' => '/(?U:)(?R)?a/', 'expected' => 'R'];
        yield 'empty cancellation restores ungreedy' => ['pattern' => '/(?-U:)(?R)?a/U', 'expected' => false];
        yield 'caret preserves ungreedy' => ['pattern' => '/(?^)(?R)?a/U', 'expected' => false];
        yield 'later cancellation wins' => ['pattern' => '/(?U)(?-U)(?R)?a/', 'expected' => 'R'];
        yield 'empty setting lets the attempt through' => ['pattern' => '/(?)(?R)(a+)+$/', 'expected' => 'R'];
        yield 'empty setting keeps ungreedy' => ['pattern' => '/(?)(?R)?a/U', 'expected' => false];
    }
}
