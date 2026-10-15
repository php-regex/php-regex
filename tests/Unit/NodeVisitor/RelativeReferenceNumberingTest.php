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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A relative reference counts group numbers, not the groups written before
 * it: every branch of a branch reset numbers its groups from the same
 * number, so "(?|(a)|(b))" holds two groups but only group 1.
 */
final class RelativeReferenceNumberingTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRelativeReferences')]
    public function test_a_relative_reference_is_judged_by_group_number(string $pattern, bool $valid): void
    {
        $this->assertSame($valid, false !== @preg_match($pattern, ''), 'PCRE agrees.');
        $this->assertSame($valid, RegexParser::create()->validate($pattern)->isValid);
    }

    /**
     * @return iterable<string, array{pattern: string, valid: bool}>
     */
    public static function provideRelativeReferences(): iterable
    {
        yield 'condition, forward past a branch reset' => ['pattern' => '/(?(+2)x)(?|(a)|(b))/', 'valid' => false];
        yield 'condition, forward three past a branch reset' => ['pattern' => '/(?(+3)x)(?|(a)|(b))(c)/', 'valid' => false];
        yield 'condition, forward to the group after a branch reset' => ['pattern' => '/(?(+2)x)(?|(a)|(b))(c)/', 'valid' => true];
        yield 'condition, forward after a branch reset' => ['pattern' => '/(?|(a)(b)|(c))(?(+1)x)(d)/', 'valid' => true];
        yield 'condition, back into a branch reset' => ['pattern' => '/(?|(a)|(b))(?(-1)x)/', 'valid' => true];
        yield 'condition, back past a branch reset' => ['pattern' => '/(?|(a)|(b))(?(-2)x)/', 'valid' => false];
        yield 'back reference, forward past a branch reset' => ['pattern' => '/\g{+2}(?|(a)|(b))/', 'valid' => false];
        yield 'back reference, back into a branch reset' => ['pattern' => '/(?|(a)|(b))\g{-1}/', 'valid' => true];
        yield 'back reference, back past a branch reset' => ['pattern' => '/(?|(a)|(b))\g{-2}/', 'valid' => false];
        yield 'back reference, back past the longest branch' => ['pattern' => '/(?|(a)|(b)(c))(d)\g{-4}/', 'valid' => false];
        yield 'back reference, back to the first group' => ['pattern' => '/(?|(a)|(b)(c))(d)\g{-3}/', 'valid' => true];
        yield 'call, forward past a branch reset' => ['pattern' => '/(?+2)(?|(a)|(b))/', 'valid' => false];
        yield 'call, back into a branch reset' => ['pattern' => '/(?|(a)|(b))(?-1)/', 'valid' => true];
        yield 'call, back past a branch reset' => ['pattern' => '/(?|(a)|(b))(?-2)/', 'valid' => false];
        yield 'call with \g, back past a branch reset' => ['pattern' => '/(?|(a)|(b))\g<-2>/', 'valid' => false];
        yield 'call, back past the longest branch' => ['pattern' => '/(?|(a)|(b)(c))(d)(?-4)/', 'valid' => false];
        yield 'call in a lookbehind, back past a branch reset' => ['pattern' => '/(?|(a)|(b))(?<=(?-2))x/', 'valid' => false];
        yield 'call in a lookbehind, back into a branch reset' => ['pattern' => '/(?|(a)|(b))(?<=(?-1))x/', 'valid' => true];
        yield 'call in a lookbehind, forward past a branch reset' => ['pattern' => '/(?<=(?+2))(?|(a)|(b))/', 'valid' => false];
        yield 'condition, forward without a branch reset' => ['pattern' => '/(a)(?(+1)b)(c)/', 'valid' => true];
        yield 'condition, forward past the last group' => ['pattern' => '/(a)(?(+2)b)(c)/', 'valid' => false];
        yield 'back reference, back past the first group' => ['pattern' => '/(a)(b)\g{-3}/', 'valid' => false];
    }
}
