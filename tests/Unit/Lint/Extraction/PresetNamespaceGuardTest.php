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

use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A preset matched on a fully qualified class opens a file only when the
 * file spells every word of the class: no import, alias, group use,
 * namespace declaration or qualified name reaches Illuminate\Support\Str
 * without writing "Illuminate", "Support" and "Str" somewhere. A file that
 * only says "Str::" is not read for it.
 */
final class PresetNamespaceGuardTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{preset: string, content: string}>
     */
    public static function provideFilesWithoutTheNamespace(): iterable
    {
        yield 'illuminate-support' => ['preset' => 'illuminate-support', 'content' => "<?php use App\\Str; Str::match('/a/', \$s);"];
        yield 'illuminate-support, alias spelling' => ['preset' => 'illuminate-support', 'content' => "<?php use App\\Str as S; S::match('/a/', \$s);"];
        yield 'nette-utils' => ['preset' => 'nette-utils', 'content' => "<?php Strings::match(\$s, '/a/');"];
        yield 'spatie-regex' => ['preset' => 'spatie-regex', 'content' => "<?php Regex::match('/a/', \$s);"];
        yield 'webmozart-assert' => ['preset' => 'webmozart-assert', 'content' => "<?php Check::regex(\$v, '/a/');"];
        yield 'beberlei-assert' => ['preset' => 'beberlei-assert', 'content' => "<?php Guard::regex(\$v, '/a/');"];
        yield 'beberlei-assert, the root alone' => ['preset' => 'beberlei-assert', 'content' => "<?php assert(\$x); \$this->regex('/a/');"];
        yield 'symfony-validator' => ['preset' => 'symfony-validator', 'content' => "<?php new Regex('/a/');"];
        yield 'symfony-validator, another component' => ['preset' => 'symfony-validator', 'content' => "<?php use Symfony\\Component\\Routing\\Route; \$route->regex('/a/');"];
        yield 'illuminate-support, another class' => ['preset' => 'illuminate-support', 'content' => "<?php use Illuminate\\Http\\Request; Str::match('/a/', \$s);"];
    }

    #[Test]
    #[DataProvider('provideFilesWithoutTheNamespace')]
    public function test_a_file_without_the_class_namespace_is_not_opened(string $preset, string $content): void
    {
        $this->assertFalse(PatternFunctionRegistry::native()->withPresets([$preset])->matchesContent($content));
    }

    /**
     * Every way PHP names the class keeps the root of its namespace.
     *
     * @return iterable<string, array{content: string}>
     */
    public static function provideWaysToNameTheClass(): iterable
    {
        yield 'import' => ['content' => "<?php\nuse Illuminate\\Support\\Str;\nStr::match('/found/', \$s);\n"];
        yield 'aliased import, upper case' => ['content' => "<?php\nuse ILLUMINATE\\Support\\Str AS S;\nS::match('/found/', \$s);\n"];
        yield 'group use split inside the namespace' => ['content' => "<?php\nuse Illuminate\\{Support\\Str as S};\nS::match('/found/', \$s);\n"];
        yield 'imported namespace' => ['content' => "<?php\nuse Illuminate\\Support;\nSupport\\Str::match('/found/', \$s);\n"];
        yield 'fully qualified' => ['content' => "<?php\n\\Illuminate\\Support\\Str::match('/found/', \$s);\n"];
        yield 'the class own namespace' => ['content' => "<?php\nnamespace Illuminate\\Support;\nStr::match('/found/', \$s);\n"];
        yield 'a parent namespace' => ['content' => "<?php\nnamespace Illuminate;\nSupport\\Str::match('/found/', \$s);\n"];
        yield 'group use of several classes' => ['content' => "<?php\nuse Illuminate\\Support\\{Arr, Str};\nStr::match('/found/', \$s);\n"];
    }

    #[Test]
    #[DataProvider('provideWaysToNameTheClass')]
    public function test_every_way_of_naming_the_class_opens_the_file(string $content): void
    {
        $registry = PatternFunctionRegistry::native()->withPresets(['illuminate-support']);

        $this->assertTrue($registry->matchesContent($content));
        $this->assertSame(['/found/'], $this->patternsReadByBoth($content, $registry));
    }

    /**
     * composer/pcre keeps the check it had before the other presets were
     * on: its short class names open the file.
     */
    #[Test]
    public function test_composer_pcre_keeps_its_short_name_check(): void
    {
        $this->assertTrue(PatternFunctionRegistry::native()->withPresets(['composer-pcre'])->matchesContent("<?php Preg::match('/a/', \$s);"));
    }

    /**
     * A needle two presets share opens the file for the preset whose
     * namespace the file spells.
     */
    #[Test]
    public function test_a_shared_needle_opens_the_file_for_either_namespace(): void
    {
        $registry = PatternFunctionRegistry::native()->withPresets(['webmozart-assert', 'symfony-validator']);

        $this->assertTrue($registry->matchesContent("<?php use Symfony\\Component\\Validator\\Constraints\\Regex; new Regex('/a/');"));
        $this->assertTrue($registry->matchesContent("<?php use Webmozart\\Assert\\Assert; Assert::regex(\$v, '/a/');"));
    }
}
