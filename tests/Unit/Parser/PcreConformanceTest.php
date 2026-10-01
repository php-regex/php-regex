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

namespace PhpRegex\Tests\Unit\Parser;

use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Differential conformance tests: the library must agree with the real PCRE
 * engine on whether each pattern compiles.
 */
final class PcreConformanceTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function provide_patterns(): iterable
    {
        $patterns = [
            // Quoted \g / \k reference syntax
            "/(a)\\g'1'/",
            "/(?<n>a)\\k'n'/",
            // Relative subroutine calls
            '/(a)\g<-1>/',
            '/\g<+1>(a)/',
            '/(?+1)(a)/',
            '/(a)(?-1)/',
            // Quantifier after \Q...\E applies to the quoted literal
            '/\Q+\E*/',
            '/\Q\E*/',
            // Escape edge cases
            '/\c/',
            '/\x{}/',
            // Quantifier ceiling (PCRE max 65535)
            '/a{65535}/',
            '/a{65536}/',
            // Group names are word characters, no leading digit
            '/(?<a-b>x)/',
            '/(?<1x>a)/',
            '/(?<x1>a)/',
            // Bracket delimiters require balanced nesting
            '{a{b}',
            '{a{b}c}',
            // Alphabetic assertion verbs (PCRE2 10.32+)
            '/(*pla:ab)c/',
            '/(*positive_lookahead:ab)c/',
            '/(*negative_lookbehind:x)y/',
            '/(*atomic:a+)b/',
            '/(*sr:(a+)+b)/',
        ];

        foreach ($patterns as $pattern) {
            yield $pattern => [$pattern];
        }
    }

    public function test_script_run_content_is_analyzed(): void
    {
        $analysis = Regex::create()->redos('/(*sr:(a+)+b)/');

        $this->assertSame('critical', $analysis->severity->value);
    }

    #[DataProvider('provide_patterns')]
    public function test_validation_agrees_with_pcre(string $pattern): void
    {
        set_error_handler(static fn (): bool => true);
        $pcreAccepts = false !== @preg_match($pattern, 'probe a+1');
        restore_error_handler();

        $libAccepts = Regex::create()->validate($pattern)->isValid;

        $this->assertSame(
            $pcreAccepts,
            $libAccepts,
            \sprintf('Library and PCRE disagree on %s (PCRE: %s)', $pattern, $pcreAccepts ? 'accepts' : 'rejects'),
        );
    }
}
