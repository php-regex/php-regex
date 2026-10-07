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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Redos\Confirmation;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The hint of a ReDoS issue says the runtime checks observed backtracking
 * only for a confirmed-mode verdict they confirmed without a replayed
 * witness, which has its own line; a skipped replay ran no check and gets
 * no confirmation hint at all.
 */
final class RedosConfirmationHintTest extends TestCase
{
    private const OBSERVED = 'Confirmation: bounded runtime checks observed evidence of excessive backtracking.';

    #[Test]
    #[DataProvider('provideAnalyses')]
    public function test_redos_hint_names_the_runtime_checks_only_when_they_confirmed(RedosAnalysis $analysis, bool $observed): void
    {
        $service = new AnalysisService(Regex::create(['cache' => null])->parser());
        $hint = (new \ReflectionClass($service))->getMethod('getReDoSHint')->invoke($service, $analysis, '/(a+)+$/');

        $this->assertIsString($hint);
        $this->assertSame($observed, str_contains($hint, self::OBSERVED), $hint);
        $this->assertStringNotContainsString('found no evidence', (string) $hint);
    }

    /**
     * @return iterable<string, array{analysis: RedosAnalysis, observed: bool}>
     */
    public static function provideAnalyses(): iterable
    {
        $confirmed = new Confirmation(true, [], '0', 100_000, null, 1, 0.0, false, 'backtrack_limit');
        $skipped = new Confirmation(false, [], null, null, null, 0, 0.0, false, Confirmation::LIMITS_UNAVAILABLE);

        yield 'confirmed by the runtime checks' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Critical, 0, mode: RedosMode::Confirmed, confirmation: $confirmed),
            'observed' => true,
        ];
        yield 'confirmed, with a replayed witness' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Critical, 0, mode: RedosMode::Confirmed, confirmation: $confirmed, replayed: true),
            'observed' => false,
        ];
        yield 'theoretical mode' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Critical, 0, confirmation: $confirmed),
            'observed' => false,
        ];
        yield 'replay skipped' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Critical, 0, mode: RedosMode::Confirmed, confirmation: $skipped),
            'observed' => false,
        ];
        yield 'no confirmation' => [
            'analysis' => new RedosAnalysis(RedosSeverity::Critical, 0, mode: RedosMode::Confirmed),
            'observed' => false,
        ];
    }
}
