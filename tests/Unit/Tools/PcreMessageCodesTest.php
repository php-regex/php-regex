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

namespace PHPRegex\Tests\Unit\Tools;

use PHPRegex\Tests\TestUtils\PcreMessageCodes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A message allows each of its codes only on the releases that report the
 * problem the code names under it: a code the other releases never report
 * there would let a wrong one through.
 */
final class PcreMessageCodesTest extends TestCase
{
    /**
     * Each code held to some releases, on the last release before them and
     * the first of them, or the last of them and the first after.
     *
     * @return iterable<string, array{message: string, code: string, release: string, allowed: bool}>
     */
    public static function provideCodesHeldToReleases(): iterable
    {
        // Up to 10.33 a lookbehind whose length wraps around past 2^31,
        // "(X{65535})(?<=\1{32770})" in the 10.32 and 10.33 suites, has no
        // fixed length, as the library reads it too: "too long" is never
        // reported under that message.
        yield 'length wrapped around, not fixed length, on 10.33' => ['message' => 'lookbehind assertion is not fixed length', 'code' => 'regex.lookbehind.variable_length_not_supported', 'release' => '10.33', 'allowed' => true];
        yield 'length wrapped around, not fixed length, on 10.34' => ['message' => 'lookbehind assertion is not fixed length', 'code' => 'regex.lookbehind.variable_length_not_supported', 'release' => '10.34', 'allowed' => true];
        // The extended classes and the lists of groups arrived in 10.45.
        yield 'extended class left open on 10.44' => ['message' => 'missing closing parenthesis', 'code' => 'regex.extended_class.unclosed_paren', 'release' => '10.44', 'allowed' => false];
        yield 'extended class left open on 10.45' => ['message' => 'missing closing parenthesis', 'code' => 'regex.extended_class.unclosed_paren', 'release' => '10.45', 'allowed' => true];
        yield 'extended class without its ] on 10.44' => ['message' => 'missing terminating ] for character class', 'code' => 'regex.extended_class.unclosed', 'release' => '10.44', 'allowed' => false];
        yield 'extended class closed twice on 10.44' => ['message' => 'unmatched closing parenthesis', 'code' => 'regex.extended_class.unmatched_close', 'release' => '10.44', 'allowed' => false];
        yield 'missing group of a list on 10.44' => ['message' => 'reference to non-existent subpattern', 'code' => 'regex.group_list.missing_group', 'release' => '10.44', 'allowed' => false];
        yield 'missing group of a list on 10.45' => ['message' => 'reference to non-existent subpattern', 'code' => 'regex.group_list.missing_group', 'release' => '10.45', 'allowed' => true];
        yield 'relative zero in a list on 10.44' => ['message' => 'a relative value of zero is not allowed', 'code' => 'regex.group_list.relative_zero', 'release' => '10.44', 'allowed' => false];
        // From 10.47 an alphabetic name the pattern ends with, "(*pla",
        // misses its ")"; before, it is no name PCRE knows.
        yield 'verb left open on 10.46' => ['message' => 'missing closing parenthesis', 'code' => 'regex.verb.unclosed', 'release' => '10.46', 'allowed' => false];
        yield 'verb left open on 10.47' => ['message' => 'missing closing parenthesis', 'code' => 'regex.verb.unclosed', 'release' => '10.47', 'allowed' => true];
        // A code held to no release stays allowed on every one.
        yield 'unbounded lookbehind on 10.32' => ['message' => 'lookbehind assertion is not fixed length', 'code' => 'regex.lookbehind.unbounded', 'release' => '10.32', 'allowed' => true];
        yield 'unclosed group on 10.49' => ['message' => 'missing closing parenthesis', 'code' => 'regex.group.unclosed', 'release' => '10.49', 'allowed' => true];
    }

    #[Test]
    #[DataProvider('provideCodesHeldToReleases')]
    public function test_a_code_is_allowed_only_on_the_releases_that_report_it_under_the_message(string $message, string $code, string $release, bool $allowed): void
    {
        $this->assertContains($code, PcreMessageCodes::CODES[$message]);
        $this->assertSame($allowed, \in_array($code, PcreMessageCodes::codesFor($message, $release), true));
    }

    #[Test]
    public function test_a_message_the_table_does_not_know_allows_no_code(): void
    {
        $this->assertSame([], PcreMessageCodes::codesFor('no such message', '10.49'));
    }

    #[Test]
    public function test_every_code_held_to_releases_is_one_its_message_allows(): void
    {
        foreach (PcreMessageCodes::RELEASES as $message => $codes) {
            $this->assertArrayHasKey($message, PcreMessageCodes::CODES);
            foreach (array_keys($codes) as $code) {
                $this->assertContains($code, PcreMessageCodes::CODES[$message], $message);
            }
        }
    }
}
