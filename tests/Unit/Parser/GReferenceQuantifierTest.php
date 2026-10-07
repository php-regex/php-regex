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
 * A bare "\g" reference reads a sign and digits, no more: a "+" after the
 * digits is a quantifier, and a count after it stacks on it, which PCRE2
 * refuses (pcre2test 10.49, error 109).
 */
final class GReferenceQuantifierTest extends TestCase
{
    #[Test]
    #[DataProvider('provideStackedQuantifiers')]
    public function test_a_count_after_a_quantified_reference_is_refused_where_pcre_refuses_it(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''), $pattern);

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame($offset, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideStackedQuantifiers(): iterable
    {
        yield 'relative reference' => ['pattern' => '/(a)\g-1+{2}/', 'offset' => 11];
        yield 'absolute reference' => ['pattern' => '/(a)\g1+{2}/', 'offset' => 10];
    }

    #[Test]
    public function test_a_quantified_reference_repeats_the_group(): void
    {
        // Oracle: "\g1+" repeats the reference, "aaa" matches whole.
        $this->assertSame(1, preg_match('/^(a)\g1+$/', 'aaa'));
        $this->assertSame(1, preg_match('/^(a)\g-1+$/', 'aaa'));

        $this->assertTrue(Regex::create(['cache' => null])->validate('/^(a)\g1+$/')->isValid);
        $this->assertTrue(Regex::create(['cache' => null])->validate('/^(a)\g-1+$/')->isValid);
    }
}
