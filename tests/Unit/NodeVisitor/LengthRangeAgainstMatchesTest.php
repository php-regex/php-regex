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

use PhpRegex\Parser\Analysis\LengthRangeCalculator;
use PhpRegex\Parser\ErrorCode;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The length range must hold every match PHP reports: its minimum is never
 * more than the shortest match, its maximum never less than the longest.
 * "\K" is left out: it only moves the start of the reported match, and the
 * range measures what the match consumes.
 * The cases come from the PCRE2 test suite (testinput1 and 2), where the
 * range was wrong; each subject is matched by PHP to find the match.
 */
final class LengthRangeAgainstMatchesTest extends TestCase
{
    /**
     * @param array{0: int, 1: int|null} $expected
     */
    #[Test]
    #[DataProvider('provideMatches')]
    public function test_the_range_holds_what_php_matches(string $pattern, string $subject, array $expected): void
    {
        $this->assertSame(1, preg_match($pattern, $subject, $matches), $pattern);
        $length = str_contains(substr($pattern, strrpos($pattern, '/') ?: 0), 'u')
            ? mb_strlen($matches[0], 'UTF-8')
            : \strlen($matches[0]);

        $range = Regex::create(['cache' => null])->parse($pattern)->accept(new LengthRangeCalculator());

        $this->assertSame($expected, $range, $pattern);
        $this->assertGreaterThanOrEqual($range[0], $length, $pattern);
        if (null !== $range[1]) {
            $this->assertLessThanOrEqual($range[1], $length, $pattern);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, expected: array{0: int, 1: int|null}}>
     */
    public static function provideMatches(): iterable
    {
        // An empty group, branch or option setting matches nothing.
        yield 'empty group' => ['pattern' => '/()ef/', 'subject' => 'ef', 'expected' => [2, 2]];
        yield 'option setting' => ['pattern' => '/(?s)a.b/', 'subject' => "a\nb", 'expected' => [3, 3]];
        yield 'empty non-capturing group' => ['pattern' => '/a(?)b/', 'subject' => 'ab', 'expected' => [2, 2]];
        yield 'empty branch' => ['pattern' => '/(abc|)+/', 'subject' => '', 'expected' => [0, null]];
        yield 'reference to an empty branch' => ['pattern' => '/^(a|)\\1*b/', 'subject' => 'b', 'expected' => [1, null]];
        yield 'conditional without a no branch' => ['pattern' => '/^(\\()?blah(?(1)(\\)))$/', 'subject' => 'blah', 'expected' => [4, 6]];
        yield 'option setting under x' => ['pattern' => '/(?x)   ^    a   (?# begins with a)  b\\sc (?# then c)/', 'subject' => 'ab c', 'expected' => [4, 4]];

        // A literal run counts each of its characters.
        yield 'quoted run' => ['pattern' => '/   abc\\Q abc\\Eabc/x', 'subject' => 'abc abcabc', 'expected' => [10, 10]];
        yield 'multibyte character in UTF mode' => ['pattern' => '/\\Qé\\E/u', 'subject' => 'é', 'expected' => [1, 1]];
        yield 'multibyte character in byte mode' => ['pattern' => '/\\Qé\\E/', 'subject' => 'é', 'expected' => [2, 2]];

        // "\R" matches "\r\n" too, "\X" a whole grapheme cluster.
        yield 'newline sequence' => ['pattern' => '/a\\Rb/', 'subject' => "a\r\nb", 'expected' => [3, 4]];
        yield 'grapheme cluster' => ['pattern' => '/\\X/u', 'subject' => "e\u{301}", 'expected' => [1, null]];

        // "(*ACCEPT)" ends the match where it stands.
        yield 'accept' => ['pattern' => '/a(*ACCEPT)b/', 'subject' => 'ab', 'expected' => [1, 2]];
        yield 'accept in a lookahead ends only the lookahead' => ['pattern' => '/(?=a(*ACCEPT))ab/', 'subject' => 'ab', 'expected' => [2, 2]];
        yield 'accept in a repeated group' => ['pattern' => '/(?:a(*ACCEPT))+b/', 'subject' => 'ab', 'expected' => [1, null]];
        yield 'accept in a branch' => ['pattern' => '/(A(A|B(*ACCEPT)|C)D)(E)/', 'subject' => 'AB', 'expected' => [2, 4]];
    }

    #[Test]
    public function test_a_newline_sequence_is_not_fixed_length_in_a_lookbehind_before_php_8_4(): void
    {
        // pcre2test 10.40 and 10.42: "(?<=\R)" is error 125 at offset 0;
        // 10.43 bounds lookbehinds instead of fixing their length.
        foreach (['/(?<=\\R)a/', '/(?<=a\\R|bc)x/'] as $pattern) {
            foreach ([80200, 80300] as $phpVersion) {
                $result = Regex::create(['cache' => null, 'php_version' => $phpVersion])->validate($pattern);

                $this->assertSame(ErrorCode::LookbehindVariableLengthNotSupported, $result->errorCode, \sprintf('%s on PHP %d', $pattern, $phpVersion));
                $this->assertSame(0, $result->offset);
            }

            $this->assertTrue(Regex::create(['cache' => null, 'php_version' => 80400])->validate($pattern)->isValid, $pattern);
        }
    }
}
