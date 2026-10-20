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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The token helpers both token readers share: a string literal is read as
 * PHP itself reads it, and a name, an identifier, a bracket and the
 * significant tokens around an index as the tokenizer lays them out.
 */
final class PhpTokensTest extends TestCase
{
    /**
     * @return iterable<string, array{source: string}>
     */
    public static function provideStringLiterals(): iterable
    {
        yield 'single quotes' => ['source' => "'/a+/'"];
        yield 'single quotes, escaped quote and backslash' => ['source' => "'\\'\\\\\\d'"];
        yield 'single quotes, a lone backslash before a letter' => ['source' => "'\\n\\t'"];
        yield 'double quotes, simple escapes' => ['source' => '"\\n\\t\\r\\v\\e\\f\\\\\\$\\""'];
        yield 'double quotes, octal' => ['source' => '"\\0\\7\\101\\1234\\377"'];
        yield 'double quotes, hexadecimal' => ['source' => '"\\x41\\X41\\xf\\xFFF\\x\\xg"'];
        yield 'double quotes, unicode' => ['source' => '"\\u{41}\\u{7FF}\\u{FFFF}\\u{10FFFF}\\u{D800}\\u"'];
        yield 'double quotes, regex escapes kept' => ['source' => '"/\\d+\\.\\/\\w\\8\\9/"'];
        yield 'double quotes, a single quote inside' => ['source' => '"it\'s"'];
        yield 'binary prefix' => ['source' => "b'/a/'"];
        yield 'binary prefix, upper case, double quotes' => ['source' => 'B"/\\x41/"'];
        yield 'empty' => ['source' => '""'];
        yield 'raw bytes' => ['source' => "'\xC3\xA9\x00'"];
    }

    /**
     * Each literal is one T_CONSTANT_ENCAPSED_STRING whose value is the one
     * PHP evaluates it to.
     */
    #[Test]
    #[DataProvider('provideStringLiterals')]
    public function test_string_value_reads_a_literal_as_php_does(string $source): void
    {
        $tokens = token_get_all('<?php '.$source.';');
        $this->assertIsArray($tokens[1]);
        $this->assertSame(\T_CONSTANT_ENCAPSED_STRING, $tokens[1][0]);
        $this->assertSame($source, $tokens[1][1]);

        $this->assertSame(eval('return '.$source.';'), PhpTokens::stringValue($tokens[1][1]));
    }

    #[Test]
    public function test_string_value_reads_no_quoted_literal_as_empty(): void
    {
        $this->assertSame('', PhpTokens::stringValue('abc'));
        $this->assertSame('', PhpTokens::stringValue(''));
    }

    #[Test]
    public function test_is_ignorable_holds_whitespace_and_comments_only(): void
    {
        $this->assertTrue(PhpTokens::isIgnorable([\T_WHITESPACE, ' ', 1]));
        $this->assertTrue(PhpTokens::isIgnorable([\T_COMMENT, '// x', 1]));
        $this->assertTrue(PhpTokens::isIgnorable([\T_DOC_COMMENT, '/** x */', 1]));
        $this->assertFalse(PhpTokens::isIgnorable([\T_STRING, 'foo', 1]));
        $this->assertFalse(PhpTokens::isIgnorable(' '));
    }

    #[Test]
    public function test_opens_nesting_holds_every_opening_bracket(): void
    {
        foreach (['(', '[', '{', [\T_CURLY_OPEN, '{', 1], [\T_DOLLAR_OPEN_CURLY_BRACES, '${', 1], [\T_ATTRIBUTE, '#[', 1]] as $token) {
            $this->assertTrue(PhpTokens::opensNesting($token));
        }

        foreach ([')', ']', '}', ',', [\T_STRING, 'foo', 1]] as $token) {
            $this->assertFalse(PhpTokens::opensNesting($token));
        }
    }

    #[Test]
    public function test_name_reads_every_spelling_of_a_name(): void
    {
        $this->assertSame('Foo', PhpTokens::name([\T_STRING, 'Foo', 1]));
        $this->assertSame('Foo\\Bar', PhpTokens::name([\T_NAME_QUALIFIED, 'Foo\\Bar', 1]));
        $this->assertSame('\\Foo', PhpTokens::name([\T_NAME_FULLY_QUALIFIED, '\\Foo', 1]));
        $this->assertSame('namespace\\Foo', PhpTokens::name([\T_NAME_RELATIVE, 'namespace\\Foo', 1]));
        $this->assertNull(PhpTokens::name([\T_MATCH, 'match', 1]));
        $this->assertNull(PhpTokens::name([\T_VARIABLE, '$a', 1]));
        $this->assertNull(PhpTokens::name('foo'));
    }

    #[Test]
    public function test_identifier_reads_reserved_words_and_rejects_the_rest(): void
    {
        $this->assertSame('match', PhpTokens::identifier([\T_MATCH, 'match', 1]));
        $this->assertSame('list', PhpTokens::identifier([\T_LIST, 'list', 1]));
        $this->assertSame('_x9', PhpTokens::identifier([\T_STRING, '_x9', 1]));
        $this->assertSame("caf\xC3\xA9", PhpTokens::identifier([\T_INLINE_HTML, "caf\xC3\xA9", 1]));
        $this->assertSame("\xC3\xA9", PhpTokens::identifier([\T_INLINE_HTML, "\xC3\xA9", 1]));
        $this->assertNull(PhpTokens::identifier([\T_VARIABLE, '$method', 1]));
        $this->assertNull(PhpTokens::identifier([\T_LNUMBER, '1', 1]));
        $this->assertNull(PhpTokens::identifier([\T_INLINE_HTML, 'a b', 1]));
        $this->assertNull(PhpTokens::identifier([\T_INLINE_HTML, '', 1]));
        $this->assertNull(PhpTokens::identifier('('));
    }

    #[Test]
    public function test_is_object_or_static_operator_holds_the_three_operators(): void
    {
        $this->assertTrue(PhpTokens::isObjectOrStaticOperator([\T_OBJECT_OPERATOR, '->', 1]));
        $this->assertTrue(PhpTokens::isObjectOrStaticOperator([\T_NULLSAFE_OBJECT_OPERATOR, '?->', 1]));
        $this->assertTrue(PhpTokens::isObjectOrStaticOperator([\T_DOUBLE_COLON, '::', 1]));
        $this->assertFalse(PhpTokens::isObjectOrStaticOperator([\T_DOUBLE_ARROW, '=>', 1]));
        $this->assertFalse(PhpTokens::isObjectOrStaticOperator('.'));
    }

    #[Test]
    public function test_is_double_arrow_and_double_colon_hold_their_operator_only(): void
    {
        $this->assertTrue(PhpTokens::isDoubleArrow([\T_DOUBLE_ARROW, '=>', 1]));
        $this->assertTrue(PhpTokens::isDoubleArrow('=>'));
        $this->assertFalse(PhpTokens::isDoubleArrow([\T_DOUBLE_COLON, '::', 1]));
        $this->assertFalse(PhpTokens::isDoubleArrow('='));

        $this->assertTrue(PhpTokens::isDoubleColon([\T_DOUBLE_COLON, '::', 1]));
        $this->assertFalse(PhpTokens::isDoubleColon([\T_DOUBLE_ARROW, '=>', 1]));
        $this->assertFalse(PhpTokens::isDoubleColon('::'));
    }

    #[Test]
    public function test_next_and_previous_significant_skip_whitespace_and_comments(): void
    {
        $tokens = token_get_all("<?php a /* c */ b // d\n");
        // <?php , a, ' ', /* c */, ' ', b, ' ', // d
        $this->assertSame(1, PhpTokens::nextSignificant($tokens, 1, \count($tokens)));
        $this->assertSame(5, PhpTokens::nextSignificant($tokens, 2, \count($tokens)));
        $this->assertNull(PhpTokens::nextSignificant($tokens, 2, 5));
        $this->assertNull(PhpTokens::nextSignificant($tokens, 6, \count($tokens)));
        // A range a file cut short leaves open stops at the last token.
        $this->assertNull(PhpTokens::nextSignificant($tokens, 6, \PHP_INT_MAX));

        $this->assertSame(5, PhpTokens::previousSignificant($tokens, 7));
        $this->assertSame(5, PhpTokens::previousSignificant($tokens, 100));
        $this->assertSame(1, PhpTokens::previousSignificant($tokens, 4));
        $this->assertNull(PhpTokens::previousSignificant($tokens, 4, 2));
        $this->assertSame(0, PhpTokens::previousSignificant($tokens, 0));
        $this->assertNull(PhpTokens::previousSignificant($tokens, -1));
    }

    #[Test]
    public function test_closers_pairs_each_bracket_and_leaves_an_open_one_out(): void
    {
        $tokens = token_get_all('<?php f([1], "{$a}", #[A] (2)); g(');
        $closers = PhpTokens::closers($tokens);

        foreach ($closers as $open => $close) {
            $this->assertTrue(PhpTokens::opensNesting($tokens[$open]));
            $this->assertContains($tokens[$close], [')', ']', '}']);
        }

        // f ( [ 1 ] , "{$a}" , #[A] ( 2 ) ) ; g ( : the last stays open.
        $this->assertSame([3 => 5, 9 => 11, 15 => 17, 19 => 21, 2 => 22], $closers);
        $this->assertSame([], PhpTokens::closers(token_get_all('<?php ) ] }')));
    }

    #[Test]
    public function test_offsets_are_the_byte_offset_of_each_token(): void
    {
        $content = "<?php\n\$a = 'é';";
        $tokens = token_get_all($content);
        $offsets = PhpTokens::offsets($tokens);

        $this->assertCount(\count($tokens), $offsets);
        foreach ($tokens as $index => $token) {
            $text = \is_array($token) ? $token[1] : $token;
            $this->assertSame($text, substr($content, $offsets[$index], \strlen($text)));
        }
        $this->assertSame([], PhpTokens::offsets([]));
    }

    #[Test]
    public function test_slice_lists_the_tokens_and_their_indexes(): void
    {
        $tokens = ['a', 'b', 'c', 'd'];

        $this->assertSame([['b', 'c'], [1, 2]], PhpTokens::slice($tokens, 1, 3));
        // An end past the last token stops there.
        $this->assertSame([['c', 'd'], [2, 3]], PhpTokens::slice($tokens, 2, 10));
        $this->assertSame([[], []], PhpTokens::slice($tokens, 2, 2));
        $this->assertSame([[], []], PhpTokens::slice($tokens, 3, 1));
    }
}
