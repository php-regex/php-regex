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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The match solver checks each possessive quantifier of a pattern: one
 * that never gives back does not vouch for another. In "/c++ca++b/u" the
 * "a++" gives nothing back to the "b", and the "c++" would have to give a
 * "c" back. Under "u" no locale tables are read.
 */
final class PossessiveAfterAnotherPossessiveTest extends TestCase
{
    #[Test]
    public function test_a_possessive_that_gives_back_beside_one_that_does_not_is_refused(): void
    {
        $this->assertSame(0, preg_match('/c++ca++b/u', 'ccab'), 'Oracle: the possessive keeps the last "c".');
        $this->assertSame(1, preg_match('/c+ca+b/u', 'ccab'), 'Oracle: the greedy repeat gives the last "c" back.');

        $this->expectException(ComplexityException::class);

        (new LanguageSolver())->matchEquivalent('/c++ca++b/u', '/c+ca+b/u');
    }
}
