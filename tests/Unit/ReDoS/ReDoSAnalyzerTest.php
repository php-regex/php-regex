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

namespace RegexParser\Tests\Unit\ReDoS;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RegexParser\Node\NodeInterface;
use RegexParser\ReDoS\ReDoSAnalysis;
use RegexParser\ReDoS\ReDoSAnalyzer;
use RegexParser\ReDoS\ReDoSConfidence;
use RegexParser\ReDoS\ReDoSConfirmation;
use RegexParser\ReDoS\ReDoSConfirmationRunnerInterface;
use RegexParser\ReDoS\ReDoSConfirmationSample;
use RegexParser\ReDoS\ReDoSConfirmOptions;
use RegexParser\ReDoS\ReDoSMode;
use RegexParser\ReDoS\ReDoSSeverity;

final class ReDoSAnalyzerTest extends TestCase
{
    private ReDoSAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new ReDoSAnalyzer();
    }

    #[DataProvider('patternProvider')]
    public function test_severity_analysis(string $pattern, ReDoSSeverity $expectedSeverity): void
    {
        $analysis = $this->analyzer->analyze($pattern);
        $this->assertSame($expectedSeverity, $analysis->severity, "Failed asserting severity for pattern: $pattern");
    }

    public static function patternProvider(): \Iterator
    {
        // SAFE
        yield ['/abc/', ReDoSSeverity::SAFE];
        yield ['/^\d{4}-\d{2}-\d{2}$/', ReDoSSeverity::SAFE];
        yield ['/^[a-z0-9]+(?:-[a-z0-9]+)*$/', ReDoSSeverity::SAFE];

        // LOW (Bounded nested)
        yield ['/(a{1,5}){1,5}/', ReDoSSeverity::LOW];

        // MEDIUM (Single unbounded)
        yield ['/a+/', ReDoSSeverity::MEDIUM];
        yield ['/.*ok/', ReDoSSeverity::MEDIUM];

        // HIGH (Nested unbounded)
        yield ['/(a+)+/', ReDoSSeverity::CRITICAL]; // Triggers Star Height > 1

        // CRITICAL (Overlapping alternation in loop)
        yield ['/(a|a)+/', ReDoSSeverity::CRITICAL];
        yield ['/(a|a)*/', ReDoSSeverity::CRITICAL];

        // CRITICAL payload inside a conditional's condition (lookaround)
        yield ['/(?(?=(a+)+b)x|y)/', ReDoSSeverity::CRITICAL];

        // Atomic groups (Mitigation)
        yield ['/(?>a+)+/', ReDoSSeverity::SAFE];
        yield ['/a++/', ReDoSSeverity::SAFE];
        yield ['/(\\d++\\. )*\\d++$/', ReDoSSeverity::SAFE];
    }

    public function test_analysis_details(): void
    {
        $analysis = $this->analyzer->analyze('/(a+)+/');

        // The visitor detects critical nesting for this specific pattern
        $this->assertSame(ReDoSSeverity::CRITICAL, $analysis->severity);
        $this->assertNotEmpty($analysis->recommendations);
    }

    public function test_confirmed_mode_adds_confirmation_and_upgrades_confidence(): void
    {
        $runner = new class implements ReDoSConfirmationRunnerInterface {
            public int $calls = 0;

            public function confirm(string $regex, ReDoSAnalysis $analysis, ?ReDoSConfirmOptions $options = null): ReDoSConfirmation
            {
                $this->calls++;

                return new ReDoSConfirmation(
                    true,
                    [new ReDoSConfirmationSample(32, 12.0, 'aaaa!')],
                    '0',
                    100,
                    100,
                    2,
                    50.0,
                    false,
                    'backtrack_limit',
                    null,
                    null,
                );
            }
        };

        $analyzer = new ReDoSAnalyzer(null, [], ReDoSSeverity::LOW, $runner);
        $analysis = $analyzer->analyze('/(a+)+$/', ReDoSSeverity::LOW, ReDoSMode::CONFIRMED, new ReDoSConfirmOptions());

        $this->assertSame(1, $runner->calls);
        $this->assertSame(ReDoSMode::CONFIRMED, $analysis->mode);
        $this->assertTrue($analysis->isConfirmed());
        $this->assertInstanceOf(ReDoSConfirmation::class, $analysis->confirmation);
        $this->assertSame('backtrack_limit', $analysis->confirmation->evidence);
        // The confirmation runs without the JIT, whatever the process sets.
        $this->assertSame('0', $analysis->confirmation->jitSetting);
        $this->assertSame(ReDoSConfidence::HIGH, $analysis->confidenceLevel());
    }

    public function test_hotspots_capture_culprit_span(): void
    {
        $analysis = $this->analyzer->analyze('/(a+)+b/');

        $this->assertNotEmpty($analysis->hotspots);
        $this->assertInstanceOf(NodeInterface::class, $analysis->getCulpritNode());

        $matched = false;
        foreach ($analysis->hotspots as $hotspot) {
            if (1 === $hotspot->start && 3 === $hotspot->end) {
                $matched = true;
                $this->assertSame(ReDoSSeverity::CRITICAL, $hotspot->severity);

                break;
            }
        }

        $this->assertTrue($matched, 'Expected a hotspot covering the inner quantifier span.');
    }

    public function test_analyze_returns_safe_for_ignored_pattern(): void
    {
        $analyzer = new ReDoSAnalyzer(null, ['/foo/']);
        $analysis = $analyzer->analyze('/foo/');

        $this->assertSame(ReDoSSeverity::SAFE, $analysis->severity);
        $this->assertSame(0, $analysis->score);
    }

    public function test_normalize_pattern_falls_back_on_parse_error(): void
    {
        $analyzer = new ReDoSAnalyzer(null, ['invalid[']);
        $analysis = $analyzer->analyze('invalid[');

        $this->assertSame(ReDoSSeverity::SAFE, $analysis->severity);
    }

    public function test_symfony_slug_pattern_is_treated_as_safe(): void
    {
        $analysis = $this->analyzer->analyze('/[a-z0-9]+(?:-[a-z0-9]+)*/');

        $this->assertContains($analysis->severity, [ReDoSSeverity::SAFE, ReDoSSeverity::LOW]);
    }
}
