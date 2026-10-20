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

use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\ValidationErrorCategory;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A parser keeps what validate() answered for the last 1 024 patterns it
 * judged: the result depends only on the pattern and on the parser's own
 * options and target, so a pattern judged again costs a lookup.
 * clearCaches() empties it.
 */
final class ValidationMemoTest extends TestCase
{
    private const KEPT = 1024;

    #[Test]
    public function test_a_pattern_judged_again_gets_the_result_it_got(): void
    {
        $parser = RegexParser::create(['cache' => null]);

        $valid = $parser->validate('/^[a-z]+\d{2,}$/');
        $invalid = $parser->validate('/(a/');

        $this->assertSame($valid, $parser->validate('/^[a-z]+\d{2,}$/'));
        $this->assertSame($invalid, $parser->validate('/(a/'));
        $this->assertTrue($valid->isValid);
        $this->assertFalse($invalid->isValid);
    }

    #[Test]
    public function test_a_long_pattern_is_kept_too(): void
    {
        $parser = RegexParser::create(['cache' => null]);
        $pattern = '/'.str_repeat('(?:ab|cd)\w+', 40).'/';

        $this->assertSame($parser->validate($pattern), $parser->validate($pattern));
        $this->assertNotSame($parser->validate($pattern), $parser->validate($pattern.'i'));
    }

    /**
     * The pattern is past the 64 KiB PCRE compiles, but its two classes are
     * counted at their floor by the static checks, one of more than 256
     * members, one of every character past 255 and a property: only the
     * running engine refuses it.
     */
    #[Test]
    public function test_a_refusal_of_the_running_engine_is_kept(): void
    {
        $parser = RegexParser::create(['cache' => null, 'runtime_pcre_validation' => true]);

        $tooLarge = '/(?:a['.implode('', array_map(static fn (int $i): string => \sprintf('\\x{%x}', 0x1000 + 2 * $i), range(0, 299))).']{0}[\\x{100}-\\x{10FFFF}\\pL]{0}){1900}/u';
        $refused = $parser->validate($tooLarge);
        $valid = $parser->validate('/(?:[à-ž]x){8}/iu');

        $this->assertSame(ValidationErrorCategory::PcreRuntime, $refused->category);
        $this->assertSame($refused, $parser->validate($tooLarge));
        $this->assertTrue($valid->isValid);
        $this->assertSame($valid, $parser->validate('/(?:[à-ž]x){8}/iu'));
    }

    #[Test]
    public function test_a_limit_of_the_library_is_kept(): void
    {
        $parser = RegexParser::create(['cache' => null, 'max_pattern_length' => 10]);

        $result = $parser->validate('/abcdefghijklmnop/');

        $this->assertSame(ValidationErrorCategory::Limit, $result->category);
        $this->assertSame($result, $parser->validate('/abcdefghijklmnop/'));
    }

    #[Test]
    public function test_clear_caches_forgets_the_results(): void
    {
        $parser = RegexParser::create(['cache' => null]);
        $first = $parser->validate('/a+b/');

        $parser->clearCaches();
        $again = $parser->validate('/a+b/');

        $this->assertNotSame($first, $again);
        $this->assertEquals($first, $again);
    }

    #[Test]
    public function test_the_facade_forgets_them_with_its_parser(): void
    {
        $regex = Regex::create(['cache' => null]);
        $first = $regex->validate('/a+b/');
        $this->assertSame($first, $regex->validate('/a+b/'));

        $regex->clearCaches();

        $this->assertNotSame($first, $regex->validate('/a+b/'));
    }

    #[Test]
    public function test_each_parser_keeps_its_own(): void
    {
        $runtime = RegexParser::create(['cache' => null]);
        $php73 = RegexParser::create(['cache' => null, 'php_version' => 70300]);

        // PHP 7.3 reads "\y" as "y"; PCRE2 10.33 and later refuse it.
        $this->assertFalse($runtime->validate('/a\y/')->isValid);
        $this->assertTrue($php73->validate('/a\y/')->isValid);
        $this->assertFalse($runtime->validate('/a\y/')->isValid);
    }

    #[Test]
    public function test_the_oldest_result_goes_first_and_a_result_read_again_is_kept(): void
    {
        $parser = RegexParser::create(['cache' => null]);
        $results = [];
        for ($i = 0; $i < self::KEPT; $i++) {
            $results[$i] = $parser->validate('/p'.$i.'/');
        }

        // Read again, the first one is now the latest: the second goes.
        $this->assertSame($results[0], $parser->validate('/p0/'));
        $parser->validate('/one-more/');

        $this->assertSame($results[0], $parser->validate('/p0/'));
        $this->assertSame($results[2], $parser->validate('/p2/'));
        $this->assertNotSame($results[1], $parser->validate('/p1/'));
    }

    #[Test]
    public function test_no_more_than_1024_results_are_kept(): void
    {
        $parser = RegexParser::create(['cache' => null]);
        $results = [];
        for ($i = 0; $i < 3 * self::KEPT; $i++) {
            $results[$i] = $parser->validate('/q'.$i.'/');
        }

        $last = 3 * self::KEPT - 1;
        $this->assertSame($results[$last], $parser->validate('/q'.$last.'/'));
        $this->assertSame($results[$last - self::KEPT + 1], $parser->validate('/q'.($last - self::KEPT + 1).'/'));
        $this->assertNotSame($results[$last - self::KEPT], $parser->validate('/q'.($last - self::KEPT).'/'));
        $this->assertNotSame($results[0], $parser->validate('/q0/'));
    }
}
