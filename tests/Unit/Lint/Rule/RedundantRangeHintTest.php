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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The hint names the range that can go: a repeated range, or the one the
 * other covers, whichever comes first, its bounds shared or not. Each row
 * checks over every byte that the class without that range is the same
 * class.
 */
final class RedundantRangeHintTest extends TestCase
{
    /**
     * @return iterable<string, array{pattern: string, without: string, hint: string}>
     */
    public static function provideRedundantRanges(): iterable
    {
        yield 'repeated range' => ['pattern' => '/[a-za-z]/', 'without' => '/[a-z]/', 'hint' => "Redundant elements: range 'a'-'z' (overlaps 'a'-'z')"];
        yield 'later range, same start' => ['pattern' => '/[a-za-m]/', 'without' => '/[a-z]/', 'hint' => "Redundant elements: range 'a'-'m' (covered by range 'a'-'z')"];
        yield 'later range, same end' => ['pattern' => '/[a-zm-z]/', 'without' => '/[a-z]/', 'hint' => "Redundant elements: range 'm'-'z' (covered by range 'a'-'z')"];
        yield 'later range strictly inside' => ['pattern' => '/[a-zc-m]/', 'without' => '/[a-z]/', 'hint' => "Redundant elements: range 'c'-'m' (covered by range 'a'-'z')"];
        yield 'earlier range, same start' => ['pattern' => '/[a-ma-z]/', 'without' => '/[a-z]/', 'hint' => "Redundant elements: range 'a'-'m' (covered by range 'a'-'z')"];
        yield 'earlier range, same end' => ['pattern' => '/[m-za-z]/', 'without' => '/[a-z]/', 'hint' => "Redundant elements: range 'm'-'z' (covered by range 'a'-'z')"];
        yield 'earlier range strictly inside' => ['pattern' => '/[c-ma-z]/', 'without' => '/[a-z]/', 'hint' => "Redundant elements: range 'c'-'m' (covered by range 'a'-'z')"];
    }

    #[Test]
    #[DataProvider('provideRedundantRanges')]
    public function test_hint_names_the_range_that_can_go(string $pattern, string $without, string $hint): void
    {
        for ($byte = 0; $byte <= 0xFF; $byte++) {
            $this->assertSame(preg_match($without, \chr($byte)), preg_match($pattern, \chr($byte)), \sprintf('byte 0x%02X', $byte));
        }

        $linter = new PatternLinter();
        Regex::create()->parse($pattern)->accept($linter);
        $hints = [];
        foreach ($linter->getIssues() as $issue) {
            if ('regex.lint.charclass.redundant' === $issue->id) {
                $hints[] = $issue->hint;
            }
        }

        $this->assertSame([$hint], $hints);
    }
}
