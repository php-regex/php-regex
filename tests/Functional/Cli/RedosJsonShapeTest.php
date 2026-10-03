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
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The JSON of a ReDoS analysis, pinned key by key: the 1.x keys unchanged,
 * the verdict keys added, for each kind of result.
 */
final class RedosJsonShapeTest extends TestCase
{
    private const KEYS = [
        // 1.x keys, unchanged.
        'severity', 'score', 'mode', 'confirmed', 'confidence', 'vulnerable_part', 'vulnerable_subpattern',
        'trigger', 'false_positive_risk', 'suggested_rewrite', 'recommendations', 'error', 'findings',
        'hotspots', 'confirmation',
        // The verdict.
        'complexity', 'degree', 'proof', 'witness', 'replayed', 'abstractions', 'pcre_version',
        'analysis_version',
    ];

    /**
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('provideResults')]
    public function test_redos_json_keys_and_verdict_values(string $pattern, RedosMode $mode, array $expected): void
    {
        $json = self::decode(json_encode((new RedosAnalyzer())->analyze($pattern, null, $mode), \JSON_THROW_ON_ERROR));

        $this->assertJsonShape($json, $expected);
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('provideResults')]
    public function test_redos_json_shape_through_the_analyze_command(string $pattern, RedosMode $mode, array $expected): void
    {
        $arguments = [$pattern, '--format=json', '--redos-mode='.$mode->value];
        $input = new Input('analyze', $arguments, new GlobalOptions(false, false, false, true, null, null), []);

        ob_start();

        try {
            (new AnalyzeCommand())->run($input, OutputFactory::create());
        } finally {
            $buffer = (string) ob_get_clean();
        }

        $payload = self::decode($buffer);
        $this->assertIsArray($payload['redos'] ?? null, $buffer);
        /** @var array<string, mixed> $redos */
        $redos = $payload['redos'];

        $this->assertJsonShape($redos, $expected);
    }

    /**
     * @return iterable<string, array{pattern: string, mode: RedosMode, expected: array<string, mixed>}>
     */
    public static function provideResults(): iterable
    {
        yield 'proven exponential' => ['pattern' => '/(a+)+$/', 'mode' => RedosMode::Theoretical, 'expected' => [
            'severity' => 'critical',
            'confidence' => 'medium',
            'complexity' => 'exponential',
            'degree' => null,
            'proof' => 'proven',
            'replayed' => null,
            'abstractions' => [],
        ]];
        yield 'proven polynomial' => ['pattern' => '/a*a*a*$/', 'mode' => RedosMode::Theoretical, 'expected' => [
            'severity' => 'high',
            'confidence' => 'medium',
            'complexity' => 'polynomial',
            'degree' => 3,
            'proof' => 'proven',
            'replayed' => null,
            'abstractions' => [],
        ]];
        yield 'proven safe' => ['pattern' => '/(?>a+)+$/', 'mode' => RedosMode::Theoretical, 'expected' => [
            'severity' => 'safe',
            'confidence' => 'high',
            'complexity' => 'linear',
            'degree' => null,
            'proof' => 'proven',
            'witness' => null,
            'replayed' => null,
            'abstractions' => [],
        ]];
        // A backreference is out of the model; the heuristics judge it medium.
        yield 'heuristic' => ['pattern' => '/(a)\1+/', 'mode' => RedosMode::Theoretical, 'expected' => [
            'severity' => 'medium',
            'complexity' => 'unknown',
            'degree' => null,
            'proof' => 'heuristic',
            'witness' => null,
            'replayed' => null,
        ]];
        yield 'not analyzed' => ['pattern' => '/(a+)+$/', 'mode' => RedosMode::Off, 'expected' => [
            'severity' => 'safe',
            'complexity' => 'unknown',
            'degree' => null,
            'proof' => 'not_analyzed',
            'witness' => null,
            'replayed' => null,
            'abstractions' => [],
        ]];
    }

    /**
     * @param array<mixed>         $json
     * @param array<string, mixed> $expected
     */
    private function assertJsonShape(array $json, array $expected): void
    {
        $actualKeys = array_keys($json);
        $expectedKeys = self::KEYS;
        sort($actualKeys);
        sort($expectedKeys);
        $this->assertSame($expectedKeys, $actualKeys);

        foreach ($expected as $key => $value) {
            $this->assertSame($value, $json[$key], $key);
        }

        $this->assertSame(RedosAnalyzer::ANALYSIS_VERSION, $json['analysis_version']);
        $this->assertIsString($json['pcre_version']);
        $this->assertNotSame('', $json['pcre_version']);
        $this->assertStringStartsWith($json['pcre_version'], \PCRE_VERSION);
        $this->assertIsArray($json['abstractions']);

        if (\array_key_exists('witness', $expected)) {
            return;
        }

        // Vulnerable verdicts: the escaped witness parts, never the input for n pumps.
        $this->assertIsArray($json['witness']);
        $this->assertSame(['prefix', 'pump', 'suffix'], array_keys($json['witness']));
        $this->assertContainsOnlyString($json['witness']);
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
