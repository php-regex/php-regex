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
use PHPRegex\Redos\Finding;
use PHPRegex\Redos\Hotspot;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Redos\RedosWitness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The hint of a ReDoS issue is a list of lines: the evidence first, the
 * risky part and its offsets, one line per distinct finding message, one
 * suggestion entry per distinct rewrite, the possessive advice when no
 * rewrite is known, the test advice last.
 */
final class RedosHintsTest extends TestCase
{
    private const TEST_ADVICE = 'Test with adversarial inputs like repeated strings followed by a non-matching character.';

    #[Test]
    public function test_a_hint_with_nothing_specific_advises_possessive_quantifiers(): void
    {
        $hints = RedosHints::forAnalysis(new RedosAnalysis(RedosSeverity::High, 10), '/abc/');

        $this->assertSame([
            RedosHints::SUGGESTION.'Use possessive quantifiers (*+ instead of *, ++ instead of +, or {m,n}+ instead of {m,n}) to prevent ReDoS.',
            self::TEST_ADVICE,
        ], $hints);
    }

    #[Test]
    public function test_a_finding_without_a_rewrite_gets_no_generic_advice(): void
    {
        $analysis = new RedosAnalysis(RedosSeverity::High, 10, findings: [new Finding(RedosSeverity::High, 'Something risky.', 'a+')]);

        $this->assertSame(['Something risky.', self::TEST_ADVICE], RedosHints::forAnalysis($analysis, '/x/'));
        $this->assertSame(
            ['Analysis incomplete: too deep.', self::TEST_ADVICE],
            RedosHints::forAnalysis(new RedosAnalysis(RedosSeverity::High, 10, null, ['Analysis incomplete: too deep.'], 'too deep.'), '/x/'),
        );
    }

    #[Test]
    public function test_the_lines_come_in_the_order_a_reader_needs(): void
    {
        $analysis = new RedosAnalysis(
            RedosSeverity::High,
            10,
            '[a-z]+',
            findings: [
                new Finding(RedosSeverity::High, 'Unbounded quantifier detected.', 'x', null, 'Make it possessive.'),
                new Finding(RedosSeverity::High, 'Unbounded quantifier detected.', 'y', null, 'Make it possessive.'),
                new Finding(RedosSeverity::Medium, 'Overlapping alternation branches inside a quantifier.', 'z', null, 'Make alternatives mutually exclusive.'),
            ],
            hotspots: [new Hotspot(3, 7, RedosSeverity::High, 'abc')],
            witness: new RedosWitness('', 'a', '!', false),
        );

        $this->assertSame([
            'Attack: "a" x n . "!"',
            'The risky part is: [a-z]+',
            'Hotspot offsets: 3-7',
            'Unbounded quantifier detected.',
            'Overlapping alternation branches inside a quantifier.',
            RedosHints::SUGGESTION.'Make it possessive.',
            RedosHints::SUGGESTION.'Make alternatives mutually exclusive.',
            self::TEST_ADVICE,
        ], RedosHints::forAnalysis($analysis, '/x/'));
        $this->assertSame(['Unbounded quantifier detected.', 'Overlapping alternation branches inside a quantifier.'], RedosHints::messages($analysis));
    }

    #[Test]
    public function test_recommendations_without_findings_are_split_at_their_suggestion(): void
    {
        $analysis = new RedosAnalysis(RedosSeverity::High, 10, null, [
            'A note without a rewrite.',
            'Keep it linear. '.RedosHints::SUGGESTION.'Anchor the pattern.',
            'Keep it linear. '.RedosHints::SUGGESTION.'Anchor the pattern.',
        ]);

        $this->assertSame([
            'A note without a rewrite.',
            'Keep it linear.',
            RedosHints::SUGGESTION.'Anchor the pattern.',
            self::TEST_ADVICE,
        ], RedosHints::forAnalysis($analysis, '/x/'));
        $this->assertSame(['A note without a rewrite.', 'Keep it linear.'], RedosHints::messages($analysis));
    }

    #[Test]
    public function test_a_fix_the_pattern_suggests_is_one_entry_beside_the_findings_rewrite(): void
    {
        $analysis = new RedosAnalysis(RedosSeverity::High, 10, 'a+)+', findings: [
            new Finding(RedosSeverity::High, 'Nested quantifiers.', 'x', null, 'replace (a+)+ with atomic group (?>a+) or possessive a++.'),
        ]);

        $hints = RedosHints::forAnalysis($analysis, '/(a+)+.*+/');

        $this->assertContains('The risky part is: a+)+', $hints);
        $this->assertContains('Nested quantifiers.', $hints);
        $suggestions = array_values(array_filter($hints, static fn (string $line): bool => str_starts_with($line, RedosHints::SUGGESTION)));
        $this->assertSame([
            RedosHints::SUGGESTION.'replace (a+)+ with atomic group (?>a+) or possessive a++.',
            RedosHints::SUGGESTION.'replace nested quantifiers like (a+)+ with atomic groups (?>a+) or possessive quantifiers a++.',
            RedosHints::SUGGESTION.'use possessive quantifiers .*+ instead of .* to prevent backtracking.',
        ], $suggestions);
        $this->assertNotContains(RedosHints::SUGGESTION.'Use possessive quantifiers (*+ instead of *, ++ instead of +, or {m,n}+ instead of {m,n}) to prevent ReDoS.', $hints);
        $this->assertSame(self::TEST_ADVICE, end($hints));
    }

    #[Test]
    public function test_a_hint_names_the_offsets_of_the_hotspot(): void
    {
        $analysis = new RedosAnalysis(RedosSeverity::High, 10, hotspots: [new Hotspot(3, 7, RedosSeverity::High, 'abc')]);

        $this->assertContains('Hotspot offsets: 3-7', RedosHints::forAnalysis($analysis, '/abc/'));
    }

    /**
     * @return iterable<string, array{pattern: string, vulnerablePart: ?string, expected: string}>
     */
    public static function provideSuggestedFixes(): iterable
    {
        yield 'nested quantifiers' => ['pattern' => '/(a+)+$/', 'vulnerablePart' => null, 'expected' => 'replace nested quantifiers like (a+)+ with atomic groups (?>a+) or possessive quantifiers a++.'];
        yield 'dot-star beside a plus' => ['pattern' => '/.*a+/', 'vulnerablePart' => null, 'expected' => 'use possessive quantifiers .*+ instead of .* to prevent backtracking.'];
        yield 'star in a group' => ['pattern' => '/(a*)b/', 'vulnerablePart' => null, 'expected' => 'replace * with *+ in groups to prevent backtracking or wrap in (?>...).'];
        yield 'a word repeated in a repeated group' => ['pattern' => '/x/', 'vulnerablePart' => '(ab+)+', 'expected' => 'replace (ab+)+ with atomic group (?>ab+) or possessive ab++.'];
    }

    #[Test]
    #[DataProvider('provideSuggestedFixes')]
    public function test_a_hint_suggests_the_fix_the_pattern_calls_for(string $pattern, ?string $vulnerablePart, string $expected): void
    {
        $hints = RedosHints::forAnalysis(new RedosAnalysis(RedosSeverity::High, 10, $vulnerablePart), $pattern);

        $this->assertContains(RedosHints::SUGGESTION.$expected, $hints);
        $this->assertNotContains($expected, $hints);
    }
}
