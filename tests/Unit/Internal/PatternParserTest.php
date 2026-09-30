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
use RegexParser\PcreTarget;

final class PatternParserTest extends TestCase
{
    public function test_extracts_flags_including_modifier_r_when_supported(): void
    {
        [$pattern, $flags, $delimiter] = PatternParser::extractPatternAndFlags('/a/r', PcreTarget::bundledWith(80400));

        $this->assertSame('a', $pattern);
        $this->assertSame('r', $flags);
        $this->assertSame('/', $delimiter);
    }

    public function test_rejects_modifier_r_when_target_php_is_older(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Unknown regex flag(s) found: "r"');

        PatternParser::extractPatternAndFlags('/a/r', PcreTarget::bundledWith(80300));
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
        $this->expectExceptionMessage('Unescaped delimiter');

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

    /**
     * When what follows the first unescaped delimiter is pattern text rather
     * than flag letters, the error names the delimiter that ended the
     * pattern early, at its offset from the start of the body, instead of
     * listing "flags".
     */
    #[DataProvider('provideDelimiterEndingThePatternEarly')]
    public function test_names_the_delimiter_that_ends_the_pattern_early(string $regex, int $position): void
    {
        try {
            PatternParser::extractPatternAndFlags($regex);
            $this->fail('No exception for '.$regex);
        } catch (ParserException $e) {
            $this->assertStringContainsString('Unescaped delimiter', $e->getMessage());
            $this->assertSame($position, $e->getPosition());
        }
    }

    /**
     * @return iterable<string, array{regex: string, position: int}>
     */
    public static function provideDelimiterEndingThePatternEarly(): iterable
    {
        yield 'slash in a class' => ['regex' => '/[/]/', 'position' => 1];
        yield 'scheme separator whose tail holds an e' => ['regex' => '/([[:space:]]|^)([[:alnum:]]+)://([^[:space:]]*)/i', 'position' => 30];
        yield 'hash in a class' => ['regex' => '#[#]#', 'position' => 1];
        yield 'after leading whitespace' => ['regex' => '  /[/]/', 'position' => 1];
    }

    public function test_unknown_flag_letters_keep_the_flag_message(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Unknown regex flag(s) found: "q"');

        PatternParser::extractPatternAndFlags('/abc/q');
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
        [$pattern, $flags, $delimiter] = PatternParser::extractPatternAndFlags('/a/e', PcreTarget::bundledWith(50600)); // PHP 5.6

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
        $this->assertSame(['a', 'r', '/'], PatternParser::extractPatternAndFlags('/a/r', PcreTarget::bundledWith(80400)));
        $this->assertSame(['a', 'r', '/'], PatternParser::extractPatternAndFlags('/a/r', PcreTarget::bundledWith(80500)));
        $this->assertSame(['a', 'r', '/'], PatternParser::extractPatternAndFlags('/a/r', PcreTarget::bundledWith(90000)));

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Unknown regex flag(s) found: "r"');
        PatternParser::extractPatternAndFlags('/a/r', PcreTarget::bundledWith(80300));
    }

    public function test_supports_modifier_e_with_specific_versions(): void
    {
        $this->assertSame(['a', 'e', '/'], PatternParser::extractPatternAndFlags('/a/e', PcreTarget::bundledWith(50600)));
        $this->assertSame(['a', 'e', '/'], PatternParser::extractPatternAndFlags('/a/e', PcreTarget::bundledWith(50500)));

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('The \'e\' flag (preg_replace /e) was removed in PHP 7.0; use preg_replace_callback() instead.');
        PatternParser::extractPatternAndFlags('/a/e', PcreTarget::bundledWith(70000));
    }

    public function test_without_a_target_the_running_php_decides_on_r(): void
    {
        // "r" arrived in PHP 8.4, built against PCRE2 10.43 or later: the
        // running PHP compiles it only then.
        $accepted = \PHP_VERSION_ID >= 80400 && PcreTarget::runtime()->pcreAtLeast('10.43');
        $this->assertSame(self::runtimeSupportsModifierR(), $accepted);

        if ($accepted) {
            $this->assertSame(['a', 'r', '/'], PatternParser::extractPatternAndFlags('/a/r', null));
        }
    }

    public function test_r_needs_php_8_4_linking_pcre2_10_43(): void
    {
        $this->assertSame(['a', 'r', '/'], PatternParser::extractPatternAndFlags('/a/r', new PcreTarget(80400, '10.43')));

        // PHP 8.4 on Ubuntu 24.04 links PCRE2 10.42, and refuses "r".
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Unknown regex flag(s) found: "r"');

        PatternParser::extractPatternAndFlags('/a/r', new PcreTarget(80400, '10.42'));
    }

    public function test_n_needs_php_8_2(): void
    {
        $this->assertSame(['(a)', 'n', '/'], PatternParser::extractPatternAndFlags('/(a)/n', PcreTarget::bundledWith(80200)));

        // PHP 8.1: "Unknown modifier 'n'".
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Unknown regex flag(s) found: "n"');

        PatternParser::extractPatternAndFlags('/(a)/n', PcreTarget::bundledWith(80100));
    }

    public function test_without_a_target_e_is_refused(): void
    {
        // "e" left in PHP 7.0.
        $this->expectException(ParserException::class);

        PatternParser::extractPatternAndFlags('/a/e', null);
    }

    private static function runtimeSupportsModifierR(): bool
    {
        $modifier = \chr(114);
        $pattern = '/a/'.$modifier;

        return false !== @preg_match($pattern, '');
    }
}
