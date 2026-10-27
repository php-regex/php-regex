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

use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A DEFINE group the pattern repeats stands as it is written: PCRE2
 * compiles "(?(DEFINE)a){7000}", where it reads the group once, and
 * refuses "(?:(?(DEFINE)a)){7000}", where it compiles it once for each
 * repeat, as too large. Its body is still rewritten; the group around it
 * is not added.
 */
final class DefineGroupRepeatTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRepeatedDefines')]
    public function test_the_rewrite_of_a_repeated_define_group_compiles_and_matches_the_same(string $pattern): void
    {
        $regex = Regex::create(['cache' => null]);
        $subject = 'ab';

        $this->assertSame(1, preg_match($pattern, $subject), 'Oracle: PCRE compiles and matches the pattern.');

        foreach ([[], ['possessive' => true], ['factorize' => true]] as $options) {
            $optimized = $regex->optimize($pattern, OptimizerOptions::fromArray($options))->optimized;

            $this->assertTrue($regex->validate($optimized)->isValid, $optimized);
            $this->assertSame(
                preg_match($pattern, $subject, $before),
                preg_match($optimized, $subject, $after),
                $optimized,
            );
            $this->assertSame($before, $after, $optimized);
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRepeatedDefines(): iterable
    {
        yield 'a define group repeated past the compiled size limit' => ['pattern' => '/(?(DEFINE)a){7000}/'];
        yield 'a define group repeated twice, its body rewritten' => ['pattern' => '/(?(DEFINE)[0-9]){2}/'];
        yield 'a define group beside a subroutine call' => ['pattern' => '/(?(DEFINE)(a))(?1)b/'];
        yield 'a define group not repeated, beside text' => ['pattern' => '/(?(DEFINE)a)b/'];
    }
}
