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

use PHPRegex\Parser\RegexParser;
use PHPRegex\PHPStan\RegexPatternRule;
use PHPRegex\Tests\Support\PcreGates;
use PHPStan\Php\PhpVersion;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A pattern is judged only for the PHP versions of PHPStan's range its call
 * runs on: PHPStan narrows PHP_VERSION_ID under a guard, and the floor and
 * the later versions judged are those of the narrowed range, its lowest
 * version first. A guard PHPStan does not narrow on keeps the whole range,
 * and a guard that keeps no version of the range judges nothing. What the
 * engine running the analysis refuses is PHPStan's to report, never the
 * rule's: a refusal is expected only of a pattern that engine compiles.
 *
 * @extends RuleTestCase<RegexPatternRule>
 *
 * @phpstan-import-type ConfigArray from RegexPatternRule
 */
final class RegexPatternRuleGuardedTargetTest extends RuleTestCase
{
    use PcreGates;

    private const ASCII = '/(?a)\d+/';

    private const KEEP_IN_LOOKBEHIND = '/(?<=a\Kb)c/';

    /**
     * @var array{min: int, max: int}
     */
    private array $range = ['min' => 80200, 'max' => 80599];

    /**
     * @var ConfigArray
     */
    private array $config = [];

    private bool $withReflection = true;

    private int $phpVersion = 80200;

    #[Test]
    public function test_a_guard_judges_the_versions_its_branch_runs_on(): void
    {
        $this->analyse([__DIR__.'/Fixtures/GuardedTargetFixture.php'], self::refusals([
            [self::ASCII, '8.2', '10.40', 28],
            [self::ASCII, '8.3', '10.42', 57],
            [self::KEEP_IN_LOOKBEHIND, '8.5', '10.44', 60],
            [self::KEEP_IN_LOOKBEHIND, '8.5', '10.44', 70],
            [self::ASCII, '8.2', '10.40', 84],
            [self::ASCII, '8.2', '10.40', 91],
        ]));
    }

    /**
     * An unqualified PHP_VERSION_ID that names a constant of its namespace
     * is no PHP version: the guard on it is not read.
     */
    #[Test]
    public function test_a_namespaced_constant_of_the_same_name_is_not_read(): void
    {
        $this->analyse([__DIR__.'/Fixtures/ShadowedVersionFixture.php'], self::refusals([
            [self::KEEP_IN_LOOKBEHIND, '8.5', '10.44', 27],
        ]));
    }

    /**
     * Without the reflection provider, nothing tells whether an unqualified
     * PHP_VERSION_ID of a namespace names the global constant: the guard
     * written so is not read, the one written "\\PHP_VERSION_ID" still is.
     */
    #[Test]
    public function test_without_the_reflection_provider_an_unqualified_guard_of_a_namespace_is_not_read(): void
    {
        $this->requiresCompile(self::ASCII);
        $this->withReflection = false;

        $lines = array_map(static fn ($error): ?int => $error->getLine(), $this->gatherAnalyserErrors([__DIR__.'/Fixtures/GuardedTargetFixture.php']));

        $this->assertContains(26, $lines);
        $this->assertNotContains(36, $lines);
        $this->assertNotContains(41, $lines);
    }

    /**
     * The lint and the optimizations of a guarded call judge it with the
     * lowest version the guard keeps.
     */
    #[Test]
    public function test_the_checks_of_a_guarded_call_run_on_its_lowest_version(): void
    {
        $this->config = ['checks' => ['lint' => ['enabled' => true], 'optimizations' => ['enabled' => true]]];

        $identifiers = [];
        foreach ($this->gatherAnalyserErrors([__DIR__.'/Fixtures/GuardedTargetFixture.php']) as $error) {
            if (\in_array($error->getLine(), [105, 106], true)) {
                $identifiers[] = \sprintf('%d %s', $error->getLine(), $error->getIdentifier() ?? '');
            }
        }

        $this->assertContains('105 regex.lint.charclass.redundant', $identifiers);
        $this->assertContains('106 regex.optimization', $identifiers);
    }

    /**
     * A range that starts on PCRE1 is judged from PHP 7.3: a guard that
     * keeps only the versions below leaves the call unjudged, though
     * PHPStan's type of PHP_VERSION_ID still holds versions there.
     */
    #[Test]
    public function test_a_guard_that_keeps_only_the_versions_on_pcre1_judges_nothing(): void
    {
        $this->requiresCompile(self::ASCII);
        $this->range = ['min' => 70100, 'max' => 80499];
        $this->phpVersion = 70100;

        $lines = array_map(static fn ($error): ?int => $error->getLine(), $this->gatherAnalyserErrors([__DIR__.'/Fixtures/GuardedTargetFixture.php']));

        $this->assertContains(28, $lines);
        $this->assertNotContains(113, $lines);
    }

    /**
     * "phpRegex.pcreVersion" names the PCRE2 of every version judged, the
     * lowest of the narrowed range included.
     */
    #[Test]
    public function test_the_pcre_version_named_judges_the_lowest_version_of_the_narrowed_range(): void
    {
        $this->requiresCompile(self::ASCII);
        $this->config = ['pcreVersion' => '10.42'];
        [$message, $line] = self::refusal(self::ASCII, '8.4', '10.42', 26);

        $errors = array_map(static fn ($error): array => [$error->getLine(), $error->getMessage()], $this->gatherAnalyserErrors([__DIR__.'/Fixtures/GuardedTargetFixture.php']));

        $this->assertContains([$line, $message], $errors);
        $this->assertNotContains(36, array_column(array_filter($errors, static fn (array $error): bool => str_contains($error[1], 'PHP 8.2')), 0));
    }

    /**
     * One version named for the rule, or by PHPStan, is judged alone, as
     * PHPStan analyses the other branch as dead: nothing changes.
     */
    #[Test]
    public function test_one_version_judged_ignores_the_guards(): void
    {
        $this->range = ['min' => 80200, 'max' => 80599];
        $this->config = ['phpVersion' => '8.2'];

        $this->analyse([__DIR__.'/Fixtures/GuardedTargetFixture.php'], self::refusals(array_map(
            static fn (int $line): array => [self::ASCII, '8.2', '10.40', $line],
            [26, 28, 36, 41, 47, 57, 59, 84, 91, 98, 113],
        )));
    }

    protected function getRule(): Rule
    {
        return new RegexPatternRule(config: $this->config, phpVersion: new PhpVersion($this->phpVersion), phpVersionRange: $this->range, reflectionProvider: $this->withReflection ? $this->createReflectionProvider() : null);
    }

    /**
     * The refusals of the rows whose pattern the engine running the
     * analysis compiles.
     *
     * @param list<array{string, string, string, int}> $rows the pattern, the PHP, the PCRE2 and the line
     *
     * @return list<array{0: string, 1: int, 2?: string|null}>
     */
    private static function refusals(array $rows): array
    {
        $refusals = [];
        foreach ($rows as [$pattern, $php, $pcre, $line]) {
            if (false !== @preg_match($pattern, '')) {
                $refusals[] = self::refusal($pattern, $php, $pcre, $line);
            }
        }

        return $refusals;
    }

    /**
     * @return array{0: string, 1: int, 2?: string|null}
     */
    private static function refusal(string $pattern, string $php, string $pcre, int $line): array
    {
        $validation = RegexParser::create(['php_version' => $php, 'pcre_version' => $pcre, 'runtime_pcre_validation' => false])->validate($pattern);
        self::assertFalse($validation->isValid, $pattern.' is refused on PHP '.$php);
        $reason = self::firstLine($validation->error ?? '');

        $error = ['Regex pattern is invalid for PHP '.$php.' with PCRE2 '.$pcre.': '.$reason.(str_ends_with($reason, '.') ? '' : '.'), $line];
        if (null !== $validation->hint && '' !== $validation->hint) {
            $error[] = $validation->hint;
        }

        return $error;
    }

    private static function firstLine(string $message): string
    {
        return explode("\n", $message)[0];
    }
}
