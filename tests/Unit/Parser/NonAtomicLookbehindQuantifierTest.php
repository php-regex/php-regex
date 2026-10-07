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
 * "(?<*" opens a non-atomic lookbehind: a "+" or "?" right after the "*"
 * repeats nothing, which PCRE2 refuses past it (pcre2test 10.49, error
 * 109 "quantifier does not follow a repeatable item").
 */
final class NonAtomicLookbehindQuantifierTest extends TestCase
{
    #[Test]
    #[DataProvider('provideQuantifiersAfterTheOpener')]
    public function test_a_quantifier_right_after_the_opener_repeats_nothing(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''), $pattern);

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame($offset, $result->offset, $pattern);
        $this->assertStringContainsString('Quantifier without target', (string) $result->error);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideQuantifiersAfterTheOpener(): iterable
    {
        yield 'plus' => ['pattern' => '/(?<*+a)/', 'offset' => 5];
        yield 'question mark' => ['pattern' => '/(?<*?a)/', 'offset' => 5];
    }

    #[Test]
    public function test_the_lookbehind_itself_stays_accepted(): void
    {
        $this->assertSame(1, preg_match('/(?<*a)b/', 'ab'));
        $this->assertTrue(Regex::create(['cache' => null])->validate('/(?<*a)b/')->isValid);
    }
}
