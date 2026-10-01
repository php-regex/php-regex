<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Integration\Bridge\PHPStan;

use PhpRegex\Parser\Exception\InvalidRegexOptionException;
use PhpRegex\PHPStan\RegexPatternRule;
use PHPStan\Analyser\Error;
use PHPStan\Php\PhpVersion;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The rule judges patterns for the PHP version PHPStan analyses the project
 * for, not for the PHP that runs PHPStan. It reports a pattern only when the
 * running engine compiles it and the target refuses it: what the running
 * engine refuses, PHPStan core already reports ("regexp.pattern").
 *
 * Every expectation below is guarded by the running engine: on a PCRE2 that
 * itself refuses the pattern (the 10.40 and 10.42 CI images), the rule must
 * stay silent.
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexParserRuleTargetVersionTest extends RuleTestCase
{
    private const ASCII_OPTION = '/(?aD)x/';

    private const SCAN_SUBSTRING = '/(a)(*scs:(1)a)/';

    private ?PhpVersion $phpVersion = null;

    /**
     * @var array<string, mixed>
     */
    private array $config = [];

    #[Test]
    public function test_a_project_on_php_8_2_is_judged_with_the_pcre2_php_8_2_bundles(): void
    {
        // 80200 names PHP 8.2 itself; only a PHP 8.2.0 runner would be the running engine.
        $this->phpVersion = new PhpVersion(80200);

        $this->analyse(
            [__DIR__.'/Fixtures/TargetVersionFixture.php'],
            self::runningEngineCompiles(self::ASCII_OPTION) && 80200 !== \PHP_VERSION_ID ? [
                ['Regex pattern is invalid for PHP 8.2 with PCRE2 10.40: Invalid group modifier syntax at position 2.', 22],
            ] : [],
        );
    }

    #[Test]
    public function test_a_target_specific_refusal_is_reported_under_invalid_for_target(): void
    {
        $this->phpVersion = new PhpVersion(80400);
        $this->config = ['phpVersion' => '8.2'];

        $this->assertSame(
            self::runningEngineCompiles(self::ASCII_OPTION) ? [[22, 'regex.invalidForTarget']] : [],
            $this->identifiersOf(__DIR__.'/Fixtures/TargetVersionFixture.php'),
        );
    }

    #[Test]
    public function test_a_project_on_php_8_4_accepts_what_pcre2_10_44_reads(): void
    {
        $this->phpVersion = new PhpVersion(80400);

        $this->analyse([__DIR__.'/Fixtures/TargetVersionFixture.php'], []);
    }

    #[Test]
    public function test_a_pcre_version_names_the_pcre2_a_distribution_links(): void
    {
        $this->phpVersion = new PhpVersion(80400);
        $this->config = ['pcreVersion' => '10.42'];

        $this->analyse(
            [__DIR__.'/Fixtures/TargetVersionFixture.php'],
            self::runningEngineCompiles(self::ASCII_OPTION) ? [
                ['Regex pattern is invalid for PHP 8.4 with PCRE2 10.42: Invalid group modifier syntax at position 2.', 22],
            ] : [],
        );
    }

    #[Test]
    public function test_a_php_version_parameter_names_the_version(): void
    {
        $this->phpVersion = new PhpVersion(80400);
        $this->config = ['phpVersion' => '8.2'];

        $this->analyse(
            [__DIR__.'/Fixtures/TargetVersionFixture.php'],
            self::runningEngineCompiles(self::ASCII_OPTION) ? [
                ['Regex pattern is invalid for PHP 8.2 with PCRE2 10.40: Invalid group modifier syntax at position 2.', 22],
            ] : [],
        );
    }

    #[Test]
    public function test_runtime_reports_no_validity_error(): void
    {
        // The target is the running engine: whatever it refuses, PHPStan core reports.
        $this->phpVersion = new PhpVersion(80200);
        $this->config = ['phpVersion' => 'runtime'];

        $this->analyse([__DIR__.'/Fixtures/TargetVersionFixture.php'], []);
        $this->analyse([__DIR__.'/Fixtures/TargetSpecificFixture.php'], []);
    }

    #[Test]
    public function test_phpstan_php_version_equal_to_the_running_php_reports_no_validity_error(): void
    {
        $this->phpVersion = new PhpVersion(\PHP_VERSION_ID);

        $this->analyse([__DIR__.'/Fixtures/TargetVersionFixture.php'], []);
        $this->analyse([__DIR__.'/Fixtures/TargetSpecificFixture.php'], []);
    }

    #[Test]
    public function test_a_pattern_the_running_engine_refuses_is_left_to_phpstan_core(): void
    {
        // Line 23 ("/a{2,1}/") is refused by every PCRE2: no error from this rule. Line 21
        // needs PCRE2 10.45, so it is reported only where the running engine compiles it.
        // The library's reason already ends with a period: the message carries one, not two.
        $this->phpVersion = new PhpVersion(80400);
        $this->config = ['phpVersion' => '8.2'];

        $this->analyse(
            [__DIR__.'/Fixtures/TargetSpecificFixture.php'],
            self::runningEngineCompiles(self::SCAN_SUBSTRING) ? [
                ['Regex pattern is invalid for PHP 8.2 with PCRE2 10.40: Invalid or unsupported PCRE verb: "scs".', 21],
            ] : [],
        );
    }

    #[Test]
    public function test_an_unreadable_php_version_fails_when_the_rule_is_built(): void
    {
        $this->expectException(InvalidRegexOptionException::class);

        new RegexPatternRule(config: ['phpVersion' => 'not-a-version']);
    }

    #[Test]
    public function test_an_unreadable_pcre_version_fails_when_the_rule_is_built(): void
    {
        $this->expectException(InvalidRegexOptionException::class);

        new RegexPatternRule(config: ['pcreVersion' => 'ten']);
    }

    protected function getRule(): Rule
    {
        return new RegexPatternRule(config: $this->config, phpVersion: $this->phpVersion);
    }

    /**
     * The oracle: whether the PCRE2 running this test compiles the pattern.
     */
    private static function runningEngineCompiles(string $pattern): bool
    {
        return false !== @preg_match($pattern, '');
    }

    /**
     * @return list<array{int|null, string|null}>
     */
    private function identifiersOf(string $file): array
    {
        $errors = $this->gatherAnalyserErrors([$file]);
        usort($errors, static fn (Error $a, Error $b): int => $a->getLine() <=> $b->getLine());

        return array_map(static fn (Error $error): array => [$error->getLine(), $error->getIdentifier()], $errors);
    }
}
