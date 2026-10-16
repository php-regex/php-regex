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

namespace PHPRegex\Tests\Unit\Lsp\Document;

use PHPRegex\LanguageServer\Document\RegexFinder;
use PHPRegex\LanguageServer\Document\RegexOccurrence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The language server finds the patterns `regex lint` reads, with the same
 * registry: a call is matched on its resolved name, not on a method name
 * any class may have, and a pattern the file proves is found at its
 * literal.
 */
final class RegexFinderRegistryTest extends TestCase
{
    /**
     * @return iterable<string, array{code: string, expected: list<string>}>
     */
    public static function provideCalls(): iterable
    {
        yield 'fully qualified native function' => ['code' => "\\preg_match('/qualified/', \$s);", 'expected' => ['/qualified/']];
        yield 'uppercase native function' => ['code' => "PREG_MATCH('/uppercase/', \$s);", 'expected' => ['/uppercase/']];
        yield 'imported drop-in' => ['code' => "use function Safe\\preg_match;\npreg_match('/drop-in/', \$s);", 'expected' => ['/drop-in/']];
        yield 'qualified drop-in' => ['code' => "\\Safe\\preg_replace('/qualified-drop-in/', '', \$s);", 'expected' => ['/qualified-drop-in/']];
        yield 'aliased composer/pcre' => ['code' => "use Composer\\Pcre\\Preg as P;\nP::match('/aliased-composer/', \$s);", 'expected' => ['/aliased-composer/']];
        yield 'nette/utils, pattern second' => ['code' => "use Nette\\Utils\\Strings;\nStrings::match(\$s, '/nette-second/');", 'expected' => ['/nette-second/']];
        yield 'phpunit assertion' => ['code' => "final class T extends \\PHPUnit\\Framework\\TestCase { public function test_it(): void { \$this->assertMatchesRegularExpression('/phpunit/', 'x'); } }", 'expected' => ['/phpunit/']];
        yield 'regex iterator' => ['code' => "new \\RegexIterator(\$it, '/regex-iterator/');", 'expected' => ['/regex-iterator/']];
        yield 'filter_var' => ['code' => "filter_var(\$v, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/filter-var/']]);", 'expected' => ['/filter-var/']];
        yield 'symfony Regex options through a constant' => ['code' => "use Symfony\\Component\\Validator\\Constraints\\Regex;\nfinal class Form\n{\n    const OPTS = ['pattern' => '/symfony-option/', 'message' => 'Letters.', 'htmlPattern' => '[a-z]+'];\n    public function c(): Regex { return new Regex(self::OPTS); }\n}", 'expected' => ['/symfony-option/']];
        // The method name alone matched any class before: a class of the
        // project is no wrapper.
        yield 'a project class named like a wrapper' => ['code' => "namespace App;\nThing::match('/project-class/', \$s);\nPreg::replace('/unimported/', '', \$s);", 'expected' => []];
        yield 'a wrapper reading its pattern elsewhere than first' => ['code' => "use Nette\\Utils\\Strings;\nStrings::match('/subject-not-pattern/', \$s);", 'expected' => []];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideCalls')]
    public function test_the_finder_reads_the_calls_the_lint_reads(string $code, array $expected): void
    {
        $occurrences = (new RegexFinder())->find("<?php\n".$code."\npreg_match('/control/', \$s);\n");

        $this->assertSame([...$expected, '/control/'], array_map(static fn (RegexOccurrence $occurrence): string => $occurrence->pattern, $occurrences));
    }

    /**
     * A resolved pattern is found where its literal is, as the lint
     * reports it: the diagnostic lands on the declaration.
     */
    #[Test]
    public function test_the_finder_places_a_resolved_pattern_at_its_literal(): void
    {
        $code = <<<'PHP'
            <?php

            final class Matcher
            {
                private const RE = '/resolved/';

                public function run(string $s): bool
                {
                    return 1 === preg_match(self::RE, $s);
                }
            }
            PHP;

        $occurrences = (new RegexFinder())->find($code);

        $this->assertCount(1, $occurrences);
        $this->assertSame('/resolved/', $occurrences[0]->pattern);
        $this->assertSame(strpos($code, "'/resolved/'"), $occurrences[0]->byteOffset);
        $this->assertSame(4, $occurrences[0]->start['line']);
    }
}
