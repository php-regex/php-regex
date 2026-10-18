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

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Validation\Validator;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Up to PHP 8.4, PHP compiles every pattern with the PCRE2 option that lets
 * "\K" stand in a lookaround. PHP 8.5 dropped it (its UPGRADING: "compiled
 * without semi-deprecated PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK"), and PCRE2
 * refuses it there: error 199, found as the pattern compiles and reported
 * at its end (pcre2test 10.40 to 10.48 and PHP 8.5 agree on every offset
 * below).
 */
final class KeepInLookaroundTest extends TestCase
{
    use PcreGates;

    use PhpUnitForwardCompat;

    #[Test]
    #[DataProvider('provideKeepsInLookarounds')]
    public function test_validate_refuses_keep_in_a_lookaround_from_php_8_5(string $pattern, int $offset): void
    {
        $result = Regex::create(['cache' => null, 'php_version' => 80500])->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s is refused by PHP 8.5.', $pattern));
        $this->assertSame(ErrorCode::KeepInLookaround, $result->errorCode);
        $this->assertSame($offset, $result->offset, $pattern);
    }

    #[Test]
    #[DataProvider('provideKeepsInLookarounds')]
    public function test_validate_accepts_keep_in_a_lookaround_before_php_8_5(string $pattern, int $offset): void
    {
        // The offset is PHP 8.5's; before, nothing is refused.
        unset($offset);

        foreach ([80200, 80300, 80400] as $phpVersion) {
            $result = Regex::create(['cache' => null, 'php_version' => $phpVersion])->validate($pattern);

            $this->assertTrue($result->isValid, \sprintf('%s is compiled by PHP %d: %s', $pattern, $phpVersion, (string) $result->error));
        }
    }

    #[Test]
    #[DataProvider('provideKeepsInLookarounds')]
    public function test_validate_follows_the_running_php(string $pattern, int $offset): void
    {
        // The offset is PHP 8.5's; before, nothing is refused.
        unset($offset);

        error_clear_last();
        $compiles = false !== @preg_match($pattern, '') || null === error_get_last();

        $this->assertSame($compiles, Regex::create(['cache' => null])->validate($pattern)->isValid, $pattern);
    }

    #[Test]
    public function test_validate_accepts_keep_outside_a_lookaround_on_php_8_5(): void
    {
        $this->assertTrue(Regex::create(['cache' => null, 'php_version' => 80500])->validate('/a\\K(?=b)/')->isValid);
    }

    #[Test]
    public function test_an_escape_before_the_end_of_the_lookaround_is_reported_first(): void
    {
        // PCRE refuses the "\K" when it closes the lookaround, so an error
        // met before that comes first: "\y" at 7 (pcre2test 10.48).
        $result = Regex::create(['cache' => null, 'php_version' => 80500])->validate('/(?=\\K\\y)/');

        $this->assertSame(ErrorCode::EscapeUnrecognized, $result->errorCode);
    }

    #[Test]
    public function test_the_keep_competes_with_missing_groups_in_pattern_order(): void
    {
        // PHP 8.5: "(?=\K)\5" fails on the \K, "\5(?=\K)" on the reference;
        // an unbounded lookbehind comes before both.
        $regex = Regex::create(['cache' => null, 'php_version' => 80500]);

        $this->assertSame(ErrorCode::KeepInLookaround, $regex->validate('/(?=\\K)\\5/')->errorCode);
        $this->assertSame(ErrorCode::BackrefMissingGroup, $regex->validate('/\\5(?=\\K)/')->errorCode);
        $this->assertSame(ErrorCode::LookbehindUnbounded, $regex->validate('/(?=\\K)(?<=a+)/')->errorCode);
    }

    #[Test]
    public function test_validating_a_lookaround_on_its_own_reports_at_once(): void
    {
        // Only a walk from the pattern root waits for the end of the pattern.
        $lookahead = Regex::create(['php_version' => 80500])->parse('/(?=a\\K)/')->pattern;

        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessageIsOrContains('not allowed in a lookaround');

        $lookahead->accept(new Validator(target: PcreTarget::bundledWith(80500)));
    }

    /**
     * Up to PHP 8.4 a "\K" in a lookaround resets the start of the match
     * from inside it (PHP 8.4.26): "(?=\Ka)" matches an empty string in
     * "a", and "(?<=\Ka)b" matches "ab" in "ab". No spelling keeps that in
     * general, so the hint says it and rewrites nothing.
     */
    #[Test]
    #[DataProvider('provideKeepsWithNoRespelling')]
    public function test_the_hint_names_what_php_8_4_reads_and_no_respelling(string $pattern): void
    {
        $hint = Regex::create(['cache' => null, 'php_version' => 80500])->validate($pattern)->hint;

        $this->assertSame('Up to PHP 8.4 a \K in a lookaround resets the start of the match from inside it: "(?=\Ka)" matches an empty string in "a", and "(?<=\Ka)b" matches "ab" in "ab". No spelling keeps that in general: rewrite the pattern without it.', $hint);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideKeepsWithNoRespelling(): iterable
    {
        yield 'lookbehind' => ['pattern' => '/(?<=a\Kb)c/'];
        yield 'not the first item of the lookahead' => ['pattern' => '/(?=a\Kb)c/'];
        yield 'one branch of the lookahead' => ['pattern' => '/(?=\Ka|b)c/'];
        yield 'two keeps' => ['pattern' => '/(?=\Ka\Kb)c/'];
        yield 'negative lookahead' => ['pattern' => '/(?!\Ka)b/'];
        yield 'repeated lookahead' => ['pattern' => '/(?=\Ka)?b/'];
        yield 'lookahead in a repeated group' => ['pattern' => '/(?:(?=\Ka)b)*c/'];
        yield 'lookahead in a lookahead' => ['pattern' => '/(?=(?=\Ka))b/'];
        yield 'non-atomic lookahead' => ['pattern' => '/(?*\Ka)b/'];
        yield 'alphabetic lookahead' => ['pattern' => '/(*pla:\Ka)b/'];
    }

    /**
     * A "\K" alone at the start of a lookahead resets the start of the
     * match where the lookahead starts, as a "\K" just before it does: the
     * two spellings match alike on PHP 8.4, and PHP 8.5 compiles the
     * second (php:8.5-cli: "\K(?=ab)a" matches "a" in "xab").
     */
    #[Test]
    public function test_the_hint_respells_a_keep_that_opens_a_lookahead(): void
    {
        $this->assertSame(
            'This \K opens the lookahead: write "\K(?=" for "(?=\K", which every PHP reads alike.',
            Regex::create(['cache' => null, 'php_version' => 80500])->validate('/(?=\Kab)a/')->hint,
        );
        $this->assertTrue(Regex::create(['cache' => null, 'php_version' => 80500])->validate('/\K(?=ab)a/')->isValid);
        // The lookahead holding the \K alone: "\K(?=)ab" matches "ab" in
        // "xab" on PHP 8.4, as "(?=\K)ab" does, and PHP 8.5 compiles it.
        $this->assertSame(
            'This \K opens the lookahead: write "\K(?=" for "(?=\K", which every PHP reads alike.',
            Regex::create(['cache' => null, 'php_version' => 80500])->validate('/(?=\K)ab/')->hint,
        );
        $this->assertTrue(Regex::create(['cache' => null, 'php_version' => 80500])->validate('/\K(?=)ab/')->isValid);
    }

    /**
     * The witnesses the hints name, replayed on an engine that reads a
     * "\K" in a lookaround.
     */
    #[Test]
    public function test_the_witnesses_of_the_hints_hold_up_to_php_8_4(): void
    {
        $this->forbidsPhp('8.4', 'A \K in a lookaround', 'refuses it');

        $this->assertSame(1, preg_match('/(?=\Ka)/', 'a', $empty));
        $this->assertSame([''], $empty);
        $this->assertSame(1, preg_match('/(?<=\Ka)b/', 'ab', $wide));
        $this->assertSame(['ab'], $wide);

        foreach (['', 'a', 'ab', 'xab', 'aab', 'abab', 'xa'] as $subject) {
            $this->assertSame(
                [preg_match('/(?=\Kab)a/', $subject, $before, \PREG_OFFSET_CAPTURE), $before],
                [preg_match('/\K(?=ab)a/', $subject, $after, \PREG_OFFSET_CAPTURE), $after],
                $subject,
            );
        }
        $this->assertSame(preg_replace('/(?=\Kab)a/', '-', 'xabab'), preg_replace('/\K(?=ab)a/', '-', 'xabab'));

        foreach (['', 'ab', 'xab', 'a', 'abab'] as $subject) {
            $this->assertSame(
                [preg_match('/(?=\K)ab/', $subject, $before, \PREG_OFFSET_CAPTURE), $before],
                [preg_match('/\K(?=)ab/', $subject, $after, \PREG_OFFSET_CAPTURE), $after],
                $subject,
            );
        }
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideKeepsInLookarounds(): iterable
    {
        yield 'lookahead' => ['pattern' => '/(?=a\\K)/', 'offset' => 7];
        yield 'lookbehind' => ['pattern' => '/(?<=\\Ka)/', 'offset' => 8];
        yield 'inside a group inside a lookahead' => ['pattern' => '/(?=(a\\K))/', 'offset' => 9];
        yield 'negative lookahead' => ['pattern' => '/(?!a\\K)b/', 'offset' => 8];
        yield 'lookahead inside a non-atomic assertion' => ['pattern' => '/(*pla:(?=a\\K))/', 'offset' => 14];
        yield 'lookahead before a longer text' => ['pattern' => '/(?=a\\K)bcdef/', 'offset' => 12];
        yield 'alphabetic lookahead' => ['pattern' => '/(*pla:a\\K)/', 'offset' => 10];
        yield 'lookahead before more text' => ['pattern' => '/(?=a\\Kb)c/', 'offset' => 9];
    }
}
