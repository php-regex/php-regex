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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan;

use PHPRegex\Parser\Hir\ClassSetProvider;
use PHPRegex\PHPStan\MixedTypeCollector;
use PHPRegex\PHPStan\NoNewMixedRule;
use PHPRegex\PHPStan\RegexPatternRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The rule as extension-installer loads it for every project: the shipped
 * extension.neon and nothing else. PHPStan's phpVersion is left unset, so the
 * target is the PHP running this test with the PCRE2 it links.
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexPatternRuleExtensionNeonTest extends RuleTestCase
{
    protected function tearDown(): void
    {
        ClassSetProvider::persistIn(null);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [
            ...parent::getAdditionalConfigFiles(),
            __DIR__.'/../../../../src/PHPStan/extension.neon',
        ];
    }

    /**
     * What the engine answers about Unicode classes is kept in PHPStan's
     * own temporary directory, for the next run and the other workers.
     */
    #[Test]
    public function test_extension_neon_keeps_the_class_scans_in_phpstan_temporary_directory(): void
    {
        $tmpDir = self::getContainer()->getParameter('tmpDir');
        $this->assertIsString($tmpDir);

        $rule = self::getContainer()->getByType(RegexPatternRule::class);
        $this->assertSame($tmpDir.'/php-regex', (new \ReflectionProperty(RegexPatternRule::class, 'cacheDirectory'))->getValue($rule));
    }

    #[Test]
    public function test_extension_neon_reports_no_lint_no_redos_and_nothing_core_reports(): void
    {
        // Lint (lines 20-22), ReDoS (line 22) and a pattern the running engine refuses (line 23):
        // none of them is this rule's to report by default.
        $this->analyse([__DIR__.'/Fixtures/NeonConfigFixture.php'], []);
    }

    #[Test]
    public function test_extension_neon_turns_every_check_but_validity_off(): void
    {
        $parameters = self::getContainer()->getParameter('phpRegex');

        $this->assertSame([
            'phpVersion' => null,
            'pcreVersion' => null,
            'lint' => false,
            'redos' => false,
            'threshold' => 'critical',
            'optimizations' => false,
            'minSavings' => 1,
            'complexity' => false,
            'complexityThreshold' => 50,
        ], [
            'phpVersion' => NeonParameters::read($parameters, 'phpVersion'),
            'pcreVersion' => NeonParameters::read($parameters, 'pcreVersion'),
            'lint' => NeonParameters::read($parameters, 'checks', 'lint', 'enabled'),
            'redos' => NeonParameters::read($parameters, 'checks', 'redos', 'enabled'),
            'threshold' => NeonParameters::read($parameters, 'checks', 'redos', 'threshold'),
            'optimizations' => NeonParameters::read($parameters, 'checks', 'optimizations', 'enabled'),
            'minSavings' => NeonParameters::read($parameters, 'checks', 'optimizations', 'minSavings'),
            'complexity' => NeonParameters::read($parameters, 'checks', 'complexity', 'enabled'),
            'complexityThreshold' => NeonParameters::read($parameters, 'checks', 'complexity', 'threshold'),
        ]);
    }

    /**
     * The library's own check on the mixed types it declares is off for
     * every other project: neither its rule nor its collector runs.
     */
    #[Test]
    public function test_extension_neon_leaves_the_mixed_type_check_off(): void
    {
        $this->assertFalse(NeonParameters::read(self::getContainer()->getParameter('phpRegex'), 'noNewMixed', 'enabled'));
        $this->assertSame([], array_filter(
            [...self::getContainer()->getServicesByTag('phpstan.rules.rule'), ...self::getContainer()->getServicesByTag('phpstan.collector')],
            static fn (mixed $service): bool => $service instanceof NoNewMixedRule || $service instanceof MixedTypeCollector,
        ));
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function provideRemovedKeys(): iterable
    {
        yield 'ignoreParseErrors' => ['ignoreParseErrors'];
        yield 'reportRedos' => ['reportRedos'];
        yield 'redosMode' => ['redosMode'];
        yield 'redosThreshold' => ['redosThreshold'];
        yield 'suggestOptimizations' => ['suggestOptimizations'];
        yield 'optimizationConfig' => ['optimizationConfig'];
        yield 'checks.redos.mode' => ['checks', 'redos', 'mode'];
        yield 'checks.redos.noJit' => ['checks', 'redos', 'noJit'];
    }

    #[Test]
    #[DataProvider('provideRemovedKeys')]
    public function test_extension_neon_carries_no_removed_key(string ...$path): void
    {
        $this->assertSame(
            NeonParameters::MISSING,
            NeonParameters::read(self::getContainer()->getParameter('phpRegex'), ...$path),
        );
    }

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(RegexPatternRule::class);
    }
}
