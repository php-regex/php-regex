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

namespace PhpRegex\Tests\Integration;

use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests that verify parsed patterns behave identically to PHP's PCRE engine.
 * This ensures the library correctly implements PCRE semantics, not just AST structure.
 */
final class BehavioralComplianceTest extends TestCase
{
    private Regex $regexService;

    protected function setUp(): void
    {
        $this->regexService = Regex::create();
    }

    /**
     * Test that round-trip compiled patterns match the same strings as the original.
     *
     * @return \Iterator<string, array{pattern: string, testCases: array<string, bool>}>
     */
    /**
     * @return \Iterator<array{pattern: string, testCases: array<int|string, bool>}>
     */
    public static function providePatternsWithBehavior(): \Iterator
    {
        // Basic patterns
        yield 'simple literal' => [
            'pattern' => '/abc/',
            'testCases' => [
                'abc' => true,
                'ABC' => false,
                'abcd' => true,  // partial match
                'xabc' => true,  // partial match
                'ab' => false,
            ],
        ];

        yield 'case insensitive flag' => [
            'pattern' => '/test/i',
            'testCases' => [
                'test' => true,
                'TEST' => true,
                'TeSt' => true,
                'testing' => true,
                'tes' => false,
            ],
        ];

        yield 'character class' => [
            'pattern' => '/[a-z]+/',
            'testCases' => [
                'hello' => true,
                'HELLO' => false,
                'hello123' => true,
                'num123' => true, // "num" matches [a-z]+
            ],
        ];

        yield 'negated character class' => [
            'pattern' => '/[^0-9]+/',
            'testCases' => [
                'abc' => true,
                'num123' => true, // "num" matches [^0-9]+
                'abc123' => true,
            ],
        ];

        yield 'quantifiers' => [
            'pattern' => '/a+b*c?/',
            'testCases' => [
                'a' => true,
                'ab' => true,
                'abc' => true,
                'aabbbbc' => true,
                'b' => false,
            ],
        ];

        yield 'anchors' => [
            'pattern' => '/^test$/',
            'testCases' => [
                'test' => true,
                'testing' => false,
            ],
        ];

        yield 'alternation' => [
            'pattern' => '/foo|bar/',
            'testCases' => [
                'foo' => true,
                'bar' => true,
                'foobar' => true,
                'baz' => false,
            ],
        ];

        yield 'capturing groups' => [
            'pattern' => '/(hello)/',
            'testCases' => [
                'hello' => true,
                'world' => false,
                'hello world' => true,
            ],
        ];

        yield 'non-capturing groups' => [
            'pattern' => '/(?:foo|bar)+/',
            'testCases' => [
                'foo' => true,
                'bar' => true,
                'foobar' => true,
                'foobarbaz' => true,
            ],
        ];

        yield 'named groups' => [
            'pattern' => '/(?<word>\w+)/',
            'testCases' => [
                'hello' => true,
                'num123' => true,
                '!' => false,
            ],
        ];

        yield 'backreferences' => [
            'pattern' => '/(a)\1/',
            'testCases' => [
                'aa' => true,
                'ab' => false,
                'aaa' => true,  // matches first two 'aa'
            ],
        ];

        yield 'lookahead' => [
            'pattern' => '/foo(?=bar)/',
            'testCases' => [
                'foobar' => true,
                'foobaz' => false,
                'foo' => false,
            ],
        ];

        yield 'negative lookahead' => [
            'pattern' => '/foo(?!bar)/',
            'testCases' => [
                'foo' => true,  // 'foo' not followed by 'bar'
                'foobaz' => true,
                'foobar' => false,
            ],
        ];

        yield 'lookbehind' => [
            'pattern' => '/(?<=foo)bar/',
            'testCases' => [
                'foobar' => true,
                'bar' => false,
                'xbar' => false,
            ],
        ];

        yield 'atomic groups' => [
            'pattern' => '/(?>a+)b/',
            'testCases' => [
                'ab' => true,
                'aab' => true,
                'aaab' => true,
            ],
        ];

        yield 'unicode properties' => [
            'pattern' => '/\p{L}+/u',
            'testCases' => [
                'hello' => true,
                'Héllo' => true,
                'num123' => true, // "num" matches \p{L}+
                'hello123' => true,
            ],
        ];

        yield 'word boundaries' => [
            'pattern' => '/\btest\b/',
            'testCases' => [
                'test' => true,
                'testing' => false,
                'a test b' => true,
                'contest' => false,
            ],
        ];

        // Additional patterns to boost coverage
        yield 'octal escape' => [
            'pattern' => '/\101/',
            'testCases' => [
                'A' => true,
                'B' => false,
            ],
        ];

        yield 'control char' => [
            'pattern' => '/\cA/',
            'testCases' => [
                \chr(1) => true,
                'B' => false,
            ],
        ];

        yield 'subroutine' => [
            'pattern' => '/a(?R)?b/',
            'testCases' => [
                'ab' => true,
                'aabb' => true,
                'c' => false,
            ],
        ];

        yield 'deeply nested groups' => [
            'pattern' => '/((((a))))/',
            'testCases' => [
                'a' => true,
                'b' => false,
            ],
        ];

        yield 'complex char class' => [
            'pattern' => '/[a-z\d]/',
            'testCases' => [
                'a' => true,
                '1' => true,
                'A' => false,
            ],
        ];

        yield 'pcre verb' => [
            'pattern' => '/(*NOTEMPTY)a/',
            'testCases' => [
                'a' => true,
                '' => false,
            ],
        ];

        yield 'pcre verb crlf' => [
            'pattern' => '/(*CRLF)a/',
            'testCases' => [
                'a' => true,
                'b' => false,
            ],
        ];

        yield 'hex escape' => [
            'pattern' => '/\x41/',
            'testCases' => [
                'A' => true,
                'B' => false,
            ],
        ];

        yield 'named group backref' => [
            'pattern' => '/(?<name>a)\k<name>/',
            'testCases' => [
                'aa' => true,
                'ab' => false,
            ],
        ];

        yield 'subroutine name' => [
            'pattern' => '/(?<sub>a)(?&sub)/',
            'testCases' => [
                'aa' => true,
                'ab' => false,
            ],
        ];

        yield 'complex char class 2' => [
            'pattern' => '/[\w\d]/',
            'testCases' => [
                'a' => true,
                '1' => true,
                ' ' => false,
            ],
        ];
    }

    /**
     * @param array<int|string, bool> $testCases
     */
    #[DataProvider('providePatternsWithBehavior')]
    public function test_pattern_behavior_matches_pcre(string $pattern, array $testCases): void
    {
        $compiler = new PatternPrinter();

        // Parse and recompile the pattern
        $ast = $this->regexService->parse($pattern);
        $compiled = $ast->accept($compiler);

        // Test that both patterns behave identically
        foreach ($testCases as $input => $shouldMatch) {
            $input = (string) $input; // Ensure input is string
            $originalMatch = (bool) @preg_match($pattern, $input);
            $compiledMatch = (bool) @preg_match($compiled, $input);

            $this->assertSame(
                $originalMatch,
                $compiledMatch,
                \sprintf(
                    "Pattern behavior mismatch for input '%s':\n".
                    "Original pattern: %s (matches: %s)\n".
                    "Compiled pattern: %s (matches: %s)\n".
                    'Expected: %s',
                    $input,
                    $pattern,
                    $originalMatch ? 'YES' : 'NO',
                    $compiled,
                    $compiledMatch ? 'YES' : 'NO',
                    $shouldMatch ? 'MATCH' : 'NO MATCH',
                ),
            );

            $this->assertSame(
                $shouldMatch,
                $originalMatch,
                \sprintf(
                    "Test case expectation incorrect for '%s' with pattern %s",
                    $input,
                    $pattern,
                ),
            );
        }
    }

    /**
     * Test that captured groups match identically.
     */
    public function test_captured_groups_match_pcre(): void
    {
        $pattern = '/(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})/';
        $input = '2025-11-24';

        $compiler = new PatternPrinter();

        $ast = $this->regexService->parse($pattern);
        $compiled = $ast->accept($compiler);

        preg_match($pattern, $input, $originalMatches);
        preg_match($compiled, $input, $compiledMatches);

        $this->assertSame($originalMatches, $compiledMatches);
    }

    /**
     * Test that substitutions work identically.
     */
    public function test_substitutions_match_pcre(): void
    {
        $pattern = '/(\w+)\s+(\w+)/';
        $replacement = '$2 $1';
        $input = 'hello world';

        $compiler = new PatternPrinter();

        $ast = $this->regexService->parse($pattern);
        $compiled = $ast->accept($compiler);

        $originalResult = preg_replace($pattern, $replacement, $input);
        $compiledResult = preg_replace($compiled, $replacement, $input);

        $this->assertSame($originalResult, $compiledResult);
    }
}
