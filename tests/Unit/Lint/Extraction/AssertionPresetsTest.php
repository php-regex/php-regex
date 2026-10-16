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
 * The assertion libraries and Symfony's Regex constraint, each a preset
 * matched on the fully qualified class once the imports are resolved.
 *
 * Signatures read in the libraries' sources: webmozart/assert 2.5
 * Assert::regex(mixed $value, mixed $pattern, ...) and notRegex() (the
 * nullOr and all variants in Mixin.php take the same arguments);
 * beberlei/assert Assertion::regex($value, $pattern, ...) and notRegex()
 * (nullOr and all variants as @method); symfony/validator
 * Constraints\Regex::__construct(?string $pattern, ?string $message,
 * ?string $htmlPattern, ...). Each hands its pattern to preg_match();
 * htmlPattern is an HTML attribute, no PCRE.
 */
final class AssertionPresetsTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{method: string}>
     */
    public static function provideWebmozartMethods(): iterable
    {
        foreach (['regex', 'notRegex', 'nullOrRegex', 'nullOrNotRegex', 'allRegex', 'allNotRegex', 'allNullOrRegex', 'allNullOrNotRegex'] as $method) {
            yield $method => ['method' => $method];
        }
    }

    #[Test]
    #[DataProvider('provideWebmozartMethods')]
    public function test_webmozart_assert_reads_the_second_argument(string $method): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/webmozart/', 'at' => "'/webmozart/'"],
            ['pattern' => '/webmozart-aliased/', 'at' => "'/webmozart-aliased/'"],
            ['pattern' => '/webmozart-named/', 'at' => "'/webmozart-named/'"],
        ], <<<PHP
            <?php

            namespace App;

            use Webmozart\\Assert\\Assert;
            use Webmozart\\Assert\\Assert as Check;

            function run(mixed \$value): void
            {
                Assert::{$method}(\$value, '/webmozart/');
                Check::{$method}(\$value, '/webmozart-aliased/', 'not a pattern');
                Assert::{$method}(pattern: '/webmozart-named/', value: \$value);
            }
            PHP, PatternFunctionRegistry::create(['webmozart-assert']));
    }

    /**
     * @return iterable<string, array{method: string}>
     */
    public static function provideBeberleiMethods(): iterable
    {
        foreach (['regex', 'notRegex', 'nullOrRegex', 'nullOrNotRegex', 'allRegex', 'allNotRegex'] as $method) {
            yield $method => ['method' => $method];
        }
    }

    #[Test]
    #[DataProvider('provideBeberleiMethods')]
    public function test_beberlei_assert_reads_the_second_argument(string $method): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/beberlei/', 'at' => "'/beberlei/'"],
            ['pattern' => '/beberlei-aliased/', 'at' => "'/beberlei-aliased/'"],
        ], <<<PHP
            <?php

            namespace App;

            use Assert\\Assertion;
            use Assert\\Assertion as Guard;

            function run(mixed \$value): void
            {
                Assertion::{$method}(\$value, '/beberlei/');
                Guard::{$method}(\$value, '/beberlei-aliased/', 'not a pattern', 'path');
            }
            PHP, PatternFunctionRegistry::create(['beberlei-assert']));
    }

    /**
     * A class of the project named like an assertion class, or another
     * class of the library, is no assertion: the class is resolved.
     */
    #[Test]
    public function test_an_assertion_class_is_matched_on_its_fully_qualified_name(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/real/', 'at' => "'/real/'"],
        ], <<<'PHP'
            <?php

            namespace App;

            use App\Support\Assert;
            use Assert\Assert as Lazy;

            function run(mixed $value): void
            {
                Assert::regex($value, '/project-class/');
                Assertion::regex($value, '/unimported/');
                Lazy::regex($value, '/other-library-class/');
                \Webmozart\Assert\Assert::regex($value, '/real/');
            }
            PHP, PatternFunctionRegistry::create(['webmozart-assert', 'beberlei-assert']));
    }

    #[Test]
    public function test_symfony_regex_constraint_is_read_as_new_and_as_attribute(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/attribute/', 'at' => "'/attribute/'"],
            ['pattern' => '/aliased-attribute/', 'at' => "'/aliased-attribute/'"],
            ['pattern' => '/named-attribute/', 'at' => "'/named-attribute/'"],
            ['pattern' => '/promoted/', 'at' => "'/promoted/'"],
            ['pattern' => '/nested-in-all/', 'at' => "'/nested-in-all/'"],
            ['pattern' => '/new/', 'at' => "'/new/'"],
            ['pattern' => '/new-named/', 'at' => "'/new-named/'"],
            ['pattern' => '/new-aliased-class/', 'at' => "'/new-aliased-class/'"],
            ['pattern' => '/legacy-array/', 'at' => "'/legacy-array/'"],
        ], <<<'PHP'
            <?php

            namespace App\Entity;

            use Symfony\Component\Validator\Constraints as Assert;
            use Symfony\Component\Validator\Constraints\Regex;
            use Symfony\Component\Validator\Constraints\Regex as MatchesPattern;

            final class User
            {
                #[Regex('/attribute/')]
                public string $a;

                #[Assert\Regex('/aliased-attribute/', htmlPattern: '[a-z]+')]
                public string $b;

                #[Assert\Regex(message: 'Bad.', pattern: '/named-attribute/')]
                public string $c;

                #[Assert\All([new Assert\Regex('/nested-in-all/')])]
                public array $d;

                public function __construct(
                    #[MatchesPattern('/promoted/')]
                    public string $e,
                ) {
                }

                public function constraints(): array
                {
                    return [
                        new Regex('/new/', 'Bad.', '[0-9]+'),
                        new Assert\Regex(htmlPattern: '[a-z]+', pattern: '/new-named/'),
                        new MatchesPattern('/new-aliased-class/'),
                        new Regex(['pattern' => '/legacy-array/', 'htmlPattern' => '[a-z]']),
                    ];
                }
            }
            PHP, PatternFunctionRegistry::create(['symfony-validator']));
    }

    /**
     * Class names never fall back to the global namespace: an unimported
     * Regex in a namespace is that namespace's class.
     */
    #[Test]
    public function test_symfony_regex_constraint_is_matched_on_its_fully_qualified_name(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/fully-qualified/', 'at' => "'/fully-qualified/'"],
        ], <<<'PHP'
            <?php

            namespace App\Entity;

            use App\Validation\Regex as ProjectRegex;

            final class User
            {
                #[Regex('/unimported/')]
                public string $a;

                #[ProjectRegex('/project-class/')]
                public string $b;

                #[\Symfony\Component\Validator\Constraints\Regex('/fully-qualified/')]
                public string $c;
            }
            PHP, PatternFunctionRegistry::create(['symfony-validator']));
    }

    /**
     * An argument that is no pattern literal is left alone, and a
     * malformed attribute does not stop the file.
     */
    #[Test]
    public function test_symfony_regex_constraint_without_a_literal_pattern_is_not_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/after-the-odd-ones/', 'at' => "'/after-the-odd-ones/'"],
        ], <<<'PHP'
            <?php

            namespace App\Entity;

            use Symfony\Component\Validator\Constraints\Regex;

            final class User
            {
                #[Regex]
                public string $a;

                #[Regex(null)]
                public string $b;

                #[Regex(pattern: self::UNKNOWN_IN_ANOTHER_FILE)]
                public string $c;

                #[Regex(['htmlPattern' => '[a-z]'])]
                public string $d;

                #[Regex('/after-the-odd-ones/')]
                public string $e;
            }
            PHP, PatternFunctionRegistry::create(['symfony-validator']));
    }

    /**
     * An attribute group may end with a comma and hold other attributes:
     * the constraint is read in it all the same.
     */
    #[Test]
    public function test_symfony_regex_attribute_is_read_in_any_attribute_group(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/trailing-comma/', 'at' => "'/trailing-comma/'"],
            ['pattern' => '/second-of-a-group/', 'at' => "'/second-of-a-group/'"],
        ], <<<'CODE'
            <?php

            namespace App\Entity;

            use Symfony\Component\Validator\Constraints\Regex;

            final class User
            {
                #[Regex('/trailing-comma/'),]
                public string $a;

                #[\Deprecated, Regex('/second-of-a-group/')]
                public string $b;
            }
            CODE, PatternFunctionRegistry::create(['symfony-validator']));
    }

    /**
     * nette/utils appends "u" to every pattern when utf8 is true
     * (Strings::match(), matchAll(), split(), replace(): $pattern .= 'u'):
     * "/\xff/" compiles, "/\xff/u" does not.
     */
    #[Test]
    public function test_nette_utf8_true_appends_the_u_flag(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/utf8-match/iu', 'at' => "'/utf8-match/i'"],
            ['pattern' => '/utf8-match-all/u', 'at' => "'/utf8-match-all/'"],
            ['pattern' => '/utf8-split/u', 'at' => "'/utf8-split/'"],
            ['pattern' => '/utf8-replace-key/u', 'at' => "'/utf8-replace-key/'"],
            ['pattern' => '/utf8-false/', 'at' => "'/utf8-false/'"],
            ['pattern' => '/positional-match/u', 'at' => "'/positional-match/'"],
            ['pattern' => '/positional-split/u', 'at' => "'/positional-split/'"],
            ['pattern' => '/positional-match-all/u', 'at' => "'/positional-match-all/'"],
            ['pattern' => '/positional-replace/u', 'at' => "'/positional-replace/'"],
            ['pattern' => '/positional-false/', 'at' => "'/positional-false/'"],
        ], <<<'PHP'
            <?php

            use Nette\Utils\Strings;

            function run(string $s, bool $flag, array $more): void
            {
                Strings::match($s, '/utf8-match/i', utf8: true);
                Strings::matchAll($s, '/utf8-match-all/', utf8: true);
                Strings::split($s, '/utf8-split/', utf8: true);
                Strings::replace($s, ['/utf8-replace-key/' => 'x'], utf8: true);
                Strings::match($s, '/utf8-false/', utf8: false);
                Strings::match($s, '/positional-match/', false, 0, false, true);
                Strings::split($s, '/positional-split/', false, false, -1, \true);
                Strings::matchAll($s, '/positional-match-all/', false, 0, false, false, true);
                Strings::replace($s, '/positional-replace/', '', -1, false, false, TRUE);
                Strings::match($s, '/positional-false/', false, 0, false, false);
            }
            PHP, PatternFunctionRegistry::create(['nette-utils']));
    }

    /**
     * A utf8 the file does not spell as true or false leaves the pattern's
     * flags unknown: nothing is read. A spread may hold it.
     */
    #[Test]
    public function test_nette_utf8_neither_true_nor_false_reads_nothing(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], <<<'PHP'
            <?php

            use Nette\Utils\Strings;

            function run(string $s, bool $flag, array $more): void
            {
                Strings::match($s, '/named-variable/', utf8: $flag);
                Strings::match($s, '/positional-variable/', false, 0, false, $flag);
                Strings::match($s, '/spread/', false, ...$more);
                Strings::replace($s, '/replace-unknown/', '', -1, false, false, 1);
                Strings::match($s, '/control/', false, 0);
            }
            PHP, PatternFunctionRegistry::create(['nette-utils']));
    }

    /**
     * @return iterable<string, array{presets: list<string>, call: string}>
     */
    public static function provideOffPresets(): iterable
    {
        yield 'webmozart-assert' => ['presets' => ['phpunit', 'beberlei-assert', 'symfony-validator'], 'call' => "\\Webmozart\\Assert\\Assert::regex(\$v, '/off/');"];
        yield 'beberlei-assert' => ['presets' => ['phpunit', 'webmozart-assert', 'symfony-validator'], 'call' => "\\Assert\\Assertion::regex(\$v, '/off/');"];
        yield 'symfony-validator' => ['presets' => ['phpunit', 'webmozart-assert', 'beberlei-assert'], 'call' => "new \\Symfony\\Component\\Validator\\Constraints\\Regex('/off/');"];
    }

    /**
     * An explicit list means exactly that list.
     *
     * @param list<string> $presets
     */
    #[Test]
    #[DataProvider('provideOffPresets')]
    public function test_a_preset_left_out_of_an_explicit_list_is_not_read(array $presets, string $call): void
    {
        $this->assertSame(['/on/'], $this->patternsReadByBoth("<?php\n\n".$call."\nnew \\RegexIterator(\$it, '/on/');\n", PatternFunctionRegistry::create($presets)));
    }
}
