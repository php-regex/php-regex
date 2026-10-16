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
use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How a call hands over its pattern: a named argument under the name of
 * the function's own parameter, a named argument after a spread, an
 * aliased import however it is spelled.
 */
final class CallArgumentsTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * PHP refuses a named argument the function does not declare
     * ("Unknown named parameter $regex"): only the real name passes the
     * pattern.
     */
    #[Test]
    public function test_a_named_argument_is_read_under_the_parameter_name_of_its_function(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/real-name/', 'at' => "'/real-name/'"],
            ['pattern' => '/spatie-real-name/', 'at' => "'/spatie-real-name/'"],
        ], <<<'PHP'
            <?php

            use Spatie\Regex\Regex;

            preg_match(regex: '/not-a-parameter-of-preg-match/', subject: $s);
            preg_replace(patterns: ['/not-a-parameter-of-preg-replace/'], replacement: '', subject: $s);
            preg_match(subject: $s, pattern: '/real-name/');
            Regex::match(regex: '/not-a-parameter-of-spatie/', subject: $s);
            Regex::match(subject: $s, pattern: '/spatie-real-name/');
            PHP, PatternFunctionRegistry::create(['spatie-regex']));
    }

    /**
     * A function marked #[RegexPattern] is read under the name of the
     * parameter it marks.
     */
    #[Test]
    public function test_a_declared_pattern_function_is_read_under_its_marked_parameter_name(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/declared-name/', 'at' => "'/declared-name/'"],
            ['pattern' => '/declared-method-name/', 'at' => "'/declared-method-name/'"],
        ], <<<'PHP_WRAP'
            <?php

            namespace App;

            use PHPRegex\Parser\Attribute\RegexPattern;

            function grep(array $lines, #[RegexPattern] string $expression): array
            {
                return [];
            }

            final class Text
            {
                public static function matches(string $subject, #[RegexPattern] string $needle): bool
                {
                    return true;
                }
            }

            grep(expression: '/declared-name/', lines: []);
            grep(lines: [], pattern: '/not-the-marked-name/');
            Text::matches(needle: '/declared-method-name/', subject: 'x');
            Text::matches(subject: 'x', regex: '/not-the-marked-method-name/');
            PHP_WRAP);
    }

    /**
     * Another attribute after the marker leaves the parameter's name read:
     * a named argument still reaches it.
     */
    #[Test]
    public function test_a_marked_parameter_keeps_its_name_behind_another_attribute(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/behind-another-attribute/', 'at' => "'/behind-another-attribute/'"],
        ], <<<'CODE'
            <?php

            namespace App;

            use PHPRegex\Parser\Attribute\RegexPattern;

            function grep(array $lines, #[RegexPattern] #[\SensitiveParameter] string $expression): array
            {
                return [];
            }

            grep(lines: [], expression: '/behind-another-attribute/');
            CODE);
    }

    /**
     * Declared at two positions, a function is read at both, positionally
     * and by each parameter's name.
     */
    #[Test]
    public function test_a_function_declared_at_two_positions_is_read_at_both(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/from/', 'at' => "'/from/'"],
            ['pattern' => '/to/', 'at' => "'/to/'"],
            ['pattern' => '/named-to/', 'at' => "'/named-to/'"],
            ['pattern' => '/named-from/', 'at' => "'/named-from/'"],
        ], <<<'CODE'
            <?php

            namespace App;

            between('subject', '/from/', '/to/');
            between(to: '/named-to/', from: '/named-from/', subject: 'subject');
            CODE, PatternFunctionRegistry::native()->withDeclaredFunctions(['App\between#1$from', 'App\between#2$to']));
    }

    /**
     * A spec string names no parameter: its pattern is read under the names
     * a pattern parameter usually has, "pattern", "patterns" and "regex".
     */
    #[Test]
    public function test_a_configured_pattern_function_is_read_under_the_usual_names(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/configured/', 'at' => "'/configured/'"],
            ['pattern' => '/configured-other-name/', 'at' => "'/configured-other-name/'"],
        ], <<<'PHP'
            <?php

            App\Support\Str::matches(subject: 'x', pattern: '/configured/');
            App\Support\Str::matches(subject: 'x', regex: '/configured-other-name/');
            PHP, PatternFunctionRegistry::native()->withCustomFunctions(['App\Support\Str::matches#1']));
    }

    /**
     * An empty literal in an array of patterns is no pattern.
     */
    #[Test]
    public function test_an_empty_literal_in_an_array_of_patterns_is_not_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/kept/', 'at' => "'/kept/'"],
        ], "<?php\npreg_replace(['', '/kept/'], '', \$s);\n");
    }

    /**
     * A named argument names its parameter as PHP does, case included:
     * preg_match(PATTERN: '/x/') throws "Unknown named parameter
     * $PATTERN", and Strings::match(..., UTF8: true) as well.
     */
    #[Test]
    public function test_a_named_argument_is_matched_case_sensitively(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/exact/', 'at' => "'/exact/'"],
            ['pattern' => '/nette/', 'at' => "'/nette/'"],
        ], <<<'PHP'
            <?php

            use Nette\Utils\Strings;

            preg_match(PATTERN: '/uppercase/', subject: $s);
            preg_match(pattern: '/exact/', subject: $s);
            Strings::match($s, '/nette/', UTF8: $flag);
            filter_var($v, FILTER_VALIDATE_REGEXP, OPTIONS: ['options' => ['regexp' => '/options-uppercase/']]);

            PHP, PatternFunctionRegistry::defaults());
    }

    /**
     * PHP fills the named parameter after a spread: preg_match(...$args,
     * pattern: '/x/') matches with "/x/" when $args holds the subject, and
     * throws "Named parameter $pattern overwrites previous argument" when
     * the spread already filled it. Nette's spread may hold utf8 as well:
     * the pattern's flags are not known, and it is not read.
     */
    #[Test]
    public function test_a_named_pattern_after_a_spread_is_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/after-spread/', 'at' => "'/after-spread/'"],
            ['pattern' => '/composer-after-spread/', 'at' => "'/composer-after-spread/'"],
        ], <<<'PHP'
            <?php

            use Composer\Pcre\Preg;
            use Nette\Utils\Strings;

            function run(array $args): void
            {
                preg_match(...$args, pattern: '/after-spread/');
                Strings::match(...$args, pattern: '/nette-after-spread/');
                Preg::match(...$args, pattern: '/composer-after-spread/');
                preg_match(...$args, subject: 'x');
            }
            PHP, PatternFunctionRegistry::create(['composer-pcre', 'nette-utils']));
    }

    /**
     * @return iterable<string, array{import: string}>
     */
    public static function provideAliasSpellings(): iterable
    {
        yield 'a tab before as' => ['import' => "use Composer\\Pcre\\Preg\tas P;"];
        yield 'a newline before as' => ['import' => "use Composer\\Pcre\\Preg\nas P;"];
        yield 'two spaces before as' => ['import' => 'use Composer\Pcre\Preg  as P;'];
        yield 'a comment before as' => ['import' => 'use Composer\Pcre\Preg /* the wrapper */ as P;'];
        yield 'a line comment before as' => ['import' => "use Composer\\Pcre\\Preg // the wrapper\nas P;"];
        yield 'uppercase AS' => ['import' => 'use Composer\Pcre\Preg AS P;'];
        yield 'a group use with a tab' => ['import' => "use Composer\\Pcre\\{Preg\tas P};"];
    }

    /**
     * PHP reads any whitespace or comment between the class and "as": each
     * spelling opens the file for the preset.
     */
    #[Test]
    #[DataProvider('provideAliasSpellings')]
    public function test_an_aliased_wrapper_import_opens_the_file_however_it_is_spelled(string $import): void
    {
        $code = "<?php\n\nnamespace App;\n\n".$import."\n\nP::match('/aliased-spelling/', \$s);\n";
        $registry = PatternFunctionRegistry::create([InteropPresets::COMPOSER_PCRE]);

        $this->assertTrue($registry->matchesContent($code));
        $this->assertSame(['/aliased-spelling/'], $this->patternsReadByBoth($code, $registry));
    }

    /**
     * A class declared in configuration opens the file the same way.
     */
    #[Test]
    #[DataProvider('provideAliasSpellings')]
    public function test_an_aliased_configured_class_import_opens_the_file_however_it_is_spelled(string $import): void
    {
        $code = "<?php\n\nnamespace App;\n\n".str_replace('Composer\Pcre\Preg', 'Acme\Text\Re', str_replace('Composer\Pcre\{Preg', 'Acme\Text\{Re', $import))."\n\nP::m('/aliased-configured/', \$s);\n";
        $registry = PatternFunctionRegistry::native()->withCustomFunctions(['Acme\Text\Re::m']);

        $this->assertTrue($registry->matchesContent($code));
        $this->assertSame(['/aliased-configured/'], $this->patternsReadByBoth($code, $registry));
    }
}
