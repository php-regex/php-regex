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
use PHPUnit\Framework\Attributes\RequiresMethod;
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
     * @return iterable<string, array{string, array<int, string>, array<int, string>}|array{fixture: string, presets: array<int, string>, expected: array<int, string>}>
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
            'fixture' => 'parity_nowdoc.php',
            'presets' => [],
            'expected' => [<<<'RE'
                /nowdoc
                  (\d+) \. \\x \' $x " {$y} \u{41}

                /x
                RE, '/nowdoc-in-array/', '/after-nowdoc/'],
        ];

        yield 'heredoc_plain' => [
            'fixture' => 'parity_heredoc_plain.php',
            'presets' => [],
            'expected' => [<<<RE
                /heredoc
                  (\d+) \. \\x \" \$ \x41 \101 \u{42} \t {x} $) a$

                /x
                RE],
        ];

        yield 'heredoc_interpolated' => [
            'fixture' => 'parity_heredoc_interpolated.php',
            'presets' => [],
            'expected' => [],
        ];

        yield 'named_pattern_first' => [
            'fixture' => 'parity_named_pattern_first.php',
            'presets' => [],
            'expected' => ['/named-first/i'],
        ];

        yield 'named_pattern_second' => [
            'fixture' => 'parity_named_pattern_second.php',
            'presets' => [],
            'expected' => ['/named-second/', '/named-wrapper/', '/named-trailing-comma/'],
        ];

        yield 'nette_replace_string_form' => [
            'fixture' => 'parity_nette_replace_string_form.php',
            'presets' => ['nette-utils'],
            'expected' => ['/nette-string/'],
        ];

        yield 'spread_before_pattern' => [
            'fixture' => 'parity_spread_before_pattern.php',
            'presets' => ['nette-utils'],
            'expected' => ['/before-spread/'],
        ];

        yield 'binary_prefix' => [
            'fixture' => 'parity_binary_prefix.php',
            'presets' => [],
            'expected' => ['/binary-single/', '/binary-upper/', "/binary-\d\x41/", <<<'RE'
                /binary-nowdoc\d/
                RE, '/binary-in-array/'],
        ];

        // Nette's Strings::replace() reads the keys of an array whose first
        // key is a string when the replacement is no callable, and the
        // values otherwise (Strings.php, replace()). A replacement that is
        // not a literal is taken for a string.
        yield 'nette_replace_keys' => [
            'fixture' => 'parity_nette_replace_keys.php',
            'presets' => ['nette-utils'],
            'expected' => ['/keys-no-replacement/', '/keys-string-a/', '/keys-string-b/', '01', '/keys-unknown-replacement/', '/keys-property-of-new/', '/keys-coalesce-callable/', '/keys-ternary-callable/', '/keys-closure-called/', '/keys-property-of-this/', '/keys-unimported-closure/', '/keys-after-null/'],
        ];

        yield 'nette_replace_list' => [
            'fixture' => 'parity_nette_replace_list.php',
            'presets' => ['nette-utils'],
            'expected' => ['/list-a/', '/list-b/', '/list-int-key/', '/list-numeric-string-key/', '/list-array-syntax/', '/list-negative-key/', '/list-parenthesized-key/', '/list-parenthesized-negative-key/', '/list-bool-key/', '/list-false-key/', '/list-float-key/', '/list-plus-key/', '/list-negative-float-key/'],
        ];

        yield 'nette_replace_callback' => [
            'fixture' => 'parity_nette_replace_callback.php',
            'presets' => ['nette-utils'],
            'expected' => ['/callback-closure/', '/callback-arrow/', '/callback-static/', '/callback-first-class/', '/callback-array/', '/callback-invokable/', '/callback-parenthesized/', '/callback-array-syntax/', '/callback-this/', '/callback-object-cast/', '/callback-array-cast/', '/callback-clone/', '/callback-from-callable/', '/callback-imported-from-callable/', '/callback-static-first-class/', '/callback-attribute/', '/callback-named/'],
        ];

        yield 'escapes_and_newlines' => [
            'fixture' => 'parity_escapes_and_newlines.php',
            'presets' => [],
            'expected' => ["/upper-\X41\X4/", <<<RE
                /heredoc-upper-\X41/
                RE, "/trailing-newline/\n", <<<'RE'
                /heredoc-blank-line/

                RE, "/flags-then-newline/i\n"],
        ];

        // Each opener a closure, an attribute or an interpolation brings is
        // closed before the next key is read.
        yield 'array_openers' => [
            'fixture' => 'parity_array_openers.php',
            'presets' => [],
            'expected' => ['/attr-a/', '/attr-b/', '/real/'],
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
            // The parser refuses the file too, falls back to the tokenizer,
            // and marks the file: one fallback, no pattern.
            $occurrences = (new PhpParserExtractionStrategy())->extract([$file]);
            $this->assertCount(1, $occurrences);
            $this->assertNotNull($occurrences[0]->parserFallback);
            $this->assertSame('', $occurrences[0]->pattern);
        }
    }

    /**
     * A partial application of a pattern function is no call: neither
     * strategy reads its pattern, a placeholder for any argument, passed by
     * position or by name; the file's other calls are read.
     */
    public function test_both_strategies_skip_a_partial_application(): void
    {
        $file = $this->write("<?php\npreg_match('/ok1/', \$s);\n"
            ."\$f = preg_replace(\"/(a/\", ?, ?);\n"
            ."\$g = preg_match(\"/(b/\", ?);\n"
            ."\$h = preg_match(subject: ?, pattern: \"/(c/\");\n"
            ."preg_replace('/ok2/', '', \$s);\n");

        $this->assertSame(['/ok1/', '/ok2/'], $this->patterns(new TokenBasedExtractionStrategy(), $file));
        if (class_exists(ParserFactory::class)) {
            $occurrences = array_filter((new PhpParserExtractionStrategy())->extract([$file]), static fn (PatternOccurrence $occurrence): bool => null === $occurrence->parserFallback);
            $this->assertSame(['/ok1/', '/ok2/'], array_values(array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $occurrences)));
        }
    }

    /**
     * @return iterable<string, array{fixture: string, presets: array<int, string>}>
     */
    public static function providePositionFixtures(): iterable
    {
        yield 'braces, interpolation, parentheses and every literal form' => [
            'fixture' => 'parity_positions.php',
            'presets' => ['nette-utils'],
        ];

        foreach (self::provideFixtures() as $name => $row) {
            yield $name => ['fixture' => $row['fixture'] ?? $row[0], 'presets' => $row['presets'] ?? $row[1]];
        }
    }

    /**
     * @param array<int, string> $presets
     */
    #[DataProvider('providePositionFixtures')]
    #[RequiresMethod(ParserFactory::class, 'createForHostVersion')]
    public function test_both_strategies_place_occurrences_the_same_way(string $fixture, array $presets): void
    {
        $file = __DIR__.'/../../../Fixtures/Extractor/'.$fixture;
        $registry = PatternFunctionRegistry::create(['composer-pcre', ...$presets]);

        $fromAst = $this->positions(new PhpParserExtractionStrategy([], $registry), $file);

        $this->assertSame($fromAst, $this->positions(new TokenBasedExtractionStrategy([], $registry), $file));
    }

    /**
     * PHP 8.4 reads a member of a new object without parentheses: the
     * replacement is then that member, no callable.
     */
    public function test_a_member_of_a_new_object_is_no_callable_replacement(): void
    {
        $file = $this->write(<<<'PHP'
            <?php
            use Nette\Utils\Strings;
            Strings::replace($s, ['/property/' => 'a'], new Suffix()->value);
            Strings::replace($s, ['/offset/' => 'a'], new Suffix()['value']);
            Strings::replace($s, ['/constant/' => 'a'], new Suffix()::VALUE);
            Strings::replace($s, ['/anonymous-class-property/' => 'a'], new class { public $p = 'x'; }->p);
            Strings::replace($s, ['/class-from-array/' => 'a'], new $classes['a']);
            PHP);
        $registry = PatternFunctionRegistry::create(['nette-utils']);

        $expected = ['/property/', '/offset/', '/constant/', '/anonymous-class-property/', 'a'];
        $this->assertSame($expected, $this->patterns(new TokenBasedExtractionStrategy([], $registry), $file));
        if (\PHP_VERSION_ID >= 80400 && class_exists(ParserFactory::class)) {
            $this->assertSame($expected, $this->patterns(new PhpParserExtractionStrategy([], $registry), $file));
        }
    }

    /**
     * A wrapper call nested in the subject of another is read once per call,
     * not once per enclosing call.
     */
    public function test_deeply_nested_calls_are_read_in_linear_time(): void
    {
        $depth = 3000;
        $file = $this->write("<?php\nuse Nette\\Utils\\Strings;\n"
            .str_repeat('Strings::match(subject: ', $depth).'$s'.str_repeat(", pattern: '/n/')", $depth).";\n");
        $strategy = new TokenBasedExtractionStrategy([], PatternFunctionRegistry::create(['nette-utils']));

        $start = hrtime(true);
        $patterns = $this->patterns($strategy, $file);
        $seconds = (hrtime(true) - $start) / 1e9;

        $this->assertCount($depth, $patterns);
        // A quadratic reading takes 70 s and more at this depth, a linear
        // one under a second: 20 s tells them apart under coverage too.
        $this->assertLessThan(20.0, $seconds, \sprintf('%d nested calls took %.1f s.', $depth, $seconds));
    }

    /**
     * The replacement of each call holds the next one: telling whether it is
     * a callable must not read it again for each call around it.
     */
    public function test_nested_replace_calls_with_callable_replacements_are_read_in_linear_time(): void
    {
        $depth = 3000;
        $file = $this->write("<?php\nuse Nette\\Utils\\Strings;\n"
            .str_repeat("Strings::replace(\$s, ['/a/' => 'x'], function (\$m) { return ", $depth).'$m'.str_repeat('; });', $depth)."\n");
        $strategy = new TokenBasedExtractionStrategy([], PatternFunctionRegistry::create(['nette-utils']));

        $start = hrtime(true);
        $patterns = $this->patterns($strategy, $file);
        $seconds = (hrtime(true) - $start) / 1e9;

        $this->assertCount($depth, $patterns);
        // A quadratic reading takes 70 s and more at this depth, a linear
        // one under a second: 20 s tells them apart under coverage too.
        $this->assertLessThan(20.0, $seconds, \sprintf('%d nested calls took %.1f s.', $depth, $seconds));
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
     * @return list<array{string, int, int|null, int|null}>
     */
    private function positions(ExtractorInterface $strategy, string $file): array
    {
        return array_values(array_map(
            static fn (PatternOccurrence $occurrence): array => [$occurrence->pattern, $occurrence->line, $occurrence->column, $occurrence->fileOffset],
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
