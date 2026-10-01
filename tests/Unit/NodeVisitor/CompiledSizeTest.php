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

use PhpRegex\Parser\ErrorCode;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE compiles a repeated group as one copy per repetition, and refuses a
 * compiled pattern above 64 KiB (error 120, "regular expression is too
 * large", with the default two-byte links PHP builds with). Each copy costs
 * at least its brackets, so a pattern whose smallest possible size already
 * passes the limit is refused; one that stays under it is left to PCRE.
 *
 * Every boundary below was measured on PHP (PCRE2 10.48): the first count
 * that fails and the one before it. 10.40 to 10.45 report the error at the
 * end of the pattern, 10.48 at offset 0 or at the end.
 */
final class CompiledSizeTest extends TestCase
{
    #[Test]
    #[DataProvider('provideTooLargePatterns')]
    public function test_validate_refuses_a_pattern_too_large_to_compile(string $pattern): void
    {
        $this->assertFalse(self::compiles($pattern), \sprintf('%s should not compile.', $pattern));

        $result = Regex::create(['cache' => null])->validate($pattern);
        $body = substr($pattern, 1, (int) strrpos($pattern, '/') - 1);

        $this->assertFalse($result->isValid, \sprintf('%s is too large for PCRE but was reported valid.', $pattern));
        $this->assertSame(ErrorCode::PatternTooLarge, $result->errorCode);
        $this->assertContains($result->offset, [0, \strlen($body)]);
    }

    #[Test]
    #[DataProvider('provideLargestPatterns')]
    public function test_validate_accepts_a_pattern_just_under_the_limit(string $pattern): void
    {
        $this->assertTrue(self::compiles($pattern), \sprintf('%s should compile.', $pattern));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s compiles but was reported invalid: %s', $pattern, (string) $result->error));
    }

    #[Test]
    public function test_nested_conditionals_are_measured_in_linear_time(): void
    {
        // Each conditional nests the next in its "no" branch; measuring that
        // branch twice made the time double with every level.
        $pattern = '/(a)'.str_repeat('(?(1)a|', 40).'b'.str_repeat(')', 40).'/';

        $this->assertTrue(Regex::create(['cache' => null])->validate($pattern)->isValid);
    }

    #[Test]
    public function test_nested_lookarounds_are_measured_in_linear_time(): void
    {
        // Each lookaround nests the next: measuring its body twice, once for
        // its size and once to see whether it is empty, made the time double
        // with every level (testinput6 of the PCRE2 suite nests 34).
        $pattern = '/'.str_repeat('(?!', 40).'a'.str_repeat(')', 40).'/';

        $this->assertTrue(Regex::create(['cache' => null])->validate($pattern)->isValid);
    }

    #[Test]
    public function test_a_class_holding_a_multibyte_literal_in_byte_mode_is_measured_quietly(): void
    {
        // Without "u", "é" is two bytes: no single code point, and ord()
        // must not be asked for one (PHP 8.5 deprecates it on a longer string).
        set_error_handler(static function (int $level, string $message): never {
            throw new \ErrorException($message, 0, $level);
        });

        try {
            $this->assertTrue(Regex::create(['cache' => null])->validate('/(?:[é-\xff]a){1000}/')->isValid);
            $this->assertTrue(Regex::create(['cache' => null])->validate("/(?:[\u{20ac}-\xff]a){1000}/")->isValid);
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function test_other_errors_come_before_the_size(): void
    {
        // PCRE refuses the missing group and the escape before it measures
        // the compiled pattern.
        $this->assertSame(ErrorCode::BackrefMissingGroup, Regex::create()->validate('/\\5(?:){20000}/')->errorCode);
        $this->assertSame(ErrorCode::EscapeUnrecognized, Regex::create()->validate('/(?:){20000}\\y/')->errorCode);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideTooLargePatterns(): iterable
    {
        yield 'empty group' => ['pattern' => '/(?:){10922}/'];
        yield 'one-letter group' => ['pattern' => '/(?:a){8192}/'];
        yield 'anchored' => ['pattern' => '/^(?:a){8192}$/'];
        yield 'capturing group' => ['pattern' => '/(a){6553}/'];
        yield 'named group' => ['pattern' => '/(?<n>a){6553}/'];
        yield 'group made non-capturing by n' => ['pattern' => '/(a){8192}/n'];
        yield 'recursion' => ['pattern' => '/(?R){21844}/'];
        yield 'accept, which PCRE wraps in a group to repeat it' => ['pattern' => '/(*ACCEPT){9362}/'];
        yield 'accept with a name' => ['pattern' => '/(*ACCEPT:xyz){5041}/'];
        yield 'lookahead' => ['pattern' => '/(?=a){8192}/'];
        yield 'two branches' => ['pattern' => '/(?:a|b){5041}/'];
        yield 'open maximum' => ['pattern' => '/(?:a){8192,}/'];
        yield 'optional copies' => ['pattern' => '/(?:a){0,4370}/'];
        yield 'mandatory then optional copies' => ['pattern' => '/(?:a){2,4370}/'];
        yield 'lazy count' => ['pattern' => '/(?:a){10000}?/'];
        yield 'scoped modifiers' => ['pattern' => '/(?i:a){8192}/'];
        yield 'nested counts' => ['pattern' => '/((?:a){100}){100}/'];
        yield 'conditional' => ['pattern' => '/(?(1)a|b){5041}(a)/'];
        // A class compiles to a 32-byte map, a case pair to one caseless
        // letter; in UTF mode what is past 255, and the types and properties
        // UCP reads, go in an extended class (pcre2test "memory", 10.40 and
        // 10.48 alike).
        yield 'class of two letters' => ['pattern' => '/(?:[ab]c){1599}/'];
        yield 'case pair' => ['pattern' => '/(?:[aA]c){6553}/'];
        // PHP refuses it from 3641 copies: its range may merge with others
        // or fold under "i", so only one smallest item is counted.
        yield 'class past 255 in UTF mode' => ['pattern' => '/(?:[\\x{100}-\\x{200}]a){4096}/u'];
        yield 'class holding a type under UCP' => ['pattern' => '/(?:[\\w]a){4096}/u'];
        yield 'property' => ['pattern' => '/(?:\\p{L}a){5958}/u'];
        yield 'types under UCP' => ['pattern' => '/(?:\\w\\s){5461}/u'];
        yield 'class of one letter' => ['pattern' => '/(?:[a]b){7282}/'];
        yield 'numbered callout' => ['pattern' => '/(?:(?C1)a){4681}/'];
        yield 'string callout' => ['pattern' => '/(?:(?C"abcdefghij")a){2260}/'];
        yield 'named mark' => ['pattern' => '/(?:(*MARK:abcdefghij)a){3121}/'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideLargestPatterns(): iterable
    {
        yield 'empty group' => ['pattern' => '/(?:){10921}/'];
        yield 'one-letter group' => ['pattern' => '/(?:a){8191}/'];
        yield 'capturing group' => ['pattern' => '/(a){6552}/'];
        yield 'group made non-capturing by n' => ['pattern' => '/(a){8191}/n'];
        yield 'modifiers that scope nothing' => ['pattern' => '/(?i)(?:a){8191}/'];
        yield 'recursion' => ['pattern' => '/(?R){21843}/'];
        yield 'accept' => ['pattern' => '/(*ACCEPT){9361}/'];
        yield 'accept with a name' => ['pattern' => '/(*ACCEPT:xyz){5040}/'];
        yield 'open maximum' => ['pattern' => '/(?:a){8191,}/'];
        yield 'optional copies' => ['pattern' => '/(?:a){0,4369}/'];
        yield 'mandatory then optional copies' => ['pattern' => '/(?:a){2,4369}/'];
        yield 'empty negative lookahead, compiled to a fail' => ['pattern' => '/(?:(?!)){6000}/'];
        yield 'empty negative lookahead, alphabetic' => ['pattern' => '/(?:(*nla:)){6000}/'];
        yield 'lookahead holding a fail' => ['pattern' => '/(?:(?=(?!))){5000}/'];
        yield 'branch that fails' => ['pattern' => '/(?:a|(?!)){5400}/'];
        yield 'class of two letters' => ['pattern' => '/(?:[ab]c){1598}/'];
        yield 'case pair' => ['pattern' => '/(?:[aA]c){6552}/'];
        yield 'class past 255 in UTF mode' => ['pattern' => '/(?:[\\x{100}-\\x{200}]a){3640}/u'];
        yield 'class holding a type under UCP' => ['pattern' => '/(?:[\\w]a){4095}/u'];
        yield 'property' => ['pattern' => '/(?:\\p{L}a){5957}/u'];
        yield 'types under UCP' => ['pattern' => '/(?:\\w\\s){5460}/u'];
        yield 'numbered callout' => ['pattern' => '/(?:(?C1)a){4680}/'];
        yield 'string callout' => ['pattern' => '/(?:(?C"abcdefghij")a){2259}/'];
        yield 'named mark' => ['pattern' => '/(?:(*MARK:abcdefghij)a){3120}/'];
        yield 'types without UCP' => ['pattern' => '/(?:\\w\\s){8191}/'];
        yield 'class holding types without UCP' => ['pattern' => '/(?:[\\d\\s]a){1598}/'];
        yield 'octal escape written like a reference' => ['pattern' => '/(?:\\101){7500}/'];
        yield 'two-digit octal after a group' => ['pattern' => '/(a)(?:\\12){7500}/'];
        yield 'x-mode line separator' => ['pattern' => "/(?:a\u{2028}){7000}/xu"];
        yield 'x-mode next-line bytes' => ['pattern' => '/(?:a'.str_repeat("\x85", 30).'){2000}/x'];
        yield 'counted letter' => ['pattern' => '/a{65535}/'];
        yield 'counted character type' => ['pattern' => '/\\d{65535}/'];
    }

    /**
     * Whether PHP compiles the pattern: a match that fails at run time, as
     * an endless recursion does, still compiled.
     */
    private static function compiles(string $pattern): bool
    {
        error_clear_last();
        @preg_match($pattern, '');

        return !str_contains(error_get_last()['message'] ?? '', 'Compilation failed');
    }
}
