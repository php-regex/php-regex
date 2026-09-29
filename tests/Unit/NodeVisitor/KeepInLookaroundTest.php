<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit\NodeVisitor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Exception\SemanticErrorException;
use RegexParser\NodeVisitor\ValidatorNodeVisitor;
use RegexParser\PcreTarget;
use RegexParser\Regex;

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
    #[Test]
    #[DataProvider('provideKeepsInLookarounds')]
    public function test_validate_refuses_keep_in_a_lookaround_from_php_8_5(string $pattern, int $offset): void
    {
        $result = Regex::create(['cache' => null, 'php_version' => 80500])->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s is refused by PHP 8.5.', $pattern));
        $this->assertSame('regex.keep.in_lookaround', $result->errorCode);
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

        $this->assertSame('regex.escape.unrecognized', $result->errorCode);
    }

    #[Test]
    public function test_the_keep_competes_with_missing_groups_in_pattern_order(): void
    {
        // PHP 8.5: "(?=\K)\5" fails on the \K, "\5(?=\K)" on the reference;
        // an unbounded lookbehind comes before both.
        $regex = Regex::create(['cache' => null, 'php_version' => 80500]);

        $this->assertSame('regex.keep.in_lookaround', $regex->validate('/(?=\\K)\\5/')->errorCode);
        $this->assertSame('regex.backref.missing_group', $regex->validate('/\\5(?=\\K)/')->errorCode);
        $this->assertSame('regex.lookbehind.unbounded', $regex->validate('/(?=\\K)(?<=a+)/')->errorCode);
    }

    #[Test]
    public function test_validating_a_lookaround_on_its_own_reports_at_once(): void
    {
        // Only a walk from the pattern root waits for the end of the pattern.
        $lookahead = Regex::create(['php_version' => 80500])->parse('/(?=a\\K)/')->pattern;

        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('not allowed in a lookaround');

        $lookahead->accept(new ValidatorNodeVisitor(target: PcreTarget::bundledWith(80500)));
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
        yield 'lookahead before a longer text' => ['pattern' => '/(?=a\\K)bcdef/', 'offset' => 12];
        yield 'alphabetic lookahead' => ['pattern' => '/(*pla:a\\K)/', 'offset' => 10];
        yield 'lookahead before more text' => ['pattern' => '/(?=a\\Kb)c/', 'offset' => 9];
    }
}
