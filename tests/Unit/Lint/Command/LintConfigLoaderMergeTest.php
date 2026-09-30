<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit\Lint\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Lint\Command\LintArgumentParser;
use RegexParser\Lint\Command\LintArguments;
use RegexParser\Lint\Command\LintConfigLoader;
use RegexParser\Lint\Command\LintDefaultsBuilder;
use RegexParser\Tests\Support\TemporaryProject;

/**
 * regex.json over regex.dist.json: a list is replaced whole, an object is
 * merged key by key, and a sub-key never switches a check on by itself.
 * Read through the arguments the lint command ends up with, whatever shape
 * the loader keeps internally.
 */
final class LintConfigLoaderMergeTest extends TestCase
{
    use TemporaryProject;

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideListOverrides')]
    public function test_load_replaces_a_list_wholesale(string $dist, string $local, string $property, array $expected): void
    {
        $arguments = $this->argumentsFor(['regex.dist.json' => $dist, 'regex.json' => $local]);

        $this->assertSame($expected, $arguments->{$property});
    }

    /**
     * @return iterable<string, array{string, string, string, list<string>}>
     */
    public static function provideListOverrides(): iterable
    {
        yield 'exclude, shorter list' => ['{"exclude": ["vendor", "tests", "Fixtures"]}', '{"exclude": ["build"]}', 'exclude', ['build']];
        yield 'exclude, empty list clears' => ['{"exclude": ["vendor", "tests"]}', '{"exclude": []}', 'exclude', []];
        yield 'paths, shorter list' => ['{"paths": ["src", "lib"]}', '{"paths": ["app"]}', 'paths', ['app']];
        yield 'paths, empty list clears' => ['{"paths": ["src", "lib"]}', '{"paths": []}', 'paths', []];
        yield 'extraction.interop, shorter list' => ['{"extraction": {"interop": ["composer-pcre", "nette-utils"]}}', '{"extraction": {"interop": ["laravel-str"]}}', 'interop', ['laravel-str']];
        yield 'extraction.interop, empty list clears' => ['{"extraction": {"interop": ["composer-pcre", "nette-utils"]}}', '{"extraction": {"interop": []}}', 'interop', []];
        yield 'extraction.functions, shorter list' => ['{"extraction": {"functions": ["a_check", "b_check"]}}', '{"extraction": {"functions": ["c_check"]}}', 'patternFunctions', ['c_check']];
    }

    #[Test]
    public function test_load_keeps_a_dist_list_the_local_file_does_not_mention(): void
    {
        $arguments = $this->argumentsFor([
            'regex.dist.json' => '{"exclude": ["vendor", "tests"]}',
            'regex.json' => '{"paths": ["app"]}',
        ]);

        $this->assertSame(['vendor', 'tests'], $arguments->exclude);
        $this->assertSame(['app'], $arguments->paths);
    }

    #[Test]
    public function test_load_merges_objects_key_by_key(): void
    {
        $arguments = $this->argumentsFor([
            'regex.dist.json' => '{"checks": {"redos": {"enabled": true, "mode": "confirmed"}, "lint": {"rules": {"unicode.shorthandWithoutU": true}}}}',
            'regex.json' => '{"checks": {"redos": {"threshold": "low"}, "lint": {"rules": {"flag.redundant": false}}}}',
        ]);

        $this->assertTrue($arguments->checkRedos);
        $this->assertSame('confirmed', $arguments->redosMode);
        $this->assertSame('low', $arguments->redosThreshold);
        $this->assertSame(['unicode.shorthandWithoutU' => true, 'flag.redundant' => false], $arguments->lintRules);
    }

    #[Test]
    #[DataProvider('provideSubKeysAlone')]
    public function test_load_never_enables_redos_from_a_sub_key(string $json): void
    {
        $this->assertFalse($this->argumentsFor(['regex.json' => $json])->checkRedos);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideSubKeysAlone(): iterable
    {
        yield 'threshold only' => ['{"checks": {"redos": {"threshold": "high"}}}'];
        yield 'mode only' => ['{"checks": {"redos": {"mode": "confirmed"}}}'];
        yield 'threshold and mode' => ['{"checks": {"redos": {"mode": "theoretical", "threshold": "low"}}}'];
    }

    #[Test]
    public function test_load_keeps_redos_disabled_by_dist_when_local_sets_a_sub_key(): void
    {
        $arguments = $this->argumentsFor([
            'regex.dist.json' => '{"checks": {"redos": {"enabled": false}}}',
            'regex.json' => '{"checks": {"redos": {"threshold": "low"}}}',
        ]);

        $this->assertFalse($arguments->checkRedos);
        $this->assertSame('low', $arguments->redosThreshold);
    }

    #[Test]
    public function test_load_keeps_optimizations_disabled_by_dist_when_local_sets_options(): void
    {
        $arguments = $this->argumentsFor([
            'regex.dist.json' => '{"checks": {"optimizations": {"enabled": false}}}',
            'regex.json' => '{"checks": {"optimizations": {"options": {"digits": true}}}}',
        ]);

        $this->assertFalse($arguments->checkOptimizations);
        $this->assertTrue($arguments->optimizations['digits'] ?? null);
    }

    #[Test]
    public function test_load_keeps_optimizations_disabled_by_dist_when_local_sets_min_savings(): void
    {
        $arguments = $this->argumentsFor([
            'regex.dist.json' => '{"checks": {"optimizations": {"enabled": false}}}',
            'regex.json' => '{"checks": {"optimizations": {"minSavings": 5}}}',
        ]);

        $this->assertFalse($arguments->checkOptimizations);
        $this->assertSame(5, $arguments->minSavings);
    }

    #[Test]
    public function test_load_keeps_lint_disabled_by_dist_when_local_sets_rules(): void
    {
        $arguments = $this->argumentsFor([
            'regex.dist.json' => '{"checks": {"lint": {"enabled": false}}}',
            'regex.json' => '{"checks": {"lint": {"rules": {"flag.redundant": false}}}}',
        ]);

        $this->assertFalse($arguments->checkLint);
    }

    /**
     * @param array<string, string> $files
     */
    private function argumentsFor(array $files): LintArguments
    {
        $this->enterProject($files);
        $result = (new LintConfigLoader())->load();
        $this->assertNull($result->error);

        $parsed = (new LintArgumentParser())->parse([], (new LintDefaultsBuilder())->build($result->config));
        $this->assertNull($parsed->error);
        $this->assertInstanceOf(LintArguments::class, $parsed->arguments);

        return $parsed->arguments;
    }
}
