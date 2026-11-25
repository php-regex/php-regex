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

use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A lookbehind reads no character of the run that holds it: the run of
 * "(?:(?<!a)b)+" starts with "b", which the "b" after it may need back.
 * Made possessive, the repeat finds nothing in "bb".
 * Oracle, PHP 8.4 / PCRE2 10.49.
 */
final class PossessiveRunHoldingALookbehindTest extends TestCase
{
    #[Test]
    public function test_a_repeated_group_whose_run_holds_a_lookbehind_stays_greedy(): void
    {
        $pattern = '/(?:(?<!a)b)+b/';
        $this->assertSame(1, preg_match($pattern, 'bb'), 'Oracle: the greedy repeat gives a run back.');
        $this->assertSame(0, preg_match('/(?:(?<!a)b)++b/', 'bb'), 'Oracle: the possessive repeat keeps every run.');

        $this->assertSame($pattern, Regex::create(['cache' => null])->parse($pattern)->accept(new Rewriter(possessive: true))->accept(new PatternPrinter()));
    }
}
