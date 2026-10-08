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

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Measuring a lookbehind, PCRE2 finds no bound for a call or a reference
 * that stands inside the group it names, or that names a group the measure
 * reached through calls already. The groups around the lookbehind itself
 * are not being measured, and a DEFINE group is skipped. PHP 8.4.26 /
 * PCRE2 10.49 gives each row below.
 */
final class LookbehindCallRecursionTest extends TestCase
{
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_a_call_is_unbounded_only_inside_the_group_it_calls_or_along_the_calls(string $pattern, ?int $offset): void
    {
        $this->assertSame(null === $offset ? 0 : false, @preg_match($pattern, ''), $pattern);

        $result = RegexParser::create(['pcre_version' => '10.49', 'php_version' => '8.4'])->validate($pattern);
        if (null === $offset) {
            $this->assertTrue($result->isValid, (string) $result->error);

            return;
        }

        $this->assertSame(ErrorCode::LookbehindUnbounded, $result->errorCode, (string) $result->error);
        $this->assertSame($offset, $result->offset);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int|null}>
     */
    public static function providePatterns(): iterable
    {
        // Group 2 holds the lookbehind but is not being measured: its call
        // from group 1 is bounded, and the DEFINE in it adds nothing.
        yield 'a call back into the group around the lookbehind, through DEFINE' => ['pattern' => '/(a(?2))(c(?(DEFINE)(?<=(?1))))/', 'offset' => null];
        yield 'a lookbehind calling the group after the one it stands in' => ['pattern' => '/(a(?<=(?2)))(b)/', 'offset' => null];
        // "(?2)" stands inside group 2: the first lookbehind, which reaches
        // it through group 3, has no bound.
        yield 'a call inside the group it calls, reached from another group' => ['pattern' => '/(a(?<=(?3)))(b(?<=(c(?2))))/', 'offset' => 2];
        yield 'a call inside the group it calls' => ['pattern' => '/(?<=(?1))(a(?1)?)/', 'offset' => 0];
        // A lookbehind asserted as a condition is measured with the group
        // around it: here it calls group 1 back along the calls.
        yield 'a lookbehind condition calling back along the calls' => ['pattern' => '/(?<=(?1))((?(?<!(?2))x)b)((?(?<!(?1)c?)x))/', 'offset' => 28];
        // PCRE measures the branches in order: a nested lookbehind met
        // before "\X" is refused first, at its own offset.
        yield 'a nested lookbehind in a branch before one holding \\X' => ['pattern' => '/(?<=b(?<=a+)|\X)/', 'offset' => 5];
        yield 'a nested lookbehind before \\X in one branch' => ['pattern' => '/(?<=(?<=a+)\X)/', 'offset' => 4];
        yield '\\X before a nested lookbehind' => ['pattern' => '/(?<=\X(?<=a+))/', 'offset' => 0];
    }
}
