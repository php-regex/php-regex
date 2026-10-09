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
use PHPUnit\Framework\TestCase;

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

    public function test_composer_pcre_is_enabled_by_default(): void
    {
        $registry = PatternFunctionRegistry::defaults();

        $this->assertSame('Preg::match', $registry->lookupMethod('Composer\\Pcre\\Preg', 'match')?->label);
        $this->assertSame('Regex::replace', $registry->lookupMethod('Composer\\Pcre\\Regex', 'replace')?->label);
        $this->assertTrue($registry->lookupMethod('Composer\\Pcre\\Preg', 'replaceCallbackArray')?->keysArePatterns);
        $this->assertNull($registry->lookupMethod('Nette\\Utils\\Strings', 'match'));
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
        yield 'nette/utils' => ['preset' => InteropPresets::NETTE_UTILS, 'content' => '<?php use Nette\Utils\Strings as S; S::match($s, "/a/");'];
        yield 'spatie/regex' => ['preset' => InteropPresets::SPATIE_REGEX, 'content' => '<?php use Spatie\Regex\Regex as R; R::match("/a/", $s);'];
        yield 'laravel Str' => ['preset' => InteropPresets::LARAVEL_STR, 'content' => '<?php use ILLUMINATE\Support\Str as S; S::match("/a/", $s);'];
    }

    #[DataProvider('provideAliasedPresetImports')]
    public function test_an_aliased_import_of_a_preset_class_opens_the_file(string $preset, string $content): void
    {
        $registry = PatternFunctionRegistry::native()->withPresets([$preset]);

        $this->assertTrue($registry->matchesContent($content));
        $this->assertFalse($registry->matchesContent('<?php use App\Support\Helpers as H; H::run($s);'));
    }

    public function test_presets_contribute_the_namespace_of_their_classes(): void
    {
        $this->assertSame(['preg::', 'regex::', 'composer\\pcre\\'], InteropPresets::needles(InteropPresets::COMPOSER_PCRE));
        $this->assertSame(['strings::', 'nette\\utils\\'], InteropPresets::needles(InteropPresets::NETTE_UTILS));
        $this->assertSame(['regex::', 'spatie\\regex\\'], InteropPresets::needles(InteropPresets::SPATIE_REGEX));
        $this->assertSame(['str::', 'illuminate\\support\\'], InteropPresets::needles(InteropPresets::LARAVEL_STR));
        $this->assertSame([], InteropPresets::needles('unknown'));
    }

    public function test_a_declared_namespaced_class_contributes_its_namespace(): void
    {
        $registry = PatternFunctionRegistry::native()->withCustomFunctions(['\\App\\Support\\Re::m#1']);

        $this->assertTrue($registry->matchesContent('<?php use App\Support\Re as R; R::m($s, "/a/");'));
        $this->assertTrue($registry->matchesContent('<?php Re::m($s, "/a/");'));
        $this->assertFalse($registry->matchesContent('<?php use Vendor\Re as R; R::run($s);'));
    }

    public function test_a_declared_global_class_adds_no_namespace(): void
    {
        $registry = PatternFunctionRegistry::native()->withCustomFunctions(['Re::m']);

        $this->assertTrue($registry->matchesContent('<?php Re::m("/a/");'));
        $this->assertFalse($registry->matchesContent('<?php use App\Support\Helpers as H; H::run($s);'));
    }
}
