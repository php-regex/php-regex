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

namespace PHPRegex\Tests\Functional\Cli;

use PHPRegex\Cli\Command\AnalyzeCommand;
use PHPRegex\Cli\Command\DebugCommand;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Redos\Hotspot;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The hotspots come from the heuristic profiler, the verdict from the proof.
 * When the proof says no input drives one attempt beyond linear steps, the
 * console does not point at a part of the pattern as risky: a hotspot under
 * "safe (proven)" reads as a contradiction. The JSON keeps them, as hints.
 */
final class ProvenSafeHotspotTest extends TestCase
{
    private const PROVEN_SAFE = '/a+/';

    private const VULNERABLE = '/(a+)+$/';

    #[Test]
    public function test_the_profiler_marks_a_hotspot_in_a_proven_safe_pattern(): void
    {
        $analysis = (new RedosAnalyzer())->analyze(self::PROVEN_SAFE);

        $this->assertTrue($analysis->isProvenSafe());
        $this->assertInstanceOf(Hotspot::class, $analysis->primaryHotspot(), 'The heuristic still marks a+: what the console hides.');
    }

    #[Test]
    public function test_analyze_prints_no_hotspot_for_a_proven_safe_pattern(): void
    {
        $buffer = $this->runCommand(new AnalyzeCommand(), 'analyze', [self::PROVEN_SAFE]);

        $this->assertStringContainsString('safe (proven)', $buffer);
        $this->assertStringNotContainsString('Hotspot', $buffer);
    }

    #[Test]
    public function test_analyze_prints_the_hotspot_of_a_vulnerable_pattern(): void
    {
        $this->assertStringContainsString('Hotspot:   1-3', $this->runCommand(new AnalyzeCommand(), 'analyze', [self::VULNERABLE]));
    }

    #[Test]
    public function test_debug_marks_no_hotspot_in_a_proven_safe_pattern(): void
    {
        $buffer = $this->runCommand(new DebugCommand(), 'debug', [self::PROVEN_SAFE]);

        $this->assertStringContainsString('safe (proven)', $buffer);
        $this->assertStringNotContainsString('Hotspots:', $buffer);
        $this->assertDoesNotMatchRegularExpression('/^ *\^+$/m', $buffer, 'No caret under the pattern.');
    }

    #[Test]
    public function test_debug_lists_the_findings_of_a_proven_safe_pattern_as_heuristic_hints(): void
    {
        $buffer = $this->runCommand(new DebugCommand(), 'debug', [self::PROVEN_SAFE]);

        $this->assertStringContainsString('Heuristic hints', $buffer);
        $this->assertStringNotContainsString('Findings', $buffer);
    }

    #[Test]
    public function test_debug_lists_the_findings_of_a_vulnerable_pattern(): void
    {
        $buffer = $this->runCommand(new DebugCommand(), 'debug', [self::VULNERABLE]);

        $this->assertStringContainsString('Findings', $buffer);
        $this->assertStringContainsString('Hotspots:', $buffer);
    }

    /**
     * @param list<string> $arguments
     */
    private function runCommand(AnalyzeCommand|DebugCommand $command, string $name, array $arguments): string
    {
        $input = new Input($name, $arguments, new GlobalOptions(false, false, false, true, null, null), []);

        $level = ob_get_level();
        ob_start();

        try {
            $command->run($input, OutputFactory::create());
        } finally {
            $buffer = (string) ob_get_clean();
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return $buffer;
    }
}
