<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit\NodeVisitor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Regex;

/**
 * Repetition and calls inside a lookbehind.
 *
 * Inside a lookbehind, only a bare lookahead may be repeated without bound,
 * because it adds no length. A repeated lookbehind, a lookahead wrapped in a
 * group, and a repeated (*ACCEPT) all make the lookbehind unbounded. A call
 * or back reference is measured as the group it names, grapheme clusters
 * included. Every row was checked with preg_match() on PCRE2 10.48.
 */
final class LookbehindRepetitionTest extends TestCase
{
    #[Test]
    #[DataProvider('provideUnboundedLookbehinds')]
    public function test_validate_rejects_unbounded_lookbehind(string $pattern): void
    {
        $this->assertFalse(Regex::create()->validate($pattern)->isValid, \sprintf('%s is refused by PCRE2 but was reported valid.', $pattern));
    }

    #[Test]
    #[DataProvider('provideBoundedLookbehinds')]
    public function test_validate_accepts_bounded_lookbehind(string $pattern): void
    {
        $result = Regex::create()->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s compiles in PCRE2 but was reported invalid: %s', $pattern, (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideUnboundedLookbehinds(): iterable
    {
        yield 'repeated lookbehind: /(?<=(?<=a)+)z/' => ['pattern' => '/(?<=(?<=a)+)z/'];
        yield 'repeated negative lookbehind: /(?<=(?<!a)*)z/' => ['pattern' => '/(?<=(?<!a)*)z/'];
        yield 'repeated alpha lookbehind: /(?<=(*plb:a){1,})z/' => ['pattern' => '/(?<=(*plb:a){1,})z/'];
        yield 'repeated non-atomic lookbehind: /(?<=(?<*a)+)z/' => ['pattern' => '/(?<=(?<*a)+)z/'];
        yield 'lookahead wrapped in a non-capturing group: /(?<=(?:(?=a))+)z/' => ['pattern' => '/(?<=(?:(?=a))+)z/'];
        yield 'lookahead wrapped in a capturing group: /(?<=((?=a))*)z/' => ['pattern' => '/(?<=((?=a))*)z/'];
        yield 'lookahead wrapped in an atomic group: /(?<=(?>(?=a))+)z/' => ['pattern' => '/(?<=(?>(?=a))+)z/'];
        yield 'alternation of lookaheads: /(?<=(?:(?=a)|(?=b))+)z/' => ['pattern' => '/(?<=(?:(?=a)|(?=b))+)z/'];
        yield 'repeated accept verb: /(?<=(*ACCEPT)+)z/' => ['pattern' => '/(?<=(*ACCEPT)+)z/'];
        yield 'accept verb wrapped in a group: /(?<=(?:(*ACCEPT))+)z/' => ['pattern' => '/(?<=(?:(*ACCEPT))+)z/'];
        yield 'call to a repeated lookbehind: /(?<n>(?<=a)+)(?<=(?1))z/' => ['pattern' => '/(?<n>(?<=a)+)(?<=(?1))z/'];
        yield 'call to a grapheme cluster: /(?<n>\\X)(?<=(?1))z/' => ['pattern' => '/(?<n>\\X)(?<=(?1))z/'];
        yield 'named back reference to a grapheme cluster: /(?<n>\\X)(?<=\\k<n>)z/' => ['pattern' => '/(?<n>\\X)(?<=\\k<n>)z/'];
        yield 'numbered back reference to a grapheme cluster: /(?<n>\\X)(?<=\\1)z/' => ['pattern' => '/(?<n>\\X)(?<=\\1)z/'];
        yield 'call to a defined grapheme cluster: /(?(DEFINE)(?<n>\\X))(?<=(?&n))z/' => ['pattern' => '/(?(DEFINE)(?<n>\\X))(?<=(?&n))z/'];
        yield 'relative zero back reference: /(a)(?<=\\g{-0})x/' => ['pattern' => '/(a)(?<=\\g{-0})x/'];
        yield 'forward relative back reference to a variable group: /(?<=\\g{+1})(a+)x/' => ['pattern' => '/(?<=\\g{+1})(a+)x/'];
        yield 'call into a branch reset whose first group is variable: /(?|(b+)|(a))(?<=(?1))x/' => ['pattern' => '/(?|(b+)|(a))(?<=(?1))x/'];
        yield 'forward relative call to a grapheme cluster: /(?<=(?+1))(?<n>\\X)z/' => ['pattern' => '/(?<=(?+1))(?<n>\\X)z/'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideBoundedLookbehinds(): iterable
    {
        yield 'repeated lookahead: /(?<=(?=a)+)z/' => ['pattern' => '/(?<=(?=a)+)z/'];
        yield 'repeated negative lookahead: /(?<=(?!a)*)z/' => ['pattern' => '/(?<=(?!a)*)z/'];
        yield 'repeated alpha lookahead: /(?<=(*pla:a)+)z/' => ['pattern' => '/(?<=(*pla:a)+)z/'];
        yield 'repeated non-atomic alpha lookahead: /(?<=(*napla:a)+)z/' => ['pattern' => '/(?<=(*napla:a)+)z/'];
        yield 'repeated non-atomic lookahead: /(?<=(?*a)+)z/' => ['pattern' => '/(?<=(?*a)+)z/'];
        // A forward relative reference names the group after it; a call into a
        // branch reset measures the first group with that number, as PCRE does.
        yield 'forward relative back reference: /(?<=\\g{+1})(a)x/' => ['pattern' => '/(?<=\\g{+1})(a)x/'];
        yield 'call into a branch reset: /(?|(a)|(b))(?<=(?1))x/' => ['pattern' => '/(?|(a)|(b))(?<=(?1))x/'];
        yield 'call into a branch reset of unequal lengths: /(?|(a)|(bc))(?<=(?1))x/' => ['pattern' => '/(?|(a)|(bc))(?<=(?1))x/'];
        yield 'call into a branch reset whose later group is variable: /(?|(a)|(b+))(?<=(?1))x/' => ['pattern' => '/(?|(a)|(b+))(?<=(?1))x/'];
        yield 'call to a fixed-length group: /(?<n>a)(?<=(?1))z/' => ['pattern' => '/(?<n>a)(?<=(?1))z/'];
    }
}
