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
 * A group name holds letters, digits and underscores only: PCRE stops on a
 * backslash and reports the missing terminator there (error 142, the same
 * offset on pcre2test 10.40 and PHP on 10.48). "\y" is not the letter "y".
 */
final class GroupNameEscapeTest extends TestCase
{
    #[Test]
    #[DataProvider('provideEscapedNames')]
    public function test_validate_refuses_an_escape_inside_a_group_name(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''), \sprintf('%s should not compile.', $pattern));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s does not compile but was reported valid.', $pattern));
        $this->assertSame($offset, $result->offset, $pattern);
        $this->assertStringContainsString('"\\y"', (string) strtok((string) $result->error, "\n"), 'The message quotes the escape as written.');
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideEscapedNames(): iterable
    {
        yield 'escaped letter in an angle-bracketed name' => ['pattern' => '/(?<a\\y>)/', 'offset' => 4];
        yield 'escaped letter in a quoted name' => ['pattern' => "/(?'a\\y'x)/", 'offset' => 4];
        yield 'escaped letter in a Python name' => ['pattern' => '/(?P<a\\y>x)/', 'offset' => 5];
    }
}
