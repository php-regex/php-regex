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

namespace PHPRegex\Tests\Unit\Packages;

use PHPRegex\Tests\Tools\PHPStan\NoNewMixedRule;
use PHPUnit\Framework\TestCase;

/**
 * The baseline of the mixed types src/ declares, counted package by
 * package: the static analysis holds each symbol to its entry, and this
 * test holds each package to its total, so that one package cannot take
 * what another gave back. A count moves here and in the baseline in the
 * same change, on purpose, and only down.
 */
final class MixedTypeBaselineTest extends TestCase
{
    private const BASELINE = __DIR__.'/../../../phpstan-mixed-baseline.php';

    /**
     * The mixed types each package may declare.
     */
    private const PER_PACKAGE = [
        'Automata' => 2,
        'Cli' => 2,
        'LanguageServer' => 10,
        'Laravel' => 7,
        'Linter' => 34,
        'Optimizer' => 3,
        'PHPStan' => 2,
        'PHPUnit' => 21,
        'Parser' => 64,
        'Symfony' => 21,
    ];

    public function test_each_package_declares_the_mixed_types_it_is_counted_for(): void
    {
        $counts = [];
        foreach (self::baseline() as $file => $symbols) {
            $package = explode('/', $file)[1] ?? '';
            foreach ($symbols as [, $count]) {
                $counts[$package] = ($counts[$package] ?? 0) + $count;
            }
        }
        ksort($counts);

        $this->assertSame(self::PER_PACKAGE, $counts);
    }

    /**
     * The check runs in the repository's own analysis, from its tooling:
     * the published PHPStan extension carries neither the rule nor its
     * parameter.
     */
    public function test_the_repository_analysis_holds_src_to_the_baseline(): void
    {
        $root = \dirname(self::BASELINE);
        $neon = (string) file_get_contents($root.'/phpstan.dist.neon');

        $this->assertStringContainsString("\n    - tests/Tools/PHPStan/mixed-types.neon\n", $neon);
        $this->assertStringContainsString("\n    noNewMixed:\n        baseline: %currentWorkingDirectory%/phpstan-mixed-baseline.php\n        paths:\n            - %currentWorkingDirectory%/src\n", $neon);
        $this->assertStringNotContainsString('noNewMixed', (string) file_get_contents($root.'/src/PHPStan/extension.neon'));
    }

    public function test_each_entry_gives_a_reason_of_the_vocabulary_and_a_count(): void
    {
        foreach (self::baseline() as $file => $symbols) {
            $this->assertFileExists(\dirname(self::BASELINE).'/'.$file);
            $this->assertStringStartsWith('src/', $file);

            foreach ($symbols as $symbol => $entry) {
                $this->assertContains($entry[0], NoNewMixedRule::REASONS, $symbol);
                $this->assertGreaterThanOrEqual(1, $entry[1], $symbol);
            }
        }
    }

    public function test_the_reasons_are_the_written_vocabulary(): void
    {
        $this->assertSame([
            'json-decode',
            'host-contract',
            'host-return',
            'template-identity',
            'validator-input',
            'protocol-slot',
            'callback-opaque',
            'reflection-sink',
            'open-options-bag',
            'preg-mirror',
        ], NoNewMixedRule::REASONS);
    }

    /**
     * @return array<string, array<string, array{string, int}>>
     */
    private static function baseline(): array
    {
        /** @var array<string, array<string, array{string, int}>> $baseline */
        $baseline = require self::BASELINE;

        return $baseline;
    }
}
