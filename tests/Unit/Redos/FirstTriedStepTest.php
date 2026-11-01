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
        yield 'nothing but transparent steps' => ['pattern' => '/(?#one)(?#two)/', 'expected' => true];
        yield 'lookaround is a step of its own' => ['pattern' => '/(?=a)(?R)|b/', 'expected' => false];
    }
}
