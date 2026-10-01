<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Lint;

use PhpRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PhpRegex\Tests\Support\LintFunctionOverrides;
use PHPUnit\Framework\TestCase;

final class TokenBasedExtractionStrategyTokenHelperTest extends TestCase
{
    protected function tearDown(): void
    {
        LintFunctionOverrides::reset();
    }

    public function test_read_identifier_token_reads_reserved_words_used_as_method_names(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        // Preg::match() tokenizes "match" as T_MATCH, not T_STRING.
        $this->assertSame('match', $this->invoke($strategy, 'readIdentifierToken', [\T_MATCH, 'match', 1]));
        $this->assertSame('matchAll', $this->invoke($strategy, 'readIdentifierToken', [\T_STRING, 'matchAll', 1]));
        $this->assertSame('list', $this->invoke($strategy, 'readIdentifierToken', [\T_LIST, 'list', 1]));
    }

    public function test_read_identifier_token_rejects_non_identifiers(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        $this->assertNull($this->invoke($strategy, 'readIdentifierToken', '('));
        $this->assertNull($this->invoke($strategy, 'readIdentifierToken', [\T_VARIABLE, '$method', 1]));
        $this->assertNull($this->invoke($strategy, 'readIdentifierToken', [\T_LNUMBER, '1', 1]));
    }

    public function test_read_name_token_returns_null_for_string_token(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        $this->assertNull($this->invoke($strategy, 'readNameToken', 'foo'));
    }

    public function test_read_name_token_handles_name_variants(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        if (\defined('T_NAME_FULLY_QUALIFIED')) {
            $this->assertSame('\\Foo\\Bar', $this->invoke($strategy, 'readNameToken', [\T_NAME_FULLY_QUALIFIED, '\\Foo\\Bar', 1]));
        }

        if (\defined('T_NAME_RELATIVE')) {
            $this->assertSame('namespace\\Foo', $this->invoke($strategy, 'readNameToken', [\T_NAME_RELATIVE, 'namespace\\Foo', 1]));
        }
    }

    public function test_is_double_arrow_token_accepts_string(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        $this->assertTrue($this->invoke($strategy, 'isDoubleArrowToken', '=>'));
    }

    public function test_next_and_previous_significant_token_index_return_null(): void
    {
        $strategy = new TokenBasedExtractionStrategy();
        $tokens = [
            [\T_WHITESPACE, ' ', 1],
            [\T_COMMENT, '//', 1],
        ];

        $this->assertNull($this->invoke($strategy, 'nextSignificantTokenIndex', $tokens, 0, \count($tokens)));
        $this->assertNull($this->invoke($strategy, 'previousSignificantTokenIndex', $tokens, \count($tokens) - 1));
    }

    public function test_closing_token_for_brace(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        $this->assertSame('}', $this->invoke($strategy, 'closingTokenFor', '{'));
    }

    public function test_strip_outer_parentheses_branches(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        $this->assertSame([[], []], $this->invoke($strategy, 'stripOuterParentheses', [], []));

        $tokens = ['(', 'a', ')'];
        $this->assertSame([['a'], [1]], $this->invoke($strategy, 'stripOuterParentheses', $tokens, array_keys($tokens)));

        $tokens = [
            [\T_WHITESPACE, ' ', 1],
        ];
        $this->assertSame([$tokens, [0]], $this->invoke($strategy, 'stripOuterParentheses', $tokens, array_keys($tokens)));

        $tokens = ['(', 'a', ')', '(', 'b', ')'];
        $this->assertSame([$tokens, array_keys($tokens)], $this->invoke($strategy, 'stripOuterParentheses', $tokens, array_keys($tokens)));
    }

    public function test_find_array_start_index_variants(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        $tokens = ['[', [\T_CONSTANT_ENCAPSED_STRING, "'/a/'", 1], ']'];
        $this->assertSame(0, $this->invoke($strategy, 'findArrayStartIndex', $tokens));

        $tokens = [
            [\T_ARRAY, 'array', 1],
            '(',
            [\T_CONSTANT_ENCAPSED_STRING, "'/a/'", 1],
            ')',
        ];
        $this->assertSame(1, $this->invoke($strategy, 'findArrayStartIndex', $tokens));

        $tokens = [[\T_STRING, 'foo', 1]];
        $this->assertNull($this->invoke($strategy, 'findArrayStartIndex', $tokens));

        $tokens = [
            [\T_WHITESPACE, ' ', 1],
        ];
        $this->assertNull($this->invoke($strategy, 'findArrayStartIndex', $tokens));
    }

    public function test_ensure_valid_utf8_handles_null_and_conversion(): void
    {
        $strategy = new TokenBasedExtractionStrategy();

        $this->assertSame('hello', $this->invoke($strategy, 'ensureValidUtf8', 'hello'));
        $this->assertNull($this->invoke($strategy, 'ensureValidUtf8', "a\0b"));

        $latin1 = "\xE9";
        $converted = $this->invoke($strategy, 'ensureValidUtf8', $latin1);
        $this->assertIsString($converted);

        $latin1WithNull = "\xE9\0";
        $this->assertNull($this->invoke($strategy, 'ensureValidUtf8', $latin1WithNull));
    }

    public function test_ensure_valid_utf8_returns_null_when_conversion_fails(): void
    {
        LintFunctionOverrides::$mbCheckEncodingResult = false;
        LintFunctionOverrides::$mbConvertEncodingResult = false;

        $strategy = new TokenBasedExtractionStrategy();

        $this->assertNull($this->invoke($strategy, 'ensureValidUtf8', 'data'));
    }

    private function invoke(TokenBasedExtractionStrategy $strategy, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionClass($strategy);
        $refMethod = $ref->getMethod($method);

        return $refMethod->invoke($strategy, ...$args);
    }
}
