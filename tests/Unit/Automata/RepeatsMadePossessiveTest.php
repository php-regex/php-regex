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

use PHPRegex\Automata\LanguageSolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE2 makes a character repeated before a possessive or atomic group
 * that may match nothing possessive; a repeat after every such group runs
 * as written, and the match solver reads it so.
 */
final class RepeatsMadePossessiveTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRepeatsAfterEverySkippableGroup')]
    public function test_a_repeat_after_every_group_the_engine_may_skip_runs_as_written(string $left, string $right): void
    {
        foreach (['', 'a', 'aa', 'aaa', 'baa', 'ba', 'bb'] as $subject) {
            $this->assertSame(preg_match($left, $subject, $leftMatch), preg_match($right, $subject, $rightMatch), 'Oracle: '.$subject);
            $this->assertSame($leftMatch, $rightMatch, 'Oracle: '.$subject);
        }
        $this->assertSame(1, preg_match($left, 'aa'), 'Oracle: the repeat gives a character back.');

        $this->assertTrue((new LanguageSolver())->matchEquivalent($left, $right)->isEquivalent);
    }

    /**
     * @return iterable<string, array{left: string, right: string}>
     */
    public static function provideRepeatsAfterEverySkippableGroup(): iterable
    {
        yield 'after a possessive optional group' => ['left' => '/(?:b)?+a+a/', 'right' => '/(?:b)?+aa+/'];
        yield 'after a possessive optional group and a repeat before it' => ['left' => '/c*+(?:b)?+a+a/', 'right' => '/c*+(?:b)?+aa+/'];
    }
}
