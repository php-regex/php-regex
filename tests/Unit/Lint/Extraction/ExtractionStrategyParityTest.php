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

namespace PHPRegex\Tests\Unit\Lint\Extraction;

use PhpParser\ParserFactory;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\InteropPresets;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternOccurrence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Both strategies must see the same patterns.
 *
 * Which one runs depends on whether nikic/php-parser is installed, so a gap
 * between them means installing an optional dependency changes what the lint
 * reports.
 */
final class ExtractionStrategyParityTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<int, string>, array<int, string>}>
     */
    public static function provideFixtures(): iterable
    {
        yield 'composer/pcre wrappers' => [
            'interop_composer_pcre.php',
            [],
            ['/imported/', '/is-match/', '/aliased/', '/fully-qualified/', '/callback-a/', '/callback-b/'],
        ];

        yield 'a project class named like a wrapper' => [
            'interop_shadowed_class.php',
            [],
            [],
        ];

        yield 'nette/utils, pattern in second argument' => [
            'interop_nette_utils.php',
            ['nette-utils'],
            ['/second-argument/', '/replace-key/'],
        ];

        yield 'arrays of patterns' => [
            'array_of_patterns.php',
            [],
            ['/array-a/', '/array-b/', '/keys-a/', '/keys-b/'],
        ];

        yield 'namespaced drop-in functions' => [
            'namespaced_drop_in.php',
            [],
            ['/aliased-drop-in/', '/qualified-drop-in/'],
        ];

        yield 'native preg_* calls' => [
            'multiple_preg_functions.php',
            [],
            ['/test/', '/old/', '/\\s+/'],
        ];
    }

    /**
     * @param array<int, string> $presets
     * @param array<int, string> $expected
     */
    #[DataProvider('provideFixtures')]
    public function test_both_strategies_extract_the_same_patterns(string $fixture, array $presets, array $expected): void
    {
        if (!class_exists(ParserFactory::class)) {
            $this->markTestSkipped('nikic/php-parser is required to compare both strategies.');
        }

        $file = __DIR__.'/../../../Fixtures/Extractor/'.$fixture;
        $registry = PatternFunctionRegistry::create(['composer-pcre', ...$presets]);

        $fromTokens = $this->patterns(new TokenBasedExtractionStrategy([], $registry), $file);
        $fromAst = $this->patterns(new PhpParserExtractionStrategy([], $registry), $file);

        $this->assertSame($expected, $fromAst);
        $this->assertSame($fromAst, $fromTokens);
    }

    /**
     * @return iterable<string, array{fixture: string, expected: array<int, string>}>
     */
    public static function provideAliasedImports(): iterable
    {
        yield 'composer/pcre' => ['fixture' => 'interop_aliased_composer_pcre.php', 'expected' => ['/aliased-composer/']];
        yield 'nette/utils' => ['fixture' => 'interop_aliased_nette_utils.php', 'expected' => ['/aliased-nette/']];
        yield 'spatie/regex' => ['fixture' => 'interop_aliased_spatie_regex.php', 'expected' => ['/aliased-spatie/']];
        yield 'illuminate/support' => ['fixture' => 'interop_aliased_laravel_str.php', 'expected' => ['/aliased-laravel/']];
        yield 'group use split inside the namespace' => ['fixture' => 'interop_aliased_split_group_use.php', 'expected' => ['/split-composer/', '/split-laravel/']];
    }

    /**
     * @param array<int, string> $expected
     */
    #[DataProvider('provideAliasedImports')]
    public function test_an_aliased_wrapper_import_opens_the_file(string $fixture, array $expected): void
    {
        $file = __DIR__.'/../../../Fixtures/Extractor/'.$fixture;
        $registry = PatternFunctionRegistry::create(InteropPresets::names());

        $this->assertSame($expected, $this->patterns(new TokenBasedExtractionStrategy([], $registry), $file));

        // Without nikic/php-parser the parser strategy has nothing to compare.
        if (class_exists(ParserFactory::class)) {
            $this->assertSame($expected, $this->patterns(new PhpParserExtractionStrategy([], $registry), $file));
        }
    }

    public function test_fqcn_resolution_rejects_another_class_of_a_wrapper_namespace(): void
    {
        $file = __DIR__.'/../../../Fixtures/Extractor/interop_aliased_unrelated_class.php';
        $registry = PatternFunctionRegistry::create(InteropPresets::names());

        $this->assertSame([], $this->patterns(new TokenBasedExtractionStrategy([], $registry), $file));

        if (class_exists(ParserFactory::class)) {
            $this->assertSame([], $this->patterns(new PhpParserExtractionStrategy([], $registry), $file));
        }
    }

    /**
     * @return iterable<string, array{fixture: string, spec: string, expected: array<int, string>}>
     */
    public static function provideAliasedDeclaredClasses(): iterable
    {
        yield 'namespaced class' => ['fixture' => 'custom_aliased_namespaced_class.php', 'spec' => 'App\\Support\\Re::m', 'expected' => ['/aliased-custom/']];
        yield 'global class' => ['fixture' => 'custom_aliased_global_class.php', 'spec' => 'Text::m', 'expected' => ['/aliased-global/']];
    }

    /**
     * @param array<int, string> $expected
     */
    #[DataProvider('provideAliasedDeclaredClasses')]
    public function test_an_aliased_import_of_a_declared_class_opens_the_file(string $fixture, string $spec, array $expected): void
    {
        $file = __DIR__.'/../../../Fixtures/Extractor/'.$fixture;
        $registry = PatternFunctionRegistry::native()->withCustomFunctions([$spec]);

        $this->assertSame($expected, $this->patterns(new TokenBasedExtractionStrategy([], $registry), $file));

        if (class_exists(ParserFactory::class)) {
            $this->assertSame($expected, $this->patterns(new PhpParserExtractionStrategy([], $registry), $file));
        }
    }

    public function test_both_strategies_label_occurrences_the_same_way(): void
    {
        if (!class_exists(ParserFactory::class)) {
            $this->markTestSkipped('nikic/php-parser is required to compare both strategies.');
        }

        $file = __DIR__.'/../../../Fixtures/Extractor/interop_composer_pcre.php';

        $fromTokens = $this->sources(new TokenBasedExtractionStrategy(), $file);
        $fromAst = $this->sources(new PhpParserExtractionStrategy(), $file);

        $this->assertSame([
            'Preg::match()',
            'Preg::isMatch()',
            'Regex::matchAll()',
            'Preg::split()',
            'Preg::replaceCallbackArray()',
            'Preg::replaceCallbackArray()',
        ], $fromAst);
        $this->assertSame($fromAst, $fromTokens);
    }

    /**
     * @return list<string>
     */
    private function patterns(ExtractorInterface $strategy, string $file): array
    {
        return array_values(array_map(
            static fn (PatternOccurrence $occurrence): string => $occurrence->pattern,
            $strategy->extract([$file]),
        ));
    }

    /**
     * @return list<string>
     */
    private function sources(ExtractorInterface $strategy, string $file): array
    {
        return array_values(array_map(
            static fn (PatternOccurrence $occurrence): string => $occurrence->source,
            $strategy->extract([$file]),
        ));
    }
}
