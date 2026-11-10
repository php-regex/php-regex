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

namespace PHPRegex\Tests\Unit\Lexer;

use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\Printer\JsonDumper;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Tests\Benchmark\Support\PeakMemory;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\TestUtils\LexerAccessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The body of an alphabetic assertion nested in another, "(*pla:(*nla:a))",
 * is read where it stands in the pattern: the parser is given its offsets,
 * not a copy of its text, and what one level keeps of the text is dropped
 * before the level inside it is read.
 *
 * The rows of the fixture hold what each pattern read as when every level
 * kept a copy of its body: each construct a body may hold, two and three
 * bodies deep, valid or not, with and without "x", as bytes and under "u".
 * Whether a pattern compiles was read from the engine, PHP 8.4 on PCRE2
 * 10.44 and on PCRE2 10.49, and is asked again where one of them runs.
 */
final class NestedAssertionBodyTest extends TestCase
{
    use PcreGates;

    private const ROWS = __DIR__.'/../../Fixtures/Lexer/nested_assertion_bodies.php';

    /**
     * @param list<array{0: string, 1: string, 2: int, 3: int}>|string $tokens
     */
    #[Test]
    #[DataProvider('provideNestedBodies')]
    public function test_parser_reads_a_nested_body_as_it_did_from_a_copy(string $pattern, string $release, bool $compiles, array|string $tokens, string $tree, ?string $error): void
    {
        $parser = self::parserFor($release);

        try {
            $read = array_map(static fn (Token $token): array => [$token->type->value, $token->value, $token->position, $token->sourceLength], $parser->tokenize($pattern));
        } catch (RegexException $exception) {
            $read = self::fault($exception);
        }

        $this->assertSame($tokens, $read, 'tokens');

        try {
            $parsed = substr(hash('sha256', json_encode($parser->parse($pattern)->accept(new JsonDumper()), \JSON_THROW_ON_ERROR)), 0, 16);
        } catch (RegexException $exception) {
            $parsed = self::fault($exception);
        }

        $this->assertSame($tree, $parsed, 'tree');

        $result = $parser->validate($pattern);

        $this->assertSame($compiles, $result->isValid, (string) $result->error);
        $this->assertSame($error, $result->isValid ? null : \sprintf('%s@%s: %s', $result->errorCode?->value, $result->offset ?? '-', $result->error), 'error');
    }

    #[Test]
    #[DataProvider('provideEngineVerdicts')]
    public function test_engine_compiles_a_nested_body_as_recorded(string $pattern, string $release, bool $compiles): void
    {
        $this->requiresPcre2Release($release, addcslashes($pattern, "\0..\37\177..\377"));

        $this->assertSame($compiles, false !== @preg_match($pattern, ''));
    }

    /**
     * @return iterable<string, array{pattern: string, release: string, compiles: bool, tokens: list<array{0: string, 1: string, 2: int, 3: int}>|string, tree: string, error: string|null}>
     */
    public static function provideNestedBodies(): iterable
    {
        /** @var array<string, array{pattern: string, reads: list<array{releases: list<string>, compiles: bool, tokens: list<array{0: string, 1: string, 2: int, 3: int}>|string, tree: string, error: string|null}>}> $rows */
        $rows = require self::ROWS;

        foreach ($rows as $name => $row) {
            foreach ($row['reads'] as $read) {
                foreach ($read['releases'] as $release) {
                    yield $name.', PCRE2 '.$release => [
                        'pattern' => $row['pattern'],
                        'release' => $release,
                        'compiles' => $read['compiles'],
                        'tokens' => $read['tokens'],
                        'tree' => $read['tree'],
                        'error' => $read['error'],
                    ];
                }
            }
        }
    }

    /**
     * @return iterable<string, array{pattern: string, release: string, compiles: bool}>
     */
    public static function provideEngineVerdicts(): iterable
    {
        foreach (self::provideNestedBodies() as $name => $row) {
            yield $name => ['pattern' => $row['pattern'], 'release' => $row['release'], 'compiles' => $row['compiles']];
        }
    }

    /**
     * The text of a part is the body the parser reads apart, and a body
     * that closes inside it is left in the pattern: its token holds the
     * opener alone, and spans the whole body.
     *
     * @param list<array{0: string, 1: string, 2: int, 3: int}> $tokens
     */
    #[Test]
    #[DataProvider('provideBodiesClosedInAPart')]
    public function test_lexer_leaves_a_body_closed_in_a_part_in_the_pattern(string $pattern, int $offset, int $length, string $release, array $tokens): void
    {
        $stream = (new Lexer(self::parserFor($release)->target()))->tokenizePart($pattern, $offset, $length);

        $this->assertSame($tokens, array_map(static fn (Token $token): array => [$token->type->value, $token->value, $token->position, $token->sourceLength], $stream->getTokens()));
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int, length: int, release: string, tokens: list<array{0: string, 1: string, 2: int, 3: int}>}>
     */
    public static function provideBodiesClosedInAPart(): iterable
    {
        yield 'alphabetic assertion' => [
            'pattern' => 'x(*pla:a(*nla:b)c)y',
            'offset' => 7,
            'length' => 10,
            'release' => '10.44',
            'tokens' => [['literal', 'a', 0, 1], ['pcre_verb', 'nla:', 1, 8], ['literal', 'c', 9, 1], ['eof', '', 10, 0]],
        ];
        yield 'short non-atomic lookahead' => [
            'pattern' => '(*pla:(?*b|c))',
            'offset' => 6,
            'length' => 7,
            'release' => '10.44',
            'tokens' => [['pcre_verb', '*', 0, 7], ['eof', '', 7, 0]],
        ];
        yield 'non-atomic lookbehind' => [
            'pattern' => '(*pla:(*naplb:b)c)',
            'offset' => 6,
            'length' => 11,
            'release' => '10.44',
            'tokens' => [['pcre_verb', 'naplb:', 0, 10], ['literal', 'c', 10, 1], ['eof', '', 11, 0]],
        ];
        yield 'script run' => [
            'pattern' => '(*pla:(*script_run:b)c)',
            'offset' => 6,
            'length' => 16,
            'release' => '10.44',
            'tokens' => [['pcre_verb', 'script_run:', 0, 15], ['literal', 'c', 15, 1], ['eof', '', 16, 0]],
        ];
        yield 'body that holds nothing' => [
            'pattern' => '(*pla:(*atomic:))',
            'offset' => 6,
            'length' => 10,
            'release' => '10.44',
            'tokens' => [['pcre_verb', 'atomic:', 0, 10], ['eof', '', 10, 0]],
        ];
        yield 'substring scan where it is read' => [
            'pattern' => '(a)(*pla:(*scs:(1)b))',
            'offset' => 9,
            'length' => 11,
            'release' => '10.49',
            'tokens' => [['pcre_verb', 'scs:', 0, 11], ['eof', '', 11, 0]],
        ];
        yield 'substring scan by its full name' => [
            'pattern' => '(a)(*pla:(*scan_substring:(1)b))',
            'offset' => 9,
            'length' => 22,
            'release' => '10.49',
            'tokens' => [['pcre_verb', 'scan_substring:', 0, 22], ['eof', '', 22, 0]],
        ];
        // No body to the parser: it keeps the text as the name of a verb.
        yield 'substring scan before PCRE2 10.45' => [
            'pattern' => '(a)(*pla:(*scs:(1)b))',
            'offset' => 9,
            'length' => 11,
            'release' => '10.44',
            'tokens' => [['pcre_verb', 'scs:(1)b', 0, 11], ['eof', '', 11, 0]],
        ];
        yield 'verb, which the token regex reads' => [
            'pattern' => '(*pla:(*MARK:b)c)',
            'offset' => 6,
            'length' => 10,
            'release' => '10.44',
            'tokens' => [['pcre_verb', 'MARK:b', 0, 9], ['literal', 'c', 9, 1], ['eof', '', 10, 0]],
        ];
    }

    /**
     * The pattern itself is read as it was: a body that closes is one token
     * holding its text, whatever the lexer read as parts before.
     */
    #[Test]
    public function test_lexer_keeps_the_text_of_a_body_closed_in_the_pattern(): void
    {
        $lexer = new Lexer(self::parserFor('10.44')->target());
        $this->assertFalse((new LexerAccessor($lexer))->state()->bodiesLeftInPattern);

        $pattern = '(*pla:a(*nla:b)c)';
        $lexer->tokenizePart($pattern, 6, 10);

        $tokens = $lexer->tokenize($pattern)->getTokens();

        $this->assertSame('pla:a(*nla:b)c', $tokens[0]->value);
        $this->assertSame(17, $tokens[0]->sourceLength);

        // As a condition PCRE takes a lookaround only: the body of
        // "(*atomic:" is not read in place there.
        $lexer->tokenizePart($pattern, 6, 10);
        $tokens = $lexer->tokenizeInPlace('(?(*atomic:a)b)')->getTokens();

        $this->assertSame('atomic:a', $tokens[1]->value);
        $this->assertSame(11, $tokens[1]->sourceLength);
    }

    /**
     * Each level kept three copies of its body while the levels inside it
     * were read. Measured then on PHP 8.4, "(*pla:" nested 300 then 1 200
     * deep around "a": 4.6 MB then 33.2 MB at the peak, seven times as
     * much for four times the pattern, and nine times as much under the
     * longer "(*negative_lookbehind:". PCRE refuses parentheses nested
     * deeper than 250, which the parser leaves to validation: only the
     * memory of reading the pattern counts here.
     */
    #[Test]
    #[DataProvider('provideNestedOpeners')]
    public function test_parser_reads_bodies_nested_four_times_as_deep_in_about_four_times_the_memory(string $opener): void
    {
        $this->requiresPhp('8.2', 'The peak memory of a parse', 'has no memory_reset_peak_usage()');

        $shallow = self::peakOfParsing('/'.str_repeat($opener, 300).'a'.str_repeat(')', 300).'/');
        $deep = self::peakOfParsing('/'.str_repeat($opener, 1_200).'a'.str_repeat(')', 1_200).'/');

        $this->assertLessThan(5 * $shallow, $deep, \sprintf('The peak grew from %.1f MB at 300 levels to %.1f MB at 1 200.', $shallow / 1_048_576, $deep / 1_048_576));
    }

    /**
     * @return iterable<string, array{opener: string}>
     */
    public static function provideNestedOpeners(): iterable
    {
        yield 'positive lookahead by its alphabetic name' => ['opener' => '(*pla:'];
        yield 'short non-atomic lookahead' => ['opener' => '(?*'];
        yield 'negative lookbehind by its full name' => ['opener' => '(*negative_lookbehind:'];
        yield 'atomic group after text' => ['opener' => 'b(*atomic:'];
    }

    /**
     * A long text under many bodies was copied three times at each of them.
     * Measured then on PHP 8.4: 200 bodies around a comment of 65 000 bytes
     * peaked 38 MB above the same bodies around a comment of 1 000 bytes,
     * about six hundred bytes for each byte of text. preg_match() compiles
     * both patterns.
     */
    #[Test]
    public function test_parser_keeps_a_text_under_many_bodies_a_few_times_whatever_their_number(): void
    {
        $this->requiresPhp('8.2', 'The peak memory of a parse', 'has no memory_reset_peak_usage()');

        $pattern = static fn (int $bytes): string => '/'.str_repeat('(*pla:', 200).'(?#'.str_repeat('a', $bytes).')'.str_repeat(')', 200).'/';
        $this->assertSame(1, preg_match($pattern(1_000), 'b'));
        $this->assertSame(1, preg_match($pattern(65_000), 'b'));

        $short = self::peakOfParsing($pattern(1_000));
        $long = self::peakOfParsing($pattern(65_000));

        $this->assertLessThan(12 * 64_000, $long - $short, \sprintf('The peak grew by %.1f MB for 64 000 bytes of text.', ($long - $short) / 1_048_576));
    }

    /**
     * The bytes the parse of $pattern takes at its peak, the tree it gives
     * included.
     */
    private static function peakOfParsing(string $pattern): int
    {
        $parser = RegexParser::create(['cache' => null, 'max_recursion_depth' => 2_000]);
        // The classes are loaded and the token regexes compiled before the
        // measure.
        $parser->parse('/(*pla:(?*(*asr:(*atomic:a(?#b)))))/');
        gc_collect_cycles();
        PeakMemory::reset();
        $before = memory_get_usage();

        try {
            $parser->parse($pattern);
        } catch (RegexException) {
            // A refusal ends the reading: only its memory counts.
        }

        return memory_get_peak_usage() - $before;
    }

    private static function parserFor(string $release): RegexParser
    {
        return RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => $release]);
    }

    private static function fault(RegexException $exception): string
    {
        return \sprintf('%s@%s: %s', $exception->errorCode->value, $exception->position ?? '-', $exception->getMessage());
    }
}
