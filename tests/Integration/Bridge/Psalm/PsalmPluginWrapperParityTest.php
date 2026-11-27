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

namespace PHPRegex\Tests\Integration\Bridge\Psalm;

use PHPRegex\PHPStan\RegexPatternRule;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPStan\Analyser\Error;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The plugin and the PHPStan extension read the calls of the wrapper
 * libraries through the same presets and judge them with the same
 * analysis: on one file of calls, each reports a ReDoS finding on the same
 * lines, of the same kind, under the same headline.
 *
 * Two readings differ, and are left out: nette/utils' utf8: true, which the
 * plugin applies and the extension does not, so their headlines show the
 * pattern each judged; and a lazy Strings::matchAll(), read by each as the
 * preset prices it.
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class PsalmPluginWrapperParityTest extends RuleTestCase
{
    private const FILE = 'Wrappers/wrapper_redos.php';

    private const KINDS = [
        RegexPatternRule::IDENTIFIER_REDOS => 'RegexRedos',
        RegexPatternRule::IDENTIFIER_REDOS_SEARCH => 'RegexRedosSearch',
    ];

    #[Test]
    public function test_plugin_reports_the_redos_findings_of_the_wrapper_calls_the_phpstan_extension_reports(): void
    {
        $exponential = PcreInterpreter::pattern('/^(\w+\s?)+$/');
        $this->assertFalse(@preg_match($exponential, str_repeat('0', 40).'!'), 'Oracle: the attack does not exhaust the backtrack limit.');
        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, preg_last_error(), 'Oracle: '.preg_last_error_msg());
        $retry = PcreInterpreter::pattern('/(?:a+\d)?/');
        $this->assertSame(1002, preg_match_all($retry, str_repeat('a', 1000).'!'), 'Oracle: preg_match_all() stops before the end of the run.');
        $this->assertSame(1, preg_match($retry, str_repeat('a', 1000).'!'), 'Oracle: preg_match() goes on.');

        $lines = file(PsalmRun::FIXTURES.'/'.self::FILE, \FILE_IGNORE_NEW_LINES) ?: [];
        $compared = static fn (int $line): bool => !str_contains($lines[$line - 1] ?? '', 'utf8: true') && !str_contains($lines[$line - 1] ?? '', 'lazy: true');

        $extension = [];
        foreach ($this->gatherAnalyserErrors([PsalmRun::FIXTURES.'/'.self::FILE]) as $error) {
            $this->assertInstanceOf(Error::class, $error);
            $kind = self::KINDS[$error->getIdentifier() ?? ''] ?? null;
            $line = (int) $error->getLine();
            if (null !== $kind && $compared($line)) {
                $extension[] = [$line, $kind, $error->getMessage()];
            }
        }
        sort($extension);

        $plugin = [];
        $issues = PsalmRun::issues(['Wrappers/wrapper_calls.php', self::FILE], '8.4', ['redos' => 'true', 'redosThreshold' => 'medium'], stubs: ['Wrappers/libraries.phpstub']);
        foreach (PsalmRun::reported(PsalmRun::inFile($issues, self::FILE)) as [$line, $type, $message]) {
            if ($compared($line)) {
                // The plugin's message is the headline, then what the extension prints in its tip.
                $plugin[] = [$line, $type, explode('. ', $message, 2)[0]];
            }
        }
        sort($plugin);

        $this->assertGreaterThan(30, \count($extension));
        $this->assertSame($extension, $plugin);
    }

    protected function getRule(): Rule
    {
        return new RegexPatternRule(config: [
            'checks' => [
                'lint' => ['enabled' => false],
                'redos' => ['enabled' => true, 'threshold' => 'medium'],
                'optimizations' => ['enabled' => false],
            ],
        ]);
    }
}
