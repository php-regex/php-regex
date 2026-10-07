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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "(?(-n)" counts back over the groups already opened: PCRE2 refuses a
 * count past them as it reads it, before any later error (pcre2test 10.49,
 * error 115 "reference to non-existent subpattern").
 */
final class RelativeConditionReferenceTest extends TestCase
{
    #[Test]
    #[DataProvider('provideBackwardReferencesPastTheGroups')]
    public function test_a_backward_count_past_the_open_groups_is_refused_first(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''), $pattern);

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame($offset, $result->offset, $pattern);
        $this->assertStringContainsString('relative reference', (string) $result->error);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideBackwardReferencesPastTheGroups(): iterable
    {
        yield 'no group before, group left open after' => ['pattern' => '/(?(-1)(/', 'offset' => 5];
        yield 'one group before, two counted' => ['pattern' => '/(a)(?(-2)(/', 'offset' => 8];
    }

    #[Test]
    public function test_a_backward_count_within_the_open_groups_is_accepted(): void
    {
        foreach (['/(a)(?(-1)a|b)/', '/((?(-1)a)b)/'] as $pattern) {
            $this->assertNotFalse(preg_match($pattern, ''), $pattern);
            $this->assertTrue(Regex::create(['cache' => null])->validate($pattern)->isValid, $pattern);
        }
    }
}
