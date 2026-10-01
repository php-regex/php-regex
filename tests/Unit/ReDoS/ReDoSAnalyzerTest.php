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

namespace PHPRegex\Tests\Unit\ReDoS;

use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Redos\Confirmation;
use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\ConfirmationRunnerInterface;
use PHPRegex\Redos\ConfirmationSample;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosConfidence;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReDoSAnalyzerTest extends TestCase
{
    private RedosAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new RedosAnalyzer();
    }

    #[DataProvider('patternProvider')]
    public function test_severity_analysis(string $pattern, RedosSeverity $expectedSeverity): void
    {
        $analysis = $this->analyzer->analyze($pattern);
        $this->assertSame($expectedSeverity, $analysis->severity, "Failed asserting severity for pattern: $pattern");
    }

    public static function patternProvider(): \Iterator
    {
        // SAFE
        yield ['/abc/', RedosSeverity::Safe];
        yield ['/^\d{4}-\d{2}-\d{2}$/', RedosSeverity::Safe];
        yield ['/^[a-z0-9]+(?:-[a-z0-9]+)*$/', RedosSeverity::Safe];

        // Proven linear: no continuation of the pumped input is rejected, so a
        // match attempt never backtracks. Measured on PCRE2 10.49, JIT off:
        // every one matches a…a! (or never finds "ok") in under 0.5 ms at n=8000.
        yield ['/(a{1,5}){1,5}/', RedosSeverity::Safe];
        yield ['/a+/', RedosSeverity::Safe];
        // o…o!k (the required "k" present): 0.11 ms at n=8000.
        yield ['/.*ok/', RedosSeverity::Safe];
        // Nested loops with nothing after them: the first run already matches.
        yield ['/(a+)+/', RedosSeverity::Safe];
        yield ['/(a|a)+/', RedosSeverity::Safe];
        yield ['/(a|a)*/', RedosSeverity::Safe];

        // CRITICAL payload inside a conditional's condition (lookaround)
        yield ['/(?(?=(a+)+b)x|y)/', RedosSeverity::Critical];

        // Atomic groups (Mitigation)
        yield ['/(?>a+)+/', RedosSeverity::Safe];
        yield ['/a++/', RedosSeverity::Safe];
        yield ['/(\\d++\\. )*\\d++$/', RedosSeverity::Safe];
    }

    public function test_analysis_details(): void
    {
        // Anchored at the end, so that a rejected continuation makes the nesting
        // critical: a…a! fails at 19 pumps on 10.49. Without the "$", /(a+)+/
        // matches a…a! at once and is proven linear.
        $analysis = $this->analyzer->analyze('/(a+)+$/');

        $this->assertSame(RedosSeverity::Critical, $analysis->severity);
        $this->assertNotEmpty($analysis->recommendations);
    }

    public function test_confirmed_mode_adds_confirmation_and_upgrades_confidence(): void
    {
        $runner = new class implements ConfirmationRunnerInterface {
            public int $calls = 0;

            public function confirm(string $regex, RedosAnalysis $analysis, ?ConfirmationOptions $options = null): Confirmation
            {
                $this->calls++;

                return new Confirmation(
                    true,
                    [new ConfirmationSample(32, 12.0, 'aaaa!')],
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

        $analyzer = new RedosAnalyzer(null, [], RedosSeverity::Low, $runner);
        $analysis = $analyzer->analyze('/(a+)+$/', RedosSeverity::Low, RedosMode::Confirmed, new ConfirmationOptions());

        $this->assertSame(1, $runner->calls);
        $this->assertSame(RedosMode::Confirmed, $analysis->mode);
        $this->assertTrue($analysis->isConfirmed());
        $this->assertInstanceOf(Confirmation::class, $analysis->confirmation);
        $this->assertSame('backtrack_limit', $analysis->confirmation->evidence);
        // The confirmation runs without the JIT, whatever the process sets.
        $this->assertSame('0', $analysis->confirmation->jitSetting);
        $this->assertSame(RedosConfidence::High, $analysis->confidenceLevel());
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
                $this->assertSame(RedosSeverity::Critical, $hotspot->severity);

                break;
            }
        }

        $this->assertTrue($matched, 'Expected a hotspot covering the inner quantifier span.');
    }

    public function test_analyze_returns_safe_for_ignored_pattern(): void
    {
        $analyzer = new RedosAnalyzer(null, ['/foo/']);
        $analysis = $analyzer->analyze('/foo/');

        $this->assertSame(RedosSeverity::Safe, $analysis->severity);
        $this->assertSame(0, $analysis->score);
    }

    public function test_normalize_pattern_falls_back_on_parse_error(): void
    {
        $analyzer = new RedosAnalyzer(null, ['invalid[']);
        $analysis = $analyzer->analyze('invalid[');

        $this->assertSame(RedosSeverity::Safe, $analysis->severity);
    }

    public function test_symfony_slug_pattern_is_treated_as_safe(): void
    {
        $analysis = $this->analyzer->analyze('/[a-z0-9]+(?:-[a-z0-9]+)*/');

        $this->assertContains($analysis->severity, [RedosSeverity::Safe, RedosSeverity::Low]);
    }
}
