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

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Parser\ErrorCode;
use PhpRegex\Tests\TestUtils\PhpErrorOffset;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "\101" with fewer than 101 groups is the octal escape of "A", one
 * character, and a lookbehind holding it has a fixed length (PHP compiles
 * each pattern below). Digits past the three octal ones are literal.
 */
final class OctalEscapeInLookbehindTest extends TestCase
{
    #[Test]
    #[DataProvider('provideBounded')]
    public function test_the_lookbehind_is_bounded(string $pattern): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), $pattern);

        foreach ([['cache' => null], ['cache' => null, 'php_version' => 80200]] as $options) {
            $result = Regex::create($options)->validate($pattern);

            $this->assertTrue($result->isValid, \sprintf('%s: %s', $pattern, $result->error));
        }
    }

    #[Test]
    public function test_a_repeated_octal_escape_is_still_unbounded(): void
    {
        // "length of lookbehind assertion is not limited at offset 0".
        $this->assertSame(0, PhpErrorOffset::of('/(?<=\\101+)a/'));

        $result = Regex::create(['cache' => null])->validate('/(?<=\\101+)a/');
        $this->assertSame(ErrorCode::LookbehindUnbounded, $result->errorCode);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideBounded(): iterable
    {
        yield 'three digits' => ['pattern' => '/(?<=\\101)a/'];
        yield 'two digits' => ['pattern' => '/(?<=\\12)a/'];
        yield 'octal then a digit' => ['pattern' => '/(?<=\\1000)a/'];
        yield 'in one branch' => ['pattern' => '/(?<=x\\101|yz)a/'];
        yield 'after a group' => ['pattern' => '/(a)(?<=\\101)/'];
        yield 'alphabetic lookbehind' => ['pattern' => '/(*plb:\\101)/'];
        yield 'reference to a group, not an octal escape' => ['pattern' => '/(a)(?<=\\1)b/'];
        yield 'reference to group ten' => ['pattern' => '/(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)(?<=\\10)k/'];
    }
}
