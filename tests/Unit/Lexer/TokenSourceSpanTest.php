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

namespace PhpRegex\Tests\Unit\Lexer;

use PhpRegex\Parser\Internal\PatternParser;
use PhpRegex\Parser\Lexer;
use PhpRegex\Parser\Token\TokenType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every token knows the span of pattern it was cut from.
 *
 * That is what lets a reader get the text as it was written instead of
 * rebuilding it from the value — which the lexer is free to rewrite.
 */
final class TokenSourceSpanTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRewrittenTokens')]
    public function test_a_rewritten_token_still_spans_what_it_was_cut_from(string $pattern, string $value, int $sourceLength): void
    {
        $tokens = (new Lexer())->tokenize($pattern)->getTokens();

        $this->assertSame($value, $tokens[0]->value);
        $this->assertSame($sourceLength, $tokens[0]->sourceLength);
        $this->assertSame($pattern, substr($pattern, $tokens[0]->position, $tokens[0]->sourceLength));
        $this->assertSame(\strlen($pattern), $tokens[0]->end());
    }

    /**
     * @return iterable<string, array{pattern: string, value: string, sourceLength: int}>
     */
    public static function provideRewrittenTokens(): iterable
    {
        // The value is what the parser works with; the span is what the
        // pattern actually said.
        yield 'a stripped backslash' => ['pattern' => '\d', 'value' => 'd', 'sourceLength' => 2];
        yield 'a control escape' => ['pattern' => '\cX', 'value' => 'X', 'sourceLength' => 3];
        yield 'a callout' => ['pattern' => '(?C1)', 'value' => '1', 'sourceLength' => 5];
        yield 'a named code point' => [
            'pattern' => '\N{LATIN SMALL LETTER A}',
            'value' => 'LATIN SMALL LETTER A',
            'sourceLength' => 24,
        ];
        yield 'a property spelled with braces' => [
            'pattern' => '\p{^Greek}',
            'value' => '{^Greek}',
            'sourceLength' => 10,
        ];
        yield 'a property negated by its letter' => [
            'pattern' => '\P{Greek}',
            'value' => '{^Greek}',
            'sourceLength' => 9,
        ];
    }

    #[Test]
    public function test_the_tokens_of_a_pattern_tile_it_exactly(): void
    {
        $patterns = [
            '/(?<year>\d{4})-\d{2}/',
            '/[a-z\d]+|\p{L}{2,}/u',
            '/\Qa*b\E(?#note)x/',
            '/(?i:foo)(?-i)bar/',
            '/\N{LATIN SMALL LETTER A}\x{1F600}/u',
            '/(*MARK:here)a(?C"tag")b/',
        ];

        foreach ($patterns as $pattern) {
            $this->assertTilesExactly($pattern);
        }
    }

    #[Test]
    public function test_the_tokens_of_every_corpus_pattern_tile_it_exactly(): void
    {
        $path = \dirname(__DIR__, 2).'/Fixtures/Corpus/lint-expectations.json';
        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        /** @var array<int, array{pattern: string, issues: array<int, string>}> $cases */
        $cases = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);

        $checked = 0;
        foreach ($cases as $case) {
            $this->assertTilesExactly($case['pattern']);
            $checked++;
        }

        $this->assertGreaterThan(1000, $checked, 'The corpus fixture looks truncated.');
    }

    private function assertTilesExactly(string $pattern): void
    {
        [$body, $flags] = PatternParser::extractPatternAndFlags($pattern);

        $covered = '';
        foreach ((new Lexer())->tokenize($body, $flags)->getTokens() as $token) {
            if (TokenType::Eof === $token->type) {
                continue;
            }

            $covered .= substr($body, $token->position, $token->sourceLength);
        }

        $this->assertSame($body, $covered, 'The tokens of '.$pattern.' do not cover it exactly.');
    }
}
