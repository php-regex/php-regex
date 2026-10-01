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

use PhpRegex\Linter\Config\LintConfigLoader;
use PhpRegex\Linter\Config\LintConfigSchema;
use PhpRegex\Tests\Support\TemporaryProject;
use PhpRegex\Tests\TestUtils\JsonSchemaSubsetValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One definition of regex.json: the PHP one. regex.schema.json is written
 * from it, the repository's own regex.dist.json conforms to it, and the
 * loader refuses exactly what it refuses.
 */
final class LintConfigSchemaTest extends TestCase
{
    use TemporaryProject;

    private const ROOT = __DIR__.'/../../../..';

    #[Test]
    public function test_schema_file_is_written_from_the_php_definition(): void
    {
        $this->assertSame(
            self::roundTrip(LintConfigSchema::definition()),
            self::decodeFile(self::ROOT.'/regex.schema.json'),
            'regex.schema.json differs from LintConfigSchema::definition(); regenerate the file from the definition.',
        );
    }

    #[Test]
    public function test_dist_config_validates_against_the_definition(): void
    {
        $contents = (string) file_get_contents(self::ROOT.'/regex.dist.json');

        $this->assertSame([], $this->validate(json_decode($contents, false, 512, \JSON_THROW_ON_ERROR)));
    }

    #[Test]
    #[DataProvider('provideRefusedConfigs')]
    public function test_schema_and_loader_both_refuse(string $json): void
    {
        $this->assertNotSame([], $this->validate(json_decode($json, false, 512, \JSON_THROW_ON_ERROR)), 'The schema accepts it.');

        $this->enterProject(['regex.json' => $json]);
        $this->assertNotNull((new LintConfigLoader())->load()->error, 'The loader accepts it.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRefusedConfigs(): iterable
    {
        yield 'removed rules' => ['{"rules": {"redos": true}}'];
        yield 'removed redosMode' => ['{"redosMode": "confirmed"}'];
        yield 'removed redosThreshold' => ['{"redosThreshold": "high"}'];
        yield 'removed redosNoJit' => ['{"redosNoJit": true}'];
        yield 'removed optimizations' => ['{"optimizations": {"digits": true}}'];
        yield 'removed top-level minSavings' => ['{"minSavings": 2}'];
        yield 'removed checks.redos.noJit' => ['{"checks": {"redos": {"noJit": true}}}'];
        yield 'redos mode off' => ['{"checks": {"redos": {"mode": "off"}}}'];
        yield 'redos as a boolean' => ['{"checks": {"redos": true}}'];
        yield 'optimizations as a boolean' => ['{"checks": {"optimizations": false}}'];
        yield 'lint as a boolean' => ['{"checks": {"lint": true}}'];
        yield 'unknown top-level key' => ['{"unknownTop": 1}'];
        yield 'unknown option' => ['{"checks": {"optimizations": {"options": {"unknownOption": true}}}}'];
        yield 'unknown extraction key' => ['{"extraction": {"unknownExtraction": []}}'];
        yield 'unknown lint rule id' => ['{"checks": {"lint": {"rules": {"not.a.rule": true}}}}'];
        yield 'threshold safe' => ['{"checks": {"redos": {"threshold": "safe"}}}'];
        yield 'threshold unknown' => ['{"checks": {"redos": {"threshold": "unknown"}}}'];
        yield 'minQuantifierCount below 2' => ['{"checks": {"optimizations": {"options": {"minQuantifierCount": 1}}}}'];
        yield 'jobs below 1' => ['{"jobs": 0}'];
    }

    #[Test]
    #[DataProvider('provideAcceptedConfigs')]
    public function test_schema_and_loader_both_accept(string $json): void
    {
        $this->assertSame([], $this->validate(json_decode($json, false, 512, \JSON_THROW_ON_ERROR)));

        $this->enterProject(['regex.json' => $json]);
        $this->assertNull((new LintConfigLoader())->load()->error);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAcceptedConfigs(): iterable
    {
        yield 'empty object' => ['{}'];
        yield 'schema pointer' => ['{"$schema": "./regex.schema.json"}'];
        yield 'target as strings' => ['{"phpVersion": "8.3", "pcreVersion": "10.42"}'];
        yield 'target as a PHP_VERSION_ID' => ['{"phpVersion": 80300}'];
        yield 'every check as an object' => ['{"checks": {"validation": true, "redos": {"enabled": true, "mode": "confirmed", "threshold": "high"}, "optimizations": {"enabled": true, "minSavings": 2, "options": {"digits": true, "minQuantifierCount": 2}}, "lint": {"enabled": true, "rules": {"unicode.shorthandWithoutU": true}}}}'];
        yield 'empty lists' => ['{"paths": [], "exclude": [], "extraction": {"interop": [], "functions": []}}'];
    }

    /**
     * @return list<string>
     */
    private function validate(mixed $document): array
    {
        return (new JsonSchemaSubsetValidator())->validate(self::roundTrip(LintConfigSchema::definition()), $document);
    }

    /**
     * The definition as the file holds it: what json_encode() writes and
     * json_decode() reads back.
     *
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private static function roundTrip(array $definition): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(json_encode($definition, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeFile(string $path): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
