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

use PHPRegex\Linter\Extraction\PatternAttributeScanner;
use PHPRegex\Parser\Attribute\RegexPattern;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The namespaced functions named as a global pattern function, which an
 * unqualified call in their namespace reaches first, and the parameter a
 * declaration marks.
 */
final class PatternAttributeScannerTest extends TestCase
{
    use TemporaryProject;

    #[Test]
    public function test_namespaced_functions_lists_each_function_once_across_files(): void
    {
        $project = $this->makeProject([
            'a.php' => "<?php\nnamespace App;\nfunction grep() {}\n",
            'b.php' => "<?php\nnamespace App;\nfunction grep() {}\n",
            'c.php' => "<?php\nnamespace Lib;\nfunction grep() {}\n",
        ]);

        $this->assertSame(
            ['App\grep', 'Lib\grep'],
            PatternAttributeScanner::namespacedFunctions([$project.'/a.php', $project.'/b.php', $project.'/c.php'], ['grep']),
        );
    }

    /**
     * PHP compares function names without case.
     */
    #[Test]
    public function test_a_namespaced_function_is_matched_whatever_its_case(): void
    {
        $this->assertSame(['App\Grep'], PatternAttributeScanner::namespacedFunctionsIn("<?php\nnamespace App;\nfunction Grep() {}\n", ['grep']));
    }

    /**
     * A function of the global namespace is the global function itself: it
     * shadows nothing.
     */
    #[Test]
    public function test_a_global_function_is_no_namespaced_function(): void
    {
        $this->assertSame([], PatternAttributeScanner::namespacedFunctionsIn("<?php\nfunction grep() {}\n", ['grep']));
    }

    /**
     * An editor's text may be half typed: a marked parameter with no name
     * yet does not take the name of the next function's parameter.
     */
    #[Test]
    public function test_a_marked_parameter_with_no_variable_has_no_name(): void
    {
        $content = "<?php\nnamespace App;\nuse PHPRegex\\Parser\\Attribute\\RegexPattern;\nfunction grep(#[RegexPattern]) {}\nfunction other(\$subject) {}\n";

        $this->assertSame(['App\grep#0'], PatternAttributeScanner::scan($content));
    }

    /**
     * Measured with PHP 8.4.27: 4,000 unterminated declarations took 4.7 s
     * when each walk read the rest of the document, 0.02 s now; the ceiling
     * sits between, far from both.
     */
    #[Test]
    public function test_unterminated_declarations_do_not_rescan_the_remaining_source(): void
    {
        $content = "<?php\nuse PHPRegex\\Parser\\Attribute\\RegexPattern;\n"
            ."function first(#[RegexPattern] \$pattern) {}\n"
            .str_repeat("function broken(\n", 4000)
            .'function last(#[RegexPattern] $pattern) {}';
        $start = hrtime(true);

        $this->assertSame(['first#0$pattern', 'last#0$pattern'], PatternAttributeScanner::scan($content));
        $this->assertLessThan(1.0, (hrtime(true) - $start) / 1e9, 'Malformed declarations must not repeatedly traverse the suffix.');
    }

    /**
     * Measured with PHP 8.4.27: 500 declarations opening an attribute took
     * 21.8 s ("Language('SQL',") when its lookaheads read the rest of the
     * document, 0.02 s now.
     */
    #[Test]
    #[DataProvider('provideUnclosedAttributes')]
    public function test_malformed_attribute_lookaheads_share_the_work_limit(string $attribute): void
    {
        $content = "<?php\n".str_repeat('function broken('.$attribute."\n", 500);
        $start = hrtime(true);

        // Incomplete parameters may be reported without a name.
        foreach (PatternAttributeScanner::scan($content) as $spec) {
            $this->assertSame('broken#0', $spec);
        }
        $this->assertLessThan(1.0, (hrtime(true) - $start) / 1e9, 'Attribute helpers must share the declaration work limit.');
    }

    /**
     * @return iterable<string, array{attribute: string}>
     */
    public static function provideUnclosedAttributes(): iterable
    {
        yield 'the pattern attribute' => ['attribute' => '#[\\PHPRegex\\Parser\\Attribute\\RegexPattern]'];
        yield 'a language attribute with an open argument list' => ['attribute' => "#[\\JetBrains\\PhpStorm\\Language('SQL',"];
    }

    /**
     * What marks a parameter as a pattern, read the same way by the token
     * scanner and by the static analysis rule: #[RegexPattern], or
     * PhpStorm's #[Language] given "RegExp", the names compared without
     * case and with or without their leading backslash.
     *
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideAttributes')]
    public function test_marks_tells_a_pattern_attribute(string $name, array $arguments, bool $expected): void
    {
        $this->assertSame($expected, PatternAttributeScanner::marks($name, $arguments));
    }

    /**
     * @return iterable<string, array{name: string, arguments: list<string>, expected: bool}>
     */
    public static function provideAttributes(): iterable
    {
        yield 'the library attribute' => ['name' => RegexPattern::class, 'arguments' => [], 'expected' => true];
        yield 'the library attribute, fully qualified' => ['name' => '\\'.RegexPattern::class, 'arguments' => [], 'expected' => true];
        yield 'the library attribute, lower case' => ['name' => 'phpregex\parser\attribute\regexpattern', 'arguments' => [], 'expected' => true];
        yield 'PhpStorm RegExp' => ['name' => 'JetBrains\PhpStorm\Language', 'arguments' => ['RegExp'], 'expected' => true];
        yield 'PhpStorm regexp, any case' => ['name' => '\jetbrains\phpstorm\LANGUAGE', 'arguments' => ['REGEXP'], 'expected' => true];
        yield 'PhpStorm another language' => ['name' => 'JetBrains\PhpStorm\Language', 'arguments' => ['SQL'], 'expected' => false];
        yield 'PhpStorm with no language' => ['name' => 'JetBrains\PhpStorm\Language', 'arguments' => [], 'expected' => false];
        yield 'RegExp given another attribute' => ['name' => 'App\Language', 'arguments' => ['RegExp'], 'expected' => false];
        yield 'a short name is no import' => ['name' => 'RegexPattern', 'arguments' => [], 'expected' => false];
    }
}
