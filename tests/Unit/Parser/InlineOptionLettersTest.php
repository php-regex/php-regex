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
 * u and d are PHP modifiers, not inline options: PCRE2 10.49 refuses them
 * after "(?" (pcre2test, error 111 "unrecognized character after (? or
 * (?-") on the letter itself.
 */
final class InlineOptionLettersTest extends TestCase
{
    #[Test]
    #[DataProvider('provideModifiersThatAreNoOptions')]
    public function test_a_php_modifier_is_no_inline_option(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''), $pattern);

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame($offset, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideModifiersThatAreNoOptions(): iterable
    {
        yield 'u alone' => ['pattern' => '/(?u)a+b/', 'offset' => 3];
        yield 'u in a scoped group' => ['pattern' => '/(?u:a)/', 'offset' => 3];
        yield 'u after an option' => ['pattern' => '/(?iu)a/', 'offset' => 4];
        yield 'u turned off' => ['pattern' => '/(?-u)a/', 'offset' => 4];
        yield 'd alone' => ['pattern' => '/(?d)a/', 'offset' => 3];
    }

    #[Test]
    public function test_the_inline_options_stay_accepted(): void
    {
        foreach (['/(?i)a/', '/(?n)(a)/', '/(?J)(?<n>a)/', '/(?U)a+/', '/(?xx)a/', '/(?^i)a/'] as $pattern) {
            $this->assertSame(1, preg_match($pattern, 'a'), $pattern);
            $this->assertTrue(Regex::create(['cache' => null])->validate($pattern)->isValid, $pattern);
        }
    }
}
