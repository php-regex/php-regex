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

use PHPRegex\Linter\Config\LintArguments;
use PHPRegex\Linter\Config\LintConfigSchema;
use PHPRegex\Linter\Extraction\InteropPresets;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPRegex\Tests\Support\JsonContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The presets a configuration may name, frozen at 2.0: the slugs of the
 * packages they read, all of them on unless configuration says otherwise.
 */
final class InteropPresetCatalogueTest extends TestCase
{
    use ExtractsWithBothStrategies;

    private const NAMES = [
        'composer-pcre',
        'nette-utils',
        'spatie-regex',
        'illuminate-support',
        'phpunit',
        'webmozart-assert',
        'beberlei-assert',
        'symfony-validator',
    ];

    #[Test]
    public function test_preset_names_are_the_package_slugs(): void
    {
        $this->assertSame(self::NAMES, InteropPresets::names());
        $this->assertSame(self::NAMES, LintConfigSchema::interopPresets());
    }

    #[Test]
    public function test_every_preset_is_on_by_default(): void
    {
        $this->assertSame(self::NAMES, LintArguments::fromDefaults([])->interop);
    }

    /**
     * The schema keeps the names as an enum for the editors, and says the
     * default is every one of them.
     */
    #[Test]
    public function test_the_schema_names_every_preset_and_defaults_to_all(): void
    {
        $interop = self::interopNode(LintConfigSchema::definition());

        $this->assertSame(self::NAMES, $interop['default'] ?? null);
        $this->assertSame(self::NAMES, array_column(JsonContract::asArray(JsonContract::asArray($interop['items'] ?? null)['oneOf'] ?? null), 'const'));

        $published = json_decode((string) file_get_contents(__DIR__.'/../../../../src/Linter/regex.schema.json'), true);
        $this->assertSame(self::NAMES, self::interopNode($published)['default'] ?? null);
    }

    /**
     * laravel-str never shipped in a tag (absent from v1.3.0): renamed
     * without an alias.
     */
    #[Test]
    public function test_laravel_str_is_no_preset_name(): void
    {
        $this->assertFalse(InteropPresets::exists('laravel-str'));
        $this->assertTrue(InteropPresets::exists('illuminate-support'));
    }

    #[Test]
    public function test_illuminate_support_reads_str(): void
    {
        $this->assertSame(['/str-match/', '/str-is-match/'], $this->patternsReadByBoth(<<<'PHP'
            <?php

            use Illuminate\Support\Str;

            Str::match('/str-match/', $s);
            Str::isMatch('/str-is-match/', $s);
            PHP, PatternFunctionRegistry::create(['illuminate-support'])));
    }

    /**
     * The defaults read every preset: a file of each library is read
     * without any configuration.
     */
    #[Test]
    public function test_the_default_registry_reads_every_preset(): void
    {
        $this->assertSame([
            '/composer/', '/nette/', '/spatie/', '/laravel/', '/phpunit/', '/webmozart/', '/beberlei/', '/symfony/',
        ], $this->patternsReadByBoth(<<<'PHP_WRAP'
            <?php
        
            use Composer\Pcre\Preg;
            use Nette\Utils\Strings;
            use Spatie\Regex\Regex;
            use Illuminate\Support\Str;
            use Webmozart\Assert\Assert;
            use Assert\Assertion;
            use Symfony\Component\Validator\Constraints as Constraint;
        
            final class EveryLibraryTest extends \PHPUnit\Framework\TestCase
            {
                public function test_it(string $s): void
                {
                    Preg::match('/composer/', $s);
                    Strings::match($s, '/nette/');
                    Regex::match('/spatie/', $s);
                    Str::match('/laravel/', $s);
                    $this->assertMatchesRegularExpression('/phpunit/', $s);
                    Assert::regex($s, '/webmozart/');
                    Assertion::regex($s, '/beberlei/');
                    new Constraint\Regex('/symfony/');
                }
            }
            PHP_WRAP, PatternFunctionRegistry::defaults()));
    }

    /**
     * @return iterable<string, array{preset: string, content: string}>
     */
    public static function provideFilesAPresetOpens(): iterable
    {
        yield 'phpunit, $this' => ['preset' => 'phpunit', 'content' => "<?php \$this->assertMatchesRegularExpression('/a/', \$s);"];
        yield 'phpunit, expectation' => ['preset' => 'phpunit', 'content' => "<?php \$this->expectExceptionMessageMatches('/a/');"];
        yield 'phpunit, legacy' => ['preset' => 'phpunit', 'content' => "<?php self::assertRegExp('/a/', \$s);"];
        yield 'webmozart-assert' => ['preset' => 'webmozart-assert', 'content' => "<?php use Webmozart\\Assert\\Assert; Assert::allNullOrNotRegex(\$v, '/a/');"];
        yield 'beberlei-assert' => ['preset' => 'beberlei-assert', 'content' => "<?php use Assert\\Assertion; Assertion::nullOrRegex(\$v, '/a/');"];
        yield 'symfony-validator, attribute' => ['preset' => 'symfony-validator', 'content' => "<?php use Symfony\\Component\\Validator\\Constraints as Assert; class A { #[Assert\\Regex('/a/')] public \$a; }"];
        yield 'symfony-validator, aliased class' => ['preset' => 'symfony-validator', 'content' => "<?php use Symfony\\Component\\Validator\\Constraints\\Regex as Pattern; class A { #[Pattern('/a/')] public \$a; }"];
    }

    #[Test]
    #[DataProvider('provideFilesAPresetOpens')]
    public function test_a_preset_opens_a_file_that_uses_its_library(string $preset, string $content): void
    {
        $this->assertTrue(PatternFunctionRegistry::native()->withPresets([$preset])->matchesContent($content));
    }

    /**
     * The needles come from the method and class names, never from a bare
     * "assert::": a file of plain assertions is not opened for the regex
     * presets.
     */
    #[Test]
    public function test_a_file_of_other_assertions_is_not_opened(): void
    {
        $registry = PatternFunctionRegistry::native()->withPresets(['phpunit', 'webmozart-assert', 'beberlei-assert', 'symfony-validator']);

        $this->assertFalse($registry->matchesContent('<?php use Webmozart\\Assert\\Assert; Assert::true($x); Assertion::notEmpty($y); $this->assertSame(1, $z);'));
        $this->assertFalse($registry->matchesContent('<?php use Symfony\\Component\\Validator\\Constraints as Assert; class A { #[Assert\\NotBlank] public $a; }'));
    }

    /**
     * A file the needles open only through the alias of a constraint
     * class is read.
     */
    #[Test]
    public function test_an_aliased_symfony_constraint_alone_is_read(): void
    {
        $this->assertSame(['/aliased-only/'], $this->patternsReadByBoth(<<<'PHP'
            <?php

            namespace App;

            use Symfony\Component\Validator\Constraints\Regex as Pattern;

            final class User
            {
                #[Pattern('/aliased-only/')]
                public string $name;
            }
            PHP, PatternFunctionRegistry::create(['symfony-validator'])));
    }

    /**
     * @return array<mixed>
     */
    private static function interopNode(mixed $schema): array
    {
        $extraction = JsonContract::asArray(JsonContract::asArray(JsonContract::asArray($schema)['properties'] ?? null)['extraction'] ?? null);

        return JsonContract::asArray(JsonContract::asArray($extraction['properties'] ?? null)['interop'] ?? null);
    }
}
