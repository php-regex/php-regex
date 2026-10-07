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

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Tests\TestUtils\PcreMessageCodes;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without /u, PCRE reads a pattern byte by byte: a quantifier after "é"
 * repeats its last byte, not the character. preg_match('/^é+$/', "é\xA9")
 * matches and preg_match('/^é+$/', 'éé') does not.
 */
final class QuantifiedMultibyteLiteralTest extends TestCase
{
    #[Test]
    public function test_the_engine_repeats_the_last_byte_without_u(): void
    {
        $this->assertSame(1, preg_match('/^é+$/', "é\xA9"));
        $this->assertSame(0, preg_match('/^é+$/', 'éé'));
        $this->assertSame(1, preg_match('/^é+$/u', 'éé'));
    }

    #[Test]
    #[DataProvider('provideByteQuantifiers')]
    public function test_only_the_last_byte_is_repeated_without_u(string $pattern, string $prefix, string $repeated, int $splitAt): void
    {
        $sequence = Regex::create()->parse($pattern)->pattern;
        $this->assertInstanceOf(SequenceNode::class, $sequence);

        $children = array_values(array_filter($sequence->children, static fn ($child): bool => $child instanceof LiteralNode || $child instanceof QuantifierNode));
        [$before, $quantified] = \array_slice($children, -2);

        $this->assertInstanceOf(LiteralNode::class, $before);
        $this->assertSame($prefix, $before->value);
        $this->assertSame($splitAt, $before->getEndPosition());

        $this->assertInstanceOf(QuantifierNode::class, $quantified);
        $this->assertInstanceOf(LiteralNode::class, $quantified->node);
        $this->assertSame($repeated, $quantified->node->value);
        $this->assertSame($splitAt, $quantified->node->getStartPosition());
    }

    #[Test]
    #[DataProvider('provideCodePointQuantifiers')]
    public function test_the_whole_code_point_is_repeated_in_utf8_mode(string $pattern): void
    {
        $quantified = Regex::create()->parse($pattern)->pattern;
        if ($quantified instanceof SequenceNode) {
            $quantified = $quantified->children[\count($quantified->children) - 1];
        }

        $this->assertInstanceOf(QuantifierNode::class, $quantified);
        $this->assertInstanceOf(LiteralNode::class, $quantified->node);
        $this->assertSame('é', $quantified->node->value);
    }

    #[Test]
    #[DataProvider('provideByteEquivalents')]
    public function test_the_solver_sees_the_language_the_engine_matches(string $pattern, string $bytes, string $member, string $outsider): void
    {
        $this->assertSame(1, preg_match($pattern, $member), $pattern);
        $this->assertSame(0, preg_match($pattern, $outsider), $pattern);
        $this->assertSame(1, preg_match($bytes, $member), $bytes);

        $this->assertTrue((new LanguageSolver())->equivalent($pattern, $bytes)->isEquivalent, \sprintf('%s and %s match the same strings.', $pattern, $bytes));
    }

    /**
     * The quantifier repeats the last byte, and a quantifier after that one
     * has nothing to repeat: PCRE refuses "é+{2}" without /u as it refuses
     * "a+{2}" ("quantifier does not follow a repeatable item"), at the end
     * of the second quantifier. The offset is read from the running engine.
     */
    #[Test]
    #[DataProvider('provideStackedQuantifiers')]
    public function test_a_quantifier_stacked_on_a_quantified_multibyte_character_is_refused(string $pattern, int $offset): void
    {
        $pcre = PcreMessageCodes::read(PcreMessageCodes::warningOf($pattern) ?? 'compiles');
        $this->assertSame(['message' => 'quantifier does not follow a repeatable item', 'offset' => $offset], $pcre, 'Oracle: '.$pattern);

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertSame([false, ErrorCode::QuantifierNothingToRepeat, $offset], [$result->isValid, $result->errorCode, $result->offset], \sprintf('%s: %s', $pattern, (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideStackedQuantifiers(): iterable
    {
        yield 'two bytes, plus then braces' => ['pattern' => '/é+{2}/', 'offset' => 6];
        yield 'two bytes, braces then braces' => ['pattern' => '/é{2}{2}/', 'offset' => 8];
        yield 'paragraph separator as bytes, plus then braces' => ['pattern' => "/\u{2029}+{2}/", 'offset' => 7];
        yield 'line separator as bytes, braces then braces' => ['pattern' => "/\u{2028}{2}{2}/", 'offset' => 9];
        yield 'paragraph separator as bytes, lazy plus then braces' => ['pattern' => "/\u{2029}+?{2}/", 'offset' => 8];
        yield 'two bytes, possessive plus then braces' => ['pattern' => '/é++{2}/', 'offset' => 7];
        yield 'two bytes, star then braces' => ['pattern' => '/é*{2}/', 'offset' => 6];
        yield 'two bytes, question mark then braces' => ['pattern' => '/é?{2}/', 'offset' => 6];
        yield 'two bytes, plus then open braces' => ['pattern' => '/é+{2,}/', 'offset' => 7];
        yield 'three bytes, plus then braces' => ['pattern' => '/€+{2}/', 'offset' => 7];
        yield 'two bytes, braces then braces under x' => ['pattern' => '/é{2}{2}/x', 'offset' => 8];
        yield 'two bytes, plus, a space, then braces under x' => ['pattern' => '/é+ {2}/x', 'offset' => 7];
        yield 'two bytes, plus, a comment, then braces' => ['pattern' => '/é+(?#c){2}/', 'offset' => 11];
        // The "?" or "+" after the comment is the suffix of the inner
        // quantifier: anything after it has nothing to repeat.
        yield 'two bytes, plus, a comment, then two question marks' => ['pattern' => '/é+(?#c)??/', 'offset' => 10];
        yield 'two bytes, plus, a comment, then question mark and plus' => ['pattern' => '/é+(?#c)?+/', 'offset' => 10];
        yield 'two bytes, plus, a comment, then plus and question mark' => ['pattern' => '/é+(?#c)+?/', 'offset' => 10];
        yield 'two bytes, plus, a comment, then star' => ['pattern' => '/é+(?#c)*/', 'offset' => 9];
        yield 'octal escape and a digit, star, a comment, then two question marks' => ['pattern' => '/\\1000*(?#c)??/', 'offset' => 13];
        yield 'octal escape and a digit, star, a comment, then star' => ['pattern' => '/\\1000*(?#c)*/', 'offset' => 12];
        // Refused where PCRE refuses them already: kept as guards.
        yield 'one byte, plus then braces' => ['pattern' => '/a+{2}/', 'offset' => 5];
        yield 'one byte, braces then braces' => ['pattern' => '/a{2}{2}/', 'offset' => 7];
        yield 'u flag, plus then braces' => ['pattern' => '/é+{2}/u', 'offset' => 6];
        yield 'u flag, braces then braces' => ['pattern' => '/é{2}{2}/u', 'offset' => 8];
        yield 'u flag, paragraph separator, plus then braces' => ['pattern' => "/\u{2029}+{2}/u", 'offset' => 7];
        yield '(*UTF), plus then braces' => ['pattern' => '/(*UTF)é+{2}/', 'offset' => 12];
    }

    /**
     * PCRE repeats the last byte of "é" (and "0" after the octal "\100"),
     * and a "?" or "+" after a comment or x-mode whitespace following that
     * quantifier makes it lazy or possessive, as if written right after it:
     * "é+(?#c)?" is "é+?". The subject tells the two readings apart (the
     * captures are the engine's, as hex), the tree holds the inner
     * quantifier with that suffix and no outer one, and the pattern the
     * library prints back captures the same. Up to a48c101d the suffix
     * repeated the whole sequence greedily: "(?:é+)?(?#c)".
     *
     * @param list<array{string, QuantifierType}> $quantifiers
     * @param list<string>                        $captures
     */
    #[Test]
    #[DataProvider('provideSuffixesAfterASplitRepeat')]
    public function test_a_suffix_after_a_split_repeat_applies_to_the_repeated_byte(string $pattern, string $subject, array $quantifiers, array $captures): void
    {
        $this->assertSame(1, preg_match($pattern, $subject, $oracle), 'Oracle: '.$pattern);
        $this->assertSame($captures, array_map(bin2hex(...), $oracle), 'Oracle: '.$pattern);

        $regex = Regex::create(['cache' => null]);
        $result = $regex->validate($pattern);
        $this->assertTrue($result->isValid, \sprintf('%s: %s', $pattern, (string) $result->error));

        $ast = $regex->parse($pattern);
        $this->assertSame($quantifiers, self::quantifiersOf($ast->pattern), $pattern);

        $printed = $ast->accept(new PatternPrinter());
        $this->assertSame(1, preg_match($printed, $subject, $matches), $printed);
        $this->assertSame($captures, array_map(bin2hex(...), $matches), \sprintf('%s printed as %s.', $pattern, $printed));
    }

    /**
     * A possessive suffix gives back nothing: what the repeat took cannot
     * be left for the byte after it (PCRE finds no match, "é+" alone does).
     */
    #[Test]
    #[DataProvider('providePossessiveSuffixesAfterASplitRepeat')]
    public function test_a_possessive_suffix_after_a_split_repeat_gives_nothing_back(string $pattern, string $greedy, string $subject): void
    {
        $this->assertSame(0, preg_match($pattern, $subject), 'Oracle: '.$pattern);
        $this->assertSame(1, preg_match($greedy, $subject), 'Oracle: '.$greedy);

        $printed = Regex::create(['cache' => null])->parse($pattern)->accept(new PatternPrinter());

        $this->assertSame(0, preg_match($printed, $subject), \sprintf('%s printed as %s.', $pattern, $printed));
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, quantifiers: list<array{string, QuantifierType}>, captures: list<string>}>
     */
    public static function provideSuffixesAfterASplitRepeat(): iterable
    {
        yield 'two bytes, plus, a comment, lazy' => ['pattern' => '/(é+(?#c)?)(.*)/', 'subject' => "é\xA9\xA9x", 'quantifiers' => [['+', QuantifierType::Lazy], ['*', QuantifierType::Greedy]], 'captures' => ['c3a9a9a978', 'c3a9', 'a9a978']];
        yield 'two bytes, star, a comment, lazy' => ['pattern' => '/(é*(?#c)?)(.*)/', 'subject' => "é\xA9\xA9x", 'quantifiers' => [['*', QuantifierType::Lazy], ['*', QuantifierType::Greedy]], 'captures' => ['c3a9a9a978', 'c3', 'a9a9a978']];
        yield 'two bytes, braces, a comment, lazy' => ['pattern' => '/(é{1,3}(?#c)?)(.*)/', 'subject' => "é\xA9\xA9x", 'quantifiers' => [['{1,3}', QuantifierType::Lazy], ['*', QuantifierType::Greedy]], 'captures' => ['c3a9a9a978', 'c3a9', 'a9a978']];
        yield 'three bytes, plus, a comment, lazy' => ['pattern' => '/(€+(?#c)?)(.*)/', 'subject' => "€\xAC\xACx", 'quantifiers' => [['+', QuantifierType::Lazy], ['*', QuantifierType::Greedy]], 'captures' => ['e282acacac78', 'e282ac', 'acac78']];
        yield 'octal escape and a digit, star, a comment, lazy' => ['pattern' => '/(\\1000*(?#c)?)(.*)/', 'subject' => '@00x', 'quantifiers' => [['*', QuantifierType::Lazy], ['*', QuantifierType::Greedy]], 'captures' => ['40303078', '40', '303078']];
        yield 'two bytes, plus, a comment, possessive' => ['pattern' => '/(é+(?#c)+)(.*)/', 'subject' => "é\xA9\xA9x", 'quantifiers' => [['+', QuantifierType::Possessive], ['*', QuantifierType::Greedy]], 'captures' => ['c3a9a9a978', 'c3a9a9a9', '78']];
        yield 'octal escape and a digit, star, a comment, possessive' => ['pattern' => '/(\\1000*(?#c)+)(.*)/', 'subject' => '@00x', 'quantifiers' => [['*', QuantifierType::Possessive], ['*', QuantifierType::Greedy]], 'captures' => ['40303078', '403030', '78']];
        // Read as the engine reads them already: kept as guards.
        yield 'two bytes, plus, a space under x, lazy' => ['pattern' => '/(é+ ?)(.*)/x', 'subject' => "é\xA9\xA9x", 'quantifiers' => [['+', QuantifierType::Lazy], ['*', QuantifierType::Greedy]], 'captures' => ['c3a9a9a978', 'c3a9', 'a9a978']];
        yield 'u flag, plus, a comment, lazy' => ['pattern' => '/(é+(?#c)?)(.*)/u', 'subject' => 'ééx', 'quantifiers' => [['+', QuantifierType::Lazy], ['*', QuantifierType::Greedy]], 'captures' => ['c3a9c3a978', 'c3a9', 'c3a978']];
    }

    /**
     * @return iterable<string, array{pattern: string, greedy: string, subject: string}>
     */
    public static function providePossessiveSuffixesAfterASplitRepeat(): iterable
    {
        yield 'two bytes, plus, a comment' => ['pattern' => '/^é+(?#c)+\xA9/', 'greedy' => '/^é+\xA9/', 'subject' => "é\xA9\xA9"];
        yield 'two bytes, braces, a comment' => ['pattern' => '/^é{1,3}(?#c)+\xA9/', 'greedy' => '/^é{1,3}\xA9/', 'subject' => "é\xA9\xA9"];
        yield 'two bytes, plus, a space under x' => ['pattern' => '/^é+ + \xA9/x', 'greedy' => '/^é+ \xA9/x', 'subject' => "é\xA9\xA9"];
        yield 'octal escape and a digit, star, a comment' => ['pattern' => '/^\\1000*(?#c)+0/', 'greedy' => '/^\\1000*0/', 'subject' => '@00'];
    }

    /**
     * What looks like a second quantifier but is none: "{2" is text, and
     * "+" or "?" after braces make them possessive or lazy. PCRE compiles
     * each, so the library must too.
     */
    #[Test]
    #[DataProvider('provideQuantifierSuffixes')]
    public function test_a_quantifier_suffix_on_a_multibyte_character_is_accepted(string $pattern): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), 'Oracle: '.$pattern);

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s: %s', $pattern, (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideQuantifierSuffixes(): iterable
    {
        yield 'unclosed braces after plus' => ['pattern' => '/é+{2/'];
        yield 'possessive braces' => ['pattern' => '/é{2}+/'];
        yield 'lazy braces' => ['pattern' => '/é{2}?/'];
        yield 'lazy plus' => ['pattern' => '/é+?/'];
    }

    /**
     * "\1000" is the octal escape "\100" then the digit "0", read as one
     * node: a quantifier after an empty quote, a comment or a space under x
     * is the first one on it, and PCRE compiles the pattern.
     */
    #[Test]
    #[DataProvider('provideQuantifiersAfterAnEscapeAndADigit')]
    public function test_a_quantifier_after_an_escape_and_a_digit_is_accepted(string $pattern): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), 'Oracle: '.$pattern);

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s: %s', $pattern, (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideQuantifiersAfterAnEscapeAndADigit(): iterable
    {
        yield 'plus after an empty quote' => ['pattern' => '/\\1000\\E+/'];
        yield 'braces after a space under x' => ['pattern' => '/\\1000 {2}/x'];
        yield 'question mark after a comment' => ['pattern' => '/\\1000(?#c)?/'];
    }

    /**
     * @return iterable<string, array{pattern: string, prefix: string, repeated: string, splitAt: int}>
     */
    public static function provideByteQuantifiers(): iterable
    {
        yield 'two bytes, plus' => ['pattern' => '/é+/', 'prefix' => "\xC3", 'repeated' => "\xA9", 'splitAt' => 1];
        yield 'two bytes, braces' => ['pattern' => '/é{2}/', 'prefix' => "\xC3", 'repeated' => "\xA9", 'splitAt' => 1];
        yield 'two bytes, lazy' => ['pattern' => '/é??/', 'prefix' => "\xC3", 'repeated' => "\xA9", 'splitAt' => 1];
        yield 'three bytes' => ['pattern' => '/€*/', 'prefix' => "\xE2\x82", 'repeated' => "\xAC", 'splitAt' => 2];
        yield 'after a comment' => ['pattern' => '/é(?#c)+/', 'prefix' => "\xC3", 'repeated' => "\xA9", 'splitAt' => 1];
        yield 'after extended whitespace' => ['pattern' => '/é +/x', 'prefix' => "\xC3", 'repeated' => "\xA9", 'splitAt' => 1];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideCodePointQuantifiers(): iterable
    {
        yield 'u flag' => ['pattern' => '/é+/u'];
        yield '(*UTF)' => ['pattern' => '/(*UTF)é+/'];
    }

    /**
     * @return iterable<string, array{pattern: string, bytes: string, member: string, outsider: string}>
     */
    public static function provideByteEquivalents(): iterable
    {
        yield 'plus' => ['pattern' => '/^é+$/', 'bytes' => '/^\xC3\xA9+$/', 'member' => "é\xA9", 'outsider' => 'éé'];
        yield 'braces' => ['pattern' => '/^é{2}$/', 'bytes' => '/^\xC3\xA9{2}$/', 'member' => "é\xA9", 'outsider' => 'éé'];
        yield 'optional' => ['pattern' => '/^é?$/', 'bytes' => '/^\xC3\xA9?$/', 'member' => "\xC3", 'outsider' => ''];
    }

    /**
     * Every quantifier of the tree, outermost first, as its text and type.
     *
     * @return list<array{string, QuantifierType}>
     */
    private static function quantifiersOf(NodeInterface $node): array
    {
        $found = $node instanceof QuantifierNode ? [[$node->quantifier, $node->type]] : [];
        foreach ($node->getChildren() as $child) {
            $found = [...$found, ...self::quantifiersOf($child)];
        }

        return $found;
    }
}
