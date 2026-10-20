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

use PHPRegex\Linter\Extraction\PhpTokens;
use PHPRegex\Linter\Extraction\StringExpressionReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The string an expression of literals folds to, as PHP evaluates it, and
 * where in the file it starts.
 */
final class StringExpressionReaderTest extends TestCase
{
    /**
     * @return iterable<string, array{expression: string}>
     */
    public static function provideConstantExpressions(): iterable
    {
        yield 'a literal' => ['expression' => "'/a+/'"];
        yield 'double quotes' => ['expression' => '"/\\d+\\x41/"'];
        yield 'a binary string' => ['expression' => "b'/a/'"];
        yield 'a concatenation' => ['expression' => "'/a' . '+/' . \"i\""];
        yield 'parentheses around a part' => ['expression' => "('/a/') . 'i'"];
        yield 'parentheses around it all' => ['expression' => "(('/a/'))"];
        yield 'a comment between the parts' => ['expression' => "'/a/' /* flags */ . 'i'"];
        yield 'a heredoc' => ['expression' => "<<<RE\n    /\\x41+/\n    RE"];
        yield 'a nowdoc' => ['expression' => "<<<'RE'\n/\\x41+/\nRE"];
        yield 'a heredoc after a literal' => ['expression' => "'/a' . <<<RE\n+/\nRE"];
        yield 'an empty heredoc' => ['expression' => "<<<RE\nRE"];
    }

    /**
     * Each expression folds to the string PHP evaluates it to.
     */
    #[Test]
    #[DataProvider('provideConstantExpressions')]
    public function test_read_folds_an_expression_as_php_evaluates_it(string $expression): void
    {
        $read = $this->read($expression);

        $this->assertNotNull($read);
        $this->assertSame(eval('return '.$expression.';'), $read['pattern']);
    }

    /**
     * @return iterable<string, array{expression: string}>
     */
    public static function provideOtherExpressions(): iterable
    {
        yield 'a variable' => ['expression' => '$re'];
        yield 'a literal and a variable' => ['expression' => "'/a/' . \$flags"];
        yield 'a constant' => ['expression' => 'PATTERN'];
        yield 'a dangling concatenation' => ['expression' => "'/a/' ."];
        yield 'two literals with no operator' => ['expression' => "'/a/' 'i'"];
        yield 'another operator' => ['expression' => "'/a/' + 'i'"];
        yield 'an interpolating heredoc' => ['expression' => "<<<RE\n/\$a/\nRE"];
        yield 'a heredoc indented less than its marker' => ['expression' => "<<<RE\n/a/\n  RE"];
        yield 'an interpolating string' => ['expression' => '"/$a/"'];
        yield 'nothing' => ['expression' => ''];
        yield 'parentheses alone' => ['expression' => '()'];
    }

    #[Test]
    #[DataProvider('provideOtherExpressions')]
    public function test_read_refuses_what_is_no_constant_string(string $expression): void
    {
        $this->assertNull($this->read($expression));
    }

    #[Test]
    public function test_read_places_the_string_at_its_first_token(): void
    {
        $read = $this->read("'/a/' . 'i'", "\n\n  ");

        $this->assertNotNull($read);
        $this->assertSame(['pattern' => '/a/i', 'line' => 3, 'offset' => 10, 'column' => 3], $read);
    }

    /**
     * ('/a/') . 'i' starts at its parenthesis, as php-parser places it,
     * on the line the parenthesis is on.
     */
    #[Test]
    public function test_read_places_a_leading_parenthesis_as_the_start(): void
    {
        $read = $this->read("(\n'/a/') . 'i'", ' ');

        $this->assertNotNull($read);
        $this->assertSame(['pattern' => '/a/i', 'line' => 1, 'offset' => 7, 'column' => 8], $read);
    }

    /**
     * @return iterable<string, array{expression: string, pattern: string|null}>
     */
    public static function providePatterns(): iterable
    {
        yield 'slashes and flags' => ['expression' => "'/a+/im'", 'pattern' => '/a+/im'];
        yield 'braces' => ['expression' => "'{a+}u'", 'pattern' => '{a+}u'];
        yield 'hashes' => ['expression' => "'#a#'", 'pattern' => '#a#'];
        yield 'tildes over a concatenation' => ['expression' => "'~a' . '~x'", 'pattern' => '~a~x'];
        yield 'a closing delimiter of another kind' => ['expression' => "'/a#'", 'pattern' => null];
        yield 'no delimiter' => ['expression' => "'abc'", 'pattern' => null];
        yield 'a flag that is no letter' => ['expression' => "'/a/1'", 'pattern' => null];
        yield 'a newline after the flags' => ['expression' => '"/a/i\\n"', 'pattern' => null];
        yield 'no constant string' => ['expression' => '$re', 'pattern' => null];
    }

    #[Test]
    #[DataProvider('providePatterns')]
    public function test_read_pattern_reads_a_delimited_pattern_only(string $expression, ?string $pattern): void
    {
        [$tokens, $indexes, $offsets, $content] = $this->tokens($expression, '');

        $read = StringExpressionReader::readPattern($tokens, $indexes, $offsets, $content);

        $this->assertSame($pattern, $read['pattern'] ?? null);
    }

    #[Test]
    public function test_strip_parentheses_drops_only_those_around_it_all(): void
    {
        $this->assertSame([[], []], StringExpressionReader::stripParentheses([], []));
        $this->assertSame([['a'], [1]], StringExpressionReader::stripParentheses(['(', 'a', ')'], [0, 1, 2]));
        $this->assertSame([['a'], [2]], StringExpressionReader::stripParentheses(['(', '(', 'a', ')', ')'], [0, 1, 2, 3, 4]));

        $spaced = [[\T_WHITESPACE, ' ', 1], '(', 'a', ')', [\T_WHITESPACE, ' ', 1]];
        $this->assertSame([['a'], [7]], StringExpressionReader::stripParentheses($spaced, [5, 6, 7, 8, 9]));

        foreach ([['(', 'a', ')', '(', 'b', ')'], ['(', 'a'], ['a', ')'], ['(', '(', 'a', ')'], [[\T_WHITESPACE, ' ', 1]]] as $kept) {
            $this->assertSame([$kept, array_keys($kept)], StringExpressionReader::stripParentheses($kept, array_keys($kept)));
        }
    }

    /**
     * @return array{pattern: string, line: int, offset?: int|null, column?: int|null}|null
     */
    private function read(string $expression, string $before = ''): ?array
    {
        [$tokens, $indexes, $offsets, $content] = $this->tokens($expression, $before);

        return StringExpressionReader::read($tokens, $indexes, $offsets, $content);
    }

    /**
     * The tokens of the expression in a file, between "<?php" and ";".
     *
     * @return array{list<array{int, string, int}|string>, list<int>, array<int, int>, string}
     */
    private function tokens(string $expression, string $before): array
    {
        $content = '<?php '.$before.$expression."\n;";
        $all = token_get_all($content);
        $first = \count(token_get_all('<?php '.$before));
        [$tokens, $indexes] = PhpTokens::slice($all, $first, \count($all) - 1);

        return [$tokens, $indexes, PhpTokens::offsets($all), $content];
    }
}
