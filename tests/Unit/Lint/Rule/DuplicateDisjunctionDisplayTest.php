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
 * The duplicate alternation branch is quoted in the mode of the pattern it
 * was cut from: under u or a leading (*UTF) a hidden character is its code
 * point "\x{HEX}" and printable UTF-8 stays as it is; read byte by byte, a
 * hidden character is one "\xHH" per byte. The branch is a piece of the
 * pattern, not a delimited pattern: a branch that opens with "(" and ends
 * with ")x" is not read as a pattern delimited by parentheses with the x
 * modifier, so nothing in it is dropped as a comment.
 *
 * Oracle (PHP 8.4, PCRE2 10.49, JIT off): each pattern compiles, and the
 * branch as quoted, read back as a pattern in the same mode, matches the
 * text the branch matches.
 */
final class DuplicateDisjunctionDisplayTest extends TestCase
{
    private const RULE = 'regex.lint.alternation.duplicateDisjunction';

    #[Test]
    #[DataProvider('provideDuplicateBranches')]
    public function test_duplicate_branch_is_quoted_in_the_pattern_mode(string $pattern, string $flags, string $subject, string $quoted): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), 'Oracle: the pattern compiles.');
        $this->assertSame(1, preg_match('/^'.$quoted.'$/'.$flags, $subject), 'Oracle: the quoted branch reads back as the text it matches.');

        $this->assertSame([\sprintf('Duplicate alternation branch "%s".', $quoted)], $this->messages($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, flags: string, subject: string, quoted: string}>
     */
    public static function provideDuplicateBranches(): iterable
    {
        yield 'hidden character in a group under u' => [
            'pattern' => "/(?:\u{202E})|(?:\u{202E})/u",
            'flags' => 'u',
            'subject' => "\u{202E}",
            'quoted' => '(?:\x{202E})',
        ];
        yield 'hidden character outside a group under u' => [
            'pattern' => "/\u{202E}a|\u{202E}a/u",
            'flags' => 'u',
            'subject' => "\u{202E}a",
            'quoted' => '\x{202E}a',
        ];
        yield 'hidden character under a leading UTF verb' => [
            'pattern' => "/(*UTF)(?:\u{202E}a|\u{202E}a)/",
            'flags' => 'u',
            'subject' => "\u{202E}a",
            'quoted' => '\x{202E}a',
        ];
        yield 'printable character under u' => [
            'pattern' => '/(?:é)|(?:é)/u',
            'flags' => 'u',
            'subject' => 'é',
            'quoted' => '(?:é)',
        ];
        yield 'hidden character written as an escape under u' => [
            'pattern' => '/(?:\x{202E})|(?:\x{202E})/u',
            'flags' => 'u',
            'subject' => "\u{202E}",
            'quoted' => '(?:\x{202E})',
        ];
        yield 'hidden character read byte by byte' => [
            'pattern' => "/(?:\xE2\x80\xAE)|(?:\xE2\x80\xAE)/",
            'flags' => '',
            'subject' => "\xE2\x80\xAE",
            'quoted' => '(?:\xE2\x80\xAE)',
        ];
        // Without x, " #c" and the line break are text the branch matches.
        yield 'number sign and line break without x' => [
            'pattern' => "/(?:a #c\n)x|(?:a #c\n)x/",
            'flags' => '',
            'subject' => "a #c\nx",
            'quoted' => '(?:a #c\n)x',
        ];
    }

    /**
     * @return list<string>
     */
    private function messages(string $pattern): array
    {
        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        $messages = [];
        foreach ($linter->getIssues() as $issue) {
            if (self::RULE === $issue->id) {
                $messages[] = $issue->message;
            }
        }

        return $messages;
    }
}
