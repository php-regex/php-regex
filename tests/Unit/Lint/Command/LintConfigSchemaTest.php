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

namespace PHPRegex\Tests\Unit\Lint\Command;

use PHPRegex\Linter\Config\LintConfigLoader;
use PHPRegex\Linter\Config\LintConfigSchema;
use PHPRegex\Linter\Formatter\LinkFormatter;
use PHPRegex\Linter\Formatter\RelativePathHelper;
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPRegex\Tests\TestUtils\JsonSchemaSubsetValidator;
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
            self::decodeFile(self::ROOT.'/src/Linter/regex.schema.json'),
            'regex.schema.json differs from LintConfigSchema::definition(); regenerate the file from the definition.',
        );
    }

    #[Test]
    public function test_schema_describes_the_values_it_takes_not_former_ones(): void
    {
        $this->assertStringNotContainsString('removed', (string) file_get_contents(self::ROOT.'/src/Linter/regex.schema.json'));
        $this->assertStringContainsString('checks.redos.enabled', (string) file_get_contents(self::ROOT.'/src/Linter/regex.schema.json'));
    }

    /**
     * Each IDE name the ide key takes opens the links with the template
     * the schema documents for it, and no other name has one.
     */
    #[Test]
    public function test_every_ide_the_schema_offers_has_the_template_the_links_use(): void
    {
        $definition = LintConfigSchema::definition();
        $this->assertIsArray($definition['properties'] ?? null);
        $this->assertIsArray($definition['properties']['ide'] ?? null);
        $this->assertIsArray($definition['properties']['ide']['oneOf'] ?? null);

        $documented = [];
        foreach ($definition['properties']['ide']['oneOf'] as $choice) {
            $this->assertIsArray($choice);
            if (isset($choice['const']) && '' !== $choice['const']) {
                $this->assertIsString($choice['const']);
                $this->assertIsString($choice['description'] ?? null);
                $documented[$choice['const']] = substr($choice['description'], \strlen('Use '));
            }
        }

        $this->assertSame(LinkFormatter::IDE_LINK_FORMATS, $documented);
    }

    /**
     * The placeholders a template of the ide key must hold one of, as the
     * schema accepts them and as its error message names them, are those
     * the links fill in: a template holding one gets a link that holds
     * none.
     */
    #[Test]
    public function test_the_ide_placeholders_are_those_the_links_fill_in(): void
    {
        $definition = LintConfigSchema::definition();
        $this->assertIsArray($definition['properties'] ?? null);
        $this->assertIsArray($definition['properties']['ide'] ?? null);
        $this->assertIsString($definition['properties']['ide']['x-expected'] ?? null);
        $this->assertIsArray($definition['properties']['ide']['oneOf'] ?? null);
        $template = end($definition['properties']['ide']['oneOf']);
        $this->assertIsArray($template);
        $this->assertIsString($template['pattern'] ?? null);

        $build = new \ReflectionMethod(LinkFormatter::class, 'buildEditorUrl');
        foreach (['%f', '%file%', '%relFile%', '%l', '%line%', '%c', '%column%'] as $placeholder) {
            $this->assertStringContainsString($placeholder, (string) $definition['properties']['ide']['x-expected']);
            $this->assertMatchesRegularExpression('/'.$template['pattern'].'/', 'x://'.$placeholder);

            $url = $build->invoke(new LinkFormatter('x://'.$placeholder, new RelativePathHelper(__DIR__)), __FILE__, 3, 7);
            $this->assertIsString($url);
            $this->assertStringNotContainsString('%', (string) $url, $placeholder);
        }
        $this->assertDoesNotMatchRegularExpression('/'.$template['pattern'].'/', 'x://%x');
    }

    /**
     * Without u, \w follows the LC_CTYPE locale, which may widen it past
     * [0-9A-Z_a-z]: the word option never promises the rewrite to \w, only
     * where the two match alike.
     */
    #[Test]
    public function test_the_word_option_does_not_promise_the_shorthand(): void
    {
        $definition = LintConfigSchema::definition();
        $this->assertIsArray($definition['$defs'] ?? null);
        $this->assertIsArray($definition['$defs']['optimizationOptions'] ?? null);
        $this->assertIsArray($definition['$defs']['optimizationOptions']['properties'] ?? null);
        $this->assertIsArray($definition['$defs']['optimizationOptions']['properties']['word'] ?? null);
        $description = $definition['$defs']['optimizationOptions']['properties']['word']['description'] ?? null;
        $this->assertIsString($description);

        $this->assertStringNotContainsString('to \w.', (string) $description);
        $this->assertStringContainsString('locale', (string) $description);
    }

    #[Test]
    public function test_dist_config_validates_against_the_definition(): void
    {
        $contents = (string) file_get_contents(self::ROOT.'/regex.dist.json');

        $this->assertSame([], $this->validate(json_decode($contents, false, 512, \JSON_THROW_ON_ERROR)));
    }

    #[Test]
    public function test_a_locale_is_read(): void
    {
        $this->assertSame([], $this->validate(json_decode('{"locale": "tr_TR.UTF-8"}', false, 512, \JSON_THROW_ON_ERROR)));

        $this->enterProject(['regex.json' => '{"locale": "C"}']);
        $loaded = (new LintConfigLoader())->load();
        $this->assertNull($loaded->error);
        $this->assertSame('C', $loaded->config['locale'] ?? null);
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
        yield 'unknown full lint rule id' => ['{"checks": {"lint": {"rules": {"regex.lint.not.a.rule": true}}}}'];
        yield 'full lint rule id not a boolean' => ['{"checks": {"lint": {"rules": {"regex.lint.quantifier.useless": 1}}}}'];
        yield 'a lint rule id with a prefix and a suffix' => ['{"checks": {"lint": {"rules": {"regex.lint.quantifier.uselessX": true}}}}'];
        yield 'a lint rule id inside another' => ['{"checks": {"lint": {"rules": {"x.regex.lint.quantifier.useless": true}}}}'];
        yield 'threshold safe' => ['{"checks": {"redos": {"threshold": "safe"}}}'];
        yield 'threshold unknown' => ['{"checks": {"redos": {"threshold": "unknown"}}}'];
        yield 'minQuantifierCount below 2' => ['{"checks": {"optimizations": {"options": {"minQuantifierCount": 1}}}}'];
        yield 'jobs below 1' => ['{"jobs": 0}'];
        yield 'locale as a number' => ['{"locale": 5}'];
        yield 'empty locale' => ['{"locale": ""}'];
        yield 'complexity as a boolean' => ['{"checks": {"complexity": true}}'];
        yield 'complexity off as a boolean' => ['{"checks": {"complexity": false}}'];
        yield 'complexity threshold zero' => ['{"checks": {"complexity": {"threshold": 0}}}'];
        yield 'complexity threshold negative' => ['{"checks": {"complexity": {"threshold": -5}}}'];
        yield 'complexity threshold as a string' => ['{"checks": {"complexity": {"threshold": "50"}}}'];
        yield 'complexity threshold as a float' => ['{"checks": {"complexity": {"threshold": 12.5}}}'];
        yield 'complexity enabled as a string' => ['{"checks": {"complexity": {"enabled": "yes"}}}'];
        yield 'unknown complexity key' => ['{"checks": {"complexity": {"max": 20}}}'];
        // A PHP version range: a Composer constraint with a lowest version
        // and x.y[.z] versions only, or a {"min", "max"} object with both
        // bounds and nothing else.
        yield 'phpVersion, an empty object' => ['{"phpVersion": {}}'];
        yield 'phpVersion, an object without max' => ['{"phpVersion": {"min": "8.1"}}'];
        yield 'phpVersion, an object without min' => ['{"phpVersion": {"max": "8.6"}}'];
        yield 'phpVersion, an object with another key' => ['{"phpVersion": {"min": "8.1", "max": "8.6", "x": 1}}'];
        yield 'phpVersion, a boolean bound' => ['{"phpVersion": {"min": true, "max": "8.6"}}'];
        yield 'phpVersion, a float bound' => ['{"phpVersion": {"min": "8.1", "max": 8.6}}'];
        yield 'phpVersion, a null bound' => ['{"phpVersion": {"min": "8.1", "max": null}}'];
        yield 'phpVersion, runtime as a bound' => ['{"phpVersion": {"min": "runtime", "max": "8.6"}}'];
        yield 'phpVersion, a constraint as a bound' => ['{"phpVersion": {"min": ">=8.1", "max": "8.6"}}'];
        yield 'phpVersion, a PHP_VERSION_ID bound below PHP 7' => ['{"phpVersion": {"min": 69999, "max": 80600}}'];
        yield 'phpVersion, a constraint without a lowest version' => ['{"phpVersion": "<8.4"}'];
        yield 'phpVersion, any version' => ['{"phpVersion": "*"}'];
        yield 'phpVersion, a branch name' => ['{"phpVersion": "dev-main"}'];
        yield 'phpVersion, a PHP_VERSION_ID inside a constraint' => ['{"phpVersion": ">=80100"}'];
        yield 'phpVersion, runtime inside a constraint' => ['{"phpVersion": "runtime || ^9.0"}'];
        yield 'phpVersion, a float' => ['{"phpVersion": 8.3}'];
        yield 'phpVersion, a list' => ['{"phpVersion": ["8.1", "8.6"]}'];
        // A version past PHP 9.99 (ParserOptions::LATEST_PHP_VERSION_ID),
        // which "phpVersion": "10.0" alone is refused for.
        yield 'phpVersion, a constraint from PHP 10' => ['{"phpVersion": ">=10.0"}'];
        yield 'phpVersion, a constraint from PHP 99.1' => ['{"phpVersion": ">=99.1"}'];
        yield 'phpVersion, a constraint from a major past every integer' => ['{"phpVersion": ">=99999999999999999999.1"}'];
        yield 'phpVersion, a string bound past PHP 9.99' => ['{"phpVersion": {"min": "8.1", "max": "10.0"}}'];
        yield 'phpVersion, a PHP_VERSION_ID bound past PHP 9.99' => ['{"phpVersion": {"min": 80100, "max": 100000}}'];
        yield 'phpVersion, a doubled separator' => ['{"phpVersion": ">=8.1,,<8.3"}'];
        yield 'phpVersion, a bound with a leading zero' => ['{"phpVersion": {"min": "08.1", "max": "8.3"}}'];
    }

    /**
     * An editor reads the patterns of the published schema as ECMA-262,
     * where "\s" matches every Unicode space, U+00A0 and U+2003 among
     * them; the loader reads the value with PCRE, which refuses them: a
     * space is written as what both read alike, "[ \t]" or "\x20".
     */
    #[Test]
    public function test_the_published_php_version_patterns_hold_no_unicode_space_class(): void
    {
        $schema = self::decodeFile(self::ROOT.'/src/Linter/regex.schema.json');
        $this->assertIsArray($schema['properties'] ?? null);
        $this->assertIsArray($schema['$defs'] ?? null);
        $patterns = self::patternsIn([$schema['properties']['phpVersion'] ?? null, $schema['$defs']['phpVersionBound'] ?? null]);

        $this->assertNotSame([], $patterns, 'The phpVersion schema holds no pattern to check.');
        foreach ($patterns as $pattern) {
            $this->assertStringNotContainsString('\\s', $pattern);
        }
    }

    /**
     * A PHP_VERSION_ID bound of {"min", "max"} stops where "phpVersion"
     * does, at PHP 9.99.99.
     */
    #[Test]
    public function test_a_php_version_id_bound_stops_at_the_latest_php(): void
    {
        $schema = self::decodeFile(self::ROOT.'/src/Linter/regex.schema.json');
        $this->assertIsArray($schema['$defs'] ?? null);
        $bound = $schema['$defs']['phpVersionBound'] ?? null;
        $this->assertIsArray($bound);
        $this->assertIsArray($bound['anyOf'] ?? null);
        $integers = array_values(array_filter($bound['anyOf'], static fn (mixed $branch): bool => \is_array($branch) && 'integer' === ($branch['type'] ?? null)));

        $this->assertCount(1, $integers);
        $this->assertIsArray($integers[0]);
        $this->assertSame(ParserOptions::OLDEST_PHP_VERSION_ID, $integers[0]['minimum'] ?? null);
        $this->assertSame(ParserOptions::LATEST_PHP_VERSION_ID, $integers[0]['maximum'] ?? null);
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
        yield 'complexity on with a threshold' => ['{"checks": {"complexity": {"enabled": true, "threshold": 20}}}'];
        yield 'complexity off' => ['{"checks": {"complexity": {"enabled": false}}}'];
        yield 'complexity threshold of one' => ['{"checks": {"complexity": {"threshold": 1}}}'];
        yield 'empty lists' => ['{"paths": [], "exclude": [], "extraction": {"interop": [], "functions": []}}'];
        yield 'lint rules in full' => ['{"checks": {"lint": {"rules": {"regex.lint.quantifier.useless": false, "regex.lint.redos.search": false, "regex.lint.compat.meaningChanges": true}}}}'];
        // A single version stays as it was read: exact.
        yield 'phpVersion as a major.minor' => ['{"phpVersion": "8.1"}'];
        yield 'phpVersion with a patch' => ['{"phpVersion": "8.1.4"}'];
        yield 'phpVersion as a PHP_VERSION_ID of 8.1' => ['{"phpVersion": 80100}'];
        yield 'phpVersion as the running PHP' => ['{"phpVersion": "runtime"}'];
        // A Composer constraint, as require.php reads it.
        yield 'phpVersion as an open constraint' => ['{"phpVersion": ">=8.1"}'];
        yield 'phpVersion as a constraint with both bounds' => ['{"phpVersion": "^8.1 <8.7"}'];
        yield 'phpVersion as a hyphen range' => ['{"phpVersion": "8.1 - 8.6"}'];
        yield 'phpVersion as an OR of carets' => ['{"phpVersion": "^7.3 || ^8.0"}'];
        yield 'phpVersion as a constraint from the last gate point' => ['{"phpVersion": ">=8.5.10"}'];
        yield 'phpVersion as a constraint above every gate point' => ['{"phpVersion": ">8.6"}'];
        yield 'phpVersion as a hyphen range of one minor' => ['{"phpVersion": "8.1 - 8.1"}'];
        // The {min, max} object, its bounds as strings or PHP_VERSION_IDs.
        yield 'phpVersion as an object of strings' => ['{"phpVersion": {"min": "8.1", "max": "8.6"}}'];
        yield 'phpVersion as an object of PHP_VERSION_IDs' => ['{"phpVersion": {"min": 80100, "max": 80699}}'];
        yield 'phpVersion as an object of patch versions' => ['{"phpVersion": {"min": "8.1.4", "max": "8.4.24"}}'];
        yield 'phpVersion as an object of mixed bounds' => ['{"phpVersion": {"min": "8.1", "max": 80600}}'];
        yield 'phpVersion as an object of one version' => ['{"phpVersion": {"min": "8.1", "max": "8.1"}}'];
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
     * Every "pattern" the schema nodes hold, at any depth.
     *
     * @param list<mixed> $nodes
     *
     * @return list<string>
     */
    private static function patternsIn(array $nodes): array
    {
        $patterns = [];
        foreach ($nodes as $node) {
            if (!\is_array($node)) {
                continue;
            }
            if (\is_string($node['pattern'] ?? null)) {
                $patterns[] = $node['pattern'];
            }
            $patterns = [...$patterns, ...self::patternsIn(array_values(array_filter($node, \is_array(...))))];
        }

        return $patterns;
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
