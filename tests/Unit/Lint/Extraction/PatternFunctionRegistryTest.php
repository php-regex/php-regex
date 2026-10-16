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

use PHPRegex\Linter\Extraction\InteropPresets;
use PHPRegex\Linter\Extraction\PatternFunction;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Regex;

final class PatternFunctionRegistryTest extends TestCase
{
    public function test_native_functions_are_matched_case_insensitively(): void
    {
        $registry = PatternFunctionRegistry::native();

        $this->assertSame('preg_match', $registry->lookupFunction('PREG_MATCH')?->label);
        $this->assertSame(0, $registry->lookupFunction('preg_split')?->argumentIndex);
        $this->assertNull($registry->lookupFunction('str_replace'));
    }

    public function test_preg_replace_callback_array_reads_its_array_keys(): void
    {
        $registry = PatternFunctionRegistry::native();

        $this->assertTrue($registry->lookupFunction('preg_replace_callback_array')?->keysArePatterns);
        $this->assertFalse($registry->lookupFunction('preg_replace')?->keysArePatterns);
    }

    public function test_a_namespaced_copy_of_a_native_function_is_matched(): void
    {
        $registry = PatternFunctionRegistry::native();

        $this->assertSame('preg_match', $registry->lookupFunction('Safe\\preg_match')?->label);
        $this->assertSame('preg_match', $registry->lookupFunction('\\Safe\\preg_match')?->label);
    }

    public function test_a_namespaced_copy_of_a_declared_helper_is_not_matched(): void
    {
        $registry = PatternFunctionRegistry::native()->withCustomFunctions(['myHelper']);

        $this->assertInstanceOf(PatternFunction::class, $registry->lookupFunction('myHelper'));
        $this->assertNull($registry->lookupFunction('Vendor\\myHelper'));
    }

    #[Test]
    public function test_every_preset_is_enabled_by_default(): void
    {
        $registry = PatternFunctionRegistry::defaults();

        $this->assertSame('Preg::match', $registry->lookupMethod('Composer\\Pcre\\Preg', 'match')?->label);
        $this->assertSame('Regex::replace', $registry->lookupMethod('Composer\\Pcre\\Regex', 'replace')?->label);
        $this->assertTrue($registry->lookupMethod('Composer\\Pcre\\Preg', 'replaceCallbackArray')?->keysArePatterns);
        $this->assertSame(1, $registry->lookupMethod('Nette\\Utils\\Strings', 'match')?->argumentIndex);
    }

    public function test_nette_puts_the_pattern_in_the_second_argument(): void
    {
        $registry = PatternFunctionRegistry::create([InteropPresets::NETTE_UTILS]);

        $this->assertSame(1, $registry->lookupMethod('Nette\\Utils\\Strings', 'match')?->argumentIndex);
        $this->assertTrue($registry->lookupMethod('Nette\\Utils\\Strings', 'replace')?->keysArePatterns);
    }

    public function test_unknown_presets_are_ignored(): void
    {
        $registry = PatternFunctionRegistry::create(['not-a-preset']);

        $this->assertInstanceOf(PatternFunction::class, $registry->lookupFunction('preg_match'));
        $this->assertNull($registry->lookupMethod('Composer\\Pcre\\Preg', 'match'));
    }

    public function test_custom_specs_declare_the_pattern_position(): void
    {
        $registry = PatternFunctionRegistry::native()->withCustomFunctions([
            'App\\Support\\Str::matches#1',
            'App\\Support\\Str::rewrite#0:keys',
            '\\regex_check',
        ]);

        $matches = $registry->lookupMethod('App\\Support\\Str', 'matches');
        $this->assertInstanceOf(PatternFunction::class, $matches);
        $this->assertSame('Str::matches', $matches->label);
        $this->assertSame(1, $matches->argumentIndex);
        $this->assertFalse($matches->keysArePatterns);

        $rewrite = $registry->lookupMethod('App\\Support\\Str', 'rewrite');
        $this->assertInstanceOf(PatternFunction::class, $rewrite);
        $this->assertTrue($rewrite->keysArePatterns);

        $this->assertSame('regex_check', $registry->lookupFunction('regex_check')?->label);
    }

    public function test_malformed_specs_are_ignored(): void
    {
        $registry = PatternFunctionRegistry::native()->withCustomFunctions(['', '   ', 'Foo::bar#x', 'Foo::', '::bar']);

        $this->assertNull($registry->lookupMethod('Foo', 'bar'));
        $this->assertInstanceOf(PatternFunction::class, $registry->lookupFunction('preg_match'));
    }

    /**
     * "#1$name" names the parameter for a named argument: a name PHP could
     * not declare makes the whole spec malformed.
     */
    #[Test]
    public function test_a_spec_naming_no_valid_parameter_is_ignored(): void
    {
        $registry = PatternFunctionRegistry::native()->withDeclaredFunctions(['App\bad#1$9regex', 'App\empty#1$', 'App\dashed#0$the-regex', 'App\good#1$regex']);

        $this->assertNull($registry->lookupFunction('App\bad'));
        $this->assertNull($registry->lookupFunction('App\empty'));
        $this->assertNull($registry->lookupFunction('App\dashed'));
        $this->assertSame(['regex'], $registry->lookupFunction('App\good')?->parameterNames);
    }

    /**
     * Two specs of one function, at two positions, make one function read
     * at both, each under its own parameter name.
     */
    #[Test]
    public function test_two_specs_of_one_function_read_both_positions(): void
    {
        $function = PatternFunctionRegistry::native()->withDeclaredFunctions(['App\between#2$to', 'App\between#1$from'])->lookupFunction('App\between');
        $this->assertInstanceOf(PatternFunction::class, $function);

        $this->assertSame(
            [[1, ['from']], [2, ['to']]],
            array_map(static fn (PatternFunction $each): array => [$each->argumentIndex, $each->parameterNames], $function->eachArgument()),
        );
    }

    public function test_content_matching_skips_files_without_a_candidate_call(): void
    {
        $registry = PatternFunctionRegistry::defaults();

        $this->assertTrue($registry->matchesContent('<?php preg_match("/a/", $s);'));
        $this->assertTrue($registry->matchesContent('<?php Preg::match("/a/", $s);'));
        $this->assertTrue($registry->matchesContent('<?php REGEX::MATCH("/a/", $s);'));
        $this->assertFalse($registry->matchesContent('<?php echo strtoupper($s);'));
    }

    public function test_declared_helpers_widen_the_content_check(): void
    {
        $registry = PatternFunctionRegistry::native()->withCustomFunctions(['App\\Support\\Str::matches']);

        $this->assertTrue($registry->matchesContent('<?php Str::matches("/a/");'));
        $this->assertFalse($registry->matchesContent('<?php echo strtoupper($s);'));
    }

    /**
     * @return iterable<string, array{preset: string, content: string}>
     */
    public static function provideAliasedPresetImports(): iterable
    {
        yield 'composer/pcre' => ['preset' => InteropPresets::COMPOSER_PCRE, 'content' => '<?php use Composer\Pcre\Preg as P; P::match("/a/", $s);'];
        yield 'composer/pcre, group use' => ['preset' => InteropPresets::COMPOSER_PCRE, 'content' => '<?php use Composer\Pcre\{Regex as R}; R::match("/a/", $s);'];
        yield 'composer/pcre, group use split inside the namespace' => ['preset' => InteropPresets::COMPOSER_PCRE, 'content' => '<?php use Composer\{Pcre\Preg as P}; P::match("/a/", $s);'];
        yield 'composer/pcre, space before the group' => ['preset' => InteropPresets::COMPOSER_PCRE, 'content' => '<?php use Composer\Pcre \{Preg as X}; X::match("/a/", $s);'];
        yield 'nette/utils' => ['preset' => InteropPresets::NETTE_UTILS, 'content' => '<?php use Nette\Utils\Strings as S; S::match($s, "/a/");'];
        yield 'spatie/regex' => ['preset' => InteropPresets::SPATIE_REGEX, 'content' => '<?php use Spatie\Regex\Regex as R; R::match("/a/", $s);'];
        yield 'illuminate/support' => ['preset' => 'illuminate-support', 'content' => '<?php use ILLUMINATE\Support\Str as S; S::match("/a/", $s);'];
        yield 'illuminate/support, group use split inside the namespace' => ['preset' => 'illuminate-support', 'content' => '<?php use Illuminate\{Support\Str as S}; S::match("/a/", $s);'];
    }

    #[DataProvider('provideAliasedPresetImports')]
    public function test_an_aliased_import_of_a_preset_class_opens_the_file(string $preset, string $content): void
    {
        $registry = PatternFunctionRegistry::native()->withPresets([$preset]);

        $this->assertTrue($registry->matchesContent($content));
        $this->assertFalse($registry->matchesContent('<?php use App\Support\Helpers as H; H::run($s);'));
    }

    /**
     * @return iterable<string, array{spec: string, content: string}>
     */
    public static function provideAliasedDeclaredClassImports(): iterable
    {
        yield 'namespaced class' => ['spec' => '\App\Support\Re::m#1', 'content' => '<?php use App\Support\Re as R; R::m($s, "/a/");'];
        yield 'namespaced class, group use split inside the namespace' => ['spec' => 'App\Support\Re::m', 'content' => '<?php use App\{Support\Re as X}; X::m("/a/");'];
        yield 'global class' => ['spec' => 'Text::m', 'content' => '<?php use Text as X; X::m("/a/");'];
    }

    #[DataProvider('provideAliasedDeclaredClassImports')]
    public function test_an_aliased_import_of_a_declared_class_opens_the_file(string $spec, string $content): void
    {
        $registry = PatternFunctionRegistry::native()->withCustomFunctions([$spec]);

        $this->assertTrue($registry->matchesContent($content));
        $this->assertFalse($registry->matchesContent('<?php use App\Support\Helpers as H; H::run($s);'));
    }

    public function test_presets_contribute_the_alias_of_their_classes(): void
    {
        $this->assertSame(['preg::', 'regex::', 'preg as', 'regex as'], InteropPresets::needles(InteropPresets::COMPOSER_PCRE));
        $this->assertSame(['strings::', 'strings as'], InteropPresets::needles(InteropPresets::NETTE_UTILS));
        $this->assertSame(['regex::', 'regex as'], InteropPresets::needles(InteropPresets::SPATIE_REGEX));
        $this->assertSame(['str::', 'str as'], InteropPresets::needles('illuminate-support'));
        $this->assertSame([], InteropPresets::needles('unknown'));
    }

    /**
     * "Re as" opens the file; "Type as", which only ends like the class's
     * name, or "re" with no "as" after it, does not.
     */
    #[Test]
    public function test_only_the_class_name_before_as_opens_the_file(): void
    {
        $registry = PatternFunctionRegistry::native()->withCustomFunctions(['App\Support\Re::m']);

        $this->assertTrue($registry->matchesContent('<?php use App\Support\Re as R; R::m("/a/");'));
        $this->assertFalse($registry->matchesContent('<?php use App\Some\Type as T; // more text'));
    }

    /**
     * A preset matched on its fully qualified class, in a file that spells
     * every word of the class: only the class's own name before "as" opens
     * it, not another import's alias.
     */
    #[Test]
    public function test_only_the_preset_class_name_before_as_opens_a_file_that_spells_the_class(): void
    {
        $registry = PatternFunctionRegistry::native()->withPresets([InteropPresets::NETTE_UTILS]);

        $this->assertTrue($registry->matchesContent('<?php use Nette\Utils\Strings as S; S::match($s, "/a/");'));
        $this->assertFalse($registry->matchesContent('<?php use Nette\Utils\Strings; use App\Other as O; // Strings are formatted by O'));
        $this->assertFalse($registry->matchesContent('<?php use Nette\Utils\Strings; use App\Xtrings as X; // Strings are formatted by X'));
    }

    #[Test]
    public function test_a_constructor_or_an_attribute_is_looked_up_by_its_fully_qualified_class(): void
    {
        $registry = PatternFunctionRegistry::defaults();

        $this->assertSame('RegexIterator', $registry->lookupConstructor('\\RegexIterator')?->label);
        $this->assertSame($registry->lookupConstructor('RegexIterator'), $registry->lookupConstructor('\\regexiterator'));
        $this->assertInstanceOf(PatternFunction::class, $registry->lookupAttribute(Regex::class));
        $this->assertSame($registry->lookupAttribute(Regex::class), $registry->lookupAttribute('\\'.Regex::class));
    }

    public function test_the_namespace_of_a_class_does_not_open_the_file(): void
    {
        $laravel = PatternFunctionRegistry::native()->withPresets(['illuminate-support']);
        $this->assertFalse($laravel->matchesContent('<?php use Illuminate\Support\Facades\Route; Route::get("/", fn () => 1);'));

        $declared = PatternFunctionRegistry::native()->withCustomFunctions(['App\RegexHelper::m']);
        $this->assertFalse($declared->matchesContent('<?php namespace App\Http; final class X {}'));
    }

    public function test_the_custom_global_functions_leave_out_natives_namespaced_functions_and_methods(): void
    {
        $this->assertSame([], PatternFunctionRegistry::native()->customGlobalFunctions());

        $registry = PatternFunctionRegistry::native()
            ->withCustomFunctions(['Grep#0', 'App\match#1', 'App\Str::is#0', 'preg_match#1'])
            ->withDeclaredFunctions(['find#0', 'App\locate#0']);

        $this->assertSame(['grep', 'find'], $registry->customGlobalFunctions());
    }
}
