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
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The tokenizer reads what PHP cannot parse: an editor buffer being typed,
 * a file the parser refused. A source cut short or malformed after a
 * complete call still gives that call's pattern, and the reading of names
 * neither stops on the broken part nor invents a value from it.
 */
final class TruncatedSourceTest extends TestCase
{
    use TemporaryProject;

    private const HEAD = <<<'PHP'
        <?php

        preg_match('/complete/', $subject);

        function resolution(string $s): int
        {
            $re = '/resolved/';

            return preg_match($re, $s);
        }


        PHP;

    /**
     * @return iterable<string, array{tail: string}>
     */
    public static function provideBrokenTails(): iterable
    {
        yield 'a constant with no value' => ['tail' => 'const A = ;'];
        yield 'a constant of empty parentheses' => ['tail' => 'const B = ();'];
        yield 'a constant of a lone operator' => ['tail' => 'const C = ?;'];
        yield 'a constant array with a hole' => ['tail' => 'const D = [1, , 2];'];
        yield 'a constant with no name' => ['tail' => 'const = 1;'];
        yield 'a constant cut short' => ['tail' => "const Z = 'a'"];
        yield 'a heredoc cut short' => ['tail' => "const H = <<<'X'\nabc"];
        yield 'a class with no body' => ['tail' => 'class E;'];
        yield 'a class left open' => ['tail' => 'class F {'];
        yield 'a trait use left open in a class' => ['tail' => 'class G { use T }'];
        yield 'an arrow function with no arrow' => ['tail' => '$g = fn($x);'];
        yield 'an arrow function cut short' => ['tail' => '$h = fn() => 1'];
        yield 'a catch cut short' => ['tail' => 'try {} catch'];
        yield 'an unset with no parenthesis' => ['tail' => 'unset;'];
        yield 'a foreach cut short' => ['tail' => 'foreach'];
        yield 'a call left open' => ['tail' => 'normalize($re'];
        yield 'static called' => ['tail' => 'static($re);'];
        yield 'an attribute called' => ['tail' => '#[A]($re);'];
        yield 'an unbalanced parenthesis assigned' => ['tail' => ') = 1;'];
        yield 'an unbalanced bracket assigned' => ['tail' => '] = 1;'];
        yield 'a use function cut short' => ['tail' => 'use function'];
        yield 'a reference cut short' => ['tail' => '$x = &$y'];
        yield 'a reference to an empty member' => ['tail' => '$x = &$y->;'];
        yield 'an attribute left open' => ['tail' => '#[A'];
        yield 'a use cut short' => ['tail' => 'use'];
        yield 'a filter option named with no value' => ['tail' => 'filter_var($v, FILTER_VALIDATE_REGEXP, options: );'];
        yield 'a filter option of a bare array keyword' => ['tail' => 'filter_var($v, FILTER_VALIDATE_REGEXP, array);'];
    }

    #[Test]
    #[DataProvider('provideBrokenTails')]
    public function test_a_broken_tail_leaves_the_complete_calls_read(string $tail): void
    {
        $directory = $this->makeProject(['buffer.php' => self::HEAD.$tail]);

        $patterns = [];
        foreach ((new TokenBasedExtractionStrategy([], PatternFunctionRegistry::defaults()))->extract([$directory.'/buffer.php']) as $occurrence) {
            $patterns[] = $occurrence->pattern;
        }

        $this->assertContains('/complete/', $patterns);
        $this->assertSame([], array_diff($patterns, ['/complete/', '/resolved/']), 'A pattern was read from the broken part.');
    }

    /**
     * A file ending inside a call's arguments: the walks stop at the last
     * token, and an argument list left open gives no argument. Neither a
     * warning nor an error.
     *
     * @return iterable<string, array{code: string}>
     */
    public static function provideFilesEndingInsideACall(): iterable
    {
        yield 'a filter option array left open' => ['code' => "<?php\nfilter_var(\$v, FILTER_VALIDATE_REGEXP, ["];
        yield 'a Regex constraint array left open' => ['code' => "<?php\nuse Symfony\\Component\\Validator\\Constraints\\Regex;\nnew Regex(["];
        yield 'a nested filter option array left open' => ['code' => "<?php\nfilter_var(\$v, FILTER_VALIDATE_REGEXP, [\"options\" => ["];
        yield 'a callback left open after a map of patterns' => ['code' => "<?php\nuse Nette\\Utils\\Strings;\nStrings::replace(\$s, ['k' => '/x/'], function ("];
        yield 'a concatenation of parenthesised literals left open' => ['code' => "<?php\npreg_match(((('/x/')) ."];
    }

    #[Test]
    #[DataProvider('provideFilesEndingInsideACall')]
    public function test_a_file_ending_inside_a_call_reads_nothing(string $code): void
    {
        $directory = $this->makeProject(['buffer.php' => $code]);
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $occurrences = (new TokenBasedExtractionStrategy([], PatternFunctionRegistry::defaults()))->extract([$directory.'/buffer.php']);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $occurrences);
    }

    /**
     * An attribute with no parameter after it names none; a parameter list
     * cut short declares nothing.
     *
     * @return iterable<string, array{code: string, expected: list<string>}>
     */
    public static function provideAttributesWithNoParameter(): iterable
    {
        yield 'the list closing' => ['code' => "<?php\nfunction f(#[\\PHPRegex\\Parser\\Attribute\\RegexPattern]) {}", 'expected' => ['f#0']];
        yield 'another parameter following' => ['code' => "<?php\nfunction g(#[\\PHPRegex\\Parser\\Attribute\\RegexPattern], \$x) {}", 'expected' => ['g#0']];
        yield 'a parameter list cut short' => ['code' => "<?php\nfunction h(string \$x", 'expected' => []];
        yield 'the file ending before the marked variable' => ['code' => "<?php\nfunction k(#[\\PHPRegex\\Parser\\Attribute\\RegexPattern] string", 'expected' => ['k#0']];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideAttributesWithNoParameter')]
    public function test_an_attribute_marking_no_parameter_gives_no_name(string $code, array $expected): void
    {
        $this->assertSame($expected, PatternAttributeScanner::scan($code));
    }
}
