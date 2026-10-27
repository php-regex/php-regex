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

namespace PHPRegex\Tests\Unit\Lint\Advice;

use PHPRegex\Linter\Advice\RedosHints;
use PHPRegex\Redos\Hotspot;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The hint of a ReDoS issue: the evidence first, then what the analysis
 * recommends, the risky part, its offsets and the fixes the pattern
 * suggests, the possessive advice when nothing else is said.
 */
final class RedosHintsTest extends TestCase
{
    #[Test]
    public function test_a_hint_with_nothing_specific_advises_possessive_quantifiers(): void
    {
        $hint = RedosHints::forAnalysis(new RedosAnalysis(RedosSeverity::High, 10), '/abc/');

        $this->assertStringContainsString('Use possessive quantifiers', $hint);
        $this->assertStringContainsString('*+', $hint);
        $this->assertStringContainsString('++', $hint);
        $this->assertStringContainsString('{m,n}+', $hint);
        $this->assertStringEndsWith('Test with adversarial inputs like repeated strings followed by a non-matching character.', $hint);
    }

    #[Test]
    public function test_a_hint_carries_the_recommendations_and_the_fixes_the_pattern_suggests(): void
    {
        $hint = RedosHints::forAnalysis(new RedosAnalysis(RedosSeverity::High, 10, 'a+)+', ['Keep it linear'], null), '/(a+)+.*+/');

        $this->assertStringContainsString('Keep it linear', $hint);
        $this->assertStringContainsString('The risky part is: a+)+', $hint);
        $this->assertStringContainsString('Suggested (verify behavior)', $hint);
        $this->assertStringContainsString('possessive', $hint);
        $this->assertStringNotContainsString('Use possessive quantifiers (*+ instead of *', $hint);
    }

    #[Test]
    public function test_a_hint_names_the_offsets_of_the_hotspot(): void
    {
        $analysis = new RedosAnalysis(RedosSeverity::High, 10, hotspots: [new Hotspot(3, 7, RedosSeverity::High, 'abc')]);

        $this->assertStringContainsString('Hotspot offsets: 3-7', RedosHints::forAnalysis($analysis, '/abc/'));
    }

    /**
     * @return iterable<string, array{pattern: string, vulnerablePart: ?string, expected: string}>
     */
    public static function provideSuggestedFixes(): iterable
    {
        yield 'nested quantifiers' => ['pattern' => '/(a+)+$/', 'vulnerablePart' => null, 'expected' => 'replace nested quantifiers like (a+)+ with atomic groups'];
        yield 'dot-star beside a plus' => ['pattern' => '/.*a+/', 'vulnerablePart' => null, 'expected' => 'use possessive quantifiers .*+ instead of .*'];
        yield 'star in a group' => ['pattern' => '/(a*)b/', 'vulnerablePart' => null, 'expected' => 'replace * with *+ in groups'];
        yield 'a word repeated in a repeated group' => ['pattern' => '/x/', 'vulnerablePart' => '(ab+)+', 'expected' => 'replace (ab+)+ with atomic group (?>ab+) or possessive ab++.'];
    }

    #[Test]
    #[DataProvider('provideSuggestedFixes')]
    public function test_a_hint_suggests_the_fix_the_pattern_calls_for(string $pattern, ?string $vulnerablePart, string $expected): void
    {
        $this->assertStringContainsString($expected, RedosHints::forAnalysis(new RedosAnalysis(RedosSeverity::High, 10, $vulnerablePart), $pattern));
    }
}
