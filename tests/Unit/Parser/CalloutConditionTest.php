<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Parser;

use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "(?(?C1)(?=a)...)": a callout may come before the assertion of a
 * condition. PCRE skips a comment, an empty "\Q\E" and, under "x",
 * whitespace between the two, as it does anywhere (PHP compiles each).
 */
final class CalloutConditionTest extends TestCase
{
    #[Test]
    #[DataProvider('provideAccepted')]
    public function test_what_pcre_skips_may_stand_between_the_callout_and_the_assertion(string $pattern): void
    {
        $this->assertSame(1, @preg_match($pattern, 'ab'), $pattern);

        $result = Regex::create(['cache' => null])->validate($pattern);
        $this->assertTrue($result->isValid, \sprintf('%s: %s', $pattern, $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideAccepted(): iterable
    {
        yield 'comment' => ['pattern' => '/(?(?C1)(?#c)(?=a)ab)/'];
        yield 'whitespace under x' => ['pattern' => '/(?(?C1) (?=a)ab)/x'];
        yield 'comment line under x' => ['pattern' => "/(?(?C1)#c\n(?=a)ab)/x"];
        yield 'empty quote' => ['pattern' => '/(?(?C1)\\Q\\E(?=a)ab)/'];
    }
}
