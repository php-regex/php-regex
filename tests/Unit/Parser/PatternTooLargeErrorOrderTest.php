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

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The first pass of the compiler measures the pattern item by item, and
 * meets as it goes a reference, a call or a condition to a group the
 * pattern does not have, and a "\K" in a lookaround from PHP 8.5: once an
 * item takes the pattern past the size limit, it stops, and what comes
 * after is never reached. A conditional of too many branches is found only
 * by the second pass, which a pattern too large never gets to. Up to PCRE2
 * 10.47 each item is checked with the branches before it in its group; from
 * 10.48 with its own branch, and the branches before it where the next one
 * starts. Every row was run through pcre2test 10.32 to 10.49.
 */
final class PatternTooLargeErrorOrderTest extends TestCase
{
    #[Test]
    #[DataProvider('provideErrorsNeverReached')]
    public function test_an_error_after_the_limit_is_passed_is_never_reached(string $pattern, string $php, string $release, int $offset): void
    {
        $result = self::validate($pattern, $php, $release);

        $this->assertSame(ErrorCode::PatternTooLarge, $result->errorCode, \sprintf('%s on PHP %s with PCRE2 %s: %s', $pattern, $php, $release, (string) $result->error));
        $this->assertSame($offset, $result->offset, \sprintf('%s on PHP %s with PCRE2 %s', $pattern, $php, $release));
    }

    #[Test]
    #[DataProvider('provideErrorsMetFirst')]
    public function test_an_error_before_the_limit_is_passed_is_reported(string $pattern, string $php, string $release, ErrorCode $error, int $offset): void
    {
        $result = self::validate($pattern, $php, $release);

        $this->assertSame($error, $result->errorCode, \sprintf('%s on PHP %s with PCRE2 %s: %s', $pattern, $php, $release, (string) $result->error));
        $this->assertSame($offset, $result->offset, \sprintf('%s on PHP %s with PCRE2 %s', $pattern, $php, $release));
    }

    /**
     * "(?:[^a]){8634}" compiles to more than 64 KiB on its own.
     *
     * @return iterable<string, array{pattern: string, php: string, release: string, offset: int}>
     */
    public static function provideErrorsNeverReached(): iterable
    {
        yield 'reference by name after the item past the limit, 10.32' => ['pattern' => '/(c)(?:[^a]){8634}|\\k<n>/', 'php' => '7.3', 'release' => '10.32', 'offset' => 23];
        yield 'reference by name after the item past the limit, 10.47' => ['pattern' => '/(c)(?:[^a]){8634}|\\k<n>/', 'php' => '8.5', 'release' => '10.47', 'offset' => 0];
        yield 'reference by name after the item past the limit, 10.49' => ['pattern' => '/(c)(?:[^a]){8634}|\\k<n>/', 'php' => '8.5', 'release' => '10.49', 'offset' => 0];
        yield 'condition on a name after the item past the limit, 10.35' => ['pattern' => '/(c)(?:[^a]){8634}|(?(<n>)a|b)/', 'php' => '7.4', 'release' => '10.35', 'offset' => 29];
        yield 'condition on a name after the item past the limit, 10.48' => ['pattern' => '/(c)(?:[^a]){8634}|(?(<n>)a|b)/', 'php' => '8.5', 'release' => '10.48', 'offset' => 0];
        yield 'call by name after the item past the limit, 10.40' => ['pattern' => '/(c)(?:[^a]){8634}|(?&n)/', 'php' => '8.2', 'release' => '10.40', 'offset' => 23];
        yield 'call by name after the item past the limit, 10.49' => ['pattern' => '/(c)(?:[^a]){8634}|(?&n)/', 'php' => '8.5', 'release' => '10.49', 'offset' => 0];
        yield 'call by name in the Python syntax after the item past the limit, 10.42' => ['pattern' => '/(c)(?:[^a]){8634}|(?P>n)/', 'php' => '8.3', 'release' => '10.42', 'offset' => 24];
        yield 'reference by name in the Python syntax after the item past the limit, 10.44' => ['pattern' => '/(c)(?:[^a]){8634}|(?P=n)/', 'php' => '8.4', 'release' => '10.44', 'offset' => 24];
        yield 'reference by name in braces after the item past the limit, 10.34' => ['pattern' => '/(c)(?:[^a]){8634}|\\g{n}/', 'php' => '7.4', 'release' => '10.34', 'offset' => 23];
        yield 'call by name in angle brackets after the item past the limit, 10.45' => ['pattern' => '/(c)(?:[^a]){8634}|\\g<n>/', 'php' => '8.4', 'release' => '10.45', 'offset' => 23];
        yield 'condition on a recursion into a name after the item past the limit, 10.46' => ['pattern' => '/(c)(?:[^a]){8634}|(?(R&n)a)/', 'php' => '8.4', 'release' => '10.46', 'offset' => 27];
        yield 'reference by number after the item past the limit, 10.33' => ['pattern' => '/(c)(?:[^a]){8634}|\\5/', 'php' => '7.3', 'release' => '10.33', 'offset' => 20];
        yield 'reference by number after the item past the limit, 10.49' => ['pattern' => '/(c)(?:[^a]){8634}|\\5/', 'php' => '8.5', 'release' => '10.49', 'offset' => 0];
        yield 'reference by number in braces after the item past the limit, 10.36' => ['pattern' => '/(c)(?:[^a]){8634}|\\g{5}/', 'php' => '7.4', 'release' => '10.36', 'offset' => 23];
        yield 'reference by number to a group left unnumbered after the item past the limit, 10.40' => ['pattern' => '/(?:[^a]){8634}|\\1/n', 'php' => '8.2', 'release' => '10.40', 'offset' => 17];
        yield 'reference by number to a group left unnumbered after the item past the limit, 10.48' => ['pattern' => '/(?:[^a]){8634}|\\1/n', 'php' => '8.5', 'release' => '10.48', 'offset' => 0];
        yield 'call by number after the item past the limit, 10.37' => ['pattern' => '/(c)(?:[^a]){8634}|(?5)/', 'php' => '7.4', 'release' => '10.37', 'offset' => 22];
        yield 'relative call forward after the item past the limit, 10.38' => ['pattern' => '/(c)(?:[^a]){8634}|(?+5)/', 'php' => '7.4', 'release' => '10.38', 'offset' => 23];
        yield 'condition on a number after the item past the limit, 10.39' => ['pattern' => '/(c)(?:[^a]){8634}|(?(5)a|b)/', 'php' => '7.4', 'release' => '10.39', 'offset' => 27];
        yield 'reference by name right after the item past the limit, 10.41' => ['pattern' => '/(c)(?:[^a]){8634}\\k<n>/', 'php' => '8.2', 'release' => '10.41', 'offset' => 22];
        yield 'reference by name right after the item past the limit, 10.49' => ['pattern' => '/(c)(?:[^a]){8634}\\k<n>/', 'php' => '8.5', 'release' => '10.49', 'offset' => 0];
        yield 'reference by name in the group past the limit, 10.43' => ['pattern' => '/(c)(?:(?:[^a]){8634}\\k<n>)/', 'php' => '8.3', 'release' => '10.43', 'offset' => 26];
        yield 'reference by name in the group past the limit, 10.48' => ['pattern' => '/(c)(?:(?:[^a]){8634}\\k<n>)/', 'php' => '8.5', 'release' => '10.48', 'offset' => 0];
        yield 'reference by name in the branch after the one past the limit, 10.32' => ['pattern' => '/(c)(?:(?:[^a]){8634}|\\k<n>)/', 'php' => '7.3', 'release' => '10.32', 'offset' => 27];
        yield 'reference by name in the branch after the one past the limit, 10.49' => ['pattern' => '/(c)(?:(?:[^a]){8634}|\\k<n>)/', 'php' => '8.5', 'release' => '10.49', 'offset' => 0];
        yield 'reference by name after a group past the limit where it ends, 10.44' => ['pattern' => '/(c)(?:(?:[^a]){8000}|(?:[^a]){8000})\\k<n>/', 'php' => '8.4', 'release' => '10.44', 'offset' => 41];
        yield 'reference by name after a group past the limit where it ends, 10.49' => ['pattern' => '/(c)(?:(?:[^a]){8000}|(?:[^a]){8000})\\k<n>/', 'php' => '8.5', 'release' => '10.49', 'offset' => 41];
        yield 'reference by name in a conditional after the item past the limit, 10.40' => ['pattern' => '/(?:[^a]){8634}|(?(1)a|\\k<n>|c)()/', 'php' => '8.2', 'release' => '10.40', 'offset' => 32];
        yield 'reference by name in a conditional after the item past the limit, 10.49' => ['pattern' => '/(?:[^a]){8634}|(?(1)a|\\k<n>|c)()/', 'php' => '8.5', 'release' => '10.49', 'offset' => 0];
        yield 'keep in a lookahead after the item past the limit, 10.47' => ['pattern' => '/(?:[^a]){8634}|(?=\\K)/', 'php' => '8.5', 'release' => '10.47', 'offset' => 0];
        yield 'keep in a lookahead after the item past the limit, 10.49' => ['pattern' => '/(?:[^a]){8634}|(?=\\K)/', 'php' => '8.5', 'release' => '10.49', 'offset' => 0];
        yield 'conditional of three branches before the item past the limit, 10.32' => ['pattern' => '/(?(1)a|b|c)(?:[^a]){8634}()/', 'php' => '7.3', 'release' => '10.32', 'offset' => 27];
        yield 'conditional of three branches before the item past the limit, 10.47' => ['pattern' => '/(?(1)a|b|c)(?:[^a]){8634}()/', 'php' => '8.5', 'release' => '10.47', 'offset' => 0];
        yield 'conditional of three branches before the item past the limit, 10.48' => ['pattern' => '/(?(1)a|b|c)(?:[^a]){8634}()/', 'php' => '8.5', 'release' => '10.48', 'offset' => 0];
        yield 'DEFINE group of two branches before the item past the limit, 10.40' => ['pattern' => '/(?(DEFINE)a|b)(?:[^a]){8634}/', 'php' => '8.2', 'release' => '10.40', 'offset' => 28];
        yield 'DEFINE group of two branches before the item past the limit, 10.49' => ['pattern' => '/(?(DEFINE)a|b)(?:[^a]){8634}/', 'php' => '8.5', 'release' => '10.49', 'offset' => 0];
        yield 'conditional of three branches holding the item past the limit, 10.44' => ['pattern' => '/(?(1)(?:[^a]){8634}|b|c)()/', 'php' => '8.4', 'release' => '10.44', 'offset' => 26];
        yield 'conditional of three branches holding the item past the limit, 10.49' => ['pattern' => '/(?(1)(?:[^a]){8634}|b|c)()/', 'php' => '8.5', 'release' => '10.49', 'offset' => 0];
        yield 'second branch past the limit with the first, before a reference, 10.32' => ['pattern' => '/(?:[^a]){5000}|(?:[^a]){4000}\\k<n>(?:[^a]){1000}/', 'php' => '7.3', 'release' => '10.32', 'offset' => 48];
        yield 'second branch past the limit with the first, before a reference, 10.46' => ['pattern' => '/(?:[^a]){5000}|(?:[^a]){4000}\\k<n>(?:[^a]){1000}/', 'php' => '8.4', 'release' => '10.46', 'offset' => 48];
        yield 'second branch past the limit with the first, before a reference, 10.47' => ['pattern' => '/(?:[^a]){5000}|(?:[^a]){4000}\\k<n>(?:[^a]){1000}/', 'php' => '8.5', 'release' => '10.47', 'offset' => 0];
        yield 'second branch past the limit with the first, in a group, before a reference, 10.40' => ['pattern' => '/(?:(?:[^a]){5000}|(?:[^a]){4000}\\k<n>(?:[^a]){1000})/', 'php' => '8.2', 'release' => '10.40', 'offset' => 52];
        yield 'second branch past the limit with the first, in a group, before a reference, 10.47' => ['pattern' => '/(?:(?:[^a]){5000}|(?:[^a]){4000}\\k<n>(?:[^a]){1000})/', 'php' => '8.5', 'release' => '10.47', 'offset' => 0];
        yield 'branch at the limit with the brackets but not the end marker, 10.48' => ['pattern' => '/(?:[^a]){8190}aaa\\d|\\k<n>/', 'php' => '8.5', 'release' => '10.48', 'offset' => 0];
        yield 'branch at the limit with the brackets but not the end marker, 10.49' => ['pattern' => '/(?:[^a]){8190}aaa\\d|\\k<n>/', 'php' => '8.5', 'release' => '10.49', 'offset' => 0];
        yield 'branch one unit past the limit with the brackets, 10.32' => ['pattern' => '/(?:[^a]){8190}aaa\\d\\d|\\k<n>/', 'php' => '7.3', 'release' => '10.32', 'offset' => 27];
        yield 'branch one unit past the limit with the brackets, 10.47' => ['pattern' => '/(?:[^a]){8190}aaa\\d\\d|\\k<n>/', 'php' => '8.5', 'release' => '10.47', 'offset' => 0];
        yield 'branch one unit past the limit with the brackets, 10.48' => ['pattern' => '/(?:[^a]){8190}aaa\\d\\d|\\k<n>/', 'php' => '8.5', 'release' => '10.48', 'offset' => 0];
        // Each letter is checked as it is added.
        yield 'reference by name after the letter past the limit, 10.32' => ['pattern' => '/(?:[^a]){8190}aaaaaa\\k<n>/', 'php' => '7.3', 'release' => '10.32', 'offset' => 25];
        yield 'reference by name after the letter past the limit, 10.49' => ['pattern' => '/(?:[^a]){8190}aaaaaa\\k<n>/', 'php' => '8.5', 'release' => '10.49', 'offset' => 0];
        // An assertion as the condition is checked before the branches.
        yield 'reference by name after a condition past the limit, 10.32' => ['pattern' => '/(?(?=(?:[^a]){8634})\\k<n>)/', 'php' => '7.3', 'release' => '10.32', 'offset' => 26];
        yield 'reference by name after a condition past the limit, 10.48' => ['pattern' => '/(?(?=(?:[^a]){8634})\\k<n>)/', 'php' => '8.5', 'release' => '10.48', 'offset' => 0];
        yield 'scan of a missing group after the item past the limit, 10.47' => ['pattern' => '/(?:[^a]){8634}|(*scs:(5)a)/', 'php' => '8.5', 'release' => '10.47', 'offset' => 0];
        // Up to 10.47 the lists of a class are checked as it is compiled.
        yield 'reference by name after a class whose lists pass the limit, 10.47' => ['pattern' => '/'.str_repeat('[\\x{100}a]', 1637).str_repeat('[\\x{100}\\x{300}\\x{500}\\x{700}\\x{900}\\x{B00}\\x{D00}a]', 2).'\\k<n>/u', 'php' => '8.5', 'release' => '10.47', 'offset' => 16479];
    }

    /**
     * @return iterable<string, array{pattern: string, php: string, release: string, error: ErrorCode, offset: int}>
     */
    public static function provideErrorsMetFirst(): iterable
    {
        yield 'reference by name before the item past the limit, 10.32' => ['pattern' => '/\\k<n>(c)(?:[^a]){8634}/', 'php' => '7.3', 'release' => '10.32', 'error' => ErrorCode::BackrefMissingNamedGroup, 'offset' => 3];
        yield 'reference by name before the item past the limit, 10.49' => ['pattern' => '/\\k<n>(c)(?:[^a]){8634}/', 'php' => '8.5', 'release' => '10.49', 'error' => ErrorCode::BackrefMissingNamedGroup, 'offset' => 3];
        yield 'reference by name between two items, the second past the limit, 10.40' => ['pattern' => '/(c)(?:[^a]){8000}|\\k<n>(?:[^a]){1000}/', 'php' => '8.2', 'release' => '10.40', 'error' => ErrorCode::BackrefMissingNamedGroup, 'offset' => 21];
        yield 'reference by name between two items, the second past the limit, 10.49' => ['pattern' => '/(c)(?:[^a]){8000}|\\k<n>(?:[^a]){1000}/', 'php' => '8.5', 'release' => '10.49', 'error' => ErrorCode::BackrefMissingNamedGroup, 'offset' => 21];
        yield 'keep in a lookahead before the item past the limit, 10.47' => ['pattern' => '/(?=\\K)(?:[^a]){8634}/', 'php' => '8.5', 'release' => '10.47', 'error' => ErrorCode::KeepInLookaround, 'offset' => 20];
        yield 'keep in a lookahead before the item past the limit, 10.49' => ['pattern' => '/(?=\\K)(?:[^a]){8634}/', 'php' => '8.5', 'release' => '10.49', 'error' => ErrorCode::KeepInLookaround, 'offset' => 20];
        yield 'second branch past the limit with the first, before a reference, 10.48' => ['pattern' => '/(?:[^a]){5000}|(?:[^a]){4000}\\k<n>(?:[^a]){1000}/', 'php' => '8.5', 'release' => '10.48', 'error' => ErrorCode::BackrefMissingNamedGroup, 'offset' => 32];
        yield 'second branch past the limit with the first, before a reference, 10.49' => ['pattern' => '/(?:[^a]){5000}|(?:[^a]){4000}\\k<n>(?:[^a]){1000}/', 'php' => '8.5', 'release' => '10.49', 'error' => ErrorCode::BackrefMissingNamedGroup, 'offset' => 32];
        yield 'second branch past the limit with the first, in a group, before a reference, 10.48' => ['pattern' => '/(?:(?:[^a]){5000}|(?:[^a]){4000}\\k<n>(?:[^a]){1000})/', 'php' => '8.5', 'release' => '10.48', 'error' => ErrorCode::BackrefMissingNamedGroup, 'offset' => 35];
        yield 'branch at the limit with the brackets but not the end marker, 10.32' => ['pattern' => '/(?:[^a]){8190}aaa\\d|\\k<n>/', 'php' => '7.3', 'release' => '10.32', 'error' => ErrorCode::BackrefMissingNamedGroup, 'offset' => 23];
        yield 'branch at the limit with the brackets but not the end marker, 10.47' => ['pattern' => '/(?:[^a]){8190}aaa\\d|\\k<n>/', 'php' => '8.5', 'release' => '10.47', 'error' => ErrorCode::BackrefMissingNamedGroup, 'offset' => 23];
        yield 'branch one unit under the limit with the end marker, 10.32' => ['pattern' => '/(?:[^a]){8190}aa\\d|\\k<n>/', 'php' => '7.3', 'release' => '10.32', 'error' => ErrorCode::BackrefMissingNamedGroup, 'offset' => 22];
        yield 'branch one unit under the limit with the end marker, 10.49' => ['pattern' => '/(?:[^a]){8190}aa\\d|\\k<n>/', 'php' => '8.5', 'release' => '10.49', 'error' => ErrorCode::BackrefMissingNamedGroup, 'offset' => 22];
        // A reference PCRE2 reports at the end of the pattern is met where it
        // stands, before the check made where the pattern ends.
        yield 'reference by number reported at the end, before the check where the pattern ends, 10.48' => ['pattern' => '/\\d|b(?:(?:[^a]){8189})|(?:[^a]){8191}\\g{7}/', 'php' => '8.5', 'release' => '10.48', 'error' => ErrorCode::BackrefMissingGroup, 'offset' => 42];
        // The first "\K" met in a lookaround is refused before a reference
        // after it.
        yield 'keep in a lookahead before a reference to a missing name, 10.47' => ['pattern' => '/(?=\\K\\k<n>)/', 'php' => '8.5', 'release' => '10.47', 'error' => ErrorCode::KeepInLookaround, 'offset' => 11];
        yield 'keep in a lookahead after a reference to a missing name, 10.49' => ['pattern' => '/(?=\\k<n>\\K)/', 'php' => '8.5', 'release' => '10.49', 'error' => ErrorCode::BackrefMissingNamedGroup, 'offset' => 6];
        yield 'reference by name after the letter that reaches the limit, 10.32' => ['pattern' => '/(?:[^a]){8190}aaaaa\\k<n>/', 'php' => '7.3', 'release' => '10.32', 'error' => ErrorCode::BackrefMissingNamedGroup, 'offset' => 22];
        yield 'reference by name after the letter that reaches the limit, 10.49' => ['pattern' => '/(?:[^a]){8190}aaaaa\\k<n>/', 'php' => '8.5', 'release' => '10.49', 'error' => ErrorCode::BackrefMissingNamedGroup, 'offset' => 22];
        // The lookbehinds are measured before the first pass.
        yield 'reference by name in a lookbehind after the item past the limit, 10.32' => ['pattern' => '/(?:[^a]){8634}|(?<=\\k<n>)/', 'php' => '7.3', 'release' => '10.32', 'error' => ErrorCode::BackrefMissingNamedGroup, 'offset' => 22];
        yield 'reference by name in a lookbehind after the item past the limit, 10.49' => ['pattern' => '/(?:[^a]){8634}|(?<=\\k<n>)/', 'php' => '8.5', 'release' => '10.49', 'error' => ErrorCode::BackrefMissingNamedGroup, 'offset' => 22];
    }

    private static function validate(string $pattern, string $php, string $release): ValidationResult
    {
        return RegexParser::create(['cache' => null, 'php_version' => $php, 'pcre_version' => $release, 'runtime_pcre_validation' => false])->validate($pattern);
    }
}
