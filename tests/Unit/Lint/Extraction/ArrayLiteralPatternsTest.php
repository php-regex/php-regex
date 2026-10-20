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

use PHPRegex\Linter\Extraction\ArrayLiteralPatterns;
use PHPRegex\Linter\Extraction\PatternFunction;
use PHPRegex\Linter\Extraction\PhpTokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which items of an array literal hold the patterns of a call: its values,
 * its keys, or, for a call that takes either, the keys when the first key
 * PHP stores is a string.
 */
final class ArrayLiteralPatternsTest extends TestCase
{
    /**
     * @return iterable<string, array{array: string, reads: string, items: list<string>}>
     */
    public static function provideArrays(): iterable
    {
        yield 'a list' => ['array' => "['/a/', '/b/']", 'reads' => 'values', 'items' => ["'/a/'", "'/b/'"]];
        yield 'array()' => ['array' => "array('/a/', '/b/')", 'reads' => 'values', 'items' => ["'/a/'", "'/b/'"]];
        yield 'array() with a space' => ['array' => "array ('/a/')", 'reads' => 'values', 'items' => ["'/a/'"]];
        yield 'the values of keyed items' => ['array' => "['x' => '/a/', 'y' => '/b/']", 'reads' => 'values', 'items' => ["'/a/'", "'/b/'"]];
        yield 'the keys' => ['array' => "['/a/' => \$f, '/b/' => \$g]", 'reads' => 'keys', 'items' => ["'/a/'", "'/b/'"]];
        yield 'the keys, an item with none' => ['array' => "['/a/' => 1, '/b/']", 'reads' => 'keys', 'items' => ["'/a/'"]];
        yield 'the keys, a closure value' => ['array' => "['/a/' => function () { return [1, 2]; }, '/b/' => 2]", 'reads' => 'keys', 'items' => ["'/a/'", "'/b/'"]];
        yield 'a string key' => ['array' => "['/a/' => 'x']", 'reads' => 'either', 'items' => ["'/a/'"]];
        yield 'a string key decides for the rest' => ['array' => "['/a/' => 'x', 1 => '/b/']", 'reads' => 'either', 'items' => ["'/a/'", '1']];
        yield 'an int key' => ['array' => "[1 => '/a/']", 'reads' => 'either', 'items' => ["'/a/'"]];
        yield 'a signed int key' => ['array' => "[-1 => '/a/', +2 => '/b/']", 'reads' => 'either', 'items' => ["'/a/'", "'/b/'"]];
        yield 'a float key' => ['array' => "[1.5 => '/a/']", 'reads' => 'either', 'items' => ["'/a/'"]];
        yield 'a bool key' => ['array' => "[\\false => '/a/']", 'reads' => 'either', 'items' => ["'/a/'"]];
        yield 'a numeric string key' => ['array' => "['1' => '/a/']", 'reads' => 'either', 'items' => ["'/a/'"]];
        yield 'a key in parentheses' => ['array' => "[('/a/') => 'x']", 'reads' => 'either', 'items' => ["('/a/')"]];
        yield 'a key that is no literal' => ['array' => "[\$k => '/a/']", 'reads' => 'either', 'items' => ['$k']];
        yield 'a signed name key' => ['array' => "[-true => '/a/']", 'reads' => 'either', 'items' => ['-true']];
        yield 'no key' => ['array' => "['/a/', '/b/']", 'reads' => 'either', 'items' => ["'/a/'", "'/b/'"]];
        yield 'nested arrays' => ['array' => "[['/x/'], array('/y/'), '/b/']", 'reads' => 'values', 'items' => ["['/x/']", "array('/y/')", "'/b/'"]];
        yield 'an interpolation and an attribute' => ['array' => '["/{$a}/", #[A] function () {}]', 'reads' => 'values', 'items' => ['"/{$a}/"', '#[A] function () {}']];
        yield 'a trailing comma' => ['array' => "['/a/',]", 'reads' => 'values', 'items' => ["'/a/'", '']];
        yield 'a comment' => ['array' => "[/* first */ '/a/']", 'reads' => 'values', 'items' => ["/* first */ '/a/'"]];
        yield 'an array left open' => ['array' => "['/a/', '/b/'", 'reads' => 'values', 'items' => ["'/a/'"]];
        yield 'no array' => ['array' => '$patterns', 'reads' => 'values', 'items' => []];
    }

    /**
     * @param list<string> $items
     */
    #[Test]
    #[DataProvider('provideArrays')]
    public function test_segments_are_the_items_holding_the_patterns(string $array, string $reads, array $items): void
    {
        $function = match ($reads) {
            'values' => new PatternFunction('preg_replace'),
            'keys' => new PatternFunction('preg_replace_callback_array', keysArePatterns: true),
            default => new PatternFunction('replace', 1, keysArePatterns: true, replacementIndex: 2),
        };
        [$tokens, $indexes, $offsets, $content] = $this->tokens($array);

        $texts = [];
        foreach (ArrayLiteralPatterns::segments($tokens, $indexes, $offsets, $content, $function) as [$segment, $segmentIndexes]) {
            $this->assertCount(\count($segment), $segmentIndexes);
            $texts[] = trim(implode('', array_map(static fn (array|string $token): string => \is_array($token) ? $token[1] : $token, $segment)));
        }

        $this->assertSame($items, $texts);
    }

    #[Test]
    public function test_segments_keep_the_file_index_of_each_token(): void
    {
        [$tokens, $indexes, $offsets, $content] = $this->tokens("['/a/' , \$b]");

        $segments = ArrayLiteralPatterns::segments($tokens, $indexes, $offsets, $content, new PatternFunction('preg_replace'));

        // <?php, [, '/a/', ' ', ',', ' ', $b, ]
        $this->assertSame([[[\T_CONSTANT_ENCAPSED_STRING, "'/a/'", 1], [\T_WHITESPACE, ' ', 1]], [2, 3]], $segments[0]);
        $this->assertSame([[[\T_WHITESPACE, ' ', 1], [\T_VARIABLE, '$b', 1]], [5, 6]], $segments[1]);
    }

    #[Test]
    public function test_start_index_is_the_bracket_opening_the_items(): void
    {
        $this->assertSame(0, ArrayLiteralPatterns::startIndex(['[', [\T_CONSTANT_ENCAPSED_STRING, "'/a/'", 1], ']']));
        $this->assertSame(1, ArrayLiteralPatterns::startIndex([[\T_WHITESPACE, ' ', 1], '[', ']']));
        $this->assertSame(2, ArrayLiteralPatterns::startIndex([[\T_ARRAY, 'array', 1], [\T_WHITESPACE, ' ', 1], '(', ')']));
        $this->assertNull(ArrayLiteralPatterns::startIndex([[\T_ARRAY, 'array', 1]]));
        $this->assertNull(ArrayLiteralPatterns::startIndex([[\T_ARRAY, 'array', 1], '[']));
        $this->assertNull(ArrayLiteralPatterns::startIndex([[\T_STRING, 'foo', 1]]));
        $this->assertNull(ArrayLiteralPatterns::startIndex(['(', '[', ']', ')']));
        $this->assertNull(ArrayLiteralPatterns::startIndex([[\T_WHITESPACE, ' ', 1]]));
        $this->assertNull(ArrayLiteralPatterns::startIndex([]));
    }

    /**
     * The tokens of the expression in a file, after "<?php".
     *
     * @return array{list<array{int, string, int}|string>, list<int>, array<int, int>, string}
     */
    private function tokens(string $expression): array
    {
        $content = '<?php '.$expression;
        $all = token_get_all($content);
        [$tokens, $indexes] = PhpTokens::slice($all, 1, \count($all));

        return [$tokens, $indexes, PhpTokens::offsets($all), $content];
    }
}
