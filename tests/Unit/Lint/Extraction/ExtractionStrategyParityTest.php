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
     * @var list<string>
     */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->files = [];
    }

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

        // The expected values are the same literals as the fixtures, so PHP
        // decodes them, not the test.
        yield 'nowdoc' => [
            'parity_nowdoc.php',
            [],
            [<<<'RE'
                /nowdoc
                  (\d+) \. \\x \' $x " {$y} \u{41}

                /x
                RE, '/nowdoc-in-array/', '/after-nowdoc/'],
        ];

        yield 'heredoc_plain' => [
            'parity_heredoc_plain.php',
            [],
            [<<<RE
                /heredoc
                  (\d+) \. \\x \" \$ \x41 \101 \u{42} \t {x} $) a$

                /x
                RE],
        ];

        yield 'heredoc_interpolated' => [
            'parity_heredoc_interpolated.php',
            [],
            [],
        ];

        yield 'named_pattern_first' => [
            'parity_named_pattern_first.php',
            [],
            ['/named-first/i'],
        ];

        yield 'named_pattern_second' => [
            'parity_named_pattern_second.php',
            [],
            ['/named-second/', '/named-wrapper/', '/named-trailing-comma/'],
        ];

        yield 'nette_replace_string_form' => [
            'parity_nette_replace_string_form.php',
            ['nette-utils'],
            ['/nette-string/'],
        ];

        yield 'spread_before_pattern' => [
            'parity_spread_before_pattern.php',
            ['nette-utils'],
            ['/before-spread/'],
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
     * @return iterable<string, array{literal: string}>
     */
    public static function provideHeredocLiterals(): iterable
    {
        yield 'indented closing marker' => ['literal' => "<<<RE\n    /a/\n      b\n    RE"];
        yield 'whitespace-only line shorter than the indentation' => ['literal' => "<<<RE\n    /a/\n  \n\n    b\n    RE"];
        yield 'tab indentation' => ['literal' => "<<<RE\n\t\t/a/\n\t\t\tb\n\t\tRE"];
        yield 'escape decoded after the indentation is removed' => ['literal' => "<<<RE\n    /a\\n  b\\\\/\n    RE"];
        yield 'quote escape kept in a heredoc' => ['literal' => "<<<RE\n    /a\\\"b/\n    RE"];
        yield 'quoted heredoc label' => ['literal' => "<<<\"RE\"\n    /a\\x41/\n    RE"];
        yield 'nowdoc keeps every backslash' => ['literal' => "<<<'RE'\n    /a\\n\\\\\\'\$b/\n    RE"];
        yield 'CRLF line endings' => ['literal' => "<<<RE\r\n    /a/\r\n    b\r\n    RE"];
        yield 'concatenated with a flag' => ['literal' => "<<<'RE'\n    /a/\n    RE . 'i'"];
    }

    #[DataProvider('provideHeredocLiterals')]
    public function test_both_strategies_decode_a_heredoc_as_php_does(string $literal): void
    {
        $value = $this->write("<?php\nreturn ".$literal.";\n");
        $expected = include $value;
        $this->assertIsString($expected);

        $file = $this->write("<?php\npreg_match(".$literal.", \$subject);\n");

        // PHP itself is the reference, so the tokenizer is checked even
        // without nikic/php-parser.
        $this->assertSame([$expected], $this->patterns(new TokenBasedExtractionStrategy(), $file));
        if (class_exists(ParserFactory::class)) {
            $this->assertSame([$expected], $this->patterns(new PhpParserExtractionStrategy(), $file));
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

    /**
     * @return iterable<string, array{literal: string}>
     */
    public static function provideHeredocsPhpRefuses(): iterable
    {
        yield 'line indented less than the closing marker' => ['literal' => "<<<RE\n    /a/\n  b\n    RE"];
        yield 'tabs and spaces mixed in the body' => ['literal' => "<<<RE\n    /a/\n  \t\n    RE"];
        yield 'tabs and spaces mixed in the closing marker' => ['literal' => "<<<RE\n \t/a/\n \tRE"];
    }

    #[DataProvider('provideHeredocsPhpRefuses')]
    public function test_both_strategies_skip_a_heredoc_php_refuses(string $literal): void
    {
        $file = $this->write("<?php\npreg_match(".$literal.", \$subject);\n");

        $this->assertSame([], $this->patterns(new TokenBasedExtractionStrategy(), $file));
        if (class_exists(ParserFactory::class)) {
            $this->assertSame([], $this->patterns(new PhpParserExtractionStrategy(), $file));
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

    private function write(string $content): string
    {
        $base = tempnam(sys_get_temp_dir(), 'regex-parity-');
        $this->assertIsString($base);
        unlink($base);
        $file = $base.'.php';
        file_put_contents($file, $content);
        $this->files[] = $file;

        return $file;
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
