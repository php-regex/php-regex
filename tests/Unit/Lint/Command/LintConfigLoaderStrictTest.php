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

namespace PhpRegex\Tests\Unit\Lint\Command;

use PhpRegex\Linter\Config\LintArgumentParser;
use PhpRegex\Linter\Config\LintArguments;
use PhpRegex\Linter\Config\LintConfigLoader;
use PhpRegex\Linter\Config\LintConfigResult;
use PhpRegex\Linter\Config\LintDefaultsBuilder;
use PhpRegex\Linter\Config\ProjectTarget;
use PhpRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * regex.json in 2.0: one spelling per setting, every unknown key refused,
 * every error reported in one pass.
 */
final class LintConfigLoaderStrictTest extends TestCase
{
    use TemporaryProject;

    #[Test]
    #[DataProvider('provideRemovedKeys')]
    public function test_load_refuses_a_removed_key_and_names_its_replacement(string $json, string $removed, ?string $replacement): void
    {
        $error = $this->loadError(['regex.json' => $json]);

        $this->assertStringContainsString($removed, $error);
        if (null !== $replacement) {
            $this->assertStringContainsString($replacement, $error);
        }
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function provideRemovedKeys(): iterable
    {
        yield 'rules' => ['{"rules": {"redos": true, "validation": true, "optimization": false}}', 'rules', 'checks'];
        yield 'redosMode' => ['{"redosMode": "confirmed"}', 'redosMode', 'checks.redos.mode'];
        yield 'redosThreshold' => ['{"redosThreshold": "high"}', 'redosThreshold', 'checks.redos.threshold'];
        yield 'optimizations' => ['{"optimizations": {"digits": true}}', 'optimizations', 'checks.optimizations.options'];
        yield 'top-level minSavings' => ['{"minSavings": 3}', 'minSavings', 'checks.optimizations.minSavings'];
        // The engine always runs without JIT: neither key has a replacement.
        yield 'redosNoJit' => ['{"redosNoJit": true}', 'redosNoJit', null];
        yield 'checks.redos.noJit' => ['{"checks": {"redos": {"enabled": true, "noJit": false}}}', 'checks.redos.noJit', null];
    }

    #[Test]
    public function test_load_does_not_point_redos_no_jit_at_a_removed_replacement(): void
    {
        $error = $this->loadError(['regex.json' => '{"redosNoJit": true}']);

        $this->assertStringNotContainsString('checks.redos.noJit', $error);
    }

    #[Test]
    public function test_load_refuses_a_removed_key_in_the_dist_file_too(): void
    {
        $error = $this->loadError(['regex.dist.json' => '{"redosThreshold": "high"}']);

        $this->assertStringContainsString('checks.redos.threshold', $error);
    }

    #[Test]
    #[DataProvider('provideBooleanCheckForms')]
    public function test_load_refuses_the_boolean_form_of_a_check(string $json, string $key): void
    {
        $this->assertStringContainsString($key, $this->loadError(['regex.json' => $json]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideBooleanCheckForms(): iterable
    {
        yield 'checks.redos true' => ['{"checks": {"redos": true}}', 'checks.redos'];
        yield 'checks.redos false' => ['{"checks": {"redos": false}}', 'checks.redos'];
        yield 'checks.optimizations false' => ['{"checks": {"optimizations": false}}', 'checks.optimizations'];
        yield 'checks.optimizations true' => ['{"checks": {"optimizations": true}}', 'checks.optimizations'];
        yield 'checks.lint true' => ['{"checks": {"lint": true}}', 'checks.lint'];
        yield 'checks.lint false' => ['{"checks": {"lint": false}}', 'checks.lint'];
    }

    #[Test]
    #[DataProvider('provideOffModes')]
    public function test_load_refuses_the_off_redos_mode(string $mode): void
    {
        $error = $this->loadError(['regex.json' => '{"checks": {"redos": {"mode": "'.$mode.'"}}}']);

        $this->assertStringContainsString('checks.redos.mode', $error);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideOffModes(): iterable
    {
        yield 'lower case' => ['off'];
        yield 'upper case' => ['OFF'];
    }

    #[Test]
    #[DataProvider('provideUnknownKeys')]
    public function test_load_refuses_an_unknown_key_at_any_level(string $json, string $path): void
    {
        $this->assertStringContainsString($path, $this->loadError(['regex.json' => $json]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideUnknownKeys(): iterable
    {
        yield 'top level' => ['{"unknownTop": 1}', 'unknownTop'];
        yield 'top level, wrong case of a known key' => ['{"Paths": ["src"]}', 'Paths'];
        yield 'inside checks' => ['{"checks": {"unknownCheck": true}}', 'checks.unknownCheck'];
        yield 'inside checks.redos' => ['{"checks": {"redos": {"unknownRedos": 1}}}', 'checks.redos.unknownRedos'];
        yield 'inside checks.optimizations' => ['{"checks": {"optimizations": {"unknownOptimizations": 1}}}', 'checks.optimizations.unknownOptimizations'];
        yield 'inside checks.lint' => ['{"checks": {"lint": {"unknownLint": true}}}', 'checks.lint.unknownLint'];
        yield 'inside checks.optimizations.options' => ['{"checks": {"optimizations": {"options": {"unknownOption": true}}}}', 'checks.optimizations.options.unknownOption'];
        yield 'snake_case option in JSON' => ['{"checks": {"optimizations": {"options": {"min_quantifier_count": 3}}}}', 'min_quantifier_count'];
        yield 'inside extraction' => ['{"extraction": {"unknownExtraction": []}}', 'extraction.unknownExtraction'];
    }

    #[Test]
    #[DataProvider('provideUnknownLintRules')]
    public function test_load_refuses_an_unknown_lint_rule_id(string $ruleId): void
    {
        $json = json_encode(['checks' => ['lint' => ['rules' => [$ruleId => true]]]], \JSON_THROW_ON_ERROR);

        $this->assertStringContainsString($ruleId, $this->loadError(['regex.json' => $json]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnknownLintRules(): iterable
    {
        yield 'made-up id' => ['not.a.rule'];
        yield 'known id with the wrong case' => ['unicode.shorthandwithoutu'];
    }

    #[Test]
    public function test_load_accepts_a_known_lint_rule_id_and_the_schema_pointer(): void
    {
        $result = $this->load(['regex.json' => '{"$schema": "./regex.schema.json", "checks": {"lint": {"rules": {"unicode.shorthandWithoutU": true}}}}']);

        $this->assertNull($result->error);
        $this->assertSame(['unicode.shorthandWithoutU' => true], $this->argumentsFor($result)->lintRules);
    }

    #[Test]
    public function test_load_reports_every_error_at_once(): void
    {
        $error = $this->loadError(['regex.json' => '{"unknownTop": 1, "redosMode": "confirmed", "checks": {"redos": {"threshold": "safe"}}}']);

        $this->assertStringContainsString('unknownTop', $error);
        $this->assertStringContainsString('redosMode', $error);
        $this->assertStringContainsString('checks.redos.threshold', $error);
    }

    #[Test]
    public function test_load_reports_three_unknown_keys_at_three_levels(): void
    {
        $error = $this->loadError(['regex.json' => '{"unknownTop": 1, "checks": {"unknownCheck": true}, "extraction": {"unknownExtraction": []}}']);

        $this->assertStringContainsString('unknownTop', $error);
        $this->assertStringContainsString('checks.unknownCheck', $error);
        $this->assertStringContainsString('extraction.unknownExtraction', $error);
    }

    #[Test]
    #[DataProvider('provideRefusedThresholds')]
    public function test_load_refuses_a_threshold_outside_low_to_critical(string $threshold): void
    {
        $error = $this->loadError(['regex.json' => '{"checks": {"redos": {"threshold": "'.$threshold.'"}}}']);

        $this->assertStringContainsString('checks.redos.threshold', $error);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRefusedThresholds(): iterable
    {
        yield 'safe' => ['safe'];
        yield 'SAFE' => ['SAFE'];
        yield 'unknown' => ['unknown'];
        yield 'Unknown' => ['Unknown'];
    }

    #[Test]
    #[DataProvider('provideAcceptedThresholds')]
    public function test_load_reads_a_threshold_case_insensitively(string $threshold, string $expected): void
    {
        $result = $this->load(['regex.json' => '{"checks": {"redos": {"enabled": true, "threshold": "'.$threshold.'"}}}']);

        $this->assertNull($result->error);
        $this->assertSame($expected, $this->argumentsFor($result)->redosThreshold);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideAcceptedThresholds(): iterable
    {
        yield 'low' => ['low', 'low'];
        yield 'MEDIUM' => ['MEDIUM', 'medium'];
        yield 'High' => ['High', 'high'];
        yield 'critical' => ['critical', 'critical'];
    }

    #[Test]
    #[DataProvider('provideTargetSettings')]
    public function test_load_accepts_and_exposes_the_target_versions(string $json, int $phpVersionId, string $pcreVersion): void
    {
        $directory = $this->enterProject(['regex.json' => $json]);
        $result = (new LintConfigLoader())->load();

        $this->assertNull($result->error);

        $target = ProjectTarget::resolve(null, null, $result->config, $directory, []);
        $this->assertSame($phpVersionId, $target->target()->phpVersionId);
        $this->assertSame($pcreVersion, $target->target()->pcreVersion);
        $this->assertSame('regex.json', $target->source());
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function provideTargetSettings(): iterable
    {
        yield 'phpVersion as a string' => ['{"phpVersion": "8.3"}', 80300, '10.42'];
        yield 'phpVersion as a PHP_VERSION_ID' => ['{"phpVersion": 80300}', 80300, '10.42'];
        yield 'both versions' => ['{"phpVersion": "8.2", "pcreVersion": "10.42"}', 80200, '10.42'];
    }

    #[Test]
    #[DataProvider('provideInvalidTargetSettings')]
    public function test_load_refuses_a_malformed_target_version(string $json, string $key): void
    {
        $this->assertStringContainsString($key, $this->loadError(['regex.json' => $json]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideInvalidTargetSettings(): iterable
    {
        yield 'phpVersion not a version' => ['{"phpVersion": "eight"}', 'phpVersion'];
        yield 'phpVersion empty' => ['{"phpVersion": ""}', 'phpVersion'];
        yield 'phpVersion boolean' => ['{"phpVersion": true}', 'phpVersion'];
        yield 'phpVersion float' => ['{"phpVersion": 8.3}', 'phpVersion'];
        yield 'pcreVersion without minor' => ['{"pcreVersion": "10"}', 'pcreVersion'];
        yield 'pcreVersion not a version' => ['{"pcreVersion": "latest"}', 'pcreVersion'];
        yield 'pcreVersion float' => ['{"pcreVersion": 10.42}', 'pcreVersion'];
    }

    /**
     * @param array<string, string> $files
     */
    private function load(array $files): LintConfigResult
    {
        $this->enterProject($files);

        return (new LintConfigLoader())->load();
    }

    /**
     * @param array<string, string> $files
     */
    private function loadError(array $files): string
    {
        $result = $this->load($files);
        $this->assertNotNull($result->error, 'The configuration should have been refused.');

        return $result->error;
    }

    private function argumentsFor(LintConfigResult $result): LintArguments
    {
        $parsed = (new LintArgumentParser())->parse([], (new LintDefaultsBuilder())->build($result->config));
        $this->assertNull($parsed->error);
        $this->assertInstanceOf(LintArguments::class, $parsed->arguments);

        return $parsed->arguments;
    }
}
