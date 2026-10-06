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

namespace PHPRegex\Tests\Unit\Cli;

use PHPRegex\Cli\Command\AnalyzeCommand;
use PHPRegex\Cli\Command\DebugCommand;
use PHPRegex\Cli\Output;
use PHPRegex\Redos\Confirmation;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The severity of a ReDoS verdict is coloured by what stands behind it: a
 * high or critical verdict is red when it stands confirmed (reproduced, or
 * proven with a replay the engine could not run), yellow otherwise.
 */
final class RedosSeverityColourTest extends TestCase
{
    #[Test]
    #[DataProvider('provideVerdicts')]
    public function test_severity_colour_follows_the_verdict(RedosAnalysis $analysis, string $colour): void
    {
        $output = OutputFactory::create(true);
        $label = strtoupper($analysis->severity->value);

        foreach ([new AnalyzeCommand(), new DebugCommand()] as $command) {
            $method = (new \ReflectionClass($command))->getMethod('formatRedosSeverity');

            $this->assertSame($colour.$label.Output::RESET, $method->invoke($command, $analysis, $output), $command::class);
        }
    }

    /**
     * @return iterable<string, array{analysis: RedosAnalysis, colour: string}>
     */
    public static function provideVerdicts(): iterable
    {
        yield 'safe' => ['analysis' => self::analysis(RedosSeverity::Safe), 'colour' => Output::GREEN];
        yield 'low' => ['analysis' => self::analysis(RedosSeverity::Low), 'colour' => Output::GREEN];
        yield 'medium' => ['analysis' => self::analysis(RedosSeverity::Medium), 'colour' => Output::YELLOW];
        yield 'unknown' => ['analysis' => self::analysis(RedosSeverity::Unknown), 'colour' => Output::BLUE];
        yield 'critical, theoretical' => ['analysis' => self::analysis(RedosSeverity::Critical), 'colour' => Output::YELLOW];
        yield 'high, theoretical' => ['analysis' => self::analysis(RedosSeverity::High), 'colour' => Output::YELLOW];
        yield 'critical, reproduced' => [
            'analysis' => self::analysis(RedosSeverity::Critical, RedosMode::Confirmed, new Confirmation(true, [], '0', 100_000, null, 1, 0.0, false, 'backtrack_limit')),
            'colour' => Output::RED,
        ];
        yield 'high, reproduced' => [
            'analysis' => self::analysis(RedosSeverity::High, RedosMode::Confirmed, new Confirmation(true, [], '0', 100_000, null, 1, 0.0, false, 'backtrack_limit')),
            'colour' => Output::RED,
        ];
        yield 'critical, proven, replay skipped' => [
            'analysis' => self::analysis(RedosSeverity::Critical, RedosMode::Confirmed, new Confirmation(false, [], null, null, null, 0, 0.0, false, Confirmation::LIMITS_UNAVAILABLE), RedosProof::Proven),
            'colour' => Output::RED,
        ];
        yield 'critical, heuristic, replay skipped' => [
            'analysis' => self::analysis(RedosSeverity::Critical, RedosMode::Confirmed, new Confirmation(false, [], null, null, null, 0, 0.0, false, Confirmation::LIMITS_UNAVAILABLE)),
            'colour' => Output::YELLOW,
        ];
    }

    private static function analysis(
        RedosSeverity $severity,
        RedosMode $mode = RedosMode::Theoretical,
        ?Confirmation $confirmation = null,
        RedosProof $proof = RedosProof::Heuristic,
    ): RedosAnalysis {
        return new RedosAnalysis($severity, 0, mode: $mode, confirmation: $confirmation, proof: $proof);
    }
}
