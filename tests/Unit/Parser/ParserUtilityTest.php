<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Parser;

use PhpRegex\Parser\Exception\ParserException;
use PhpRegex\Parser\Internal\PatternParser;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\ConditionalNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Syntax\TokenParser;
use PhpRegex\Parser\Token\TokenType;
use PhpRegex\Tests\TestUtils\ParserAccessor;
use PhpRegex\Tests\TestUtils\PhpErrorOffset;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the private utility methods of the Parser class.
 */
final class ParserUtilityTest extends TestCase
{
    private ParserAccessor $accessor;

    protected function setUp(): void
    {
        $parser = new TokenParser();
        $this->accessor = new ParserAccessor($parser);
    }

    public function test_extract_pattern_handles_escaped_delimiter_in_flags(): void
    {
        // Regex: /abc\/def/i
        // The slash in the middle is escaped and must not be considered as the ending delimiter.
        [$pattern, $flags, $delimiter] = PatternParser::extractPatternAndFlags('/abc\/def/i');

        $this->assertSame('/', $delimiter);
        $this->assertSame('i', $flags);
        $this->assertSame('abc\/def', $pattern);
    }

    public function test_extract_pattern_handles_alternating_delimiters(): void
    {
        // Regex: (abc)i
        [$pattern, $flags, $delimiter] = PatternParser::extractPatternAndFlags('(abc)i');

        $this->assertSame('(', $delimiter);
        $this->assertSame('i', $flags);
        $this->assertSame('abc', $pattern);
    }

    /**
     * @return \Iterator<string, array{string, string}>
     */
    public static function provideWhitespaceSeparatedFlags(): \Iterator
    {
        yield 'space separated single flag' => ['/a/ i', 'i'];
        yield 'newline before flag' => ["/a/\n i", 'i'];
        yield 'trailing whitespace after flag' => ['/a/i ', 'i'];
        yield 'space separated multiple flags' => ['/a/ i u', 'iu'];
    }

    #[DataProvider('provideWhitespaceSeparatedFlags')]
    public function test_extract_pattern_accepts_whitespace_in_flags(string $regex, string $expectedFlags): void
    {
        [$pattern, $flags, $delimiter] = PatternParser::extractPatternAndFlags($regex);

        $this->assertSame('/', $delimiter);
        $this->assertSame('a', $pattern);
        $this->assertSame($expectedFlags, $flags);
    }

    public function test_extract_pattern_throws_on_malformed_flags(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Unknown regex flag(s) found: "!"');

        PatternParser::extractPatternAndFlags('/abc/i!');
    }

    public function test_extract_pattern_handles_r_modifier_based_on_runtime(): void
    {
        if (self::supportsModifierR()) {
            [$pattern, $flags, $delimiter] = PatternParser::extractPatternAndFlags('/a/r');

            $this->assertSame('/', $delimiter);
            $this->assertSame('a', $pattern);
            $this->assertSame('r', $flags);

            return;
        }

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Unknown regex flag(s) found: "r"');

        PatternParser::extractPatternAndFlags('/a/r');
    }

    public function test_extract_pattern_handles_leading_whitespace_with_paired_delimiter(): void
    {
        // Edge case: Leading whitespace + paired delimiter
        [$pattern, $flags, $delimiter] = PatternParser::extractPatternAndFlags('  {foo}i');

        $this->assertSame('{', $delimiter);
        $this->assertSame('i', $flags);
        $this->assertSame('foo', $pattern);
    }

    public function test_extract_pattern_handles_escaped_delimiter_near_end(): void
    {
        // Edge case: Escaped delimiter near the end: "/a\/b/i" should parse pattern "a\/b"
        [$pattern, $flags, $delimiter] = PatternParser::extractPatternAndFlags('/a\/b/i');

        $this->assertSame('/', $delimiter);
        $this->assertSame('i', $flags);
        $this->assertSame('a\\/b', $pattern);
    }

    public function test_extract_pattern_simple(): void
    {
        [$pattern, $flags, $delimiter] = PatternParser::extractPatternAndFlags('/foo/');

        $this->assertSame('/', $delimiter);
        $this->assertSame('', $flags);
        $this->assertSame('foo', $pattern);
    }

    public function test_extract_pattern_handles_lots_of_backslashes_before_delimiter(): void
    {
        // An even run of backslashes does not escape the delimiter after it:
        // "/foo\\\\/" is the pattern "foo\\\\" (preg_match('/foo\\\\/', 'foo\\') === 1).
        [$pattern, $flags, $delimiter] = PatternParser::extractPatternAndFlags('/foo\\\\/');

        $this->assertSame('/', $delimiter);
        $this->assertSame('', $flags);
        $this->assertSame('foo\\\\', $pattern);
    }

    public function test_extract_pattern_handles_very_long_patterns(): void
    {
        // Edge case: Very long patterns near max_pattern_length
        $longPattern = str_repeat('a', 1000);
        $regex = '/'.$longPattern.'/i';
        [$pattern, $flags, $delimiter] = PatternParser::extractPatternAndFlags($regex);

        $this->assertSame('/', $delimiter);
        $this->assertSame('i', $flags);
        $this->assertSame($longPattern, $pattern);
    }

    public function test_throws_on_quantifier_without_target(): void
    {
        // Le parser appelle parseQuantifiedAtom. Si le premier atom est absent,
        // and the next token is T_QUANTIFIER, it raises the error.
        // Simulate: Token T_QUANTIFIER at the beginning.
        $tokens = [
            $this->accessor->createToken(TokenType::T_QUANTIFIER, '*', 0),
        ];
        $this->accessor->setTokens($tokens);
        $this->accessor->setPosition(0);

        $this->expectException(ParserException::class);
        // PHP reports "/*/" past the "*" from PCRE2 10.47, on it before:
        // "quantifier does not follow a repeatable item".
        $this->expectExceptionMessage(\sprintf('Quantifier without target at position %d', PhpErrorOffset::of('/*/')));

        $this->accessor->callPrivateMethod('parseQuantifiedAtom');
    }

    public function test_parse_atom_throws_on_unexpected_token(): void
    {
        // Simuler un jeton de fermeture de groupe inattendu dans un contexte atomique
        $tokens = [
            $this->accessor->createToken(TokenType::T_GROUP_CLOSE, ')', 0),
            $this->accessor->createToken(TokenType::T_EOF, '', 1),
        ];
        $this->accessor->setTokens($tokens);
        $this->accessor->setPosition(0);

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Unexpected token ")" (group_close) at position 0.');

        $this->accessor->callPrivateMethod('parseAtom');
    }

    public function test_parse_group_modifier_inline_flags_no_colon_valid(): void
    {
        // Regex: /?(im)/. Le parser consomme '('. Puis il consomme '?' (dans parseGroupModifier).
        // Il doit consommer les flags 'i', 'm', puis ')'
        $tokens = [
            $this->accessor->createToken(TokenType::T_LITERAL, 'i', 2),
            $this->accessor->createToken(TokenType::T_LITERAL, 'm', 3),
            $this->accessor->createToken(TokenType::T_GROUP_CLOSE, ')', 4),
        ];
        $this->accessor->setTokens($tokens);
        $this->accessor->setPosition(0); // Position 0 -> Token 'i'

        // The token '(?-' is handled by the upstream parser logic.
        // Ici, on teste l'extraction des flags 'im' sans ':'.
        $node = $this->accessor->callPrivateMethod('parseGroupModifier');

        $this->assertInstanceOf(GroupNode::class, $node);
        $this->assertSame(GroupType::T_GROUP_INLINE_FLAGS, $node->type);
        $this->assertSame('im', $node->flags);
        $this->assertInstanceOf(LiteralNode::class, $node->child);
        $this->assertSame('', $node->child->value, 'Child should be an empty node.');
    }

    public function test_parse_group_modifier_throws_on_invalid_python_syntax(): void
    {
        // Simulate (?P[invalid]) - parseGroupModifier is called after consuming (?
        // Position 0 = '/', 1 = '(', 2 = '?', 3 = 'P', 4 = '['
        $tokens = [
            $this->accessor->createToken(TokenType::T_LITERAL, 'P', 2), // P at position 2
            $this->accessor->createToken(TokenType::T_LITERAL, '[', 3), // [ at position 3
            $this->accessor->createToken(TokenType::T_GROUP_CLOSE, ')', 4),
            $this->accessor->createToken(TokenType::T_EOF, '', 5),
        ];
        $this->accessor->setTokens($tokens);
        $this->accessor->setPosition(0); // Start at 'P'

        $this->accessor->setPattern('(?P[)');

        $this->expectException(ParserException::class);
        // PHP on PCRE2 10.47 and later reports "(?P[)" past the "[":
        // "unrecognized character after (?P at offset 4"; before, on it.
        $this->expectExceptionMessage(\sprintf('Invalid syntax after (?P at position %d', version_compare(explode(' ', \PCRE_VERSION)[0], '10.47', '>=') ? 4 : 3));

        $this->accessor->callPrivateMethod('parseGroupModifier');
    }

    public function test_parse_conditional_lookaround(): void
    {
        // "(?(?=a)b)": the "?" after "(?(" opens the lookahead that is the
        // condition. A group there, "(?((?=a))b)", is no condition: PHP
        // refuses it, "subpattern name expected at offset 3".
        $conditional = Regex::create(['cache' => null])->parse('/(?(?=a)b)/')->pattern;
        $this->assertInstanceOf(ConditionalNode::class, $conditional);

        $condition = $conditional->condition;
        $this->assertInstanceOf(GroupNode::class, $condition);
        $this->assertSame(GroupType::T_GROUP_LOOKAHEAD_POSITIVE, $condition->type);
    }

    public function test_parse_conditional_assertion(): void
    {
        // Simuler (?(DEFINE)...)
        $tokens = [
            $this->accessor->createToken(TokenType::T_LITERAL, 'D', 2),
            $this->accessor->createToken(TokenType::T_LITERAL, 'E', 3),
            $this->accessor->createToken(TokenType::T_LITERAL, 'F', 4),
            $this->accessor->createToken(TokenType::T_LITERAL, 'I', 5),
            $this->accessor->createToken(TokenType::T_LITERAL, 'N', 6),
            $this->accessor->createToken(TokenType::T_LITERAL, 'E', 7),
            $this->accessor->createToken(TokenType::T_GROUP_CLOSE, ')', 8),
            $this->accessor->createToken(TokenType::T_GROUP_CLOSE, ')', 9), // Fermeture externe
        ];
        $this->accessor->setTokens($tokens);
        $this->accessor->setPosition(0);

        // Simulate the state where the parser has consumed '(?('
        $condition = $this->accessor->callPrivateMethod('parseConditionalCondition');

        $this->assertInstanceOf(AssertionNode::class, $condition);
        $this->assertSame('DEFINE', $condition->value);
    }

    public function test_parse_conditional_invalid_atom_throws(): void
    {
        // Simulate (?(.)...) where T_DOT is not a valid condition (should be Backref or Group)
        $tokens = [
            $this->accessor->createToken(TokenType::T_DOT, '.', 2), // Jeton T_DOT
            $this->accessor->createToken(TokenType::T_GROUP_CLOSE, ')', 3),
        ];
        $this->accessor->setTokens($tokens);
        $this->accessor->setPosition(0);

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Invalid conditional construct at position 2. Condition must be a group reference, lookaround, or (DEFINE).');

        $this->accessor->callPrivateMethod('parseConditionalCondition');
    }

    private static function supportsModifierR(): bool
    {
        $modifier = \chr(114);
        $pattern = '/a/'.$modifier;
        $result = @preg_match($pattern, '');

        return false !== $result;
    }
}
