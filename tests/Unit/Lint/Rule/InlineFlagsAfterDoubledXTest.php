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
 * The letters of an option setting are read together: those after a
 * doubled "x" are read as those before it, and one already in force is
 * redundant wherever it stands.
 */
final class InlineFlagsAfterDoubledXTest extends TestCase
{
    #[Test]
    #[DataProvider('provideSettings')]
    public function test_a_flag_after_a_doubled_x_is_read(string $pattern, string $without, string $subject, ?string $message): void
    {
        // Oracle: the letter changes no match where it is already in force.
        if (null !== $message) {
            $this->assertSame(preg_match($without, $subject), preg_match($pattern, $subject), $pattern);
        }

        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);
        $messages = array_map(static fn ($issue): string => $issue->message, $linter->getIssues());

        if (null === $message) {
            $this->assertSame([], preg_grep('/redundant/', $messages), $pattern);
        } else {
            $this->assertContains($message, $messages, $pattern);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, without: string, subject: string, message: string|null}>
     */
    public static function provideSettings(): iterable
    {
        yield 'i after xx, under i' => ['pattern' => '/(?xxi)a/i', 'without' => '/(?xx)a/i', 'subject' => 'A', 'message' => "Inline flag 'i' is redundant; it is already set globally."];
        yield 's after xx, under s' => ['pattern' => '/(?xxs)./s', 'without' => '/(?xx)./s', 'subject' => "\n", 'message' => "Inline flag 's' is redundant; it is already set globally."];
        yield 'i after xx and x again, under i' => ['pattern' => '/(?xxxi)a/i', 'without' => '/(?xxx)a/i', 'subject' => 'A', 'message' => "Inline flag 'i' is redundant; it is already set globally."];
        yield 'i after xx, without i' => ['pattern' => '/(?xxi)a/', 'without' => '/(?xx)a/', 'subject' => 'A', 'message' => null];
    }
}
