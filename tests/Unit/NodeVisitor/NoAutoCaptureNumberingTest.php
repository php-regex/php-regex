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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Under (?n) or the n flag, a plain "(...)" group does not capture, so it
 * takes no number; named groups still do. Each row was checked with
 * preg_match() on PCRE2 10.48 and with pcre2test 10.40: the rejected ones
 * report "reference to non-existent subpattern".
 */
final class NoAutoCaptureNumberingTest extends TestCase
{
    #[Test]
    #[DataProvider('provideReferencesToUncapturedGroups')]
    public function test_validate_rejects_a_reference_to_a_group_n_leaves_uncaptured(string $pattern): void
    {
        $this->assertFalse(Regex::create()->validate($pattern)->isValid, \sprintf('%s is refused by PHP but was reported valid.', $pattern));
    }

    #[Test]
    #[DataProvider('provideReferencesToCapturedGroups')]
    public function test_validate_accepts_a_reference_to_a_group_that_still_captures(string $pattern): void
    {
        $result = Regex::create()->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s compiles in PHP but was reported invalid: %s', $pattern, (string) $result->error));
    }

    #[Test]
    #[DataProvider('provideNoAutoCapturePatterns')]
    public function test_compiling_keeps_the_groups_as_written(string $pattern): void
    {
        // Under n a plain group does not capture: preg_match() fills no group.
        $this->assertSame($pattern, Regex::create()->parse($pattern)->accept(new PatternPrinter()));
        $this->assertSame(1, preg_match($pattern, 'ab', $matches));
        $this->assertSame(['ab'], $matches);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideNoAutoCapturePatterns(): iterable
    {
        yield 'inline n' => ['pattern' => '/(?n)(a)(b)/'];
        yield 'n flag' => ['pattern' => '/(a)(b)/n'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideReferencesToUncapturedGroups(): iterable
    {
        yield 'inline n, back reference: /(?n)(a)\\1/' => ['pattern' => '/(?n)(a)\\1/'];
        yield 'n flag, back reference: /(a)\\1/n' => ['pattern' => '/(a)\\1/n'];
        yield 'inline n after a capture, back reference to the second group: /(a)(?n)(b)\\2/' => ['pattern' => '/(a)(?n)(b)\\2/'];
        yield 'scoped n, back reference: /(a)(?n:(b))\\2/' => ['pattern' => '/(a)(?n:(b))\\2/'];
        yield 'inline n, call by number: /(?n)(a)(?1)/' => ['pattern' => '/(?n)(a)(?1)/'];
        yield 'inline n after a capture, call by number: /(a)(?n)(b)(?2)/' => ['pattern' => '/(a)(?n)(b)(?2)/'];
        yield 'inline n, branch reset, back reference: /(?n)(?|(a)|(b))\\1/' => ['pattern' => '/(?n)(?|(a)|(b))\\1/'];
        yield 'inline n, condition on a number: /(?n)(a)(?(1)b)/' => ['pattern' => '/(?n)(a)(?(1)b)/'];
        yield 'inline n reaches an alpha lookahead payload: /(?n)(*pla:(a))\\1/' => ['pattern' => '/(?n)(*pla:(a))\\1/'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideReferencesToCapturedGroups(): iterable
    {
        yield 'inline n, named groups still capture: /(?n)(?<x>a)\\1/' => ['pattern' => '/(?n)(?<x>a)\\1/'];
        yield 'inline n, named back reference: /(?n)(?<x>a)\\k<x>/' => ['pattern' => '/(?n)(?<x>a)\\k<x>/'];
        yield 'inline n, a named group is the first number: /(?n)(a)(?<x>b)\\1/' => ['pattern' => '/(?n)(a)(?<x>b)\\1/'];
        yield 'n switched off again: /(?n)(a)(?-n)(b)\\1/' => ['pattern' => '/(?n)(a)(?-n)(b)\\1/'];
        yield 'inline n after the referenced capture: /(a)(?n)(b)\\1/' => ['pattern' => '/(a)(?n)(b)\\1/'];
    }
}
