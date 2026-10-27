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
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Shapes of PHP the tokenizer reads as nikic/php-parser does: imports,
 * attributes, option arrays, assignments, and what the second pass over
 * the file follows.
 */
final class TokenBasedExtractionStrategyShapesTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * A function no rule of the case touches: the resolution runs, and only
     * the case at hand is left out.
     */
    private const CONTROL = <<<'PHP'

        function control_resolution(string $s): int
        {
            $control = '/control/';

            return preg_match($control, $s);
        }

        PHP;

    /**
     * Shapes of PHP the tokenizer must read as nikic/php-parser does: each
     * case lists the patterns both strategies read, where their literal is.
     *
     * @return iterable<string, array{expected: list<array{pattern: string, at: string}>, code: string, presets: list<string>, declared: list<string>}>
     */
    public static function provideShapesReadAsTheParserReadsThem(): iterable
    {
        yield 'an assignment with no space around "="' => ['expected' => [['pattern' => '/no-spaces/', 'at' => "'/no-spaces/'"]], 'code' => <<<'PHP'
            function run(string $s): int
            {
                $re='/no-spaces/';

                return preg_match($re, $s);
            }
            PHP, 'presets' => [], 'declared' => []];

        // A parameter is never assigned with "=": it needs no second pass,
        // the constant after it does.
        yield 'a constant after a variable the file never assigns' => ['expected' => [['pattern' => '/after-unassigned/', 'at' => "'/after-unassigned/'"]], 'code' => <<<'PHP'
            final class Matcher
            {
                private const RE = '/after-unassigned/';

                public function run(string $given, string $s): int
                {
                    preg_match($given, $s);

                    return preg_match(self::RE, $s);
                }
            }
            PHP, 'presets' => [], 'declared' => []];

        yield 'a property of another object passed to a call' => ['expected' => [], 'code' => <<<'PHP'
            final class Matcher
            {
                private string $re = '/aliased/';

                public function run(self $other, string $s): int
                {
                    touch_it($other->re);

                    return preg_match($this->re, $s);
                }
            }
            PHP, 'presets' => [], 'declared' => []];

        yield 'a second attribute with no space after the comma' => ['expected' => [['pattern' => '/no-space/', 'at' => "'/no-space/'"]], 'code' => <<<'PHP'
            use Symfony\Component\Validator\Constraints\Regex;

            final class Form
            {
                #[Other,Regex('/no-space/')]
                public string $code = '';
            }
            PHP, 'presets' => [InteropPresets::SYMFONY_VALIDATOR], 'declared' => []];

        yield 'two pattern attributes in one group' => ['expected' => [['pattern' => '/first/', 'at' => "'/first/'"], ['pattern' => '/second/', 'at' => "'/second/'"]], 'code' => <<<'PHP'
            use Symfony\Component\Validator\Constraints\Regex;

            final class Form
            {
                #[Regex('/first/'), Regex('/second/')]
                public string $code = '';
            }
            PHP, 'presets' => [InteropPresets::SYMFONY_VALIDATOR], 'declared' => []];

        yield 'new with no parentheses' => ['expected' => [], 'code' => <<<'PHP'
            $iterator = new \RegexIterator;
            $other = keep($iterator, '/not-an-argument/');
            PHP, 'presets' => [], 'declared' => []];

        yield 'the last class of a group import' => ['expected' => [['pattern' => '/group-last/', 'at' => "'/group-last/'"]], 'code' => <<<'PHP'
            namespace App;

            use Composer\Pcre\{Regex, Preg};

            Preg::match('/group-last/', $s);
            PHP, 'presets' => [InteropPresets::COMPOSER_PCRE], 'declared' => []];

        yield 'the first class of an import list' => ['expected' => [['pattern' => '/list-first/', 'at' => "'/list-first/'"]], 'code' => <<<'PHP'
            namespace App;

            use Composer\Pcre\Preg, App\Support\Other;

            Preg::match('/list-first/', $s);
            PHP, 'presets' => [InteropPresets::COMPOSER_PCRE], 'declared' => []];

        yield 'a function of a group import' => ['expected' => [['pattern' => '/group-function/', 'at' => "'/group-function/'"]], 'code' => <<<'PHP'
            namespace App;

            use Lib\{function grep};

            grep('/group-function/');
            PHP, 'presets' => [], 'declared' => ['Lib\grep#0']];

        yield 'an import in a braced global namespace' => ['expected' => [['pattern' => '/braced-global/', 'at' => "'/braced-global/'"]], 'code' => <<<'PHP'
            namespace{
            use Nette\Utils\Strings;

            Strings::match($s, '/braced-global/');
            }
            PHP, 'presets' => [InteropPresets::NETTE_UTILS], 'declared' => []];

        // A declaration named as an assertion is no call: its default value
        // is no pattern.
        yield 'a static method declared with an assertion name' => ['expected' => [], 'code' => <<<'PHP'
            final class Checks
            {
                public static function assertMatchesRegularExpression(string $pattern = '/declared-default/', string $string = ''): void
                {
                }
            }
            PHP, 'presets' => [InteropPresets::PHPUNIT], 'declared' => []];

        // PHP compares class names, self among them, without case.
        yield 'SELF:: in upper case' => ['expected' => [['pattern' => '/upper-self/', 'at' => "'/upper-self/'"]], 'code' => <<<'PHP_WRAP'
            final class MatcherTest extends \PHPUnit\Framework\TestCase
            {
                #[Test]
                public function test_it(): void
                {
                    SELF::assertMatchesRegularExpression('/upper-self/', 'x');
                }
            }
            PHP_WRAP, 'presets' => [InteropPresets::PHPUNIT], 'declared' => []];

        yield 'utf8: \true and utf8: TRUE' => ['expected' => [['pattern' => '/qualified-true/u', 'at' => "'/qualified-true/'"], ['pattern' => '/upper-true/u', 'at' => "'/upper-true/'"]], 'code' => <<<'PHP'
            use Nette\Utils\Strings;

            Strings::match($s, '/qualified-true/', utf8: \true);
            Strings::match($s, '/upper-true/', utf8: TRUE);
            PHP, 'presets' => [InteropPresets::NETTE_UTILS], 'declared' => []];

        // filter_var() reads its pattern under ['options']['regexp'] only.
        yield 'filter_var() options that are a string' => ['expected' => [], 'code' => <<<'PHP'
            filter_var($s, FILTER_VALIDATE_REGEXP, '/not-options/');
            PHP, 'presets' => [], 'declared' => []];

        yield 'filter_var() options that are a sum of arrays' => ['expected' => [], 'code' => <<<'PHP'
            filter_var($s, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/summed/']] + $defaults);
            PHP, 'presets' => [], 'declared' => []];

        yield 'filter_var() options under a key in parentheses' => ['expected' => [['pattern' => '/parenthesized-key/', 'at' => "'/parenthesized-key/'"]], 'code' => <<<'PHP'
            filter_var($s, FILTER_VALIDATE_REGEXP, [('options') => ['regexp' => '/parenthesized-key/']]);
            PHP, 'presets' => [], 'declared' => []];

        yield 'filter_var() options written with no space' => ['expected' => [['pattern' => '/compact/', 'at' => "'/compact/'"]], 'code' => <<<'PHP'
            filter_var($s, FILTER_VALIDATE_REGEXP, ['flags'=>0,'options'=>['regexp'=>'/compact/']]);
            PHP, 'presets' => [], 'declared' => []];

        yield 'a constant among the items of an array literal' => ['expected' => [['pattern' => '/from-constant/', 'at' => "'/from-constant/'"], ['pattern' => '/literal-item/', 'at' => "'/literal-item/'"]], 'code' => <<<'PHP'
            final class Cleaner
            {
                private const RE = '/from-constant/';

                public function run(string $s): string
                {
                    return preg_replace([self::RE, '/literal-item/'], '', $s);
                }
            }
            PHP, 'presets' => [], 'declared' => []];

        yield 'a local variable after function and constant imports' => ['expected' => [['pattern' => '/after-imports/', 'at' => "'/after-imports/'"]], 'code' => <<<'PHP'
            namespace App;

            use function Lib\helper;
            use const Lib\LIMIT;

            function run(string $s): int
            {
                $re = '/after-imports/';

                return preg_match($re, $s);
            }
            PHP, 'presets' => [], 'declared' => []];
    }

    /**
     * @param list<array{pattern: string, at: string}> $expected
     * @param list<string>                             $presets
     * @param list<string>                             $declared
     */
    #[Test]
    #[DataProvider('provideShapesReadAsTheParserReadsThem')]
    public function test_a_shape_is_read_as_the_parser_reads_it(array $expected, string $code, array $presets, array $declared): void
    {
        $code = "<?php\n\n".$code."\n";
        if (!str_contains($code, 'namespace')) {
            $code .= self::CONTROL;
            $expected[] = ['pattern' => '/control/', 'at' => "'/control/'"];
        }

        $this->assertExtractedByBoth($expected, $code, PatternFunctionRegistry::native()->withPresets($presets)->withDeclaredFunctions($declared));
    }

    /**
     * An editor's half-typed attribute group: an item that names nothing is
     * passed over, the items after it are read. PHP cannot parse it, so the
     * tokenizer alone reads it.
     */
    #[Test]
    public function test_an_attribute_item_that_names_nothing_is_passed_over(): void
    {
        $code = "<?php\n\nuse Symfony\\Component\\Validator\\Constraints\\Regex;\n\nfinal class Form\n{\n    #['typing', Regex('/after-malformed/')]\n    public string \$code = '';\n}\n";

        $read = (new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()->withPresets([InteropPresets::SYMFONY_VALIDATOR])))->extractSource($code);

        $this->assertSame(['/after-malformed/'], array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $read));
    }

    /**
     * A call an editor has left open is read as far as it goes: a call
     * nested in it that closes leaves its arguments known.
     */
    #[Test]
    public function test_a_call_left_open_is_read_past_a_nested_call_that_closes(): void
    {
        $read = (new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()))->extractSource("<?php\npreg_match('/open/', trim(\$s)");

        $this->assertSame(['/open/'], array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $read));
    }

    /**
     * An item of an array of patterns that is itself an array() holds no
     * pattern, as a [] item holds none: PHP reads it as the string "Array"
     * ("Delimiter must not be alphanumeric"), and an array is no key.
     */
    #[Test]
    public function test_an_array_item_spelled_array_holds_no_pattern(): void
    {
        $this->assertExtractedByBoth([['pattern' => '/kept/', 'at' => "'/kept/'"]], <<<'PHP'
            <?php

            preg_replace([array('/nested-long/')], '', $s);
            preg_replace(array(array ('/nested-in-long/'), '/kept/'), '', $s);
            preg_replace([['/nested-short/']], '', $s);
            preg_replace_callback_array([array('/nested-key/') => fn ($m) => ''], $s);
            PHP);
    }

    /**
     * The "=>" of an arrow function in an array of patterns is the arrow
     * function's: what it returns is no pattern, as PHP refuses the closure
     * itself ("Object of class Closure could not be converted to string"),
     * and an item after it is read as any other.
     */
    #[Test]
    public function test_an_arrow_function_item_holds_no_pattern(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/kept-a/', 'at' => "'/kept-a/'"],
            ['pattern' => '/kept-b/', 'at' => "'/kept-b/'"],
            ['pattern' => '/kept-c/', 'at' => "'/kept-c/'"],
            ['pattern' => '/kept-d/', 'at' => "'/kept-d/'"],
        ], <<<'PHP'
            <?php

            preg_replace([fn () => '/arrow-value/'], '', $s);
            preg_replace(['/kept-a/', static fn ($x) => '/arrow-after/'], '', $s);
            preg_replace_callback_array(['/kept-b/' => fn ($m) => fn () => '/arrow-nested/', '/kept-c/' => $g], $s);
            preg_replace([fn () => [1, 2], '/kept-d/'], '', $s);
            preg_replace([#[Pure] fn () => '/arrow-attributed/'], '', $s);
            PHP);
    }
}
