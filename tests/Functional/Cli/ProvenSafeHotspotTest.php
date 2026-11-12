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
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The hotspots come from the heuristic profiler, the verdict from the proof.
 * When the proof says no input drives one attempt beyond linear steps, the
 * analysis carries no hotspot, finding or culprit, and the console points
 * at no part of the pattern as risky: a hotspot under "safe (proven)" would
 * read as a contradiction.
 */
final class ProvenSafeHotspotTest extends TestCase
{
    private const PROVEN_SAFE = '/a+/';

    private const VULNERABLE = '/(a+)+$/';

    #[Test]
    public function test_a_proven_safe_analysis_carries_no_hotspot(): void
    {
        $analysis = (new RedosAnalyzer())->analyze(self::PROVEN_SAFE);

        $this->assertTrue($analysis->isProvenSafe());
        $this->assertNull($analysis->primaryHotspot(), 'The proof settles the verdict: the heuristic does not mark a+.');
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
    public function test_debug_lists_no_findings_for_a_proven_safe_pattern(): void
    {
        $buffer = $this->runCommand(new DebugCommand(), 'debug', [self::PROVEN_SAFE]);

        $this->assertStringNotContainsString('Heuristic hints', $buffer);
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
     * The culprit, its trigger and an attack input built for it are the
     * profiler's guess too: under a proof of safety there is nothing to
     * blame and no input to try.
     */
    #[Test]
    public function test_debug_blames_nothing_and_builds_no_attack_for_a_proven_safe_pattern(): void
    {
        $buffer = $this->runCommand(new DebugCommand(), 'debug', [self::PROVEN_SAFE]);

        $this->assertStringContainsString('safe (proven)', $buffer);
        $this->assertStringNotContainsString('Culprit:', $buffer);
        $this->assertStringNotContainsString('Trigger:', $buffer);
        $this->assertStringNotContainsString('Input:', $buffer);
    }

    #[Test]
    public function test_debug_blames_the_culprit_and_builds_an_attack_for_a_vulnerable_pattern(): void
    {
        $buffer = $this->runCommand(new DebugCommand(), 'debug', [self::VULNERABLE]);

        $this->assertStringContainsString('Culprit:    a+', $buffer);
        $this->assertStringContainsString('Trigger:', $buffer);
        $this->assertMatchesRegularExpression('/^  Input:      "a+!" \(auto\)$/m', $buffer);
    }

    #[Test]
    public function test_debug_still_shows_the_input_given_for_a_proven_safe_pattern(): void
    {
        $buffer = $this->runCommand(new DebugCommand(), 'debug', [self::PROVEN_SAFE, '--input=xyz']);

        $this->assertStringContainsString("  Input:      \"xyz\"\n", $buffer);
    }

    #[Test]
    public function test_debug_json_carries_no_attack_input_for_a_proven_safe_pattern(): void
    {
        $document = $this->runJson([self::PROVEN_SAFE]);

        $this->assertSame('safe', $document['severity']);
        $this->assertSame(['value' => null, 'source' => null], $document['input']);
    }

    #[Test]
    public function test_debug_json_carries_the_input_given_for_a_proven_safe_pattern(): void
    {
        $document = $this->runJson([self::PROVEN_SAFE, '--input=xyz']);

        $this->assertSame(['value' => 'xyz', 'source' => 'user'], $document['input']);
    }

    #[Test]
    public function test_debug_json_carries_the_attack_input_of_a_vulnerable_pattern(): void
    {
        $document = $this->runJson([self::VULNERABLE]);

        $this->assertSame('auto', $document['input']['source'] ?? null);
        $this->assertIsString($document['input']['value'] ?? null);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{severity: mixed, input: array<mixed>}
     */
    private function runJson(array $arguments): array
    {
        $document = json_decode($this->runCommand(new DebugCommand(), 'debug', [...$arguments, '--format=json']), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($document);
        $this->assertIsArray($document['analysis'] ?? null);
        $this->assertIsArray($document['input'] ?? null);

        return ['severity' => $document['analysis']['severity'] ?? null, 'input' => $document['input']];
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
