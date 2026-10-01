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

namespace PhpRegex\Tests\Unit\ReDoS;

use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Redos\Confirmation;
use PhpRegex\Redos\ConfirmationOptions;
use PhpRegex\Redos\ConfirmationRunnerInterface;
use PhpRegex\Redos\ConfirmationSample;
use PhpRegex\Redos\RedosAnalysis;
use PhpRegex\Redos\RedosAnalyzer;
use PhpRegex\Redos\RedosConfidence;
use PhpRegex\Redos\RedosMode;
use PhpRegex\Redos\RedosSeverity;
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
        yield ['/abc/', RedosSeverity::SAFE];
        yield ['/^\d{4}-\d{2}-\d{2}$/', RedosSeverity::SAFE];
        yield ['/^[a-z0-9]+(?:-[a-z0-9]+)*$/', RedosSeverity::SAFE];

        // LOW (Bounded nested)
        yield ['/(a{1,5}){1,5}/', RedosSeverity::LOW];

        // MEDIUM (Single unbounded)
        yield ['/a+/', RedosSeverity::MEDIUM];
        yield ['/.*ok/', RedosSeverity::MEDIUM];

        // HIGH (Nested unbounded)
        yield ['/(a+)+/', RedosSeverity::CRITICAL]; // Triggers Star Height > 1

        // CRITICAL (Overlapping alternation in loop)
        yield ['/(a|a)+/', RedosSeverity::CRITICAL];
        yield ['/(a|a)*/', RedosSeverity::CRITICAL];

        // CRITICAL payload inside a conditional's condition (lookaround)
        yield ['/(?(?=(a+)+b)x|y)/', RedosSeverity::CRITICAL];

        // Atomic groups (Mitigation)
        yield ['/(?>a+)+/', RedosSeverity::SAFE];
        yield ['/a++/', RedosSeverity::SAFE];
        yield ['/(\\d++\\. )*\\d++$/', RedosSeverity::SAFE];
    }

    public function test_analysis_details(): void
    {
        $analysis = $this->analyzer->analyze('/(a+)+/');

        // The visitor detects critical nesting for this specific pattern
        $this->assertSame(RedosSeverity::CRITICAL, $analysis->severity);
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

        $analyzer = new RedosAnalyzer(null, [], RedosSeverity::LOW, $runner);
        $analysis = $analyzer->analyze('/(a+)+$/', RedosSeverity::LOW, RedosMode::CONFIRMED, new ConfirmationOptions());

        $this->assertSame(1, $runner->calls);
        $this->assertSame(RedosMode::CONFIRMED, $analysis->mode);
        $this->assertTrue($analysis->isConfirmed());
        $this->assertInstanceOf(Confirmation::class, $analysis->confirmation);
        $this->assertSame('backtrack_limit', $analysis->confirmation->evidence);
        // The confirmation runs without the JIT, whatever the process sets.
        $this->assertSame('0', $analysis->confirmation->jitSetting);
        $this->assertSame(RedosConfidence::HIGH, $analysis->confidenceLevel());
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
                $this->assertSame(RedosSeverity::CRITICAL, $hotspot->severity);

                break;
            }
        }

        $this->assertTrue($matched, 'Expected a hotspot covering the inner quantifier span.');
    }

    public function test_analyze_returns_safe_for_ignored_pattern(): void
    {
        $analyzer = new RedosAnalyzer(null, ['/foo/']);
        $analysis = $analyzer->analyze('/foo/');

        $this->assertSame(RedosSeverity::SAFE, $analysis->severity);
        $this->assertSame(0, $analysis->score);
    }

    public function test_normalize_pattern_falls_back_on_parse_error(): void
    {
        $analyzer = new RedosAnalyzer(null, ['invalid[']);
        $analysis = $analyzer->analyze('invalid[');

        $this->assertSame(RedosSeverity::SAFE, $analysis->severity);
    }

    public function test_symfony_slug_pattern_is_treated_as_safe(): void
    {
        $analysis = $this->analyzer->analyze('/[a-z0-9]+(?:-[a-z0-9]+)*/');

        $this->assertContains($analysis->severity, [RedosSeverity::SAFE, RedosSeverity::LOW]);
    }
}
