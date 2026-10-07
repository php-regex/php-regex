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
 * Under a newline convention other than LF, "$" matches before that
 * newline: a "$" followed by it is no impossible anchor.
 */
final class EndAnchorNewlineConventionTest extends TestCase
{
    #[Test]
    #[DataProvider('provideEndAnchorsBeforeTheirNewline')]
    public function test_an_end_anchor_before_the_convention_newline_is_not_reported(string $pattern, string $subject): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the pattern matches.
        $this->assertSame(1, preg_match($pattern, $subject));

        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);
        $ids = array_map(static fn ($issue): string => $issue->id, $linter->getIssues());

        $this->assertNotContains('regex.lint.anchor.impossible.end', $ids, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideEndAnchorsBeforeTheirNewline(): iterable
    {
        yield 'carriage return' => ['pattern' => "/(*CR)a\$\r/", 'subject' => "a\r"];
        yield 'carriage return and line feed' => ['pattern' => "/(*CRLF)a\$\r\n/", 'subject' => "a\r\n"];
        yield 'any of the three' => ['pattern' => "/(*ANYCRLF)a\$\r/", 'subject' => "a\r"];
    }
}
