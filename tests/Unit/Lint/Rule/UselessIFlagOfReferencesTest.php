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
use PHPRegex\Linter\Rule\UselessIFlagRule;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Under i a reference matches what its group captured in either case: the
 * flag is useless only where the group captures no character with a case.
 * An anchor, an assertion or a comment captures none; nor does a class of
 * digits, written as a code point, a type or a POSIX class under PCRE2's
 * own tables. A class of word characters or of letters does.
 */
final class UselessIFlagOfReferencesTest extends TestCase
{
    private const SUBJECT_LETTERS = ['a', 'A', '0', '5', '_', ' ', "\n"];

    #[Test]
    #[DataProvider('provideReferences')]
    public function test_flag_i_is_called_useless_where_the_referenced_group_captures_no_case(string $pattern, bool $useless): void
    {
        // Oracle: every subject of one or two characters over a small and a
        // capital letter, digits, an underscore, a space and a line feed;
        // i is useless where none answers otherwise without it.
        $without = substr($pattern, 0, -1);
        $differs = [];
        foreach (self::subjects() as $subject) {
            if (preg_match($pattern, $subject) !== preg_match($without, $subject)) {
                $differs[] = $subject;
            }
        }
        $this->assertSame($useless, [] === $differs, \sprintf('Oracle: %s answers otherwise without i on %s.', $pattern, json_encode($differs)));

        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);
        $ids = array_map(static fn ($issue): string => $issue->id, $linter->getIssues());

        $this->assertSame($useless, \in_array(UselessIFlagRule::ID, $ids, true), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, useless: bool}>
     */
    public static function provideReferences(): iterable
    {
        yield 'a group of a line start' => ['pattern' => '/(^)\1/i', 'useless' => true];
        yield 'a group of a word boundary' => ['pattern' => '/(\b)\1/i', 'useless' => true];
        yield 'a group of a comment' => ['pattern' => '/((?#c))\1/i', 'useless' => true];
        yield 'a class of a digit written as a code point' => ['pattern' => '/([\x30])\1/i', 'useless' => true];
        yield 'a class of the digits type' => ['pattern' => '/([\d])\1/i', 'useless' => true];
        yield 'a class of the POSIX digits' => ['pattern' => '/([[:digit:]])\1/i', 'useless' => true];
        yield 'a class of the word type' => ['pattern' => '/([\w])\1/i', 'useless' => false];
        yield 'a class of the POSIX letters' => ['pattern' => '/([[:alpha:]])\1/i', 'useless' => false];
        yield 'a class of a letter written as a code point' => ['pattern' => '/([\x61])\1/i', 'useless' => false];
    }

    /**
     * @return list<string>
     */
    private static function subjects(): array
    {
        $subjects = [''];
        foreach (self::SUBJECT_LETTERS as $first) {
            $subjects[] = $first;
            foreach (self::SUBJECT_LETTERS as $second) {
                $subjects[] = $first.$second;
            }
        }

        return $subjects;
    }
}
