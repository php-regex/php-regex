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

namespace RegexParser\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RegexParser\Exception\ParserException;
use RegexParser\Internal\PatternParser;

final class PatternParserTest extends TestCase
{
    public function test_extracts_flags_including_modifier_r_when_supported(): void
    {
        [$pattern, $flags, $delimiter] = PatternParser::extractPatternAndFlags('/a/r', 80400);

        $this->assertSame('a', $pattern);
        $this->assertSame('r', $flags);
        $this->assertSame('/', $delimiter);
    }

    public function test_rejects_modifier_r_when_target_php_is_older(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Unknown regex flag(s) found: "r"');

        PatternParser::extractPatternAndFlags('/a/r', 80300);
    }

    public function test_rejects_modifier_e_with_improved_message(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('The \'e\' flag (preg_replace /e) was removed in PHP 7.0; use preg_replace_callback() instead.');

        PatternParser::extractPatternAndFlags('/a/e');
    }

    /**
     * PHP ends the pattern at the FIRST delimiter not escaped by a backslash,
     * whatever it sits in: a class, a comment or a \Q...\E run. Every row is
     * refused by preg_match() ("Unknown modifier ...") on PCRE2 10.40 and 10.48.
     */
    #[DataProvider('provideDelimiterInsideAConstruct')]
    public function test_pattern_ends_at_the_first_unescaped_delimiter(string $regex): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Unknown regex flag');

        PatternParser::extractPatternAndFlags($regex);
    }

    /**
     * @return iterable<string, array{regex: string}>
     */
    public static function provideDelimiterInsideAConstruct(): iterable
    {
        yield 'slash in a class' => ['regex' => '/[/]/'];
        yield 'slash inside a longer class' => ['regex' => '/[a/b]/'];
        yield 'hash delimiter in a class' => ['regex' => '#[#]#'];
        yield 'slash in a comment' => ['regex' => '/(?#/)a/'];
        yield 'slash in a quoted run' => ['regex' => '/\\Q/\\E/'];
    }

    public function test_escaped_delimiter_does_not_end_the_pattern(): void
    {
        // preg_match('/[\/]/', '/') === 1 and preg_match('/a\/b/', 'a/b') === 1.
        $this->assertSame(['[\\/]', '', '/'], PatternParser::extractPatternAndFlags('/[\\/]/'));
        $this->assertSame(['a\\/b', 'i', '/'], PatternParser::extractPatternAndFlags('/a\\/b/i'));
        $this->assertSame(['a\\\\', '', '/'], PatternParser::extractPatternAndFlags('/a\\\\/'));
    }

    public function test_throws_for_missing_closing_delimiter(): void
    {
        $this->expectException(ParserException::class);
        PatternParser::extractPatternAndFlags('/abc');
    }

    public function test_supports_modifier_e_for_old_php_versions(): void
    {
        // Test that modifier 'e' is allowed when targeting PHP < 7.0
        [$pattern, $flags, $delimiter] = PatternParser::extractPatternAndFlags('/a/e', 50600); // PHP 5.6

        $this->assertSame('a', $pattern);
        $this->assertSame('e', $flags);
        $this->assertSame('/', $delimiter);
    }

    public function test_supports_modifier_r_runtime_detection(): void
    {
        // This test triggers the runtime detection code in supportsModifierR
        // The behavior depends on the runtime PCRE capabilities
        if (self::runtimeSupportsModifierR()) {
            $result = PatternParser::extractPatternAndFlags('/a/r');
            $this->assertIsArray($result);
            $this->assertCount(3, $result);
        } else {
            $this->expectException(ParserException::class);
            $this->expectExceptionMessage('Unknown regex flag(s) found: "r"');
            PatternParser::extractPatternAndFlags('/a/r');
        }
    }

    public function test_supports_modifier_e_runtime_detection(): void
    {
        // This test triggers the runtime detection code in supportsModifierE
        // On PHP 7.0+, this should reject 'e' modifier
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('The \'e\' flag (preg_replace /e) was removed in PHP 7.0; use preg_replace_callback() instead.');

        // This call will trigger the runtime detection code
        PatternParser::extractPatternAndFlags('/a/e');
    }

    public function test_supports_modifier_r_runtime_detection_with_null_version(): void
    {
        // This test specifically targets the runtime detection code path
        // where phpVersionId is null (lines 137-143 in PatternParser)
        if (self::runtimeSupportsModifierR()) {
            $result = PatternParser::extractPatternAndFlags('/a/r');
            $this->assertIsArray($result);
            $this->assertCount(3, $result);
            $this->assertSame('a', $result[0]);
            $this->assertSame('r', $result[1]);
        } else {
            $this->expectException(ParserException::class);
            $this->expectExceptionMessage('Unknown regex flag(s) found: "r"');
            PatternParser::extractPatternAndFlags('/a/r');
        }
    }

    public function test_supports_modifier_e_runtime_detection_with_null_version(): void
    {
        // This test specifically targets the runtime detection code path
        // where phpVersionId is null for modifier 'e'
        // PHP 7.0+ should reject the 'e' modifier at runtime
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('The \'e\' flag (preg_replace /e) was removed in PHP 7.0; use preg_replace_callback() instead.');

        // This call will trigger the runtime detection code
        PatternParser::extractPatternAndFlags('/a/e');
    }

    public function test_supports_modifier_r_caching_behavior(): void
    {
        if (self::runtimeSupportsModifierR()) {
            $result1 = PatternParser::extractPatternAndFlags('/a/r');
            $this->assertIsArray($result1);

            $result2 = PatternParser::extractPatternAndFlags('/b/r');
            $this->assertIsArray($result2);

            $this->assertSame('b', $result2[0]);
            $this->assertSame('r', $result2[1]);
        } else {
            $this->expectException(ParserException::class);
            PatternParser::extractPatternAndFlags('/a/r');
        }
    }

    public function test_supports_modifier_r_with_specific_versions(): void
    {
        $this->assertSame(['a', 'r', '/'], PatternParser::extractPatternAndFlags('/a/r', 80400));
        $this->assertSame(['a', 'r', '/'], PatternParser::extractPatternAndFlags('/a/r', 80500));
        $this->assertSame(['a', 'r', '/'], PatternParser::extractPatternAndFlags('/a/r', 90000));

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Unknown regex flag(s) found: "r"');
        PatternParser::extractPatternAndFlags('/a/r', 80300);
    }

    public function test_supports_modifier_e_with_specific_versions(): void
    {
        $this->assertSame(['a', 'e', '/'], PatternParser::extractPatternAndFlags('/a/e', 50600));
        $this->assertSame(['a', 'e', '/'], PatternParser::extractPatternAndFlags('/a/e', 50500));

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('The \'e\' flag (preg_replace /e) was removed in PHP 7.0; use preg_replace_callback() instead.');
        PatternParser::extractPatternAndFlags('/a/e', 70000);
    }

    public function test_supports_modifier_r_with_null_version_clears_cache_first(): void
    {
        $reflectionMethod = new \ReflectionMethod(PatternParser::class, 'supportsModifierR');

        $supportsModifierRProperty = new \ReflectionProperty(PatternParser::class, 'supportsModifierR');
        $supportsModifierRProperty->setValue(null, []);

        $result = $reflectionMethod->invoke(null, null);

        $this->assertSame(self::runtimeSupportsModifierR(), $result);
    }

    public function test_supports_modifier_e_with_null_version_clears_cache_first(): void
    {
        $reflectionMethod = new \ReflectionMethod(PatternParser::class, 'supportsModifierE');

        $supportsModifierEProperty = new \ReflectionProperty(PatternParser::class, 'supportsModifierE');
        $supportsModifierEProperty->setValue(null, []);

        $result = $reflectionMethod->invoke(null, null);

        $this->assertFalse($result);
    }

    private static function runtimeSupportsModifierR(): bool
    {
        $modifier = \chr(114);
        $pattern = '/a/'.$modifier;

        return false !== @preg_match($pattern, '');
    }
}
