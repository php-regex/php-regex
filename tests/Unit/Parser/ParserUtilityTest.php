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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Internal\PatternParser;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Syntax\TokenParser;
use PHPRegex\Parser\Token\TokenType;
use PHPRegex\Tests\TestUtils\ParserAccessor;
use PHPRegex\Tests\TestUtils\PhpErrorOffset;
use PHPRegex\Toolkit\Regex;
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
            $this->accessor->createToken(TokenType::Quantifier, '*', 0),
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
            $this->accessor->createToken(TokenType::GroupClose, ')', 0),
            $this->accessor->createToken(TokenType::Eof, '', 1),
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
            $this->accessor->createToken(TokenType::Literal, 'i', 2),
            $this->accessor->createToken(TokenType::Literal, 'm', 3),
            $this->accessor->createToken(TokenType::GroupClose, ')', 4),
        ];
        $this->accessor->setTokens($tokens);
        $this->accessor->setPosition(0); // Position 0 -> Token 'i'

        // The token '(?-' is handled by the upstream parser logic.
        // Ici, on teste l'extraction des flags 'im' sans ':'.
        $node = $this->accessor->callPrivateMethod('parseGroupModifier');

        $this->assertInstanceOf(GroupNode::class, $node);
        $this->assertSame(GroupType::InlineFlags, $node->type);
        $this->assertSame('im', $node->flags);
        $this->assertInstanceOf(LiteralNode::class, $node->child);
        $this->assertSame('', $node->child->value, 'Child should be an empty node.');
    }

    public function test_parse_group_modifier_throws_on_invalid_python_syntax(): void
    {
        // Simulate (?P[invalid]) - parseGroupModifier is called after consuming (?
        // Position 0 = '/', 1 = '(', 2 = '?', 3 = 'P', 4 = '['
        $tokens = [
            $this->accessor->createToken(TokenType::Literal, 'P', 2), // P at position 2
            $this->accessor->createToken(TokenType::Literal, '[', 3), // [ at position 3
            $this->accessor->createToken(TokenType::GroupClose, ')', 4),
            $this->accessor->createToken(TokenType::Eof, '', 5),
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
        $this->assertSame(GroupType::LookaheadPositive, $condition->type);
    }

    public function test_parse_conditional_assertion(): void
    {
        // Simuler (?(DEFINE)...)
        $tokens = [
            $this->accessor->createToken(TokenType::Literal, 'D', 2),
            $this->accessor->createToken(TokenType::Literal, 'E', 3),
            $this->accessor->createToken(TokenType::Literal, 'F', 4),
            $this->accessor->createToken(TokenType::Literal, 'I', 5),
            $this->accessor->createToken(TokenType::Literal, 'N', 6),
            $this->accessor->createToken(TokenType::Literal, 'E', 7),
            $this->accessor->createToken(TokenType::GroupClose, ')', 8),
            $this->accessor->createToken(TokenType::GroupClose, ')', 9), // Fermeture externe
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
            $this->accessor->createToken(TokenType::Dot, '.', 2), // Jeton T_DOT
            $this->accessor->createToken(TokenType::GroupClose, ')', 3),
        ];
        $this->accessor->setTokens($tokens);
        $this->accessor->setPosition(0);

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Invalid conditional construct at position 2. Condition must be a group reference, lookaround, or (DEFINE).');

        $this->accessor->callPrivateMethod('parseConditionalCondition');
    }

    /**
     * PHP skips the leading bytes C's isspace() accepts (space, \t, \n, \v,
     * \f, \r) before it reads the delimiter.
     */
    #[DataProvider('provideLeadingWhitespace')]
    public function test_leading_form_feed_and_vertical_tab_are_skipped_like_php(string $regex): void
    {
        // Oracle: the engine accepts the pattern and matches.
        $this->assertSame(1, @preg_match($regex, 'a'));

        [$pattern, $flags, $delimiter] = PatternParser::extractPatternAndFlags($regex);

        $this->assertSame('/', $delimiter);
        $this->assertSame('a', $pattern);
        $this->assertSame('', $flags);
        $this->assertTrue(Regex::create()->validate($regex)->isValid);
    }

    /**
     * @return iterable<string, array{regex: string}>
     */
    public static function provideLeadingWhitespace(): iterable
    {
        yield 'form feed' => ['regex' => "\x0C/a/"];
        yield 'vertical tab' => ['regex' => "\x0B/a/"];
        yield 'every isspace byte' => ['regex' => " \x0C\x0B\t\r\n/a/"];
    }

    /**
     * PHP refuses NUL as a delimiter; it is not whitespace and is never
     * skipped, so a NUL byte before a valid delimiter is the delimiter.
     */
    #[DataProvider('provideNulDelimiter')]
    public function test_a_nul_delimiter_is_refused_like_php(string $regex): void
    {
        // Oracle: preg_match() refuses the pattern.
        $this->assertFalse(@preg_match($regex, 'a'));

        try {
            PatternParser::extractPatternAndFlags($regex);
            $this->fail(\sprintf('%s must be refused.', json_encode($regex)));
        } catch (ParserException $e) {
            $this->assertSame(ErrorCode::DelimiterInvalid, $e->getErrorCode());
            // The NUL byte is the delimiter named, not a character after it.
            $this->assertStringStartsWith('Invalid delimiter "\x00".', $e->getMessage());
        }

        $this->assertSame(ErrorCode::DelimiterInvalid, Regex::create()->validate($regex)->errorCode);
    }

    /**
     * @return iterable<string, array{regex: string}>
     */
    public static function provideNulDelimiter(): iterable
    {
        yield 'NUL before a slash pattern' => ['regex' => "\0/a/"];
        yield 'NUL as both delimiters' => ['regex' => "\0a\0"];
        yield 'NUL after a space' => ['regex' => " \0/a/"];
        yield 'NUL after a form feed' => ['regex' => "\x0C\0/a/"];
        yield 'lone NUL' => ['regex' => "\0"];
    }

    public function test_invalid_delimiter_message_names_nul_not_whitespace(): void
    {
        try {
            PatternParser::extractPatternAndFlags("\0/a/");
            $this->fail('A NUL delimiter must be refused.');
        } catch (ParserException $e) {
            $this->assertSame('Invalid delimiter "\x00". Delimiters must not be alphanumeric, backslash, or NUL byte.', $e->getMessage());
        }

        // An alphanumeric delimiter keeps its suggestion, after the same sentence.
        try {
            PatternParser::extractPatternAndFlags('abc');
            $this->fail('An alphanumeric delimiter must be refused.');
        } catch (ParserException $e) {
            $this->assertSame('Invalid delimiter "a". Delimiters must not be alphanumeric, backslash, or NUL byte. Try #abc#.', $e->getMessage());
        }
    }

    /**
     * Whitespace alone is an empty pattern and whitespace before a lone
     * delimiter leaves it unclosed, as PHP says.
     */
    public function test_leading_form_feed_before_nothing_or_a_lone_delimiter(): void
    {
        foreach (["\x0C" => ErrorCode::PatternEmpty, "\x0C/" => ErrorCode::DelimiterUnclosed] as $regex => $code) {
            // Oracle: preg_match() refuses both ("Empty regular expression",
            // "No ending delimiter '/' found").
            $this->assertFalse(self::compiles($regex));
            $this->assertSame($code, Regex::create()->validate($regex)->errorCode);
        }
    }

    /**
     * After the closing delimiter PHP 8.2-8.4 skip a space, "\n" and "\r"
     * only: a tab, "\v" or "\f" there is an unknown modifier.
     */
    #[DataProvider('provideWhitespaceAfterTheDelimiter')]
    public function test_whitespace_after_the_closing_delimiter_is_read_like_php(string $regex, bool $accepted): void
    {
        // Oracle: preg_match() refuses "Unknown modifier" or accepts.
        $this->assertSame($accepted, false !== self::compiles($regex));

        if ($accepted) {
            $this->assertSame(['a', 'i', '/'], PatternParser::extractPatternAndFlags($regex));
            $this->assertTrue(Regex::create()->validate($regex)->isValid);

            return;
        }

        $this->assertSame(ErrorCode::FlagUnknown, Regex::create()->validate($regex)->errorCode);
    }

    /**
     * @return iterable<string, array{regex: string, accepted: bool}>
     */
    public static function provideWhitespaceAfterTheDelimiter(): iterable
    {
        yield 'tab after a flag' => ['regex' => "/a/i\t", 'accepted' => false];
        yield 'vertical tab' => ['regex' => "/a/\x0B", 'accepted' => false];
        yield 'form feed' => ['regex' => "/a/\f", 'accepted' => false];
        yield 'space, newline and carriage return after a flag' => ['regex' => "/a/i \n\r", 'accepted' => true];
    }

    /**
     * Through a call, so that static analysis does not compile the pattern.
     */
    private static function compiles(string $regex): int|false
    {
        return @preg_match($regex, '');
    }

    private static function supportsModifierR(): bool
    {
        $modifier = \chr(114);
        $pattern = '/a/'.$modifier;
        $result = @preg_match($pattern, '');

        return false !== $result;
    }
}
